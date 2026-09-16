<?php

namespace App\Services;

use App\Models\AppNotification;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\User;
use Illuminate\Support\Carbon;

// The create+evaluate+notify sequence behind ExpenseController@store, pulled
// out so GenerateRecurringExpenses (no HTTP request/controller context) can
// submit an auto-generated expense through the exact same approval gate and
// notification as a manually entered one — never a shortcut around either.
class ExpenseService
{
    public function __construct(private ExpenseApprovalService $approvalService)
    {
    }

    public function create(array $data): Expense
    {
        $data['tax_rate']   = $data['tax_rate'] ?? 0;
        $data['tax_amount'] = (int) round($data['amount'] * $data['tax_rate'] / 100);

        $category = ExpenseCategory::findOrFail($data['category_id']);
        $evaluation = $this->approvalService->evaluate($category, $data['amount'] + $data['tax_amount']);
        $data['requires_director_approval'] = $evaluation['requires_director_approval'];
        $data['escalation_reason']          = $evaluation['escalation_reason'];
        $data['status'] = $evaluation['requires_director_approval'] ? 'pending_director' : 'pending_cto';

        $expense = Expense::create($data);

        $this->notifySubmitted($expense);

        return $expense;
    }

    // Ported from clickhuduma's pos:generateRecurringExpense — same due-date
    // math (diff from the last-generated date must be a positive multiple of
    // the interval), simplified since Hypermed is single-tenant and has no
    // per-business timezone to juggle. Each generated expense still goes
    // through create() above, so it lands in the normal approval queue
    // rather than posting straight to the ledger.
    public function generateDueRecurring(): int
    {
        $templates = Expense::where('is_recurring', true)
            ->whereNull('recur_stopped_on')
            ->whereNotNull('recur_interval')
            ->whereNotNull('recur_interval_type')
            ->with('recurChildren')
            ->get();

        $generated = 0;
        $today = Carbon::today();

        foreach ($templates as $template) {
            $childCount = $template->recurChildren->count();
            if ($template->recur_repetitions && $childCount >= $template->recur_repetitions) {
                continue;
            }

            $lastGenerated = $childCount > 0
                ? $template->recurChildren->max('expense_date')
                : $template->expense_date;
            $last = Carbon::parse($lastGenerated)->startOfDay();

            if ($template->recur_interval_type === 'months' && $template->recur_repeat_on) {
                $last = $last->copy()->startOfMonth()->addDays($template->recur_repeat_on - 1);
            }

            // Carbon's diffInX methods return float, not int — cast before
            // the strict === below, or "already generated today" (diff 0.0)
            // never matches int 0 and this regenerates on every run.
            $diff = (int) match ($template->recur_interval_type) {
                'days'   => $last->diffInDays($today),
                'months' => $last->diffInMonths($today),
                'years'  => $last->diffInYears($today),
                default  => 0,
            };

            if ($diff === 0 || $diff % $template->recur_interval !== 0) {
                continue;
            }

            $this->create([
                'name'            => $template->name,
                'category_id'     => $template->category_id,
                'amount'          => $template->amount,
                'tax_rate'        => $template->tax_rate,
                'payment_mode'    => $template->payment_mode,
                'expense_date'    => $today->toDateString(),
                'reference'       => $template->reference,
                'notes'           => $template->notes,
                'created_by'      => $template->created_by,
                'recur_parent_id' => $template->id,
            ]);
            $generated++;
        }

        return $generated;
    }

    private function notifySubmitted(Expense $expense): void
    {
        $name = $expense->createdBy?->name ?? 'A staff member';
        $roles = $expense->requires_director_approval ? User::ADMIN_TIER : User::CTO_TIER;
        $type  = $expense->requires_director_approval ? 'expense_escalated' : 'expense_requested';

        User::whereIn('role', $roles)
            ->pluck('id')
            ->each(fn ($id) => AppNotification::create([
                'user_id'     => $id,
                'type'        => $type,
                'title'       => 'Expense Submitted',
                'body'        => "{$name} submitted an expense: {$expense->name} (TZS " . number_format($expense->gross_amount) . ').',
                'entity_type' => 'expense',
                'entity_id'   => $expense->id,
                'is_read'     => false,
            ]));
    }
}
