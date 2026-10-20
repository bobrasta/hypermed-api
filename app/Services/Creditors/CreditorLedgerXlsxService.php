<?php

namespace App\Services\Creditors;

use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Creditor ledgers as the accounts team's own workbook (CREDITOR LEDGERS
 * DRILLAS.xlsx): an INTERNATIONAL sheet (DATE, NARRATION, REF, XRATE, then
 * DEBIT / CREDIT / BALANCE each in TZS and the creditor's currency) and a
 * LOCAL sheet (TZS only), one block per creditor. TZS amounts, running
 * balances and the totals stay formulas, as in the original.
 */
class CreditorLedgerXlsxService
{
    private const MONEY = '#,##0.00';
    private const TZS = '#,##0';
    private const HEAD_FILL = 'E8EEF2';
    private const GAP = 5;

    /** @param array[] $statements CreditorLedgerService::statement() results */
    public function build(array $statements): string
    {
        $book = new Spreadsheet();
        $intl = $book->getActiveSheet()->setTitle('INTERNATIONAL');
        $local = $book->createSheet()->setTitle('LOCAL');
        $row = ['international' => 2, 'local' => 2];
        foreach ($statements as $s) {
            $cat = $s['creditor']['category'];
            $row[$cat] = $cat === 'international' ? $this->intlBlock($intl, $s, $row[$cat]) : $this->localBlock($local, $s, $row[$cat]);
            $row[$cat] += self::GAP;
        }
        foreach ([$intl, $local] as $sh) {
            if ($sh->getHighestRow() <= 1) {
                $sh->setCellValue('B2', 'No creditors in this category.');
            }
            $sh->getColumnDimension('A')->setWidth(3);
            $sh->getColumnDimension('B')->setWidth(18);
            $sh->getColumnDimension('C')->setWidth(36);
            $sh->getColumnDimension('D')->setWidth(16);
            foreach (range('E', 'K') as $col) {
                $sh->getColumnDimension($col)->setWidth(16);
            }
        }
        $book->setActiveSheetIndex(0);

        ob_start();
        (new Xlsx($book))->save('php://output');

        return ob_get_clean();
    }

    private function header(Worksheet $sh, array $s, int $r, string $categoryLabel, string $totalsLabelCol, string $totalsCol): array
    {
        $c = $s['creditor'];
        $sh->fromArray([
            ['SUPPLIER', $c['name']],
            ['CATEGORY', $categoryLabel],
            ['CONTACT', trim(implode(' · ', array_filter([$c['phone'], $c['country']])))],
            ['EMAIL', $c['email']],
            ['CONTACT PERSON', $c['contact_person']],
            ['CURRENCY:', $c['currency']],
        ], null, "B{$r}");
        $sh->getStyle("B{$r}:B" . ($r + 5))->getFont()->setBold(true);
        $sh->getStyle("C{$r}")->getFont()->setBold(true)->setSize(12);
        foreach ([2 => 'TOTAL  CREDIT', 3 => 'TOTAL DEBIT', 4 => 'BALANCE'] as $o => $label) {
            $sh->setCellValue($totalsLabelCol . ($r + $o), $label);
            $sh->getStyle($totalsLabelCol . ($r + $o))->getFont()->setBold(true);
        }
        $sh->setCellValue($totalsCol . ($r + 4), "={$totalsCol}" . ($r + 2) . "-{$totalsCol}" . ($r + 3));
        $sh->getStyle("{$totalsCol}" . ($r + 2) . ":{$totalsCol}" . ($r + 4))->getNumberFormat()->setFormatCode(self::MONEY);
        $sh->getStyle("{$totalsCol}" . ($r + 4))->getFont()->setBold(true);

        return [$r + 2, $r + 3];
    }

    /** International block: TZS + creditor currency side by side. Returns the next free row. */
    private function intlBlock(Worksheet $sh, array $s, int $r): int
    {
        $cur = $s['creditor']['currency'];
        [$creditRow, $debitRow] = $this->header($sh, $s, $r, 'INTERNATIONAL SUPPLIERS', 'I', 'K');
        $h = $r + 7;
        $sh->fromArray(['DATE', 'NARRATION', 'REF', 'XRATE', 'DEBIT', null, 'CREDIT', null, 'BALANCE'], null, "B{$h}");
        foreach (['F', 'H', 'J'] as $col) {
            $next = chr(ord($col) + 1);
            $sh->mergeCells("{$col}{$h}:{$next}{$h}");
        }
        $sh->fromArray(['TZS', $cur, 'TZS', $cur, 'TZS', $cur], null, 'F' . ($h + 1));
        $this->headStyle($sh, "B{$h}:K" . ($h + 1));

        $first = $h + 2;
        $i = $first;
        foreach ($this->lines($s) as $line) {
            $sh->setCellValue("B{$i}", CreditorLedgerService::date($line['date']));
            $sh->setCellValue("C{$i}", $line['narration']);
            $sh->setCellValue("D{$i}", $line['ref']);
            $sh->setCellValue("E{$i}", $line['rate']);
            $sh->setCellValue("F{$i}", "=E{$i}*G{$i}");
            $sh->setCellValue("G{$i}", $line['debit']);
            $sh->setCellValue("H{$i}", "=E{$i}*I{$i}");
            $sh->setCellValue("I{$i}", $line['credit']);
            $sh->setCellValue("J{$i}", "=K{$i}*E{$i}");
            $sh->setCellValue("K{$i}", $i === $first ? "=I{$i}-G{$i}" : '=K' . ($i - 1) . "-G{$i}+I{$i}");
            $i++;
        }
        $last = max($first, $i - 1);
        $t = $i;
        $sh->setCellValue("C{$t}", 'TOTAL');
        $sh->setCellValue("F{$t}", "=SUM(F{$first}:F{$last})");
        $sh->setCellValue("G{$t}", "=SUM(G{$first}:G{$last})");
        $sh->setCellValue("H{$t}", "=SUM(H{$first}:H{$last})");
        $sh->setCellValue("I{$t}", "=SUM(I{$first}:I{$last})");
        $sh->setCellValue("J{$t}", "=J{$last}");
        $sh->setCellValue("K{$t}", "=I{$t}-G{$t}");
        $sh->getStyle("B{$t}:K{$t}")->getFont()->setBold(true);
        $sh->setCellValue("K{$creditRow}", "=I{$t}");
        $sh->setCellValue("K{$debitRow}", "=G{$t}");

        $sh->getStyle("E{$first}:E{$t}")->getNumberFormat()->setFormatCode('#,##0.####');
        foreach (['F', 'H', 'J'] as $col) {
            $sh->getStyle("{$col}{$first}:{$col}{$t}")->getNumberFormat()->setFormatCode(self::TZS);
        }
        foreach (['G', 'I', 'K'] as $col) {
            $sh->getStyle("{$col}{$first}:{$col}{$t}")->getNumberFormat()->setFormatCode(self::MONEY);
        }

        return $t + 1;
    }

    /** Local block: TZS only. */
    private function localBlock(Worksheet $sh, array $s, int $r): int
    {
        [$creditRow, $debitRow] = $this->header($sh, $s, $r, 'LOCAL SUPPLIER', 'F', 'G');
        $h = $r + 6;
        $sh->fromArray(['DATE', 'NARRATION', 'REF', 'DEBIT', 'CREDIT', 'BALANCE'], null, "B{$h}");
        $this->headStyle($sh, "B{$h}:G{$h}");

        $first = $h + 1;
        $i = $first;
        foreach ($this->lines($s) as $line) {
            $sh->setCellValue("B{$i}", CreditorLedgerService::date($line['date']));
            $sh->setCellValue("C{$i}", $line['narration']);
            $sh->setCellValue("D{$i}", $line['ref']);
            $sh->setCellValue("E{$i}", $line['debit'] ?: null);
            $sh->setCellValue("F{$i}", $line['credit'] ?: null);
            $sh->setCellValue("G{$i}", $i === $first ? "=F{$i}-E{$i}" : '=G' . ($i - 1) . "-E{$i}+F{$i}");
            $i++;
        }
        $last = max($first, $i - 1);
        $t = $i;
        $sh->setCellValue("C{$t}", 'TOTAL');
        $sh->setCellValue("E{$t}", "=SUM(E{$first}:E{$last})");
        $sh->setCellValue("F{$t}", "=SUM(F{$first}:F{$last})");
        $sh->setCellValue("G{$t}", "=F{$t}-E{$t}");
        $sh->getStyle("B{$t}:G{$t}")->getFont()->setBold(true);
        $sh->setCellValue("G{$creditRow}", "=F{$t}");
        $sh->setCellValue("G{$debitRow}", "=E{$t}");
        $sh->getStyle("E{$first}:G{$t}")->getNumberFormat()->setFormatCode(self::TZS);

        return $t + 1;
    }

    /** Opening b/f (when the statement starts mid-way), then the period's lines. */
    private function lines(array $s): array
    {
        $lines = [];
        if ($s['period']['from'] && abs($s['opening_balance']) > 0.004) {
            $ob = $s['opening_balance'];
            $lines[] = [
                'date' => $s['period']['from'], 'narration' => 'OPENING BALANCE', 'ref' => null,
                'rate' => $ob ? round($s['opening_balance_tzs'] / $ob, 4) : 1,
                'credit' => max($ob, 0), 'debit' => max(-$ob, 0),
            ];
        }

        return array_merge($lines, $s['entries']);
    }

    private function headStyle(Worksheet $sh, string $range): void
    {
        $st = $sh->getStyle($range);
        $st->getFont()->setBold(true);
        $st->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::HEAD_FILL);
        $st->getAlignment()->setHorizontal('center');
    }

    public static function filename(string $what, ?string $to): string
    {
        return 'Creditor-Ledger-' . preg_replace('/[^A-Za-z0-9]+/', '-', $what) . '-' . Carbon::parse($to ?? now())->format('Y-m-d') . '.xlsx';
    }
}
