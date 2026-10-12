<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\Shared\Date as XlDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

// Customer statement as the accounts team's own Excel ledger (Ledger +
// Invoice Status sheets), from ReceivablesController::buildStatement(). The
// balance, totals and summary stay formulas so the sheet still adds up if
// someone edits it; invoice status uses the payments as recorded, by date.
class CustomerLedgerXlsxService
{
    private const MONEY = '#,##0';
    private const DATE = 'dd/mm/yyyy';
    private const HEAD_FILL = 'E8EEF2';
    private const STATUS = ['paid' => 'Paid', 'partly_paid' => 'Partly paid', 'unpaid' => 'Unpaid'];

    public function build(array $s): string
    {
        $book = new Spreadsheet();
        $ledger = $book->getActiveSheet()->setTitle('Ledger');
        $closingCell = $this->ledgerSheet($ledger, $s);
        $this->statusSheet($book->createSheet()->setTitle('Invoice Status'), $s, $closingCell);
        $book->setActiveSheetIndex(0);

        ob_start();
        (new Xlsx($book))->save('php://output');

        return ob_get_clean();
    }

    /** @return string the closing-balance cell, for the status sheet's check */
    private function ledgerSheet(Worksheet $sh, array $s): string
    {
        $c = $s['customer'];
        $this->letterhead($sh);
        $sh->setCellValue('A6', 'CUSTOMER LEDGER');
        $sh->getStyle('A6')->getFont()->setBold(true)->setSize(14);

        $from = $s['period']['from'];
        $sh->fromArray([['Customer:', $c['client_name']], ['Customer TIN:', $c['tin'] ?: '—'],
            ['Period from:', $from ? $this->date($from) : 'Start'], ['Period to:', $this->date($s['as_at'])],
            ['Opening balance:', (int) $s['opening_balance']]], null, 'A8');
        $sh->getStyle('A8:A12')->getFont()->setBold(true);
        $sh->getStyle('B10:B11')->getNumberFormat()->setFormatCode(self::DATE);
        $sh->getStyle('B12')->getNumberFormat()->setFormatCode(self::MONEY);
        $sh->getStyle('B8:B12')->getAlignment()->setHorizontal('left');

        $sh->fromArray(['Date', 'Reference', 'Description', 'Debit (Invoiced) TZS', 'Credit (Paid) TZS', 'Balance TZS'], null, 'A14');
        $this->header($sh, 'A14:F14');
        $sh->fromArray([$from ? $this->date($from) : null, null, 'Opening balance b/f', null, null], null, 'A15');
        $sh->setCellValue('F15', '=B12');

        $r = 16;
        foreach ($s['entries'] as $e) {
            $sh->fromArray([$this->date($e['date']), $e['ref'], $e['description'],
                $e['debit'] ?: null, $e['credit'] ?: null], null, "A{$r}");
            $sh->setCellValue("F{$r}", '=F' . ($r - 1) . "+D{$r}-E{$r}");
            $r++;
        }
        $last = $r - 1;
        $tot = $r;
        $sh->setCellValue("C{$tot}", 'TOTALS / CLOSING BALANCE');
        $sh->setCellValue("D{$tot}", $last >= 16 ? "=SUM(D16:D{$last})" : 0);
        $sh->setCellValue("E{$tot}", $last >= 16 ? "=SUM(E16:E{$last})" : 0);
        $sh->setCellValue("F{$tot}", "=F15+D{$tot}-E{$tot}");
        $sh->getStyle("A{$tot}:F{$tot}")->getFont()->setBold(true);
        $sh->getStyle("A{$tot}:F{$tot}")->getBorders()->getTop()->setBorderStyle('thin');
        $sh->getStyle("A15:A{$tot}")->getNumberFormat()->setFormatCode(self::DATE);
        $sh->getStyle("D15:F{$tot}")->getNumberFormat()->setFormatCode(self::MONEY);
        $sh->getStyle("F15:F{$tot}")->getFont()->setBold(true);

        // Summary block, as on the sheet the accounts team uses.
        $sh->setCellValue('E7', 'SUMMARY');
        $sh->getStyle('E7')->getFont()->setBold(true);
        $sh->fromArray([['Total invoiced', "=D{$tot}"], ['Total paid', "=E{$tot}"],
            ['Outstanding balance', "=F{$tot}"], ['% collected', "=IF(B12+D{$tot}=0,0,E{$tot}/(B12+D{$tot}))"]], null, 'E8');
        $sh->getStyle('F8:F10')->getNumberFormat()->setFormatCode(self::MONEY);
        $sh->getStyle('F11')->getNumberFormat()->setFormatCode('0.0%');
        $sh->getStyle('F10')->getFont()->setBold(true);

        $sh->setCellValue('A' . ($tot + 2), 'Balance = opening balance + invoices − payments and credit notes to date. '
            . 'Payments are shown on the date they were paid. Generated ' . now()->format('d M Y, H:i') . '.');
        $sh->getStyle('A' . ($tot + 2))->getFont()->setItalic(true)->setSize(9);

        $sh->getColumnDimension('A')->setWidth(16);
        $sh->getColumnDimension('B')->setWidth(18);
        $sh->getColumnDimension('C')->setWidth(48);
        foreach (['D', 'E', 'F'] as $col) {
            $sh->getColumnDimension($col)->setWidth(22);
        }
        $sh->freezePane('A15');

        return "Ledger!F{$tot}";
    }

    private function statusSheet(Worksheet $sh, array $s, string $closingCell): void
    {
        $this->letterhead($sh);
        $sh->setCellValue('A6', 'INVOICE STATUS – ' . $s['customer']['client_name'] . ' as at ' . Carbon::parse($s['as_at'])->format('d M Y'));
        $sh->getStyle('A6')->getFont()->setBold(true)->setSize(14);
        $sh->setCellValue('A7', 'Each payment counts against the invoice it was recorded on, on the date it was paid. '
            . 'Days overdue are counted to the as-at date.');
        $sh->getStyle('A7')->getFont()->setItalic(true)->setSize(9);

        $sh->fromArray(['Invoice No', 'Date', 'Due date', 'Invoice Amount TZS', 'Paid TZS', 'Credit notes TZS',
            'Remaining TZS', 'Status', 'Days overdue'], null, 'A10');
        $this->header($sh, 'A10:I10');

        $r = 11;
        foreach ($s['invoice_status'] as $i) {
            $sh->fromArray([$i['invoice_number'], $this->date($i['issue_date']), $this->date($i['due_date']),
                $i['total'], $i['paid'], $i['credited'] ?: null, null,
                self::STATUS[$i['status']] ?? $i['status'], $i['days_overdue'] ?: null], null, "A{$r}");
            $sh->setCellValue("G{$r}", "=MAX(0,D{$r}-E{$r}-F{$r})");
            $r++;
        }
        $last = $r - 1;
        $tot = $r;
        $sh->setCellValue("A{$tot}", 'TOTALS');
        foreach (['D', 'E', 'F', 'G'] as $col) {
            $sh->setCellValue("{$col}{$tot}", $last >= 11 ? "=SUM({$col}11:{$col}{$last})" : 0);
        }
        $sh->getStyle("A{$tot}:I{$tot}")->getFont()->setBold(true);
        $sh->getStyle("A{$tot}:I{$tot}")->getBorders()->getTop()->setBorderStyle('thin');
        $sh->getStyle("B11:C{$tot}")->getNumberFormat()->setFormatCode(self::DATE);
        $sh->getStyle("D11:G{$tot}")->getNumberFormat()->setFormatCode(self::MONEY);

        $check = $tot + 2;
        $sh->setCellValue("A{$check}", 'Check: remaining on invoices = ledger closing balance');
        $sh->setCellValue("G{$check}", "=G{$tot}-{$closingCell}");
        $sh->setCellValue("H{$check}", "=IF(ABS(G{$check})<1,\"OK\",\"Mismatch\")");
        $sh->getStyle("G{$check}")->getNumberFormat()->setFormatCode(self::MONEY);
        $sh->setCellValue('A' . ($check + 1), 'A mismatch means the customer has paid more than an invoice was for.');
        $sh->getStyle('A' . ($check + 1))->getFont()->setItalic(true)->setSize(9);

        $sh->getColumnDimension('A')->setWidth(18);
        foreach (['B', 'C'] as $col) {
            $sh->getColumnDimension($col)->setWidth(13);
        }
        foreach (['D', 'E', 'F', 'G'] as $col) {
            $sh->getColumnDimension($col)->setWidth(20);
        }
        $sh->getColumnDimension('H')->setWidth(13);
        $sh->getColumnDimension('I')->setWidth(13);
        $sh->freezePane('A11');
    }

    private function letterhead(Worksheet $sh): void
    {
        $lh = config('company.letterhead');
        $sh->setCellValue('A1', $lh['name_header']);
        $sh->getStyle('A1')->getFont()->setBold(true)->setSize(13);
        $sh->setCellValue('A2', implode(' · ', array_slice($lh['display_lines'], 0, 3)));
        $sh->setCellValue('A3', $lh['tin']);
    }

    private function header(Worksheet $sh, string $range): void
    {
        $sh->getStyle($range)->getFont()->setBold(true);
        $sh->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::HEAD_FILL);
        $sh->getStyle($range)->getAlignment()->setWrapText(true);
    }

    private function date(?string $d): ?float
    {
        return $d ? XlDate::PHPToExcel(Carbon::parse($d)->startOfDay()) : null;
    }
}
