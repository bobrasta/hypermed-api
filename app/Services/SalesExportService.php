<?php

namespace App\Services;

use App\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

// All sales "Export as" — the filtered sales list as an Excel sheet or a
// landscape PDF, one row per sale in the order shown on screen.
class SalesExportService
{
    // A PDF of thousands of rows takes dompdf minutes; past this, use Excel.
    public const PDF_MAX_ROWS = 2000;

    // Rows per <table> in the PDF — about one landscape A4 page (the first
    // page also carries the title and totals, so it's a little short).
    private const PDF_ROWS_PER_TABLE = 36;
    private const PDF_FIRST_PAGE_ROWS = 26;

    private const METHODS = ['cash' => 'Cash', 'bank_transfer' => 'Bank transfer', 'mobile_money' => 'Mobile money', 'cheque' => 'Cheque'];
    private const STATUS = ['paid' => 'Paid', 'due' => 'Due', 'partial' => 'Partial', 'overdue' => 'Overdue',
        'cancelled' => 'Cancelled', 'waived' => 'Waived', 'draft' => 'Draft', 'proforma' => 'Proforma'];

    /** @param Collection<int, Invoice> $sales */
    public function rows(Collection $sales): array
    {
        return $sales->map(fn (Invoice $i) => [
            'date'     => $i->issue_date?->format('d/m/Y'),
            'number'   => $i->invoice_number,
            'customer' => $i->client_name ?? $i->hospital?->name ?? '—',
            'contact'  => $i->client_contact ?: $i->hospital?->contact_phone,
            'status'   => self::STATUS[$i->paymentStatus()] ?? ucfirst($i->paymentStatus()),
            'method'   => $i->payments->pluck('payment_method')->unique()->map(fn ($m) => self::METHODS[$m] ?? $m)->implode(', '),
            'total'    => (int) $i->total,
            'paid'     => (int) $i->amount_paid,
            // Same as the invoice's balance_due (credit notes count as paid).
            'due'      => in_array($i->status, ['cancelled', 'waived'], true) ? 0 : max(0, $i->total - $i->amount_paid - (int) $i->credited),
            'items'    => (float) $i->total_items,
            'added_by' => $i->creator?->name ?? $i->added_by_name,
            'shipping' => $i->shipping_status ? ucfirst(str_replace('_', ' ', $i->shipping_status)) : null,
            'note'     => $i->notes,
        ])->all();
    }

    public function xlsx(array $rows, string $subtitle): string
    {
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet()->setTitle('All sales');
        $cols = ['Date', 'Invoice No.', 'Customer', 'Contact', 'Payment status', 'Payment method',
            'Total amount', 'Total paid', 'Sell due', 'Total items', 'Added by', 'Shipping', 'Sell note'];

        $sheet->setCellValue('A1', 'All sales');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->setCellValue('A2', $subtitle);
        $sheet->fromArray($cols, null, 'A4');
        $sheet->getStyle('A4:M4')->getFont()->setBold(true);
        $sheet->getStyle('A4:M4')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E8EEF2');

        $r = 5;
        foreach ($rows as $row) {
            $sheet->fromArray(array_values($row), null, "A{$r}");
            $sheet->setCellValueExplicit("D{$r}", (string) $row['contact'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $r++;
        }
        $last = max(5, $r - 1);
        $sheet->getStyle("G5:I{$last}")->getNumberFormat()->setFormatCode('#,##0');
        $sheet->getStyle("J5:J{$last}")->getNumberFormat()->setFormatCode('#,##0.##');
        foreach (range('A', 'L') as $c) {
            $sheet->getColumnDimension($c)->setAutoSize(true);
        }
        $sheet->getColumnDimension('M')->setWidth(40);
        $sheet->freezePane('A5');
        $sheet->setAutoFilter("A4:M{$last}");

        ob_start();
        (new Xlsx($book))->save('php://output');

        return ob_get_clean();
    }

    public function pdf(array $rows, string $subtitle, array $totals): string
    {
        $money = fn ($v) => number_format($v);
        // The invoice letterhead, in a derivative fit for a long landscape
        // list (2026-10-24: "every export should have the standard or
        // derivative of the Hypermed template"): navy band + company footer on
        // every page, compact lockup letterhead and title on page 1. Stays on
        // dompdf — mPDF is far slower at 2,000 rows.
        $lh = config('company.letterhead');
        $logos = resource_path('pdf-assets/logos');
        $tin = 'TIN ' . preg_replace('/\s*-\s*/', '-', trim(preg_replace('/^[A-Za-z ]+:\s*/', '', (string) $lh['tin'])));
        $addr = $lh['display_lines'];
        $ref = 'ALL SALES · ' . now()->format('d M Y');
        $html = '<html><head><meta charset="utf-8"><style>
            @page { margin: 46px 20px 40px; }
            body { font-family: Helvetica, sans-serif; font-size: 8.5px; color: #1d1f20; }
            #band { position: fixed; top: -38px; left: 0; right: 0; height: 26px; background: #1d2d3d; }
            #band td { color: #f2f2f3; font-weight: bold; font-size: 10px; letter-spacing: 0.5px; padding: 6px 12px; vertical-align: middle; }
            #band .ref { color: #b5d9fd; font-size: 7px; letter-spacing: 1.2px; text-align: right; }
            #foot { position: fixed; bottom: -6px; left: 0; right: 0; border-top: 1px solid #1d1f20; padding-top: 4px; color: #5d5d60; font-size: 7px; }
            #foot td { vertical-align: top; }
            .lh td { vertical-align: middle; padding-bottom: 6px; }
            .lh-name { font-size: 15px; font-weight: bold; letter-spacing: 0.3px; }
            .lh-tag { font-size: 7px; font-weight: bold; letter-spacing: 1.2px; color: #416180; }
            .lh-addr { font-size: 7.5px; color: #5d5d60; line-height: 1.5; }
            .rule { border-top: 1px solid #1d1f20; margin: 4px 0 8px; }
            h1 { font-size: 22px; margin: 0; letter-spacing: -0.5px; } .sub { color: #416180; font-weight: bold; font-size: 7.5px; letter-spacing: 0.8px; margin: 4px 0 10px; }
            .sum td { padding: 0 22px 8px 0; color: #416180; font-size: 7px; font-weight: bold; letter-spacing: 1px; } .sum b { font-size: 12px; color: #1d1f20; letter-spacing: 0; }
            table.t { width: 100%; border-collapse: collapse; }
            .t th { background: #e7e7ea; text-align: left; padding: 4px 5px; font-size: 7px; letter-spacing: 0.5px; text-transform: uppercase; border-bottom: 1px solid #1d1f20; }
            .t td { padding: 3px 5px; border-bottom: 0.5px solid #ddd; }
            .t .n { text-align: right; white-space: nowrap; }
            .pb { page-break-after: always; }
            </style></head><body>';
        $html .= '<table id="band" width="100%"><tr><td><img src="' . $logos . '/hypermed_icon.png" height="14" style="vertical-align:middle"> &nbsp;HYPERMED HEALTHCARE LTD</td>'
            . '<td class="ref">' . e($ref) . '</td></tr></table>';
        $html .= '<table id="foot" width="100%"><tr><td style="width:30%"><b>HYPERMED HEALTHCARE LTD</b><br>' . e($tin) . '</td>'
            . '<td style="width:45%">' . e($addr[0]) . ' &middot; ' . e(explode(' · ', $addr[1])[0]) . '<br>' . e($addr[2]) . '</td>'
            . '<td style="width:25%; text-align:right"><b>' . e($ref) . '</b><br>www.hypermed.co.tz</td></tr></table>';
        $html .= '<table class="lh" width="100%"><tr><td style="width:40%"><img src="' . $logos . '/hypermed_lockup.png" height="56"><br><b style="font-size:10px">' . e($tin) . '</b></td>'
            . '<td style="text-align:right"><div class="lh-name">' . e($lh['name_header']) . '</div>'
            . '<div class="lh-tag">' . e(strtoupper(config('company.tagline', ''))) . '</div>'
            . '<div class="lh-addr">' . implode('<br>', array_map(fn ($l) => e(str_replace(' · ', ' - ', $l)), $addr)) . '</div></td></tr></table>';
        $html .= '<div class="rule"></div>';
        $html .= '<h1>ALL SALES</h1><div class="sub">' . e(strtoupper($subtitle)) . '</div>';
        $html .= '<table class="sum"><tr>'
            . '<td>SALES<br><b>' . number_format(count($rows)) . '</b></td>'
            . '<td>TOTAL AMOUNT<br><b>TSh ' . $money($totals['total']) . '</b></td>'
            . '<td>TOTAL PAID<br><b>TSh ' . $money($totals['paid']) . '</b></td>'
            . '<td>SELL DUE<br><b>TSh ' . $money($totals['due']) . '</b></td></tr></table>';
        // One table per page-sized chunk: dompdf lays out a single huge
        // table in worse-than-linear time, so ~800 rows (a year of sales)
        // never finished. Chunks keep it linear (~7 s for 800 rows).
        $head = '<thead><tr><th>Date</th><th>Invoice No.</th><th>Customer</th><th>Contact</th><th>Status</th>'
            . '<th>Method</th><th class="n">Total</th><th class="n">Paid</th><th class="n">Due</th><th>Added by</th></tr></thead>';
        // Page 1 also carries the letterhead, title and totals, so it holds fewer rows.
        $chunks = $rows === [] ? [[]] : array_merge(
            [array_slice($rows, 0, self::PDF_FIRST_PAGE_ROWS)],
            array_chunk(array_slice($rows, self::PDF_FIRST_PAGE_ROWS), self::PDF_ROWS_PER_TABLE),
        );
        foreach ($chunks as $i => $chunk) {
            $html .= '<table class="t' . ($i < count($chunks) - 1 ? ' pb' : '') . '">' . $head . '<tbody>';
            foreach ($chunk as $x) {
                $html .= '<tr><td>' . e($x['date']) . '</td><td>' . e($x['number']) . '</td><td>' . e($x['customer']) . '</td>'
                    . '<td>' . e($x['contact']) . '</td><td>' . e($x['status']) . '</td><td>' . e($x['method']) . '</td>'
                    . '<td class="n">' . $money($x['total']) . '</td><td class="n">' . $money($x['paid']) . '</td>'
                    . '<td class="n">' . $money($x['due']) . '</td><td>' . e($x['added_by']) . '</td></tr>';
            }
            $html .= '</tbody></table>';
        }
        $html .= '</body></html>';

        return Pdf::loadHTML($html)->setPaper('a4', 'landscape')->output();
    }
}
