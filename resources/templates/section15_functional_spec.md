# HyperMed: Additive Prompt, Section 15
# Travel plan template: in-app view and XLSX export

This is an **addition** to the main change request (Sections 0 to 14). Everything in Section 0 (API first, server-side rules, web and Flutter parity, backward compatibility, audit logging, no card widgets) applies here too.

It **amends**:
- **Section 7** (technician views and PDF download of plans)
- **Section 8** (plan and plan-day data model, CTO editing, revisions)
- **Section 12** (cost categories and per-ticket cost allocation)

## 15.0 Reference file

A real, approved travel plan is provided as the template: `YONAH_LEONARD_TRAVEL_PLAN_TO_KAGERA___KIGOMA_FOR_INSTALLATION_AND_REPAIR_from_21_09_2026_to_02_10_2026.xlsx`.

- Copy it into the API repo (suggested: `resources/templates/travel_plan_template.xlsx`) and use it as the visual and structural reference. Open it and inspect it cell by cell (merged ranges, fills, fonts, number formats, column widths).
- It is also used as the **test fixture** (section 15.9).
- The file contains a real payment account number. **Never copy it into code, seeds, tests, logs or documentation.** Use dummy values in fixtures.

## 15.1 What the template contains

One sheet named **WORKPLAN**, columns A to I.

**Header block (rows 1 to 5)**
- Row 1: "Hypermed Healthcare Ltd" (A1:E1 merged, bold) and "Work Plan" (F1:I1 merged, bold).
- Row 2: "Description of the Trip:" and the trip description text (for example "CBCT installation, commissioning and user training").
- Row 3: "Trip coverage date" and the date range.
- Row 4: "Name" and the staff member's full name.
- Row 5: "Designation" and the staff member's job title.

**Day table (header row 6, gray fill BFBFBF, bold), one row per day from row 7**

| Col | Header | Notes |
|---|---|---|
| A | No. | Running number 1..N |
| B | Date | Long format, for example "Monday, September 21, 2026" (bold) |
| C | Region | For example KAGERA |
| D | District | For example BUKOBA MJINI (blank on some rows) |
| E | Site name | Hospital or facility (blank on pure travel days) |
| F | Activity | Free text: travel legs, installation, calibration, training, repair |
| G | Labor | Amount, `#,##0.00` |
| H | Per diem | Amount, `#,##0.00` |
| I | Transportation fare | Amount, accounting format |

**Totals and summary (below the table)**
- Total row: `Total` in column F, and `=SUM(...)` in G, H and I over all day rows (bold).
- Then: `Number of sites visited`, `Total number of days spent`, `Average number of days / site`, `Avarage Cost Per site` (sic), and `Grand total` (= sum of the three totals), all in column F with values in column I. In the sample, sites and days were typed by hand and the two averages were left blank.

**Payment row:** a gray row with the payment provider label (SELCOM), the account number (C:E merged), and "ACCOUNT NAME: ..." (F:I merged).

**Signature block (bottom)**
- Left side: "Prepared by" (the requester) with Signature and Date; "Reviewed by" with the **Technical supervisor** (Signature and Date); **Finance** (Signature and Date).
- Right side (column F): "Approved by" with the **Managing director** (Signature and Date).

**Column widths** (keep): A 4.3, B 47.8, C 26.3, D 22.5, E 20.8, F 73.2, G 10, H 16, I 21.8.

**Sample data (12 days)**

| Day | Date | Region | District | Site | Activity | Labor | Per diem | Transport |
|---|---|---|---|---|---|---|---|---|
| 1 | 2026-09-21 | Dar es Salaam | Bukoba Mjini | | Travel from HQ to Bukoba | 0 | 80,000 | 105,000 |
| 2 | 2026-09-22 | Kagera | Bukoba Mjini | Bukoba RRH | Installation of CBCT machine | | 80,000 | |
| 3 | 2026-09-23 | Kagera | Bukoba Mjini | Bukoba RRH | Installation and machine calibration | 0 | 80,000 | 0 |
| 4 | 2026-09-24 | Kagera | Bukoba Mjini | Bukoba RRH | User training and practical session | | 80,000 | |
| 5 | 2026-09-25 | Kagera | | Bukoba DH | Travel from Bukoba RRH to Bukoba DH | | 80,000 | 10,000 |
| 6 | 2026-09-26 | Kagera | Bukoba Vijiji | Bukoba DH | Repair of X-ray machine | | 80,000 | |
| 7 | 2026-09-27 | Kigoma | | | Travel from Bukoba to Kigoma | | 80,000 | 50,000 |
| 8 | 2026-09-28 | Kigoma | Kibondo | Kibondo DH | Installation and training of ultrasound machine | | 80,000 | |
| 9 | 2026-09-29 | Kigoma | | | Travel from Kibondo to Kasuru | | 80,000 | 30,000 |
| 10 | 2026-09-30 | Kigoma | Kasuru | Kasuru TH | OPG machine repair | | 80,000 | |
| 11 | 2026-10-01 | Kigoma | | | Travel from Kasuru to Bitale | | 80,000 | 20,000 |
| 12 | 2026-10-02 | Kigoma | Bitale | Bitale HC | Replacement of X-ray fuse and travel from Kigoma to Dar es Salaam | | 50,000 | 70,000 |

Totals in the sample: Labor 0, Per diem 930,000, Transport 285,000, **Grand total 1,215,000**. Sites visited 5, days 12.

## 15.2 Data model changes (amends Section 8)

Plan header (add or confirm):
- `trip_description` (required text, the purpose of the trip; may default from the linked tickets).
- Trip coverage dates derived from the first and last day (do not store separately).
- Staff name and designation are read from the requester's profile at submission and **snapshotted** on the plan (so later profile changes don't alter old plans).
- `payment_method_snapshot`: provider label, account number and account name, copied from the requester's payment profile at submission. Store the payment profile on the staff record (Settings or profile screen), with restricted access (see 15.8).

Plan day (add or confirm; one row per day):
- `date`, `region`, `district`, `site` (hospital reference, nullable), `activity` (text, required), `labor_amount`, `per_diem_amount`, `transport_amount`, `notes`, and optional `ticket_id` (link to a service ticket).
- **Labor is a third cost category** that Section 8 did not include. Default 0. Include it in plan totals, the grand total, the CTO day editor, revisions and adjustments, and feed it to the "labor" category in the Section 12 cost breakdown.
- **Region and District** come from the selected hospital when a site is chosen (hospital records need region and district fields; add and backfill them if missing). For travel days with no site, the user picks a region (and optionally a district) using searchable selects (Section 4), with districts filtered by region. This removes free-text variants such as "KAGERA" versus "Kagera".
- Site uses the searchable hospital picker (server-side search, Section 4).
- Per diem is **not** always the standard amount (the last day of the sample is lower), so each day's amount is individually editable. A default per-diem rate can be configured in Settings and pre-filled.
- Section 12 refinement: costs of a day linked to a ticket are attributed to that ticket (and split across its machines, Section 6). Costs of days without a ticket link (pure travel days) are split equally across the tickets linked to the plan's other days.
- Everything from Sections 8 and 9 still applies: revisions, CTO edit from a chosen day onwards, technician edits only when unlocked, adjustments after money is released, mandatory reasons.

## 15.3 Computed summary (server-side)

Compute these in the API and return them with the plan, so web, Flutter, PDF and XLSX always agree:
- `total_labor`, `total_per_diem`, `total_transport`, `grand_total` (sum of the three).
- `days_spent`: number of distinct dates.
- `sites_visited`: number of **distinct sites** (ignore blank site rows). Sample: 5.
- `avg_days_per_site` = `days_spent / sites_visited` (sample: 2.4). Guard against zero sites (show "-").
- `avg_cost_per_site` = `grand_total / sites_visited` (sample: 243,000). Guard against zero sites.
- Improve on the template: the template left the two averages blank and typed the counts by hand. The app must calculate all four.

## 15.4 In-app view (web and Flutter, replaces the plan detail layout from Section 7)

The plan viewer mirrors the template, in this order:
1. Header block: company name, "Work Plan", trip description, trip coverage dates, name, designation. Show plan status and stage (Section 11) next to it.
2. Day table with the columns No., Date, Region, District, Site name, Activity, Labor, Per diem, Transportation fare. Use the same column order and labels. Date in long format ("Monday, September 21, 2026").
3. Totals row and the summary block (sites visited, days spent, average days per site, average cost per site, grand total).
4. Payment details row (visible only to permitted roles, see 15.8).
5. Signature block (see 15.6).
6. Below: status timeline (Section 11), revision history (Section 8), rejection or cancellation reason (Section 9), and adjustments.

Web: render as a table matching the template. Flutter: the same content, with each day as a card showing the same fields in the same order, and the totals and summary as a section at the bottom (tables become cards, per the parity rule). Days changed by a revision are marked ("Edited by CTO"); edited fields are highlighted.

The CTO and technician day editor (Section 8) uses the same fields and order as the table columns.

## 15.5 XLSX export (generated by the API, same file for web and Flutter)

- New endpoint that returns an `.xlsx` for a plan. Available to the requester, the approvers on that plan, the CTO, finance and admins (see 15.8). Web downloads it through the browser; Flutter saves it to `Downloads/Hypermed` and shows it on the Downloads page (Section 5).
- **Reproduce the template faithfully:** sheet name `WORKPLAN`, same cell layout, merged ranges, gray header fill (BFBFBF), bold styling, fonts, column widths, number formats (`#,##0.00`, accounting format for transport), long date format for the Date column, and page setup suitable for printing (landscape, fit to one page wide).
- Two ways to build it; choose what fits the API stack, but the result must match the template: (a) load the template file and fill it in, inserting or removing rows for the actual number of days, or (b) build it programmatically, copying styles from the template. Either way the number of day rows is variable (the sample has 12; another plan may have 3), and the totals, summary and signature blocks move down accordingly.
- **Live formulas, not hardcoded results:** the Total row uses `SUM` over the day rows for Labor, Per diem and Transport; Grand total = sum of the three totals; Number of sites, days, and the two averages are formulas too where practical (for example `=COUNTA(...)` is not enough for distinct sites, so write the sites and days values from the API but keep the averages and grand total as formulas that reference them). The file must open with no formula errors in Excel and LibreOffice and must recalculate when someone edits an amount.
- Fix template typos in the export: "Avarage" becomes "Average". Keep all other labels exactly as in the template.
- Format the dates consistently (the template mixed `09/21/2026` and `18/09/2026`); use the long format in the table and `dd/mm/yyyy` for the trip coverage text and signature dates.
- **File name:** follow the template's convention: `{STAFF_NAME}_TRAVEL_PLAN_TO_{REGIONS}_FOR_{PURPOSE}_from_{dd_mm_yyyy}_to_{dd_mm_yyyy}.xlsx`, uppercase, spaces replaced with underscores, regions joined in trip order (for example `KAGERA & KIGOMA`, sanitized), and special characters removed. Keep the length reasonable (truncate the purpose if needed).
- **Second sheet, `APP DETAILS`** (so the first sheet stays identical to the finance-approved template): ticket links per day, revision history (who, when, what changed, old and new values, reason), CTO edits marker per day, payment adjustments (Section 8), and the rejection or cancellation reason (Section 9) when it applies. Finance can ignore this sheet or hide it.
- The PDF export from Section 7 renders **the same layout** from the same data, so the PDF and XLSX never disagree.
- A plan that has been edited after money was released exports the current approved values in the main sheet and lists the adjustment on the second sheet.

## 15.6 Signature block

The template shows four sign-off lines: Prepared by (requester), Reviewed by the technical supervisor, Finance, and Approved by the managing director. The approval chain described in Sections 1 and 11 (Team Lead, CTO, Accountant, Director) does not match those four lines exactly. Do not hardcode names or titles.

- Generate the signature block **from the plan's actual approval stages**, in workflow order, showing role title, person name (from the user who acted, or the assigned approver if still pending), and the date.
- Default mapping of the template lines to app roles (adjust in a Settings screen, do not hardcode): Prepared by = requester; Reviewed by (technical supervisor) = Team Lead stage; Finance = Accountant stage; Approved by (managing director) = Director stage. If a CTO stage exists in the workflow, show it as an additional "Reviewed by" line so no real approval is missing from the printout.
- Keep the blank "Signature: ....." lines so the export can still be printed and signed by hand. For stages that were approved in the app, also print "Approved electronically on dd/mm/yyyy hh:mm". Pending stages show the blank line and no date.
- Dates come from actual approval timestamps (Africa/Dar_es_Salaam), never the same typed date on every line.

## 15.7 Payment details row

- Show provider (for example SELCOM), account number and account name, from the snapshot taken at submission (15.2).
- If the requester has no payment profile, block submission with a clear validation message ("Add your payment details to your profile") and provide the profile fields (provider, account number, account name) on web and Flutter.
- Mask the account number in normal in-app views (show the last 4 digits) and show it in full only to the requester, the accountant and finance roles, and in the export for those roles. Never send the full number to roles that don't need it, enforced server-side.

## 15.8 Access and privacy

- Payment details and the export follow Section 1 rules: a technician sees and exports only their own plans; the CTO, finance and admins see all; approvers see plans assigned to them.
- Audit-log every export and every change to a payment profile.
- Do not log account numbers. Exclude them from error reports and analytics.

## 15.9 Tests and acceptance criteria

Use the reference workbook as the fixture (replace the account number with a dummy value).

1. Create the sample plan (12 days, header, dummy payment details) through the API and export it. The `WORKPLAN` sheet matches the template's structure: same merged ranges, header fill, column widths, number formats and cell positions.
2. Totals after recalculation: Labor 0.00, Per diem 930,000.00, Transport 285,000.00, Grand total 1,215,000.00. Sites visited 5, days spent 12, average days per site 2.4, average cost per site 243,000.
3. Export a plan with 3 days and another with 20 days: layout stays correct, formulas still reference the right ranges, no formula errors after recalculation in LibreOffice.
4. A plan with no sites: averages display "-" and nothing divides by zero.
5. In-app viewer on web and Flutter shows the same fields, order, labels and totals as the export.
6. After a CTO edit (Section 8), the viewer, XLSX and PDF show the updated values, the edited days are marked, and the revision appears on `APP DETAILS`.
7. A technician cannot download another technician's plan (403). A non-finance role does not receive the full account number.
8. The file name follows the convention in 15.5.

## 15.10 Assumptions to confirm (used where the user did not decide)

1. **Labor:** treated as an optional per-day amount (default 0), included in totals, and mapped to the labor cost category. Confirm what labor represents in practice (for example a labor charge for installation work).
2. **Signature lines:** default mapping in 15.6 (technical supervisor = Team Lead stage, managing director = Director stage, finance = Accountant stage), with the CTO shown as an extra line if the workflow includes a CTO stage. Confirm the real mapping.
3. **One row per day.** If a day genuinely covers two sites, the user chooses the main site and mentions the second in the activity text.
4. **PDF:** rendered from the same layout as the template (no separate PDF design).
5. **Currency:** TSh, as in the template.
