<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PayrollItemResource;
use App\Http\Resources\PayrollRunResource;
use App\Models\ApprovalLog;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\User;
use App\Services\DocumentPdfService;
use App\Services\FinancePostingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

class PayrollController extends Controller
{
    private const MONTH_NAMES = ['', 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

    // Payroll is data-model + manual entry this round — the accountant
    // enters gross/allowances/overtime/deductions per staff line by hand.
    // No PAYE/NSSF/HESLB auto-calculation engine yet (needs the current
    // TRA/NSSF/HESLB rate tables, a separate future task); gross_pay/
    // net_pay columns exist so that engine can populate them later without
    // a schema change. Gated on the existing accountant/admin authority,
    // same as Credit Notes — no new permission module for this round.
    private function authorize(Request $request): void
    {
        abort_if(! $request->user()->hasAccountantAuthority(), 403, 'You are not authorised to manage payroll.');
    }

    // One staff member's pay lines across every run, newest first — the
    // Directory profile's Payroll tab, and the technician dashboard's own
    // payroll history. Self-access: a user can always view their own
    // history; HR/accountant authority is required to view anyone else's.
    public function historyForUser(Request $request, User $user)
    {
        abort_if(
            $user->id !== $request->user()->id
                && ! $request->user()->hasStaffManageAuthority()
                && ! $request->user()->hasAccountantAuthority(),
            403, 'You are not authorised to view payroll history.',
        );

        $items = PayrollItem::with('payrollRun')->where('user_id', $user->id)->get()
            ->sortByDesc(fn (PayrollItem $i) => $i->payrollRun->period_year * 100 + $i->payrollRun->period_month)
            ->values();

        return response()->json(['data' => $items->map(fn (PayrollItem $i) => [
            'id'               => $i->id,
            'period_month'     => $i->payrollRun->period_month,
            'period_year'      => $i->payrollRun->period_year,
            'status'           => $i->payrollRun->status,
            'base_salary'      => $i->base_salary,
            'allowances_total' => $i->allowances_total,
            'overtime_amount'  => $i->overtime_amount,
            'paye_amount'      => $i->paye_amount,
            'nssf_amount'      => $i->nssf_amount,
            'nssf_employer_amount' => $i->nssf_employer_amount,
            'heslb_amount'     => $i->heslb_amount,
            'other_deductions' => $i->other_deductions,
            'gross_pay'        => $i->gross_pay,
            'net_pay'          => $i->net_pay,
            'paid_at'          => $i->payrollRun->paid_at?->toIso8601String(),
        ])]);
    }

    // Read-only list of runs (id/period/status/aggregate totals, no
    // per-staff line items) — HR needs this for its dashboard summary, not
    // just the accountant. Distinct from every other method below, which
    // stays accountant-only via the shared authorize() since they create/
    // edit/approve actual pay data.
    public function index(Request $request)
    {
        abort_if(
            ! $request->user()->hasStaffManageAuthority() && ! $request->user()->hasAccountantAuthority(),
            403, 'You are not authorised to view payroll runs.',
        );

        $runs = PayrollRun::withCount('items')
            ->orderByDesc('period_year')->orderByDesc('period_month')
            ->get();

        return PayrollRunResource::collection($runs);
    }

    public function store(Request $request)
    {
        $this->authorize($request);

        $data = $request->validate([
            'period_month' => ['required', 'integer', 'min:1', 'max:12'],
            'period_year'  => ['required', 'integer', 'min:2000', 'max:2100'],
        ]);

        $run = PayrollRun::create([
            'period_month'     => $data['period_month'],
            'period_year'      => $data['period_year'],
            'status'           => 'draft',
            // Explicitly defaulted rather than relying on the migration's
            // DB-level default(0) — Eloquent doesn't re-read column
            // defaults after create(), so the in-memory model (and this
            // response) would show null despite the DB row being correct.
            // Same bug class hit twice already this build (Allowance.
            // recurring, Applicant.talent_pool) — see project memory.
            'gross_total'      => 0,
            'deductions_total' => 0,
            'net_total'        => 0,
            'created_by'       => $request->user()->id,
        ]);

        return (new PayrollRunResource($run))->response()->setStatusCode(201);
    }

    public function show(Request $request, PayrollRun $payrollRun)
    {
        $this->authorize($request);

        return new PayrollRunResource($payrollRun->load(['items.user', 'createdBy', 'approvedBy']));
    }

    // Upserts one staff member's line for the run — one call per row saved
    // from the entry grid, keyed on (payroll_run_id, user_id).
    public function upsertItem(Request $request, PayrollRun $payrollRun)
    {
        $this->authorize($request);
        abort_if($payrollRun->status !== 'draft', 422, 'Only a draft run can have its items edited.');

        $data = $request->validate([
            'user_id'           => ['required', 'exists:users,id'],
            'base_salary'       => ['nullable', 'integer', 'min:0'],
            'allowances_total'  => ['nullable', 'integer', 'min:0'],
            'overtime_amount'   => ['nullable', 'integer', 'min:0'],
            'paye_amount'       => ['nullable', 'integer', 'min:0'],
            'nssf_amount'       => ['nullable', 'integer', 'min:0'],
            // Employer-side NSSF match — informational, does NOT factor into
            // gross/net (it's not deducted from the employee). Left as-is
            // (not reset to null) on an update that doesn't touch it, so a
            // figure entered once by finance isn't wiped out by a later
            // edit to an unrelated line item.
            'nssf_employer_amount' => ['nullable', 'integer', 'min:0'],
            'heslb_amount'      => ['nullable', 'integer', 'min:0'],
            'other_deductions'  => ['nullable', 'integer', 'min:0'],
            'notes'             => ['nullable', 'string'],
        ]);

        $existing = PayrollItem::where('payroll_run_id', $payrollRun->id)->where('user_id', $data['user_id'])->first();

        $base       = $data['base_salary'] ?? 0;
        $allowances = $data['allowances_total'] ?? 0;
        $overtime   = $data['overtime_amount'] ?? 0;
        $paye       = $data['paye_amount'] ?? 0;
        $nssf       = $data['nssf_amount'] ?? 0;
        $nssfEmployer = array_key_exists('nssf_employer_amount', $data) ? $data['nssf_employer_amount'] : $existing?->nssf_employer_amount;
        $heslb      = $data['heslb_amount'] ?? 0;
        $otherDed   = $data['other_deductions'] ?? 0;
        $gross      = $base + $allowances + $overtime;
        $net        = $gross - ($paye + $nssf + $heslb + $otherDed);

        $item = PayrollItem::updateOrCreate(
            ['payroll_run_id' => $payrollRun->id, 'user_id' => $data['user_id']],
            [
                'base_salary'       => $base,
                'allowances_total'  => $allowances,
                'overtime_amount'   => $overtime,
                'paye_amount'       => $paye,
                'nssf_amount'       => $nssf,
                'nssf_employer_amount' => $nssfEmployer,
                'heslb_amount'      => $heslb,
                'other_deductions'  => $otherDed,
                'gross_pay'         => $gross,
                'net_pay'           => $net,
                'notes'             => $data['notes'] ?? null,
            ],
        );

        return new PayrollItemResource($item->load('user'));
    }

    public function destroyItem(Request $request, PayrollRun $payrollRun, PayrollItem $item)
    {
        $this->authorize($request);
        abort_if($payrollRun->status !== 'draft', 422, 'Only a draft run can have its items edited.');
        abort_if($item->payroll_run_id !== $payrollRun->id, 404);

        $item->delete();

        return response()->json(['message' => 'Line removed.']);
    }

    public function review(Request $request, PayrollRun $payrollRun)
    {
        $this->authorize($request);
        abort_if($payrollRun->status !== 'draft', 422, 'Only a draft run can be marked reviewed.');
        abort_if($payrollRun->items()->count() === 0, 422, 'Add at least one staff line before review.');

        $payrollRun->update(['status' => 'reviewed']);

        return new PayrollRunResource($payrollRun);
    }

    // Approval is deliberately gated to Director authority, not the same
    // hasAccountantAuthority() check as every other stage in this
    // controller — the accountant who drafted and reviewed a run should
    // not also be the one who signs off on paying it (segregation of
    // duties). See the finance-approval-policy pass this method was split
    // out of.
    public function approve(Request $request, PayrollRun $payrollRun)
    {
        abort_if(! $request->user()->hasDirectorAuthority(), 403, 'Only the Director can approve a payroll run.');
        abort_if($payrollRun->status !== 'reviewed', 422, 'Only a reviewed run can be approved.');
        abort_if($payrollRun->created_by === $request->user()->id, 403, 'You cannot approve a payroll run you created.');

        $totals = $payrollRun->items()->selectRaw(
            'SUM(gross_pay) as gross, SUM(gross_pay - net_pay) as deductions, SUM(net_pay) as net'
        )->first();

        $payrollRun->update([
            'status'           => 'approved',
            'gross_total'      => (int) ($totals->gross ?? 0),
            'deductions_total' => (int) ($totals->deductions ?? 0),
            'net_total'        => (int) ($totals->net ?? 0),
            'approved_by'      => $request->user()->id,
            'approved_at'      => now(),
        ]);
        ApprovalLog::record($payrollRun, 'approved', $request->user());

        return new PayrollRunResource($payrollRun->load(['createdBy', 'approvedBy']));
    }

    // Marking a run paid used to just flip a status flag — the money it
    // represents was invisible everywhere else in Finance (Expenses, Cash
    // Flow, P&L all read from the ledger, and payroll never posted to it).
    // Now it creates a real Expense under "Salaries & Wages" and posts it
    // through the same FinancePostingService every other cash-out event in
    // this app already uses, rather than inventing a parallel path.
    public function markPaid(Request $request, PayrollRun $payrollRun, FinancePostingService $financePosting)
    {
        $this->authorize($request);
        abort_if($payrollRun->status !== 'approved', 422, 'Only an approved run can be marked paid.');

        $payrollRun = DB::transaction(function () use ($payrollRun, $request, $financePosting) {
            $category = ExpenseCategory::where('name', 'Salaries & Wages')->firstOrFail();
            static $months = ['', 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

            // Payroll's own draft → reviewed → approved chain already is the
            // approval — this posts straight to 'approved', bypassing the
            // separate pending_cto/pending_director expense workflow rather
            // than making the accountant approve the same spend twice.
            // Posts net pay (the actual bank outflow) as the expense, not
            // gross — a fully rigorous ledger would book gross as the
            // expense and split PAYE/NSSF/HESLB out as separate payable
            // liabilities (money withheld, owed to TRA/NSSF, not yet an
            // expense reduction). That needs those liability accounts to
            // exist first — deliberately out of scope alongside the rest of
            // "no statutory auto-calc engine yet" for this round; net-only
            // is simpler and still correctly reflects money actually leaving
            // the bank, which is what was missing.
            $expense = Expense::create([
                'name'         => "Payroll — {$months[$payrollRun->period_month]} {$payrollRun->period_year}",
                'category_id'  => $category->id,
                'amount'       => $payrollRun->net_total,
                'tax_rate'     => 0,
                'tax_amount'   => 0,
                'payment_mode' => 'bank',
                'expense_date' => now()->toDateString(),
                'reference'    => "Payroll Run #{$payrollRun->id}",
                'created_by'   => $request->user()->id,
                'status'       => 'approved',
                'requires_director_approval' => false,
                'reviewed_by'  => $request->user()->id,
                'reviewed_at'  => now(),
            ]);
            $financePosting->postExpense($expense);

            $payrollRun->update(['status' => 'paid', 'paid_at' => now(), 'expense_id' => $expense->id]);
            ApprovalLog::record($payrollRun, 'paid', $request->user());

            return $payrollRun;
        });

        return new PayrollRunResource($payrollRun->fresh()->load('expense'));
    }

    // Staff picker for the item-entry grid — active staff not yet on this run.
    public function eligibleStaff(Request $request, PayrollRun $payrollRun)
    {
        $this->authorize($request);

        $existingIds = $payrollRun->items()->pluck('user_id');
        $staff = User::where('is_active', true)->whereNotIn('id', $existingIds)->orderBy('name')->get(['id', 'name']);

        return response()->json(['data' => $staff]);
    }

    // One user's payslip PDF for one run — same self-or-authority gate as
    // historyForUser(): a user can always pull their own, HR/accountant can
    // pull anyone's. Streamed by both the signed public route (below) and
    // reachable directly by an authenticated session, same dual-route shape
    // as InvoiceController::pdf()/QuotationController::pdf(). The public
    // route carries no auth:sanctum session (that's the whole point — no
    // auth header needed), so the self-or-authority check only applies when
    // there IS an authenticated caller; an unauthenticated request only
    // gets here at all if it presented a valid, short-lived (10 min)
    // signature — the 'signed' middleware is what gates that path, same
    // reasoning as HrReportController::exportPdf().
    public function payslip(Request $request, User $user, PayrollRun $payrollRun, DocumentPdfService $pdfService)
    {
        $caller = $request->user();
        abort_if(
            $caller
                && $user->id !== $caller->id
                && ! $caller->hasStaffManageAuthority()
                && ! $caller->hasAccountantAuthority(),
            403, 'You are not authorised to view this payslip.',
        );

        $item = PayrollItem::where('payroll_run_id', $payrollRun->id)->where('user_id', $user->id)->firstOrFail();

        $pdf = $pdfService->payslipPdf([
            'user'        => $user->load('position'),
            'item'        => $item,
            'periodLabel' => self::MONTH_NAMES[$payrollRun->period_month] . ' ' . $payrollRun->period_year,
            'paidAt'      => $payrollRun->paid_at?->format('d M Y'),
        ]);

        return $pdf->stream("payslip-{$user->id}-{$payrollRun->period_year}-{$payrollRun->period_month}.pdf");
    }

    // Signed, time-limited link to the payslip PDF — lets the Flutter app
    // open it via launchUrl() without attaching an auth header. Same gate
    // as payslip() above (the 'signed' middleware only proves the link
    // itself wasn't tampered with, not who's allowed to view it — the
    // signed route still calls through to payslip(), which re-checks).
    public function payslipLink(Request $request, User $user, PayrollRun $payrollRun)
    {
        abort_if(
            $user->id !== $request->user()->id
                && ! $request->user()->hasStaffManageAuthority()
                && ! $request->user()->hasAccountantAuthority(),
            403, 'You are not authorised to view this payslip.',
        );

        $expiresAt = now()->addMinutes(10);
        $url = URL::temporarySignedRoute('payslips.pdf-public', $expiresAt, ['user' => $user->id, 'payrollRun' => $payrollRun->id]);

        return response()->json(['data' => [
            'url'        => $url,
            'expires_at' => $expiresAt->toIso8601String(),
        ]]);
    }
}
