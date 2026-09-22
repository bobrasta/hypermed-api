<?php

namespace App\Services;

use App\Models\PerDiemRequest;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Section 15.5: reproduces resources/templates/travel_plan_template.xlsx
 * (see travel_plan_template_SPEC.md for the exact cell-by-cell reference
 * this was built against) programmatically rather than loading and
 * splicing the template file, since the day-row count is variable and a
 * built-from-scratch sheet keeps every offset explicit and traceable.
 *
 * One structural deviation from the reference file, deliberate and
 * spec-directed: the template's signature block only has 4 fixed lines
 * (Prepared by / Technical supervisor / Finance / Approved by), but this
 * app's real approval chain has a distinct CTO stage the template never
 * anticipated. Per 15.6 ("if a CTO stage exists ... show it as an
 * additional line so no real approval is missing"), a 5th line is
 * inserted directly after "Technical supervisor" — shifting the LEFT
 * column's Finance/Signature/Date rows down by one. The RIGHT column
 * (Approved by / Managing director) is deliberately left at its
 * original row offset, since it doesn't depend on the CTO line at all —
 * this is the one place the block grows beyond travel_plan_template_
 * SPEC.md's documented "Variable row count" offsets (sigRow1+6 becomes
 * sigRow1+7).
 */
class PerDiemXlsxExportService
{
    private const GRAY = 'BFBFBF';
    private const FONT = 'Times New Roman';
    private const LONG_DATE_FORMAT = '[$-F800]dddd\,\ mmmm\ dd\,\ yyyy';
    private const MONEY_FORMAT = '#,##0.00';
    private const ACCOUNTING_FORMAT = '_(* #,##0.00_);_(* \(#,##0.00\);_(* "-"??_);_(@_)';

    public function build(PerDiemRequest $plan, bool $viewerCanSeeFullPayment): Spreadsheet
    {
        $plan->loadMissing([
            'user', 'lines', 'teamLeadReviewer', 'reviewer', 'paymentInitiatedBy', 'paidBy',
            'revisions.editor', 'adjustments.createdBy', 'serviceTicket',
        ]);

        $lines = $plan->lines;
        $n = max($lines->count(), 1);

        $spreadsheet = new Spreadsheet();
        $spreadsheet->removeSheetByIndex(0);
        $sheet = new Worksheet($spreadsheet, 'WORKPLAN');
        $spreadsheet->addSheet($sheet);
        $spreadsheet->setActiveSheetIndex(0);
        $sheet->getSheetView()->setZoomScale(71);
        $sheet->getTabColor()->setRGB('C00000');

        $firstDayRow = 7;
        $lastDayRow = $firstDayRow + $n - 1;
        $totalRow = $lastDayRow + 1;
        $summaryStart = $totalRow + 2;
        $payRow = $summaryStart + 5;
        $sigRow1 = $payRow + 1;
        $sigLastRow = $sigRow1 + 7; // 8-row block, see class doc comment

        $this->writeHeaderBlock($sheet, $plan);
        $this->writeDayTableHeader($sheet);
        $this->writeDayRows($sheet, $lines, $firstDayRow, $lastDayRow);
        $this->writeTotalsRow($sheet, $firstDayRow, $lastDayRow, $totalRow);
        $summary = $plan->summary();
        $this->writeSummaryBlock($sheet, $summary, $totalRow, $summaryStart);
        $this->writePaymentRow($sheet, $plan, $payRow, $viewerCanSeeFullPayment);
        $this->writeSignatureBlock($sheet, $plan, $sigRow1);
        $this->applyOuterFrame($sheet, $sigLastRow);
        $this->applyColumnWidthsAndPageSetup($sheet);

        $this->writeAppDetailsSheet($spreadsheet, $plan);

        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    public function filename(PerDiemRequest $plan): string
    {
        $name = $plan->staff_name_snapshot ?? $plan->user?->name ?? 'STAFF';
        $regions = $plan->lines->pluck('region')->filter()->unique()->values();
        $regionsPart = $regions->isNotEmpty() ? $regions->implode(' & ') : ($plan->destination ?: 'TRIP');
        $purpose = $plan->purpose ?: 'TRAVEL';
        // Keep the length reasonable — a long free-text purpose truncated
        // rather than producing an unwieldy filename.
        $purpose = \Illuminate\Support\Str::limit($purpose, 60, '');

        $raw = sprintf(
            '%s_TRAVEL_PLAN_TO_%s_FOR_%s_from_%s_to_%s',
            $name,
            $regionsPart,
            $purpose,
            $plan->start_date?->format('d_m_Y'),
            $plan->end_date?->format('d_m_Y'),
        );

        $upper = mb_strtoupper($raw);
        $sanitized = preg_replace('/[^A-Z0-9_&]+/', '_', $upper);
        $sanitized = preg_replace('/_+/', '_', $sanitized);

        return trim($sanitized, '_').'.xlsx';
    }

    // ── 1-2: Header block (rows 1-5) ────────────────────────────────────────

    private function writeHeaderBlock(Worksheet $sheet, PerDiemRequest $plan): void
    {
        $sheet->mergeCells('A1:E1');
        $sheet->mergeCells('F1:I1');
        $sheet->setCellValue('A1', 'Hypermed Healthcare Ltd');
        $sheet->setCellValue('F1', 'Work Plan');
        foreach (['A1', 'F1'] as $cell) {
            $sheet->getStyle($cell)->getFont()->setName(self::FONT)->setSize(22)->setBold(true)->setItalic(true);
        }
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        $sheet->getStyle('F1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet->getRowDimension(1)->setRowHeight(37.35);

        $dateRange = ($plan->start_date?->format('d/m/Y') ?? '').'- '.($plan->end_date?->format('d/m/Y') ?? '');

        $rows = [
            2 => ['Description of the Trip:', $plan->purpose ?: '', Alignment::VERTICAL_CENTER],
            3 => ['Trip coverage date', $dateRange, Alignment::VERTICAL_CENTER],
            4 => ['Name', $plan->staff_name_snapshot ?? $plan->user?->name ?? '', Alignment::VERTICAL_TOP],
            5 => ['Designation', $plan->staff_designation_snapshot ?? '', Alignment::VERTICAL_TOP],
        ];

        foreach ($rows as $row => [$label, $value, $valign]) {
            $sheet->mergeCells("A{$row}:B{$row}");
            $sheet->mergeCells("C{$row}:I{$row}");
            $sheet->setCellValue("A{$row}", $label);
            $sheet->setCellValue("C{$row}", $value);
            $sheet->getStyle("A{$row}:I{$row}")->getFont()->setName(self::FONT)->setSize(12)->setBold(true)->setItalic(true);
            $sheet->getStyle("A{$row}")->getAlignment()->setVertical($valign);
            $sheet->getStyle("C{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT)->setVertical($valign);
        }
    }

    // ── 3: Day-table header (row 6) ──────────────────────────────────────────

    private function writeDayTableHeader(Worksheet $sheet): void
    {
        $headers = ['A' => 'No.', 'B' => 'Date', 'C' => 'Region', 'D' => 'District', 'E' => 'Site name',
            'F' => 'Activity', 'G' => 'Labor', 'H' => 'Per diem', 'I' => 'Transportation fare'];

        foreach ($headers as $col => $text) {
            $cell = "{$col}6";
            $sheet->setCellValue($cell, $text);
            $sheet->getStyle($cell)->getFont()->setName(self::FONT)->setSize(12)->setBold(true)->setItalic(true);
            $sheet->getStyle($cell)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::GRAY);
            $sheet->getStyle($cell)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT)
                ->setVertical(Alignment::VERTICAL_TOP)->setWrapText(true);
            $sheet->getStyle($cell)->getNumberFormat()->setFormatCode('General');
        }
        $sheet->getRowDimension(6)->setRowHeight(45.6);
    }

    // ── 4: Day rows ───────────────────────────────────────────────────────────

    private function writeDayRows(Worksheet $sheet, $lines, int $firstDayRow, int $lastDayRow): void
    {
        $row = $firstDayRow;
        $seq = 1;

        foreach ($lines as $line) {
            $sheet->setCellValue("A{$row}", $seq);
            $sheet->getStyle("A{$row}")->getNumberFormat()->setFormatCode('General');

            $sheet->setCellValue("B{$row}", ExcelDate::dateTimeToExcel($line->date));
            $sheet->getStyle("B{$row}")->getNumberFormat()->setFormatCode(self::LONG_DATE_FORMAT);

            $sheet->setCellValueExplicit("C{$row}", (string) ($line->region ?? ''), DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("D{$row}", (string) ($line->district ?? ''), DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("E{$row}", (string) ($line->site_name ?? ''), DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("F{$row}", (string) ($line->activity ?? ''), DataType::TYPE_STRING);
            foreach (['C', 'D', 'E', 'F'] as $col) {
                $sheet->getStyle("{$col}{$row}")->getNumberFormat()->setFormatCode('General');
            }
            $sheet->getStyle("F{$row}")->getAlignment()->setWrapText(true);

            $sheet->setCellValue("G{$row}", (float) $line->labor_cost);
            $sheet->setCellValue("H{$row}", (float) $line->per_diem_cost);
            $sheet->setCellValue("I{$row}", (float) $line->transport_fare);
            $sheet->getStyle("G{$row}")->getNumberFormat()->setFormatCode(self::MONEY_FORMAT);
            $sheet->getStyle("H{$row}")->getNumberFormat()->setFormatCode(self::MONEY_FORMAT);
            $sheet->getStyle("I{$row}")->getNumberFormat()->setFormatCode(self::ACCOUNTING_FORMAT);

            $sheet->getStyle("A{$row}:I{$row}")->getFont()->setName(self::FONT)->setSize(12);
            $sheet->getStyle("B{$row}")->getFont()->setBold(true)->setItalic(true);
            foreach (['A', 'C', 'D', 'E', 'F', 'G', 'H', 'I'] as $col) {
                $sheet->getStyle("{$col}{$row}")->getFont()->setBold(false)->setItalic(false);
            }
            $sheet->getStyle("B{$row}")->getFont()->setBold(true)->setItalic(true);

            $sheet->getStyle("A{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT)->setVertical(Alignment::VERTICAL_CENTER);
            $sheet->getStyle("B{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT)->setVertical(Alignment::VERTICAL_CENTER);
            foreach (['C', 'D', 'E', 'F'] as $col) {
                $sheet->getStyle("{$col}{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT)->setVertical(Alignment::VERTICAL_CENTER);
            }
            foreach (['G', 'H', 'I'] as $col) {
                $sheet->getStyle("{$col}{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT)->setVertical(Alignment::VERTICAL_CENTER);
            }

            $this->thinBorderRow($sheet, $row);

            $row++;
            $seq++;
        }

        $sheet->getDefaultRowDimension()->setRowHeight(15.75);
        for ($r = $firstDayRow; $r <= $lastDayRow; $r++) {
            $sheet->getRowDimension($r)->setRowHeight(21.75);
        }
    }

    // ── 5: Totals row ─────────────────────────────────────────────────────────

    private function writeTotalsRow(Worksheet $sheet, int $firstDayRow, int $lastDayRow, int $totalRow): void
    {
        $sheet->setCellValue("F{$totalRow}", 'Total');
        $sheet->setCellValue("G{$totalRow}", "=SUM(G{$firstDayRow}:G{$lastDayRow})");
        $sheet->setCellValue("H{$totalRow}", "=SUM(H{$firstDayRow}:H{$lastDayRow})");
        $sheet->setCellValue("I{$totalRow}", "=SUM(I{$firstDayRow}:I{$lastDayRow})");
        $sheet->getStyle("F{$totalRow}:I{$totalRow}")->getFont()->setName(self::FONT)->setSize(12)->setBold(true)->setItalic(false);
        $sheet->getStyle("G{$totalRow}:I{$totalRow}")->getNumberFormat()->setFormatCode(self::MONEY_FORMAT);
        $sheet->getRowDimension($totalRow)->setRowHeight(23.25);
        $this->thinBorderRow($sheet, $totalRow);
    }

    // ── 6: Summary block ──────────────────────────────────────────────────────

    private function writeSummaryBlock(Worksheet $sheet, array $summary, int $totalRow, int $summaryStart): void
    {
        $sitesRow = $summaryStart;
        $daysRow = $summaryStart + 1;
        $avgDaysRow = $summaryStart + 2;
        $avgCostRow = $summaryStart + 3;
        $grandTotalRow = $summaryStart + 4;

        $sheet->setCellValue("F{$sitesRow}", 'Number of sites visited');
        $sheet->setCellValue("I{$sitesRow}", $summary['sites_visited']);

        $sheet->setCellValue("F{$daysRow}", 'Total number of days spent');
        $sheet->setCellValue("I{$daysRow}", $summary['days_spent']);

        $sheet->setCellValue("F{$avgDaysRow}", 'Average number of days / site');
        $sheet->setCellValue("F{$avgCostRow}", 'Average Cost Per site'); // 15.5: fix the template's "Avarage" typo
        $sheet->setCellValue("F{$grandTotalRow}", 'Grand total');
        $sheet->setCellValue("I{$grandTotalRow}", "=SUM(G{$totalRow}:I{$totalRow})");

        if ($summary['sites_visited'] > 0) {
            $sheet->setCellValue("I{$avgDaysRow}", "=I{$daysRow}/I{$sitesRow}");
            $sheet->setCellValue("I{$avgCostRow}", "=SUM(G{$totalRow}:I{$totalRow})/I{$sitesRow}");
            $sheet->getStyle("I{$avgDaysRow}")->getNumberFormat()->setFormatCode('0.0');
            $sheet->getStyle("I{$avgCostRow}")->getNumberFormat()->setFormatCode(self::MONEY_FORMAT);
        } else {
            // 15.9 criterion #4: no sites -> "-", never a division by zero.
            $sheet->setCellValueExplicit("I{$avgDaysRow}", '-', DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("I{$avgCostRow}", '-', DataType::TYPE_STRING);
        }

        for ($r = $sitesRow; $r <= $grandTotalRow; $r++) {
            $sheet->getStyle("F{$r}")->getFont()->setName(self::FONT)->setSize(12)->setBold(true)->setItalic(false);
            $sheet->getStyle("F{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT)->setVertical(Alignment::VERTICAL_TOP);
            $sheet->getStyle("I{$r}")->getFont()->setName(self::FONT)->setSize(12)->setBold(true);
            $sheet->getStyle("I{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $this->thinBorderRow($sheet, $r);
        }
        $sheet->getStyle("I{$grandTotalRow}")->getNumberFormat()->setFormatCode(self::MONEY_FORMAT);
        // Closes the summary block before the gray payment bar starts.
        $sheet->getStyle("F{$grandTotalRow}:I{$grandTotalRow}")->getBorders()->getBottom()->setBorderStyle(Border::BORDER_MEDIUM);
    }

    // ── 7: Payment row ────────────────────────────────────────────────────────

    private function writePaymentRow(Worksheet $sheet, PerDiemRequest $plan, int $payRow, bool $viewerCanSeeFullPayment): void
    {
        $snapshot = $plan->payment_snapshot ?? [];
        $provider = $snapshot['provider'] ?? '';
        $accountNumber = (string) ($snapshot['account_number'] ?? '');
        $accountName = $snapshot['account_name'] ?? '';

        if (! $viewerCanSeeFullPayment && strlen($accountNumber) > 4) {
            $accountNumber = str_repeat('*', strlen($accountNumber) - 4).substr($accountNumber, -4);
        }

        $sheet->mergeCells("C{$payRow}:E{$payRow}");
        $sheet->mergeCells("F{$payRow}:I{$payRow}");
        $sheet->setCellValue("B{$payRow}", $provider);
        $sheet->getStyle("B{$payRow}")->getNumberFormat()->setFormatCode('General');
        $sheet->setCellValueExplicit("C{$payRow}", $accountNumber, DataType::TYPE_STRING);
        $sheet->getStyle("C{$payRow}")->getNumberFormat()->setFormatCode('@');
        $sheet->setCellValue("F{$payRow}", 'ACCOUNT NAME: '.$accountName);

        $sheet->getStyle("A{$payRow}:I{$payRow}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::GRAY);
        $sheet->getStyle("A{$payRow}:I{$payRow}")->getFont()->setName(self::FONT)->setSize(12)->setBold(true);
        $sheet->getStyle("B{$payRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("C{$payRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("F{$payRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        $sheet->getRowDimension($payRow)->setRowHeight(16.5);

        $sheet->getStyle("A{$payRow}:I{$payRow}")->getBorders()->getTop()->setBorderStyle(Border::BORDER_MEDIUM);
        $sheet->getStyle("A{$payRow}:I{$payRow}")->getBorders()->getBottom()->setBorderStyle(Border::BORDER_MEDIUM);
    }

    // ── 8: Signature block (8 rows — see class doc comment) ─────────────────

    private function writeSignatureBlock(Worksheet $sheet, PerDiemRequest $plan, int $sigRow1): void
    {
        $sig = app(PerDiemSignatureBlockService::class)->build($plan);
        $byStage = collect($sig)->keyBy('stage');

        $signOff = fn (array $line) => $line['pending']
            ? 'Signature: ….............................................'
            : 'Approved electronically on '.$line['acted_at_display'];

        $preparedBy = $byStage['prepared_by'];
        $teamLead = $byStage['team_lead'];
        $cto = $byStage['cto'];
        $finance = $byStage['accountant_initiate'];
        $finalRelease = $byStage['final_release'];

        $r = $sigRow1;
        $sheet->setCellValue("B{$r}", 'Prepared by: '.($preparedBy['person_name'] ?? ''));
        $r++;
        // Preparing isn't an "approval" step (unlike the four stages
        // below it) — always the blank hand-sign line, never "Approved
        // electronically"; the submission date is already in C{$r}.
        $sheet->setCellValue("B{$r}", 'Signature: ….............................................');
        $sheet->mergeCells("C{$r}:D{$r}");
        $sheet->setCellValue("C{$r}", 'Date: '.($preparedBy['acted_at']
            ? \Illuminate\Support\Carbon::parse($preparedBy['acted_at'])->timezone('Africa/Dar_es_Salaam')->format('d/m/Y')
            : ''));
        $sheet->getStyle("C{$r}")->getFont()->setItalic(true);
        $r++;
        $sheet->setCellValue("B{$r}", 'Reviewed by :');
        $r++;
        // Technical supervisor (team_lead stage)
        $sheet->setCellValue("B{$r}", $teamLead['role_label'].': '.($teamLead['person_name'] ?? ''));
        $sheet->mergeCells("C{$r}:D{$r}");
        $sheet->setCellValue("C{$r}", $signOff($teamLead));
        $sheet->setCellValue("E{$r}", 'Date:'.($teamLead['acted_at'] ? \Illuminate\Support\Carbon::parse($teamLead['acted_at'])->timezone('Africa/Dar_es_Salaam')->format('d/m/Y') : ''));
        // Right side, independent of the CTO row inserted below it —
        // stays at its original template offset (see class doc comment).
        // "Approved by" is the template's own FIXED section label (not
        // the configurable role_label — that's the standalone
        // "{role_label}" caption two rows down, matching the original
        // template's row-32 "Managing director" caption under the name).
        $sheet->setCellValue("F{$r}", 'Approved by:  '.($finalRelease['person_name'] ?? '').' ');
        $sheet->mergeCells("G{$r}:H{$r}");
        $r++;
        // CTO — the extra line, not in the original 4-signer template.
        $sheet->setCellValue("B{$r}", $cto['role_label'].': '.($cto['person_name'] ?? ''));
        $sheet->mergeCells("C{$r}:D{$r}");
        $sheet->setCellValue("C{$r}", $signOff($cto));
        $sheet->setCellValue("E{$r}", 'Date:'.($cto['acted_at'] ? \Illuminate\Support\Carbon::parse($cto['acted_at'])->timezone('Africa/Dar_es_Salaam')->format('d/m/Y') : ''));
        $sheet->setCellValue("F{$r}", 'Signature:');
        $r++;
        $sheet->setCellValue("B{$r}", $finance['role_label'].': '.($finance['person_name'] ?? ''));
        // Standalone caption under "Approved by: {name}" two rows up —
        // this IS the configurable role_label (matches the template's
        // original row-32 "Managing director" caption, no name attached).
        $sheet->setCellValue("F{$r}", $finalRelease['role_label']);
        $r++;
        $sheet->setCellValue("B{$r}", $signOff($finance));
        $sheet->setCellValue("F{$r}", 'Date:'.($finalRelease['acted_at'] ? \Illuminate\Support\Carbon::parse($finalRelease['acted_at'])->timezone('Africa/Dar_es_Salaam')->format('d/m/Y') : ''));
        $r++;
        $sheet->setCellValue("B{$r}", 'Date:'.($finance['acted_at'] ? \Illuminate\Support\Carbon::parse($finance['acted_at'])->timezone('Africa/Dar_es_Salaam')->format('d/m/Y') : ''));

        $lastRow = $r;
        for ($row = $sigRow1; $row <= $lastRow; $row++) {
            $sheet->getStyle("A{$row}:I{$row}")->getFont()->setName(self::FONT)->setSize(12)->setBold(true);
        }
        $sheet->getStyle("C".($sigRow1 + 1))->getFont()->setItalic(true);

        $sheet->getRowDimension($sigRow1)->setRowHeight(24.0);
        $sheet->getRowDimension($sigRow1 + 1)->setRowHeight(45.75);
        $sheet->getRowDimension($sigRow1 + 2)->setRowHeight(43.35);
        $sheet->getRowDimension($sigRow1 + 3)->setRowHeight(45.0);
        $sheet->getRowDimension($sigRow1 + 4)->setRowHeight(45.0);
        $sheet->getRowDimension($sigRow1 + 5)->setRowHeight(35.1);
        $sheet->getRowDimension($sigRow1 + 6)->setRowHeight(24.0);
        $sheet->getRowDimension($sigRow1 + 7)->setRowHeight(24.0);

        foreach ([$sigRow1 + 1, $sigRow1 + 2, $sigRow1 + 3, $sigRow1 + 4, $sigRow1 + 5] as $row) {
            $sheet->getStyle("A{$row}:I{$row}")->getBorders()->getBottom()->setBorderStyle(Border::BORDER_THIN);
        }
        $sheet->getStyle("A{$lastRow}:I{$lastRow}")->getBorders()->getBottom()->setBorderStyle(Border::BORDER_MEDIUM);
    }

    // ── Frame / layout ────────────────────────────────────────────────────────

    private function thinBorderRow(Worksheet $sheet, int $row): void
    {
        $sheet->getStyle("A{$row}:I{$row}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    }

    // 12: a continuous medium frame down column A's left edge and column
    // I's right edge from row 2 through the very last row — the single
    // biggest visual signature of the template.
    private function applyOuterFrame(Worksheet $sheet, int $lastRow): void
    {
        for ($row = 2; $row <= $lastRow; $row++) {
            $sheet->getStyle("A{$row}")->getBorders()->getLeft()->setBorderStyle(Border::BORDER_MEDIUM);
            $sheet->getStyle("I{$row}")->getBorders()->getRight()->setBorderStyle(Border::BORDER_MEDIUM);
        }
        $sheet->getStyle('A6')->getBorders()->getLeft()->setBorderStyle(Border::BORDER_MEDIUM);
        $sheet->getStyle('I6')->getBorders()->getRight()->setBorderStyle(Border::BORDER_MEDIUM);
    }

    private function applyColumnWidthsAndPageSetup(Worksheet $sheet): void
    {
        $widths = ['A' => 4.33, 'B' => 47.83, 'C' => 26.33, 'D' => 22.5, 'E' => 20.83, 'F' => 73.16, 'G' => 10, 'H' => 16, 'I' => 21.83];
        foreach ($widths as $col => $width) {
            $sheet->getColumnDimension($col)->setWidth($width);
        }

        $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);
        $sheet->getPageSetup()->setPaperSize(PageSetup::PAPERSIZE_A4);
        $sheet->getPageSetup()->setFitToPage(true);
        $sheet->getPageSetup()->setFitToWidth(1);
        $sheet->getPageSetup()->setFitToHeight(1);
        $sheet->getPageMargins()->setLeft(0.7)->setRight(0.7)->setTop(0.75)->setBottom(0.75)->setHeader(0.3)->setFooter(0.3);
    }

    // ── Second sheet: APP DETAILS ────────────────────────────────────────────

    private function writeAppDetailsSheet(Spreadsheet $spreadsheet, PerDiemRequest $plan): void
    {
        $sheet = new Worksheet($spreadsheet, 'APP DETAILS');
        $spreadsheet->addSheet($sheet);

        $row = 1;
        $sheet->setCellValue("A{$row}", 'Ticket links per day');
        $sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(13);
        $row += 2;

        $sheet->fromArray(['Seq', 'Date', 'Site', 'Ticket #'], null, "A{$row}");
        $sheet->getStyle("A{$row}:D{$row}")->getFont()->setBold(true);
        $row++;
        $ticketNumber = $plan->serviceTicket?->ticket_number;
        foreach ($plan->lines as $line) {
            $sheet->fromArray([
                $line->seq_no,
                $line->date?->format('d/m/Y'),
                $line->site_name,
                $ticketNumber,
            ], null, "A{$row}");
            $row++;
        }
        $row += 2;

        if ($plan->revisions->isNotEmpty()) {
            $sheet->setCellValue("A{$row}", 'Revision history');
            $sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(13);
            $row += 2;
            $sheet->fromArray(['When', 'By', 'Role', 'Status', 'Reason'], null, "A{$row}");
            $sheet->getStyle("A{$row}:E{$row}")->getFont()->setBold(true);
            $row++;
            foreach ($plan->revisions as $rev) {
                $sheet->fromArray([
                    $rev->created_at?->timezone('Africa/Dar_es_Salaam')->format('d/m/Y H:i'),
                    $rev->editor?->name,
                    $rev->editor_role,
                    $rev->status,
                    $rev->reason,
                ], null, "A{$row}");
                $row++;
            }
            $row += 2;
        }

        if ($plan->adjustments->isNotEmpty()) {
            $sheet->setCellValue("A{$row}", 'Payment adjustments');
            $sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(13);
            $row += 2;
            $sheet->fromArray(['When', 'By', 'Amount', 'Reason'], null, "A{$row}");
            $sheet->getStyle("A{$row}:D{$row}")->getFont()->setBold(true);
            $row++;
            foreach ($plan->adjustments as $adj) {
                $sheet->fromArray([
                    $adj->created_at?->timezone('Africa/Dar_es_Salaam')->format('d/m/Y H:i'),
                    $adj->createdBy?->name,
                    $adj->amount,
                    $adj->reason,
                ], null, "A{$row}");
                $row++;
            }
            $row += 2;
        }

        if ($plan->status === 'rejected' || $plan->status === 'cancelled') {
            $label = $plan->status === 'rejected' ? 'Rejection reason' : 'Cancellation reason';
            $reason = $plan->status === 'rejected'
                ? ($plan->rejection_reason ?: $plan->team_lead_rejection_reason)
                : $plan->cancellation_reason;
            $sheet->setCellValue("A{$row}", $label);
            $sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(13);
            $row++;
            $sheet->setCellValue("A{$row}", $reason);
        }

        foreach (range('A', 'E') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
    }
}
