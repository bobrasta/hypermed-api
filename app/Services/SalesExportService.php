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
        $html = '<html><head><meta charset="utf-8"><style>
            @page { margin: 22px 20px; }
            body { font-family: Helvetica, sans-serif; font-size: 8.5px; color: #1d2329; }
            h1 { font-size: 15px; margin: 0; } .sub { color: #5d6670; margin: 2px 0 10px; }
            .sum td { padding: 0 18px 8px 0; } .sum b { font-size: 11px; }
            table.t { width: 100%; border-collapse: collapse; }
            .t th { background: #e8eef2; text-align: left; padding: 4px 5px; font-size: 8px; text-transform: uppercase; }
            .t td { padding: 3px 5px; border-bottom: 0.5px solid #d9dee3; }
            .t .n { text-align: right; white-space: nowrap; }
            </style></head><body>';
        $html .= '<h1>All sales</h1><div class="sub">' . e($subtitle) . '</div>';
        $html .= '<table class="sum"><tr>'
            . '<td>Sales<br><b>' . number_format(count($rows)) . '</b></td>'
            . '<td>Total amount<br><b>TSh ' . $money($totals['total']) . '</b></td>'
            . '<td>Total paid<br><b>TSh ' . $money($totals['paid']) . '</b></td>'
            . '<td>Sell due<br><b>TSh ' . $money($totals['due']) . '</b></td></tr></table>';
        $html .= '<table class="t"><thead><tr><th>Date</th><th>Invoice No.</th><th>Customer</th><th>Contact</th><th>Status</th>'
            . '<th>Method</th><th class="n">Total</th><th class="n">Paid</th><th class="n">Due</th><th>Added by</th></tr></thead><tbody>';
        foreach ($rows as $x) {
            $html .= '<tr><td>' . e($x['date']) . '</td><td>' . e($x['number']) . '</td><td>' . e($x['customer']) . '</td>'
                . '<td>' . e($x['contact']) . '</td><td>' . e($x['status']) . '</td><td>' . e($x['method']) . '</td>'
                . '<td class="n">' . $money($x['total']) . '</td><td class="n">' . $money($x['paid']) . '</td>'
                . '<td class="n">' . $money($x['due']) . '</td><td>' . e($x['added_by']) . '</td></tr>';
        }
        $html .= '</tbody></table></body></html>';

        return Pdf::loadHTML($html)->setPaper('a4', 'landscape')->output();
    }
}
