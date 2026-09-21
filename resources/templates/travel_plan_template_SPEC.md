# Travel Plan Template — Exact Structure Spec

Source file inspected: `resources/templates/travel_plan_template.xlsx`, sheet name **`WORKPLAN`** (single sheet, tab color `FFC00000` dark red).
Inspected programmatically with Python `openpyxl` 3.1.5 (both formula view and cached-value view) plus a raw XML dump of `xl/worksheets/sheet1.xml`. Nothing in the original file was modified.

This reference file happens to have **12 day-rows** (rows 7–18). A real generated plan may need 3, 20, or any other count — every "day row" rule below (formatting, borders, formula ranges) must be applied per-row for however many rows are generated, and the **Total row, blank spacer, summary block, payment row, and signature block must shift down accordingly** (they are NOT fixed at rows 19–33; they are `first_day_row + N` where N = number of day rows). See "Variable row count" section at the end for the exact offsets to use.

> **Security note:** cell `C26` in the reference file contains a real bank account number. It is redacted below as `[REDACTED-ACCOUNT-NUMBER]`. Do not copy the real value from the source file into any shared document — read it directly from the xlsx only when wiring the live "Payment Details" field of the real feature.

---

## 1. Merged cell ranges (as found, 12-day version)

```
A1:E1        F1:I1
A2:B2        C2:I2
A3:B3        C3:I3
A4:B4        C4:I4
A5:B5        C5:I5
C26:E26      F26:I26     (payment row — row 26 = totals-row + 7 in this 12-day file)
C28:D28                  (Date, under "Prepared by")
C30:D30                  (Signature, next to "Technical supervisor")
G30:H30                  (empty merged cell, right side of "Approved by" row)
```
Row 6 (day-table header) and rows 7–18 (day rows) are **not merged** — every A–I cell in those rows is a distinct cell. Row 19 (Total) and rows 21–25 (summary block) and rows 27, 29, 31–33 (rest of signature block) are also not merged.

PhpSpreadsheet:
```php
$sheet->mergeCells('A1:E1');
$sheet->mergeCells('F1:I1');
$sheet->mergeCells('A2:B2'); $sheet->mergeCells('C2:I2');
$sheet->mergeCells('A3:B3'); $sheet->mergeCells('C3:I3');
$sheet->mergeCells('A4:B4'); $sheet->mergeCells('C4:I4');
$sheet->mergeCells('A5:B5'); $sheet->mergeCells('C5:I5');
// payment row + signature block merges — see sections 7-8, offsets shift with day-row count
```

---

## 2. Rows 1–5 header block

| Cell | Text | Font | Bold | Italic | Align |
|---|---|---|---|---|---|
| A1 (merged A1:E1) | `Hypermed Healthcare Ltd` (has heavy trailing whitespace padding in the source — cosmetic, can be trimmed) | Times New Roman 22 | **True** | **True** | left |
| F1 (merged F1:I1) | `Work Plan ` | Times New Roman 22 | **True** | **True** | right |
| A2 (merged A2:B2) | `Description of the Trip:` | Times New Roman 12 | **True** | **True** | vert=center |
| C2 (merged C2:I2) | *(trip description value)* | Times New Roman 12 | **True** | **True** | left, vert=center |
| A3 (merged A3:B3) | `Trip coverage date` | Times New Roman 12 | **True** | **True** | vert=center |
| C3 (merged C3:I3) | *(date range string, e.g. `09/21/2026- 10/2/2026`)* | Times New Roman 12 | **True** | **True** | left, vert=center |
| A4 (merged A4:B4) | `Name` | Times New Roman 12 | **True** | **True** | vert=top |
| C4 (merged C4:I4) | *(staff name value)* | Times New Roman 12 | **True** | **True** | left, vert=top |
| A5 (merged A5:B5) | `Designation` | Times New Roman 12 | **True** | **True** | vert=top |
| C5 (merged C5:I5) | *(designation value)* | Times New Roman 12 | **True** | **True** | left, vert=top |

Note: **every** cell in rows 2–5, both label and value side, is bold **and** italic — not just the labels.

Row heights: row 1 = **37.35**, rows 2–5 use the default (15.75).

Borders: outer box is drawn with `medium` on the left edge of column A and the top of row 2 (start of the info box) and `thin` everywhere else inside; right edge of the merged C:I ranges is `thin` (column I's own right border), not `medium` — the heavier right-hand `medium` frame only starts at the day table (row 6 onward).

---

## 3. Row 6 — day-table column headers

| Col | Header text |
|---|---|
| A | `No.` |
| B | `Date` |
| C | `Region` |
| D | `District` |
| E | `Site name` |
| F | `Activity` |
| G | `Labor` |
| H | `Per diem` |
| I | `Transportation fare` |

- Fill: solid, `FFBFBFBF` (gray) on **every** A6:I6 cell.
- Font: Times New Roman 12, **bold=True, italic=True** on every header cell.
- Alignment: horizontal=left, vertical=top, wrap_text=True.
- Row height: **45.6**.
- Borders: `thin` all around each cell except the box edges — A6 left border = `medium`, I6 right border = `medium` (these are the outer frame of the whole day table, which continues down through the last day row and the Total row).
- Number format on the header cells themselves is cosmetically inherited from copy/paste (`B6`/`C6` show a date format, `I6` shows the accounting format) but since the values are plain header text this has no visual effect — **do not bother replicating it**; set `'General'` on header text cells.

```php
foreach (['A','B','C','D','E','F','G','H','I'] as $col) {
    $sheet->getStyle("{$col}6")->getFont()->setName('Times New Roman')->setSize(12)->setBold(true)->setItalic(true);
    $sheet->getStyle("{$col}6")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('BFBFBF');
}
$sheet->getRowDimension(6)->setRowHeight(45.6);
```

---

## 4. Day rows (7 onward) — per-column formatting

| Col | Content | Number format | Font | Align (H/V) |
|---|---|---|---|---|
| A | Row sequence No. (1, 2, 3…) | `General` | TNR 12, **not bold**, not italic | left / center |
| B | **Date** | `[$-F800]dddd\,\ mmmm\ dd\,\ yyyy` (long-date, e.g. "Monday, September 21, 2026") | TNR 12, **bold=True, italic=True** | left / center |
| C | Region | `General` | TNR 12, not bold, not italic | left / center |
| D | District | `General` | TNR 12, not bold, not italic | left / center |
| E | Site name | `General` | TNR 12, not bold, not italic | left / center |
| F | Activity | `General` | TNR 12, not bold, not italic | left / center, wrap_text=True |
| G | Labor | `#,##0.00` | TNR 12, not bold, not italic | right / center |
| H | Per diem | `#,##0.00` | TNR 12, not bold, not italic | right / center |
| I | Transportation fare | `_(* #,##0.00_);_(* \(#,##0.00\);_(* "-"??_);_(@_)` (**accounting** format) | TNR 12, not bold, not italic | right / center |

Confirms your notes: **column I (Transportation fare) is the only column with a true accounting number format**; G and H (Labor, Per diem) use plain `#,##0.00`. **Column B (Date) is both bold and italic**, every other data column in the day rows is plain (not bold, not italic).

Quirk found (harmless, don't replicate): several C/F cells in the source inherited a stray date-format number code from copy-paste even though they hold plain text (region/activity names). Since text ignores number-format codes visually, this has zero effect — but when generating programmatically, explicitly set `'General'` on these text columns rather than copying the leftover code.

Row heights in the reference (12-day) file: row 7 = 26.25, rows 8–17 = 21.75 (uniform), row 18 (last day row, longer wrapped text) = 48.75. **For a variable row count, just set a uniform row height (≈21.75–24) per day row** and let `wrap_text` auto-grow visually; don't try to reproduce the exact per-row variation, it's an artifact of this file's specific text lengths.

Borders per day row: `thin` on all inner edges; **column A's left edge = `medium`** and **column I's right edge = `medium`** (outer frame of the table, continues down to the Total row). No merges in the day rows.

```php
for ($r = $firstDayRow; $r <= $lastDayRow; $r++) {
    $sheet->getStyle("A{$r}")->getFont()->setBold(false)->setItalic(false);
    $sheet->getStyle("B{$r}")->getFont()->setBold(true)->setItalic(true);
    $sheet->getStyle("B{$r}")->getNumberFormat()->setFormatCode('[$-F800]dddd\,\ mmmm\ dd\,\ yyyy');
    $sheet->getStyle("G{$r}")->getNumberFormat()->setFormatCode('#,##0.00');
    $sheet->getStyle("H{$r}")->getNumberFormat()->setFormatCode('#,##0.00');
    $sheet->getStyle("I{$r}")->getNumberFormat()->setFormatCode('_(* #,##0.00_);_(* \(#,##0.00\);_(* "-"??_);_(@_)');
    $sheet->getStyle("A{$r}:I{$r}")->getFont()->setName('Times New Roman')->setSize(12);
}
```

---

## 5. Totals row (row 19 in the 12-day file = `firstDayRow + N`)

| Cell | Content |
|---|---|
| F19 | `Total` (label, bold, not italic) |
| G19 | `=SUM(G7:G18)` — **live formula**, range = the actual day-row block |
| H19 | `=SUM(H7:H18)` — **live formula** |
| I19 | `=SUM(I7:I18)` — **live formula** |

- These are genuine Excel formulas (`ws2` cached-value read confirms: formula string `=SUM(G7:G18)` with a separately-stored cached numeric result — this is a real `SUM`, not a hand-typed number).
- Number format on G19/H19/I19 is plain **`#,##0.00`** — note this is *not* the accounting format even though I-column day cells above use accounting format. Keep it consistent with the reference (plain `#,##0.00` for all three totals cells).
- Font: bold=True, italic=False on F19/G19/H19/I19.
- Row height: 23.25.
- Formula ranges must be generated dynamically: `=SUM(G{firstDayRow}:G{lastDayRow})` etc.

```php
$sheet->setCellValue("F{$totalRow}", 'Total');
$sheet->setCellValue("G{$totalRow}", "=SUM(G{$firstDayRow}:G{$lastDayRow})");
$sheet->setCellValue("H{$totalRow}", "=SUM(H{$firstDayRow}:H{$lastDayRow})");
$sheet->setCellValue("I{$totalRow}", "=SUM(I{$firstDayRow}:I{$lastDayRow})");
$sheet->getStyle("F{$totalRow}:I{$totalRow}")->getFont()->setBold(true);
$sheet->getStyle("G{$totalRow}:I{$totalRow}")->getNumberFormat()->setFormatCode('#,##0.00');
```

There is one fully blank spacer row after Total (row 20 in the reference) before the summary block starts.

---

## 6. Summary block (rows 21–25 in the reference; label in column F, value in column I)

| Row | Label (col F) | Value (col I) | Value type |
|---|---|---|---|
| 21 | `Number of sites visited` | `5` | **static hand-typed number**, not a formula |
| 22 | `Total number of days spent` | `12` | **static hand-typed number**, not a formula |
| 23 | `Average number of days / site` | *(empty — no value at all)* | blank in the reference file, despite having the accounting number format pre-applied |
| 24 | `Avarage Cost Per site` *(sic — confirmed misspelled exactly this way in the file)* | *(empty — no value at all)* | blank, format = `General` |
| 25 | `Grand total` | `=SUM(G19:I19)` | **live formula** (sums the Total row across Labor+Per diem+Transportation) |

Important findings to flag for the real feature:
- **"Number of sites visited" and "Total number of days spent" are static numbers typed by the preparer, not formulas.** If the real feature wants these live, they'd need to be computed and written as values (or as formulas like `=COUNTA(...)` / day-row count) — the reference template does **not** demonstrate a formula for these two.
- **"Average number of days / site" and "Avarage Cost Per site" are genuinely blank** in this approved reference — the preparer never filled them in even though the row/format exists. Don't treat their absence as a bug to "fix" by inventing values; either leave them blank (matching real-world usage) or compute them explicitly if the new feature requires it (e.g. `=I22/I21` and `=I19/I21` respectively).
- Only **Grand total (I25)** carries a real formula: `=SUM(G{totalRow}:I{totalRow})`.
- The typo **"Avarage Cost Per site"** (missing the second "e" in Average) is real, present in the approved template — reproduce it verbatim unless the business wants it corrected.
- All 5 labels: font Times New Roman 12, bold=True, italic=False, in column F, left-aligned, vertical=top, `thin` borders around each row. Value cells in column I are right-aligned, bold=True, with `medium` right border (frame edge). G/H in these rows are empty/unused.

```php
$sheet->setCellValue("F{$totalRow+2}", 'Number of sites visited');
$sheet->setCellValue("I{$totalRow+2}", $siteCount); // static value, matches reference behavior
$sheet->setCellValue("F{$totalRow+3}", 'Total number of days spent');
$sheet->setCellValue("I{$totalRow+3}", $dayCount);  // static value
$sheet->setCellValue("F{$totalRow+4}", 'Average number of days / site'); // leave I blank, or compute if required
$sheet->setCellValue("F{$totalRow+5}", 'Avarage Cost Per site'); // keep typo; leave I blank, or compute if required
$sheet->setCellValue("F{$totalRow+6}", 'Grand total');
$sheet->setCellValue("I{$totalRow+6}", "=SUM(G{$totalRow}:I{$totalRow})");
```

---

## 7. Payment row (row 26 in the reference = summary-block-end + 1, no blank spacer before it)

| Cell | Content | Notes |
|---|---|---|
| A26 | *(empty)*, filled gray, bold | just a styled spacer cell — **not merged** with B26 despite visual continuity |
| B26 (not merged) | `SELCOM ` | bold, center-aligned |
| C26 (merged **C26:E26**) | `[REDACTED-ACCOUNT-NUMBER]` — 13-digit string, number format `@` (text), bold, center-aligned | this is the real payment/account number field — treat as a plain text string, not a number, so leading structure/format is preserved |
| F26 (merged **F26:I26**) | `ACCOUNT NAME: YONAH LEONARD NYANDA` | bold, left-aligned |

- Fill: solid gray `FFBFBFBF` across the **entire row A26:I26** (same gray as the row-6 header).
- Borders: `medium` top and bottom across the whole row (heavier frame separating payment info from the rest), `medium` on A26's left edge and I26's right edge (outer box), `thin` internal separators.
- Row height: 16.5.

```php
$sheet->mergeCells("C{$payRow}:E{$payRow}");
$sheet->mergeCells("F{$payRow}:I{$payRow}");
$sheet->setCellValue("B{$payRow}", 'SELCOM');
$sheet->getStyle("B{$payRow}")->getNumberFormat()->setFormatCode('General');
$sheet->setCellValueExplicit("C{$payRow}", $accountNumber, DataType::TYPE_STRING);
$sheet->getStyle("C{$payRow}")->getNumberFormat()->setFormatCode('@');
$sheet->setCellValue("F{$payRow}", 'ACCOUNT NAME: ' . $accountHolderName);
$sheet->getStyle("A{$payRow}:I{$payRow}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('BFBFBF');
$sheet->getStyle("A{$payRow}:I{$payRow}")->getFont()->setBold(true);
```

---

## 8. Signature block (rows 27–33 in the reference; irregular, not a clean symmetric grid)

The layout is **not** a tidy 4-row-by-2-column grid — left (col B-ish) and right (col F-ish) blocks are offset by different amounts. Reproduce exactly as laid out below:

| Row | Col B (left) | Col C/D/E (left, sub-fields) | Col F (right) | Col G/H (right, sub-fields) |
|---|---|---|---|---|
| 27 | `Prepared by: {name}` | — | *(empty)* | — |
| 28 | `Signature: ….............................................` | C28 (merged C28:D28) = `Date: {date}` *(bold+italic)* | *(empty)* | — |
| 29 | `Reviewed by :` | — | *(empty)* | — |
| 30 | `Technical supervisor: {name}` | C30 (merged C30:D30) = `Signature:…........................`; E30 (standalone) = `Date:{date}` | `Approved by:  {managing director name} ` | G30:H30 merged, empty |
| 31 | `Finance: {name}` | — | `Signature:` | — |
| 32 | `Signature: ` | — | `Managing director` | — |
| 33 | `Date:{date}` | — | `Date:{date}` | — |

- All text: Times New Roman 12, **bold=True** (C28's "Date:" value is additionally italic=True; everything else in this block is bold-only, not italic).
- Borders: thin horizontal rules under rows 28, 29, 30, 31; a `medium` bottom border closes the whole block at row 33; `medium` left border on column A and `medium` right border on column I run down the entire block (continuing the outer table frame from the day table through to the very last row).
- Row heights: 27=24.0, 28=45.75, 29=43.35, 30=45.0, 31=35.1, 32=24.0, 33=24.0.
- Date values in this block are static hand-typed strings (e.g. `Date: 18/09/2026`), not formulas or actual Excel date cells — write them as plain text.

```php
$r = $sigStartRow; // = payRow + 1
$sheet->setCellValue("B{$r}", "Prepared by: {$preparedByName}");
$r++;
$sheet->setCellValue("B{$r}", 'Signature: ….............................................');
$sheet->mergeCells("C{$r}:D{$r}");
$sheet->setCellValue("C{$r}", "Date: {$preparedDate}");
$sheet->getStyle("C{$r}")->getFont()->setItalic(true);
$r++;
$sheet->setCellValue("B{$r}", 'Reviewed by :');
$r++;
$sheet->setCellValue("B{$r}", "Technical supervisor: {$reviewerName}");
$sheet->mergeCells("C{$r}:D{$r}");
$sheet->setCellValue("C{$r}", 'Signature:…........................');
$sheet->setCellValue("E{$r}", "Date:{$reviewDate}");
$sheet->setCellValue("F{$r}", "Approved by:  {$mdName} ");
$sheet->mergeCells("G{$r}:H{$r}");
$r++;
$sheet->setCellValue("B{$r}", "Finance: {$financeName}");
$sheet->setCellValue("F{$r}", 'Signature:');
$r++;
$sheet->setCellValue("B{$r}", 'Signature: ');
$sheet->setCellValue("F{$r}", 'Managing director');
$r++;
$sheet->setCellValue("B{$r}", "Date:{$mdDate}");
$sheet->setCellValue("F{$r}", "Date:{$mdDate}");
```

---

## 9. Column widths (confirmed against your notes — matches within rounding)

| Col | Width (openpyxl raw) | Your note |
|---|---|---|
| A | 4.33203125 | 4.3 ✓ |
| B | 47.83203125 | 47.8 ✓ |
| C | 26.33203125 | 26.3 ✓ |
| D | 22.5 | 22.5 ✓ |
| E | 20.83203125 | 20.8 ✓ |
| F | 73.1640625 | 73.2 ✓ |
| G | 10.0 | 10 ✓ |
| H | 16.0 | 16 ✓ |
| I | 21.83203125 | 21.8 ✓ |

(Columns J/K have leftover widths of 17.16 and 8.0 defined but are unused/blank — safe to ignore.)

```php
$sheet->getColumnDimension('A')->setWidth(4.33);
$sheet->getColumnDimension('B')->setWidth(47.83);
$sheet->getColumnDimension('C')->setWidth(26.33);
$sheet->getColumnDimension('D')->setWidth(22.5);
$sheet->getColumnDimension('E')->setWidth(20.83);
$sheet->getColumnDimension('F')->setWidth(73.16);
$sheet->getColumnDimension('G')->setWidth(10);
$sheet->getColumnDimension('H')->setWidth(16);
$sheet->getColumnDimension('I')->setWidth(21.83);
```

---

## 10. Page setup

- Orientation: **landscape**.
- Paper size: 9 (A4).
- `fitToPage = true` (set via `sheetPr/pageSetUpPr fitToPage="1"` in the raw XML) — this makes Excel fit to **1 page wide × 1 page tall** by default (fitToWidth/fitToHeight not explicitly overridden, so they default to 1 each).
- A `scale="59"` attribute is also stored on `<pageSetup>`, but it is **ignored for printing** while `fitToPage` is on (Excel keeps it only as the last manual-scale value in case the user toggles fit-to-page off). Don't rely on scale=59 to control layout — set fit-to-page instead.
- Print area: none explicitly defined (whole used range prints).
- Margins: left/right 0.7", top/bottom 0.75", header/footer 0.3".
- Sheet tab color: `FFC00000` (dark red) — cosmetic, optional to replicate.
- Zoom (on-screen view only, doesn't affect print): 71%.

```php
$sheet->getPageSetup()->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE);
$sheet->getPageSetup()->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4);
$sheet->getPageSetup()->setFitToPage(true);
$sheet->getPageSetup()->setFitToWidth(1);
$sheet->getPageSetup()->setFitToHeight(1);
$sheet->getPageMargins()->setLeft(0.7)->setRight(0.7)->setTop(0.75)->setBottom(0.75)->setHeader(0.3)->setFooter(0.3);
$sheet->getSheetView()->setZoomScale(71);
```

---

## 11. Fonts used

Only one font family in the whole file: **Times New Roman**.

| Use | Size | Bold | Italic |
|---|---|---|---|
| Title (A1/F1) | 22 | True | True |
| Header-block labels/values (rows 2–5) | 12 | True | True |
| Day-table column headers (row 6) | 12 | True | True |
| Day-row data, column A (No.) | 12 | False | False |
| Day-row data, column B (Date) | 12 | **True** | **True** |
| Day-row data, columns C–I | 12 | False | False |
| Total row labels/values | 12 | True | False |
| Summary block labels/values | 12 | True | False |
| Payment row | 12 | True | False |
| Signature block (all) | 12 | True | False (except C28's "Date:" value, which is also italic) |

Font color is default automatic black (`FF000000`) throughout; no colored text anywhere.

---

## 12. Other structural details

- **Freeze panes:** none (`ws.freeze_panes` is `None`).
- **Gridlines:** `showGridLines` not explicitly set (Excel default = shown on-screen; irrelevant to the exported/printed file since a fit-to-page print doesn't show gridlines unless `printGridlines` is set, which it isn't).
- **Conditional formatting / data validation:** none found.
- **Embedded images:** none (`ws._images` is empty — no logo embedded in the sheet itself).
- **Defined names:** none.
- **Default row height:** 15.75; **default column width:** 8.0 (both standard Excel defaults, only overridden per the widths/heights listed above).
- **Outer table frame:** a `medium`-weight border box runs continuously down column A's left edge and column I's right edge from row 2 (top of the info block) all the way through row 33 (bottom of the signature block) — this is the single biggest visual signature of the template; make sure the day-row loop, totals row, summary block, payment row, and signature block **all** keep `medium` on A's left / I's right edge for a continuous frame, with only `thin`/`medium` horizontal dividers changing between sections.
- **Row-19 vs row-25 vs row-26 borders:** the Total row (19) has a `medium`-adjacent frame continuing from the day table; the Grand total row (25) has a `medium` **bottom** border under F25 to visually close the summary block before the gray payment bar starts.

---

## Variable row count — how to parameterize

Let `N` = number of day rows requested (reference file used N=12). Using 1-indexed rows with day rows starting at row 7 (unchanged — rows 1–6 are always fixed):

```
firstDayRow  = 7
lastDayRow   = 7 + N - 1
totalRow     = lastDayRow + 1             // "Total" row, SUM(firstDayRow:lastDayRow)
blankRow     = totalRow + 1               // one empty spacer row (no styling needed beyond default)
summaryStart = totalRow + 2               // "Number of sites visited"
// summaryStart+0 = Number of sites visited
// summaryStart+1 = Total number of days spent
// summaryStart+2 = Average number of days / site
// summaryStart+3 = Avarage Cost Per site
// summaryStart+4 = Grand total  → I cell = SUM(G{totalRow}:I{totalRow})
payRow       = summaryStart + 5           // SELCOM / account row, immediately after Grand total, no blank spacer
sigRow1      = payRow + 1                 // Prepared by
// sigRow1+1 = Signature/Date (Prepared by)
// sigRow1+2 = Reviewed by
// sigRow1+3 = Technical supervisor / Signature / Date  +  Approved by (right side)
// sigRow1+4 = Finance  +  Signature (right side)
// sigRow1+5 = Signature  +  Managing director (right side)
// sigRow1+6 = Date (left)  +  Date (right)  — last row, medium bottom border closes the sheet
```

All per-day-row styling (borders, fonts, number formats from Section 4) must be applied in a loop from `firstDayRow` to `lastDayRow`; the SUM formulas in the Total row and Grand total must reference `firstDayRow`/`lastDayRow`/`totalRow` dynamically rather than the hardcoded `7:18`/`19` seen in this specific reference file.
