<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PayrollItemResource;
use App\Http\Resources\PayrollRunResource;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\User;
use Illuminate\Http\Request;

class PayrollController extends Controller
{
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

    public function index(Request $request)
    {
        $this->authorize($request);

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
            'heslb_amount'      => ['nullable', 'integer', 'min:0'],
            'other_deductions'  => ['nullable', 'integer', 'min:0'],
            'notes'             => ['nullable', 'string'],
        ]);

        $base       = $data['base_salary'] ?? 0;
        $allowances = $data['allowances_total'] ?? 0;
        $overtime   = $data['overtime_amount'] ?? 0;
        $paye       = $data['paye_amount'] ?? 0;
        $nssf       = $data['nssf_amount'] ?? 0;
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

    public function approve(Request $request, PayrollRun $payrollRun)
    {
        $this->authorize($request);
        abort_if($payrollRun->status !== 'reviewed', 422, 'Only a reviewed run can be approved.');

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

        return new PayrollRunResource($payrollRun->load(['createdBy', 'approvedBy']));
    }

    public function markPaid(Request $request, PayrollRun $payrollRun)
    {
        $this->authorize($request);
        abort_if($payrollRun->status !== 'approved', 422, 'Only an approved run can be marked paid.');

        $payrollRun->update(['status' => 'paid', 'paid_at' => now()]);

        return new PayrollRunResource($payrollRun);
    }

    // Staff picker for the item-entry grid — active staff not yet on this run.
    public function eligibleStaff(Request $request, PayrollRun $payrollRun)
    {
        $this->authorize($request);

        $existingIds = $payrollRun->items()->pluck('user_id');
        $staff = User::where('is_active', true)->whereNotIn('id', $existingIds)->orderBy('name')->get(['id', 'name']);

        return response()->json(['data' => $staff]);
    }
}
