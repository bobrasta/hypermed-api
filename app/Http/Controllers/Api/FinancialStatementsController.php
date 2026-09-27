<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\EffectivePermissionResolver;
use App\Services\FinancialStatementsPdfService;
use App\Services\FinancialStatementsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

// Annual financial statements (Finance > Reports > Export financial
// statements). Figures come from the ledger (FinancialStatementsService);
// the narrative — directors, auditor, policies — is the editable profile.
// Both are gated on finance.export_reports (super_admin, admin,
// finance_manager), the permission that already existed for exporting
// finance reports but wasn't enforced anywhere yet.
class FinancialStatementsController extends Controller
{
    private function authorizeExport(): void
    {
        abort_unless(
            app(EffectivePermissionResolver::class)->can(Auth::user(), 'finance.export_reports'),
            403,
            'You are not authorised to export financial statements.'
        );
    }

    public function profile()
    {
        $this->authorizeExport();

        return response()->json(['data' => FinancialStatementsService::profile()]);
    }

    public function updateProfile(Request $request)
    {
        $this->authorizeExport();

        $data = $request->validate([
            'company'              => 'required|string|max:200',
            'company_short'        => 'required|string|max:200',
            'cover_address'        => 'array|max:4',
            'cover_address.*'      => 'nullable|string|max:200',
            'principal_activities' => 'nullable|string|max:2000',
            'directors'            => 'array|max:20',
            'directors.*'          => 'array|size:3',
            'directors.*.*'        => 'nullable|string|max:200',
            'md_note_name'         => 'nullable|string|max:200',
            'md_note'              => 'nullable|string|max:2000',
            'shareholders'         => 'array|max:20',
            'shareholders.*'       => 'array|size:3',
            'shareholders.*.0'     => 'required|string|max:200',
            'shareholders.*.1'     => 'nullable|string|max:100',
            'shareholders.*.2'     => 'required|integer|min:0',
            'employees'            => 'nullable|integer|min:0',
            'chairman'             => 'nullable|string|max:200',
            'finance_head'         => 'array',
            'finance_head.name'    => 'nullable|string|max:200',
            'finance_head.title'   => 'nullable|string|max:200',
            'finance_head.reg_no'  => 'nullable|string|max:50',
            'auditor'              => 'array',
            'auditor.*'            => 'nullable',
            'auditor.letterhead'   => 'array|max:6',
            'auditor.letterhead.*' => 'nullable|string|max:200',
            'policies'             => 'array|max:20',
            'policies.*'           => 'array|size:2',
            'policies.*.*'         => 'nullable|string|max:3000',
            'dep_rates'            => 'array|max:20',
            'dep_rates.*'          => 'array|size:2',
            'dep_rates.*.*'        => 'nullable|string|max:100',
            'dep_note'             => 'nullable|string|max:1000',
        ]);

        // Only known keys, strings coerced — the PDF renders these verbatim.
        $defaults = FinancialStatementsService::defaultProfile();
        if (isset($data['auditor'])) {
            $data['auditor'] = array_intersect_key($data['auditor'], $defaults['auditor']);
            foreach ($data['auditor'] as $k => $v) {
                $data['auditor'][$k] = is_array($v) ? array_values(array_map('strval', array_filter($v, 'strlen'))) : (string) $v;
            }
            $data['auditor'] += $defaults['auditor'];
        }
        $profile = array_replace(FinancialStatementsService::profile(), array_intersect_key($data, $defaults));
        foreach (['md_note', 'md_note_name', 'principal_activities', 'chairman', 'dep_note'] as $k) {
            $profile[$k] = (string) ($profile[$k] ?? '');
        }

        Setting::set(FinancialStatementsService::PROFILE_KEY, json_encode($profile), Auth::id());

        return response()->json(['data' => $profile]);
    }

    public function pdf(Request $request, FinancialStatementsService $statements, FinancialStatementsPdfService $renderer)
    {
        $this->authorizeExport();

        $data = $request->validate([
            'year'      => 'required|integer|min:2000|max:' . now()->year,
            'audited'   => 'sometimes|boolean',
            'sign_date' => 'nullable|date',
        ]);

        $year = (int) $data['year'];
        $audited = (bool) ($data['audited'] ?? false);
        $d = $statements->build($year, $audited, $data['sign_date'] ?? now()->toDateString());
        $filename = 'financial_statements_' . $year . ($audited ? '' : '_draft') . '.pdf';

        return response($renderer->render($d), 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $filename . '"',
            'X-Statements-Balanced' => $d['sofp']['balanced'] ? '1' : '0',
        ]);
    }
}
