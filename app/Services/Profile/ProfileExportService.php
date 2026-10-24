<?php

namespace App\Services\Profile;

use App\Models\Expense;
use App\Models\LeaveRequest;
use App\Models\PayrollItem;
use App\Models\SalaryAdjustment;
use App\Models\User;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * "Give me a way to export my (one's) own profile data" (2026-10-24): every
 * record the system holds about one person, as sections — each a list of
 * label/value pairs or a table — rendered to PDF (one heading per section)
 * or Excel (one sheet per section), so the two never disagree.
 */
class ProfileExportService
{
    private const MONTHS = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

    public function sections(User $u): array
    {
        $u->loadMissing(['position', 'manager', 'paymentProfile', 'activeContract.allowances']);
        $tsh = fn ($v) => $v === null ? '—' : 'TSh ' . number_format((int) $v);
        $d = fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->format('d M Y') : '—';
        $c = $u->activeContract;

        return [
            ['title' => 'Personal details', 'pairs' => [
                'Name' => $u->name, 'Work email' => $u->email, 'Phone' => $u->phone ?: '—',
                'Role' => strtoupper(str_replace('_', ' ', (string) $u->role)),
                'Position' => $u->position?->title ?: '—', 'Department' => $u->position?->department ?: '—',
                'Reports to' => $u->manager?->name ?: '—', 'Region' => $u->region ?: '—', 'Zone' => $u->zone ?: '—',
                'Hire date' => $d($u->hire_date), 'Gender' => $u->gender ? ucfirst($u->gender) : '—',
                'Bio' => $u->bio ?: '—', 'Qualifications' => collect($u->qualifications ?? [])->join(', ') ?: '—',
            ]],
            ['title' => 'Statutory IDs', 'pairs' => [
                'NSSF number' => $u->nssf_number ?: '—', 'NIDA number' => $u->nida_number ?: '—', 'TIN number' => $u->tin_number ?: '—',
            ]],
            ['title' => 'Next of kin', 'pairs' => [
                'Name' => $u->next_of_kin_name ?: '—', 'Phone' => $u->next_of_kin_phone ?: '—', 'Relationship' => $u->next_of_kin_relationship ?: '—',
            ]],
            ['title' => 'Payment details', 'pairs' => [
                'Bank / provider' => $u->paymentProfile?->provider ?: '—',
                'Account number' => $u->paymentProfile?->account_number ?: '—',
                'Account name' => $u->paymentProfile?->account_name ?: '—',
            ]],
            ['title' => 'Employment', 'pairs' => $c ? [
                'Contract' => ucwords(str_replace('_', ' ', $c->contract_type)), 'Status' => ucfirst($c->status),
                'Start date' => $d($c->start_date), 'End date' => $d($c->end_date),
                'Basic salary' => $tsh($c->base_salary) . ($c->salary_placeholder ? ' (placeholder — not yet confirmed)' : ''),
                'Monthly allowances' => $tsh($c->allowances->where('recurring', true)->sum('amount')),
                'Health insurance (company-paid)' => $tsh($c->health_insurance_amount ?? 0) . ' / month',
            ] : ['Contract' => 'No contract on record']],
            ['title' => 'Salary changes', 'columns' => ['Effective', 'From', 'To', 'Status', 'Reason'],
                'rows' => SalaryAdjustment::where('user_id', $u->id)->latest('effective_date')->get()
                    ->map(fn ($a) => [$d($a->effective_date), $tsh($a->previous_salary), $tsh($a->new_salary), ucfirst($a->status), $a->reason ?: '—'])->all()],
            ['title' => 'Payslips', 'columns' => ['Period', 'Gross', 'PAYE', 'NSSF', 'HESLB', 'Other', 'Net', 'Run status'],
                'rows' => PayrollItem::where('user_id', $u->id)->with('payrollRun')->get()
                    ->sortByDesc(fn ($i) => sprintf('%04d%02d', $i->payrollRun?->period_year, $i->payrollRun?->period_month))
                    ->map(fn ($i) => [self::MONTHS[$i->payrollRun?->period_month ?? 0] . ' ' . $i->payrollRun?->period_year,
                        $tsh($i->gross_pay), $tsh($i->paye_amount), $tsh($i->nssf_amount), $tsh($i->heslb_amount),
                        $tsh($i->other_deductions), $tsh($i->net_pay), ucfirst((string) $i->payrollRun?->status)])->values()->all()],
            ['title' => 'Leave', 'columns' => ['Type', 'From', 'To', 'Days', 'Status'],
                'rows' => LeaveRequest::where('user_id', $u->id)->with('leaveType')->latest('start_date')->get()
                    ->map(fn ($l) => [$l->leaveType?->name ?? ucwords(str_replace('_', ' ', (string) $l->type)), $d($l->start_date), $d($l->end_date),
                        (string) $l->days_count, ucfirst((string) $l->status)])->all()],
            ['title' => 'Expenses', 'columns' => ['Date', 'Expense', 'Category', 'Amount', 'Status'],
                'rows' => Expense::where('created_by', $u->id)->with('category')->latest('expense_date')->get()
                    ->map(fn ($e) => [$d($e->expense_date), $e->name, $e->category?->name ?? '—', $tsh($e->gross_amount ?? $e->amount),
                        ucwords(str_replace('_', ' ', (string) $e->status))])->all()],
        ];
    }

    /** On the Hypermed letterhead, like invoices and statements. */
    public function pdf(User $u, string $filename)
    {
        $e = fn ($v) => e((string) $v);
        $th = "style='text-align:left; font-size:7pt; font-weight:700; letter-spacing:0.5px; padding:7px 6px; background:#e7e7ea; border-bottom:1px solid #1d1f20; white-space:nowrap;'";
        $td = "style='padding:6px; font-size:8.5pt; border-bottom:1px solid #ddd; vertical-align:top;'";
        $body = '';
        foreach ($this->sections($u) as $s) {
            $body .= "<div style='margin-top:18px'><div class='terms-h'><strong>" . $e(strtoupper($s['title'])) . '</strong></div>';
            if (isset($s['pairs'])) {
                $body .= "<table style='width:100%; border-collapse:collapse; margin-top:4px'>";
                foreach ($s['pairs'] as $k => $v) {
                    $body .= "<tr><td style='width:34%; padding:5px 0; font-size:8.5pt; color:#5d5d60; border-bottom:1px solid #eee; vertical-align:top'>" . $e($k)
                        . "</td><td style='padding:5px 0; font-size:9pt; border-bottom:1px solid #eee; vertical-align:top'>" . $e($v) . '</td></tr>';
                }
                $body .= '</table>';
            } elseif (empty($s['rows'])) {
                $body .= "<div style='padding-top:8px; font-size:8.5pt; color:#7a7a7d'>None on record.</div>";
            } else {
                $body .= "<table style='width:100%; border-collapse:collapse; margin-top:6px'><tr>";
                foreach ($s['columns'] as $c) {
                    $body .= "<th {$th}>" . $e(strtoupper($c)) . '</th>';
                }
                $body .= '</tr>';
                foreach ($s['rows'] as $row) {
                    $body .= '<tr>' . implode('', array_map(fn ($c) => "<td {$td}>" . $e($c) . '</td>', $row)) . '</tr>';
                }
                $body .= '</table>';
            }
            $body .= '</div>';
        }
        $body .= "<div style='margin-top:20px; font-size:8pt; color:#7a7a7d'>Everything Hypermed's system holds about "
            . $e($u->name) . ' as at ' . now()->format('d M Y, H:i') . '. Confidential — for the named person only.</div>';

        return app(\App\Services\DocumentPdfService::class)->letterhead([
            'title' => 'PERSONAL DATA', 'docLabel' => 'Staff', 'docNumber' => 'HM-' . str_pad((string) $u->id, 4, '0', STR_PAD_LEFT),
            'date' => now()->format('d M Y'), 'tag' => 'CONFIDENTIAL · DATA EXPORT FOR ' . strtoupper($u->name),
            'metaCells' => [['EXPORTED FOR', $u->name, true], ['ROLE', strtoupper(str_replace('_', ' ', (string) $u->role))], ['DATE', now()->format('d M Y, H:i')]],
            'bodyHtml' => $body, 'filename' => $filename, 'download' => true,
        ]);
    }

    /** One sheet per section. */
    public function xlsx(User $u): string
    {
        $book = new Spreadsheet();
        $book->removeSheetByIndex(0);
        foreach ($this->sections($u) as $s) {
            $sheet = $book->createSheet();
            $sheet->setTitle(substr(preg_replace('/[\[\]\*\/\\\\?:]/', '', $s['title']), 0, 31));
            if (isset($s['pairs'])) {
                $r = 1;
                foreach ($s['pairs'] as $k => $v) {
                    $sheet->setCellValue("A{$r}", $k);
                    $sheet->setCellValueExplicit("B{$r}", (string) $v, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                    $r++;
                }
            } else {
                $sheet->fromArray($s['columns'], null, 'A1');
                foreach ($s['rows'] as $i => $row) {
                    foreach (array_values($row) as $j => $v) {
                        $sheet->setCellValueExplicit([$j + 1, $i + 2], (string) $v, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                    }
                }
                $sheet->getStyle('A1:' . $sheet->getHighestColumn() . '1')->getFont()->setBold(true);
            }
            foreach (range('A', $sheet->getHighestColumn()) as $col) {
                $sheet->getColumnDimension($col)->setAutoSize(true);
            }
        }
        $book->setActiveSheetIndex(0);
        $path = tempnam(sys_get_temp_dir(), 'profile') . '.xlsx';
        (new Xlsx($book))->save($path);

        return $path;
    }
}
