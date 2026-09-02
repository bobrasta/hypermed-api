<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ExpenseResource;
use App\Models\AppNotification;
use App\Models\ApprovalLog;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\User;
use App\Services\ExpenseApprovalService;
use App\Services\FinancePostingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ExpenseController extends Controller
{
    private function categoryPayload(ExpenseCategory $c): array
    {
        return [
            'id' => $c->id, 'name' => $c->name, 'account_id' => $c->account_id,
            'account_code' => $c->account?->code, 'account_name' => $c->account?->name,
            'parent_id' => $c->parent_id, 'parent_name' => $c->parent?->name,
            'requires_director_approval' => $c->requires_director_approval,
        ];
    }

    public function categories()
    {
        $categories = ExpenseCategory::with(['account:id,code,name', 'parent:id,name'])->orderBy('name')->get();
        return response()->json(['data' => $categories->map(fn ($c) => $this->categoryPayload($c))]);
    }

    // A subcategory always posts to its parent's GL account — parent_id is
    // purely a reporting tree, not a second ledger dimension, so the chart
    // of accounts doesn't grow one line per subcategory.
    public function storeCategory(Request $request)
    {
        abort_if(! $request->user()->hasFinanceApprovalAuthority(), 403, 'You are not authorised to manage expense categories.');

        $data = $request->validate([
            'name'       => ['required', 'string', 'max:150'],
            'account_id' => ['required_without:parent_id', 'nullable', 'exists:chart_of_accounts,id'],
            'parent_id'  => ['nullable', 'exists:expense_categories,id'],
        ]);

        $accountId = $data['account_id'] ?? null;
        if (empty($accountId) && ! empty($data['parent_id'])) {
            $accountId = ExpenseCategory::findOrFail($data['parent_id'])->account_id;
        }

        $category = ExpenseCategory::create([
            'name'       => $data['name'],
            'account_id' => $accountId,
            'parent_id'  => $data['parent_id'] ?? null,
        ]);

        return response()->json(['data' => $this->categoryPayload($category->load(['account', 'parent']))], 201);
    }

    public function updateCategory(Request $request, ExpenseCategory $expenseCategory)
    {
        if ($request->has('requires_director_approval') && count($request->all()) === 1) {
            abort_if(! $request->user()->hasDirectorAuthority(), 403, 'Only the Director can change approval routing.');
            $expenseCategory->update($request->validate(['requires_director_approval' => ['required', 'boolean']]));
            return response()->json(['data' => $this->categoryPayload($expenseCategory->load(['account', 'parent']))]);
        }

        abort_if(! $request->user()->hasFinanceApprovalAuthority(), 403, 'You are not authorised to manage expense categories.');

        $data = $request->validate([
            'name'                       => ['sometimes', 'string', 'max:150'],
            'account_id'                 => ['sometimes', 'exists:chart_of_accounts,id'],
            'parent_id'                  => ['sometimes', 'nullable', 'exists:expense_categories,id'],
            'requires_director_approval' => ['sometimes', 'boolean'],
        ]);

        abort_if(($data['parent_id'] ?? null) === $expenseCategory->id, 422, 'A category cannot be its own parent.');

        $expenseCategory->update($data);

        return response()->json(['data' => $this->categoryPayload($expenseCategory->load(['account', 'parent']))]);
    }

    public function destroyCategory(Request $request, ExpenseCategory $expenseCategory)
    {
        abort_if(! $request->user()->hasFinanceApprovalAuthority(), 403, 'You are not authorised to manage expense categories.');
        abort_if($expenseCategory->expenses()->exists(), 422, 'Expenses are recorded against this category — reassign them first.');
        abort_if($expenseCategory->children()->exists(), 422, 'This category has subcategories — delete or reassign them first.');

        $expenseCategory->delete();

        return response()->noContent();
    }

    public function index(Request $request)
    {
        $query = Expense::with(['category', 'createdBy', 'reviewer']);

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }
        if ($request->filled('payment_mode')) {
            $query->where('payment_mode', $request->payment_mode);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('date_from')) {
            $query->where('expense_date', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->where('expense_date', '<=', $request->date_to);
        }
        if ($request->filled('search')) {
            $query->where('name', 'like', "%{$request->search}%");
        }

        return ExpenseResource::collection($query->latest('expense_date')->paginate(50));
    }

    public function store(Request $request, ExpenseApprovalService $approvalService)
    {
        $data = $request->validate([
            'name'         => ['required', 'string', 'max:255'],
            'category_id'  => ['required', 'exists:expense_categories,id'],
            'amount'       => ['required', 'integer', 'min:1'],
            'tax_rate'     => ['nullable', 'numeric', 'min:0', 'max:100'],
            'payment_mode' => ['required', 'in:cash,bank,mobile_money'],
            'expense_date' => ['required', 'date'],
            'reference'    => ['nullable', 'string', 'max:255'],
            'notes'        => ['nullable', 'string'],
        ]);
        $data['created_by'] = $request->user()->id;
        $data['tax_rate']   = $data['tax_rate'] ?? 0;
        $data['tax_amount'] = (int) round($data['amount'] * $data['tax_rate'] / 100);

        $category = ExpenseCategory::findOrFail($data['category_id']);
        $evaluation = $approvalService->evaluate($category, $data['amount'] + $data['tax_amount']);
        $data['requires_director_approval'] = $evaluation['requires_director_approval'];
        $data['escalation_reason']          = $evaluation['escalation_reason'];
        $data['status'] = $evaluation['requires_director_approval'] ? 'pending_director' : 'pending_cto';

        $expense = Expense::create($data);

        $this->notifySubmitted($expense);

        return response()->json(['data' => new ExpenseResource($expense->load(['category', 'createdBy']))], 201);
    }

    public function show(Expense $expense)
    {
        return response()->json(['data' => new ExpenseResource($expense->load(['category', 'createdBy', 'escalator', 'reviewer']))]);
    }

    public function update(Request $request, Expense $expense, ExpenseApprovalService $approvalService)
    {
        abort_if($expense->status === 'approved', 422, 'Approved expenses cannot be edited — void and recreate if a correction is needed.');

        $data = $request->validate([
            'name'         => ['sometimes', 'string', 'max:255'],
            'category_id'  => ['sometimes', 'exists:expense_categories,id'],
            'amount'       => ['sometimes', 'integer', 'min:1'],
            'tax_rate'     => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'payment_mode' => ['sometimes', 'in:cash,bank,mobile_money'],
            'expense_date' => ['sometimes', 'date'],
            'reference'    => ['nullable', 'string', 'max:255'],
            'notes'        => ['nullable', 'string'],
        ]);

        $newAmount  = $data['amount']   ?? $expense->amount;
        $newTaxRate = $data['tax_rate'] ?? $expense->tax_rate;
        $data['tax_rate']   = $newTaxRate;
        $data['tax_amount'] = (int) round($newAmount * $newTaxRate / 100);

        $category = ExpenseCategory::findOrFail($data['category_id'] ?? $expense->category_id);
        $evaluation = $approvalService->evaluate($category, $newAmount + $data['tax_amount']);
        $data['requires_director_approval'] = $evaluation['requires_director_approval'];
        $data['escalation_reason']          = $evaluation['escalation_reason'];
        $data['status'] = $evaluation['requires_director_approval'] ? 'pending_director' : 'pending_cto';

        $expense->update($data);

        return response()->json(['data' => new ExpenseResource($expense->fresh()->load(['category', 'createdBy']))]);
    }

    public function destroy(Request $request, Expense $expense, FinancePostingService $financePosting)
    {
        $user = $request->user();

        if ($expense->status === 'approved') {
            abort_if(! $user->hasDirectorAuthority(), 403, 'Only the Director can delete an approved expense.');
            DB::transaction(function () use ($expense, $financePosting) {
                $financePosting->reverseExpense($expense);
                $expense->delete();
            });
        } else {
            abort_if($expense->created_by !== $user->id && ! $user->hasCtoApprovalAuthority(), 403, 'Not authorised.');
            $expense->delete();
        }

        return response()->json(null, 204);
    }

    public function approve(Request $request, Expense $expense, FinancePostingService $financePosting)
    {
        $user = $request->user();

        if ($expense->status === 'pending_cto') {
            abort_if(! $user->hasCtoApprovalAuthority(), 403, 'You are not authorised to approve expenses.');
            abort_if($expense->requires_director_approval, 422, 'This expense requires Director approval — escalate it instead.');
        } elseif ($expense->status === 'pending_director') {
            abort_if(! $user->hasDirectorAuthority(), 403, 'Only the Director can approve this expense.');
        } else {
            abort(422, 'Only pending expenses can be approved.');
        }
        abort_if($expense->created_by === $user->id, 403, 'You cannot approve your own expense submission.');

        DB::transaction(function () use ($expense, $user, $financePosting) {
            $expense->update(['status' => 'approved', 'reviewed_by' => $user->id, 'reviewed_at' => now()]);
            $financePosting->postExpense($expense->fresh());
            ApprovalLog::record($expense, 'approved', $user);
        });

        $this->notifyRequester($expense, approved: true);

        return response()->json(['data' => new ExpenseResource($expense->fresh()->load(['category', 'createdBy', 'reviewer']))]);
    }

    public function escalate(Request $request, Expense $expense)
    {
        abort_if(! $request->user()->hasCtoApprovalAuthority(), 403, 'You are not authorised to escalate expenses.');
        abort_if($expense->status !== 'pending_cto', 422, 'Only expenses awaiting CTO review can be escalated.');

        $data = $request->validate(['escalation_reason' => ['nullable', 'string']]);

        $expense->update([
            'status'            => 'pending_director',
            'escalated_by'      => $request->user()->id,
            'escalated_at'      => now(),
            'escalation_reason' => $data['escalation_reason'] ?? $expense->escalation_reason ?? 'Escalated by CTO for Director review.',
        ]);
        ApprovalLog::record($expense, 'escalated', $request->user(), $data['escalation_reason'] ?? null);

        $this->notifyDirector($expense);

        return response()->json(['data' => new ExpenseResource($expense->fresh()->load(['category', 'createdBy', 'escalator']))]);
    }

    public function reject(Request $request, Expense $expense)
    {
        $user = $request->user();

        if ($expense->status === 'pending_cto') {
            abort_if(! $user->hasCtoApprovalAuthority(), 403, 'You are not authorised to review this expense.');
        } elseif ($expense->status === 'pending_director') {
            abort_if(! $user->hasDirectorAuthority(), 403, 'Only the Director can reject this expense.');
        } else {
            abort(422, 'Only pending expenses can be rejected.');
        }

        $data = $request->validate(['rejection_reason' => ['nullable', 'string']]);

        $expense->update([
            'status'            => 'rejected',
            'reviewed_by'       => $user->id,
            'reviewed_at'       => now(),
            'rejection_reason'  => $data['rejection_reason'] ?? null,
        ]);
        ApprovalLog::record($expense, 'rejected', $user, $data['rejection_reason'] ?? null);

        $this->notifyRequester($expense, approved: false);

        return response()->json(['data' => new ExpenseResource($expense->fresh()->load(['category', 'createdBy', 'reviewer']))]);
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

    private function notifyDirector(Expense $expense): void
    {
        $name = $expense->createdBy?->name ?? 'A staff member';

        User::whereIn('role', User::ADMIN_TIER)
            ->pluck('id')
            ->each(fn ($id) => AppNotification::create([
                'user_id'     => $id,
                'type'        => 'expense_escalated',
                'title'       => 'Expense Escalated',
                'body'        => "CTO escalated {$name}'s expense '{$expense->name}' (TZS " . number_format($expense->gross_amount) . ') for your approval.',
                'entity_type' => 'expense',
                'entity_id'   => $expense->id,
                'is_read'     => false,
            ]));
    }

    private function notifyRequester(Expense $expense, bool $approved): void
    {
        AppNotification::create([
            'user_id'     => $expense->created_by,
            'type'        => $approved ? 'expense_approved' : 'expense_rejected',
            'title'       => $approved ? 'Expense Approved' : 'Expense Rejected',
            'body'        => $approved
                ? "Your expense '{$expense->name}' was approved."
                : "Your expense '{$expense->name}' was rejected." . ($expense->rejection_reason ? " Reason: {$expense->rejection_reason}" : ''),
            'entity_type' => 'expense',
            'entity_id'   => $expense->id,
            'is_read'     => false,
        ]);
    }
}
