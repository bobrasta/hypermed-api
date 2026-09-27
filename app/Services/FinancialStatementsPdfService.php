<?php

namespace App\Services;

use Mpdf\HTMLParserMode;
use Mpdf\Mpdf;

/**
 * Renders FinancialStatementsService's data as the annual financial
 * statements PDF — a port of the standalone mPDF template
 * (me/system/financial_statements.php): cover, contents, head-of-finance
 * declaration, directors' report, auditor's report, the four primary
 * statements, notes, asset schedule, back cover, with the Hypermed navy
 * band / BarlowCondensed / "+" rule design.
 *
 * Differences from the template:
 *  - A draft (the default) has no auditor's report pages and says
 *    "DRAFT · UNAUDITED" on the cover and every footer. The auditor's
 *    report is only included when the caller marks the statements audited,
 *    and even then carries no auditor logo, signature or stamp — those come
 *    from the auditor's own signed report.
 *  - Page references (contents, "set out on pages x to y") are real: the
 *    document is rendered once section by section to record where each
 *    section starts, then rendered again with those numbers.
 *
 * mPDF constraints (same as the template / invoice PDF):
 *  - No CSS variables / flex / grid — every layout is a table, every color
 *    a literal hex; statement lines use flat classes on each <td>.
 *  - Named @page selectors: 'cover' / 'back' (no header/footer), 'front'
 *    (roman i, ii), 'body' (1, 2, …). The navy strip on inner pages is a
 *    stretched @page background (fs_topbar.png); the cover watermark is a
 *    baked full-page background (fs_cover_bg.png) since mPDF ignores opacity.
 *  - Don't add 'size:' to @page — mPDF then lays pages out at near-zero
 *    width. Size comes from the constructor's 'format'.
 */
class FinancialStatementsPdfService
{
    private const SECTIONS = [
        'contents'    => 'Contents',
        'declaration' => 'Declaration',
        'directors'   => "Directors' report",
        'sofp'        => 'Financial position',
        'soci'        => 'Comprehensive income',
        'cashflow'    => 'Cash flows',
        'equity'      => 'Changes in equity',
        'policies'    => 'Accounting policies',
        'notes'       => "Notes cont'd",
        'assets'      => 'Asset schedule',
    ];

    private string $logos;
    private array $d;
    /** Body page number each section starts/ends on, from the first pass. */
    private array $pages = [];

    public function render(array $d): string
    {
        $this->d = $d;
        $this->logos = resource_path('pdf-assets/logos');

        // Pass 1 records section pages; pass 2 prints them.
        $this->pages = $this->write()[1];

        return $this->write()[0]->Output('', \Mpdf\Output\Destination::STRING_RETURN);
    }

    /** @return array{0: Mpdf, 1: array} */
    private function write(): array
    {
        $mpdf = $this->mpdf();
        $mpdf->SetTitle($this->d['company'] . ' — Financial Statements ' . $this->d['year']);
        $mpdf->WriteHTML($this->css(), HTMLParserMode::HEADER_CSS);

        $pages = [];
        foreach ($this->chunks() as [$key, $html]) {
            $before = $mpdf->page;
            $mpdf->WriteHTML($html, HTMLParserMode::HTML_BODY);
            // Front matter is cover + 2 roman pages, so body page = physical − 3.
            $pages[$key][0] ??= $before + 1 - 3;
            $pages[$key][1] = $mpdf->page - 3;
        }

        return [$mpdf, $pages];
    }

    private function page(string $key, int $end = 0): int
    {
        return $this->pages[$key][$end] ?? 0;
    }

    private function range(string $key): string
    {
        [$a, $b] = [$this->page($key), $this->page($key, 1)];

        return $a === $b ? (string) $a : "{$a} - {$b}";
    }

    private function lastNotePage(): int
    {
        return $this->page('assets', 1);
    }

    // ------------------------------------------------------------ formatting

    private static function e($v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES);
    }

    /** Escaped multi-line text. */
    private static function t($v): string
    {
        return nl2br(self::e($v));
    }

    /** Accounting format: brackets for negatives, "-" for zero, blank for null. */
    private static function f($n, int $dec = 0): string
    {
        if ($n === null || $n === '') {
            return '';
        }
        if (round($n, $dec) == 0) {
            return '-';
        }
        $str = number_format(abs($n), $dec);

        return $n < 0 ? "({$str})" : $str;
    }

    /** label | note | current year | prior year. Flags: b i ind ul tot dbl. */
    private function row(string $label, $cy, $py, string $cls = '', $note = ''): string
    {
        $flags = array_filter(explode(' ', $cls));
        $lbl = 'lbl' . (in_array('ind', $flags) ? ' ind' : '') . (in_array('b', $flags) ? ' b' : '') . (in_array('i', $flags) ? ' i' : '');
        $num = 'num' . implode('', array_map(fn ($x) => in_array($x, $flags) ? " {$x}" : '', ['b', 'i', 'ul', 'tot', 'dbl']));

        return "<tr><td class='{$lbl}'>{$label}</td><td class='note'>" . self::e($note) . '</td>'
            . "<td class='{$num}'>" . self::f($cy) . "</td><td class='{$num}'>" . self::f($py) . '</td></tr>';
    }

    /** Rows from [label, cy, py, flags?]; the last gets a line under it when $ulLast. */
    private function rows(array $items, bool $ulLast = false): string
    {
        $out = '';
        foreach (array_values($items) as $k => $it) {
            $cls = trim(($it[3] ?? '') . ($ulLast && $k === count($items) - 1 ? ' ul' : ''));
            $out .= $this->row(self::e($it[0]), $it[1], $it[2], $cls);
        }

        return $out ?: $this->row('<i>None</i>', 0, 0);
    }

    /** A statement line shown only when non-zero in either year. */
    private function optRow(string $label, array $v, string $cls = '', $note = ''): string
    {
        return ($v[0] || $v[1]) ? $this->row($label, $v[0], $v[1], $cls, $note) : '';
    }

    private function noteHead($no, string $title): string
    {
        return "<tr><td class='lbl nh' colspan='4'>{$no}&nbsp;&nbsp;&nbsp;{$title}</td></tr>";
    }

    private function gap(int $h = 5): string
    {
        return "<tr><td colspan='4' style='height:{$h}mm'></td></tr>";
    }

    private function yearHead(bool $notes = true): string
    {
        $d = $this->d;

        return "<tr><td class='lbl'></td><td class='note b'>" . ($notes ? 'Notes' : '') . '</td>'
            . "<td class='num b' style='text-align:center'>{$d['year']}<br>{$d['currency']}</td>"
            . "<td class='num b' style='text-align:center'>{$d['prior_year']}<br>{$d['currency']}</td></tr>";
    }

    /** Page break that switches the running header to the given section. */
    private function brk(string $key, string $attrs = ''): string
    {
        $hdr = isset(self::SECTIONS[$key])
            ? "<sethtmlpageheader name='h_{$key}' value='on' show-this-page='1' />"
            : "<sethtmlpageheader name='' value='off' show-this-page='1' />";

        return "<pagebreak {$attrs} />{$hdr}";
    }

    /** Full-width hairline with the design's "+" marks at both ends. */
    private function plusRule(): string
    {
        $plus = 'font-family:barlowcondensed,tildasans,sans-serif; font-size:9pt; line-height:1; color:#597ea3; width:3mm; padding:0; vertical-align:middle';

        return "<table style='width:100%; border-collapse:collapse'>"
            . "<tr><td rowspan='2' style='{$plus}; text-align:left'>+</td>"
            . "<td style='width:174mm; height:1.6mm; padding:0; border-bottom:0.26mm solid #1d1f20; font-size:1px; line-height:1px'>&nbsp;</td>"
            . "<td rowspan='2' style='{$plus}; text-align:right'>+</td></tr>"
            . "<tr><td style='height:1.6mm; padding:0; font-size:1px; line-height:1px'></td></tr></table>";
    }

    private function statusTag(): string
    {
        return $this->d['audited'] ? "FY {$this->d['year']} &middot; AUDITED" : "FY {$this->d['year']} &middot; DRAFT &middot; UNAUDITED";
    }

    private function pageHeader(string $key): string
    {
        $d = $this->d;
        $bc = 'font-family:barlowcondensed,tildasans,sans-serif; font-weight:bold';

        return "<htmlpageheader name='h_{$key}'>"
            . "<table style='width:100%; border-collapse:collapse'><tr>"
            . "<td style='width:14mm; padding:0 0 3.5mm 0; vertical-align:middle'><img src='{$this->logos}/hypermed_icon.png' style='height:10.5mm; width:auto' /></td>"
            . "<td style='padding:0 0 3.5mm 0; vertical-align:middle'>"
            . "<div style='{$bc}; font-size:16.5pt; line-height:1; letter-spacing:0.1mm; color:#1d1f20'>" . self::e(strtoupper($d['company'])) . '</div>'
            . "<div style='{$bc}; font-size:8pt; letter-spacing:0.4mm; color:#416180; padding-top:1.3mm'>FINANCIAL STATEMENTS FOR THE YEAR ENDED " . self::e(strtoupper($d['period_text'])) . '</div></td>'
            . "<td style='width:42mm; padding:0 0 3.5mm 4mm; border-left:0.26mm solid #cccccc; text-align:right; vertical-align:middle'>"
            . "<div style='{$bc}; font-size:7.5pt; letter-spacing:0.4mm; color:#416180'>SECTION</div>"
            . "<div style='{$bc}; font-size:10.5pt; letter-spacing:0.1mm; color:#1d1f20; padding-top:0.8mm; white-space:nowrap'>" . self::e(strtoupper(self::SECTIONS[$key])) . '</div></td>'
            . '</tr></table>' . $this->plusRule() . '</htmlpageheader>';
    }

    private function pageFooter(): string
    {
        $d = $this->d;
        $bc = 'font-family:barlowcondensed,tildasans,sans-serif; font-weight:bold';
        $who = $d['audited'] ? 'Audited by ' . self::e($d['auditor']['cpa_line']) : 'Prepared from the company ledger &middot; subject to audit';

        return "<htmlpagefooter name='foot'>" . $this->plusRule()
            . "<table style='width:100%; border-collapse:collapse; font-family:tildasans,dejavusans,sans-serif; font-size:8pt; line-height:1.45; color:#5d5d60'><tr>"
            . "<td style='width:42%; padding:2.6mm 0 0 0; vertical-align:middle'>"
            . "<div style='{$bc}; font-size:8.5pt; letter-spacing:0.15mm; color:#1d1f20'>" . self::e(strtoupper($d['company'])) . '</div>'
            . "<div>{$who}</div></td>"
            . "<td style='padding:0'></td>"
            . "<td style='width:13mm; border:0.26mm solid #1d1f20; padding:1.3mm 0; text-align:center; vertical-align:middle; {$bc}; font-size:11pt; line-height:1.1; color:#1d1f20'>{PAGENO}</td>"
            . "<td style='padding:0'></td>"
            . "<td style='width:42%; padding:2.6mm 0 0 0; vertical-align:middle; text-align:right'>"
            . "<div style='{$bc}; font-size:7.5pt; letter-spacing:0.4mm; color:" . ($d['audited'] ? '#416180' : '#b45309') . "'>" . $this->statusTag() . '</div>'
            . '<div>The notes form an integral part of these statements</div></td>'
            . '</tr></table></htmlpagefooter>';
    }

    /** Numbered section of the directors' report / end notes. */
    private function sec($no, string $title, string $body, int $top = 0): string
    {
        return "<table class='sec' style='margin-top:{$top}mm'><tr><td class='secno'>{$no}</td><td class='secbody'>"
            . ($title !== '' ? "<div class='sech'>{$title}</div>" : '') . $body . '</td></tr></table>';
    }

    private function signBlock(string $who = 'Managing Director'): string
    {
        return "<table class='sign'><tr>"
            . "<td style='width:55%'><div class='dots'>………………………………………</div><div class='b'>{$who}</div></td>"
            . "<td style='width:45%'><div class='hand'>" . self::e($this->d['sign_date']) . '</div>'
            . "<div class='dots'>…........................</div><div class='b'>Date</div></td>"
            . '</tr></table>';
    }

    // ------------------------------------------------------------------ body

    /** @return array<int, array{0: string, 1: string}> [section key, html] */
    private function chunks(): array
    {
        $d = $this->d;
        $au = $d['auditor'];
        $s = $d['sofp'];
        $p = $d['soci'];
        $cf = $d['cashflow'];
        $c = $d['cos'];
        $t = $d['tax'];
        $yeText = self::e($d['year_end_text']);
        $yeShort = self::e(str_replace(',', '', $d['year_end_text']));
        $chunks = [];

        // ---- Cover ----
        $h = $this->pageFooter();
        foreach (array_keys(self::SECTIONS) as $key) {
            $h .= $this->pageHeader($key);
        }
        $icon = "{$this->logos}/hypermed_icon.png";
        $bc = 'font-family:barlowcondensed,tildasans,sans-serif; font-weight:bold';
        $h .= "<div style='page: cover'>";
        $h .= "<table style='width:100%; background-color:#1d2d3d'><tr>"
            . "<td style='height:26mm; padding:0 0 0 15mm; width:20mm; vertical-align:middle'><img src='{$icon}' style='height:15.3mm; width:auto' /></td>"
            . "<td style='vertical-align:middle; padding-left:4mm; {$bc}; font-size:18pt; letter-spacing:0.15mm; color:#ffffff'>" . self::e(strtoupper($d['company_short'])) . '</td>'
            . "<td style='vertical-align:middle; padding-right:15mm; text-align:right; {$bc}; font-size:8.25pt; letter-spacing:0.45mm; color:#9fb3c8'>FINANCIAL YEAR {$d['year']}</td>"
            . '</tr></table>';
        $h .= "<div style='padding:25mm 15mm 0 15mm'>";
        $h .= "<div class='cvlbl'>ANNUAL REPORT &amp; ACCOUNTS" . ($d['audited'] ? '' : ' &middot; DRAFT') . '</div>';
        $h .= "<div class='cvtitle' style='margin-top:4.8mm'>" . ($d['audited'] ? 'AUDITED' : 'DRAFT') . '<br>FINANCIAL<br>STATEMENTS</div>';
        // accent dash: the border between two half-height rows (see plusRule)
        $h .= "<table style='margin-top:7.4mm; border-collapse:collapse'>"
            . "<tr><td style='width:10.6mm; height:2.7mm; padding:0; border-bottom:0.53mm solid #597ea3; font-size:1px; line-height:1px'></td>"
            . "<td rowspan='2' class='cvsub' style='padding:0 0 0 3.7mm; vertical-align:middle'>FOR THE YEAR ENDED " . self::e(strtoupper($d['period_text'])) . '</td></tr>'
            . "<tr><td style='height:2.7mm; padding:0; font-size:1px; line-height:1px'></td></tr></table>";
        $h .= "<table style='margin-top:22mm'><tr><td style='border:0.26mm solid #1d1f20; padding:5.8mm 6.9mm'>"
            . "<div class='cvlbl'>REPORTING ENTITY</div>"
            . "<div class='cventity' style='margin-top:2.1mm'>" . self::e(strtoupper($d['company'])) . '</div>'
            . "<div style='margin-top:3.2mm; font-size:10pt; line-height:1.55; color:#1d1f20'>" . implode('<br>', array_map([self::class, 'e'], $d['cover_address'])) . '</div>'
            . '</td></tr></table>';
        $h .= '</div></div>';
        // pinned to the bottom (mPDF only honours position:fixed at top level)
        $left = $d['audited']
            ? "<div class='cvlbl'>INDEPENDENT AUDITOR</div><div class='cvname' style='margin-top:1.6mm'>" . self::e(strtoupper($au['name'])) . '</div>'
              . "<div class='cvgrey'>" . self::e(ucwords(strtolower($au['title']))) . '<br>' . self::e($au['cover_address']) . '</div>'
            : "<div class='cvlbl'>STATUS</div><div class='cvname' style='margin-top:1.6mm'>DRAFT &middot; SUBJECT TO AUDIT</div>"
              . "<div class='cvgrey'>Prepared from the company's accounting records</div>";
        $h .= "<div style='position:fixed; left:15mm; width:180mm; top:246mm'>" . $this->plusRule()
            . "<table style='width:100%; margin-top:4.8mm'><tr>"
            . "<td style='vertical-align:bottom; padding:0'>{$left}</td>"
            . "<td style='vertical-align:bottom; padding:0; text-align:right'><div class='cvlbl'>ISSUED</div>"
            . "<div class='cvname' style='margin-top:1.6mm'>" . self::e($d['issue_month']) . '</div></td>'
            . '</tr></table></div>';
        $chunks[] = ['cover', $h];

        // ---- Contents (i) ----
        $h = $this->brk('contents', "page-selector='front' resetpagenum='1' pagenumstyle='i'");
        $h .= "<table class='toc'><tr><td class='b'>TABLE OF CONTENTS</td><td class='pg b'>Page</td></tr>";
        $toc = [['Index page', 'i'], ['Declaration of the head of finance', 'ii'], ["Directors' report", $this->range('directors')]];
        if ($d['audited']) {
            $toc[] = ["Auditor's report", $this->range('auditor')];
        }
        $toc = array_merge($toc, [
            ['Statement of financial position', $this->range('sofp')], ['Statement of comprehensive income', $this->range('soci')],
            ['Cash flow statement', $this->range('cashflow')], ["Statement of changes in owners' equity", $this->range('equity')],
            ['Notes to the financial statements', $this->page('policies') . ' - ' . $this->lastNotePage()],
        ]);
        foreach ($toc as $r) {
            $h .= "<tr><td>{$r[0]}</td><td class='pg'>{$r[1]}</td></tr>";
        }
        $h .= '</table>';
        $chunks[] = ['contents', $h];

        // ---- Declaration of head of finance (ii) ----
        $fh = $d['finance_head'];
        $h = $this->brk('declaration');
        $h .= "<div class='title'>DECLARATION OF HEAD OF FINANCE:</div>";
        $h .= "<table class='box'><tr><td>";
        $h .= '<p>The National Board of Accountants and Auditors (NBAA) according to the power conferred under the Auditors and Accountants (Registration) Act. No. 33 of 1972, as amended by Act No. 2 of 1995, requires financial statements to be accompanied with a declaration issued by the Head of Finance/Accounting responsible for the preparation of financial statements of the entity concerned.</p>';
        $h .= "<p style='margin-top:7mm'>It is the duty of a Professional Accountant to assist the Board of Governing Body to discharge the responsibility of preparing financial statements of an entity showing true and fair view of the entity position and performance in accordance with applicable International Financial Reporting Standards and statutory financial reporting requirements. Full legal responsibility for the preparation of financial statements rests with the Board of Directors as under Directors’ Responsibilities statement on an earlier page.</p>";
        $h .= '<p>I, <b><u>' . self::e($fh['name']) . '</u></b>, being the ' . self::e($fh['title']) . ' of &nbsp; ' . self::e(strtoupper($d['company_short'])) . ' hereby acknowledge my responsibility of ensuring that Financial Statements for the year ended ' . $yeText . ' have been prepared in compliance with applicable accounting standards and other Statutory requirements.</p>';
        $h .= '<p>I thus confirm that the Financial Statements give a true and fair view of the financial position of ' . self::e($d['company_short']) . ' as on ' . $yeShort . ', its financial results and its cash flows for the year then ended, and that they have been prepared based on properly maintained financial records.</p>';
        $h .= "<p style='margin-top:18mm'>Signed by: .....................................................<b>" . self::e($fh['reg_no']) . '</b></p>';
        $h .= '<p>Date: <b><u>' . self::e($d['sign_date']) . '</u></b></p>';
        $h .= '</td></tr></table>';
        $chunks[] = ['declaration', $h];

        // ---- Directors' report ----
        $h = $this->brk('directors', "page-selector='body' resetpagenum='1' pagenumstyle='1'");
        $h .= "<div class='title'>DIRECTORS REPORT</div>";
        $h .= "<p class='j'>&nbsp;&nbsp;The directors have pleasure in submitting the annual financial report together with the financial statements for the period ended {$yeText} which discloses the state of affairs of the company.</p>";
        $h .= $this->sec(1, 'Incorporation.', 'The company is incorporated in Tanzania under the company act, 2002 as a limited liability company with shares not publicly traded.', 3);
        $h .= $this->sec(2, 'Principal Activities.', self::t($d['principal_activities']), 4);
        $b = "<b>i. Board of directors</b><br>&nbsp;&nbsp;&nbsp;&nbsp;The directors of the company as per registrar of the companies during the year ended {$yeText} and the date of this report were:";
        $b .= "<table class='plain'><tr><td class='hd'>Name</td><td class='hd' style='width:34%'>Qualification</td><td class='hd' style='width:20%'>Nationalities</td></tr>";
        foreach ($d['directors'] as $i => $r) {
            $b .= '<tr><td>' . ($i + 1) . ' ' . self::e($r[0]) . '</td><td>' . self::e($r[1] ?? '') . '</td><td>' . self::e($r[2] ?? '') . '</td></tr>';
        }
        $b .= '</table>' . ($d['md_note'] !== '' ? '<p><b>' . self::e($d['md_note_name']) . '</b> ' . self::t($d['md_note']) . '</p>' : '');
        $b .= '<b>ii. Shareholding structure</b><br>Shareholding structure of the company as at date of this report was as follows';
        $b .= "<table class='plain'><tr><td class='hd'>Shareholder</td><td class='hd' style='width:34%'>Nationality</td><td class='hd' style='width:20%'>Shares</td></tr>";
        foreach ($d['shareholders'] as $i => $r) {
            $b .= '<tr><td>' . ($i + 1) . ' ' . self::e($r[0]) . '</td><td>' . self::e($r[1] ?? '') . "</td><td class='b'>" . number_format((int) ($r[2] ?? 0)) . '</td></tr>';
        }
        $b .= '</table>';
        $h .= $this->sec(3, 'Board of directors, compositions and shareholding structure.', $b, 4);
        $h .= $this->sec(4, 'Directors’ welfare &amp; emoluments', 'During the year, the company has paid directors fees and board expenses totalling <b>' . self::e($d['director_fees_text']) . ".</b> However the monthly director salary is enshrined under company's operating expenses.", 4);
        $h .= $this->sec(5, 'Management of the company', "<p>The company is under the supervision of board of directors and the day to day management is entrusted to the key management team of the company led by the Managing Director appointed.</p>There has been significant growth both in size of the personnel especially technical department during the year ended {$yeText}", 5);

        $h .= $this->brk('directors');
        $h .= "<div class='title'>DIRECTORS REPORT CONTINUING..</div>";
        $bul = '';
        foreach (['The effectiveness and efficiency of operations', 'The safeguarding of the company’s assets', 'Compliance with applicable laws and regulating authorities',
            'The reliability of accounting records', 'Business sustainability under normal as well as adverse condition and', 'Responsible behaviors towards all stake holders'] as $li) {
            $bul .= "<tr><td style='width:6mm'>&middot;</td><td>{$li}</td></tr>";
        }
        $h .= $this->sec(6, 'Risk Management and internal control',
            'The board accepts final responsibilities for the risk management and internal control systems of the company. It is the task of management to ensure that adequate internal financial and operational control system are developed and maintained on an ongoing basis in order to provide reasonable assurance regarding;'
            . "<table class='bul'>{$bul}</table>"
            . "The efficiency of any internal control system is depending on the strict observance of prescribed measures. There is always a risk of non-compliance of such measures by staff. Whilst no system of internal control can provide absolute assurance against misstatement or losses, the company system is designed to provide the board with reasonable assurance that the procedures in place are operating effectively. The board of directors assessed the internal control systems throughout the financial year ended <b>31<sup>st</sup> December {$d['year']}</b> and is of the opinion that they met accepted criteria.", 2);
        $h .= $this->sec(7, 'Gender parity', '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;The company is equal opportunity employer it gives equal accesses to employment opportunities and ensure that the best available person is appointed to any given position free from discrimination of any kind and without regard to factors like gender, marital status, tribes, religion and disabilities which does not impair ability to discharge duties. There is total of ' . (int) $d['employees'] . ' employees for the year ended.', 10);
        $h .= $this->sec(8, 'Employee welfare',
            "<div class='subh'>Management and employee relationship</div>"
            . '<p>There is cordial relationship between employees and the executive management. There were no unresolved complaints by employees during the year ended.</p>'
            . "<div class='subh'>i. Employee benefit plan</div>"
            . 'The company pays contribution to publicly administered pension plan on mandatory basis which qualifies to be a defined contribution plan. The company’s obligation in respect of this contribution are limited to be 10% of employees basic salary for parastatal pension fund.', 10);

        $h .= $this->brk('directors');
        $h .= "<div class='title'>DIRECTORS REPORT CONTINUING…..</div>";
        $h .= $this->sec('', '', "<div class='subh' style='margin-left:0'>ii. Health and safety</div>As Employer, the company has continued to improve the working environment in light of health and safety issues which might affect its employees, and has taken such steps as provision of safety gear and training on the best safety practices. It has secured membership with OSHA as authoritative regulator for welfare of employees. Nevertheless during the year ended {$yeText} the company took full responsibility of the National Health Insurance Fund (NHIF) by covering their whole statutory contributions to the fund.", 3);
        $h .= $this->sec(9, 'Corporate social responsibilities', "The company is strongly committed to the communities around the company and aims to address issues of prime local concern such as health, women education, environment protection and water supply programs among others. Corporate social responsibility is firmly embedded in our mission, values and behaviors in the whole company and the region at large. During the year ended {$yeText}, the company has contributed <b>" . self::e($d['csr_amount_text']) . '</b> to various non profit making charities.', 11);
        $h .= $this->sec(10, 'Auditors', '&nbsp;&nbsp;&nbsp;The auditors, ' . self::e($au['short']) . ' of ' . self::e($au['po_box_text']) . ', have expressed their willingness to continue in office, pending re-appointment at the next Annual General Meeting', 14);
        $sum = "<table class='summary'><tr><td></td><td class='n b'>31st Dec {$d['year']}</td><td class='n b'>31st Dec {$d['prior_year']}</td></tr>";
        foreach ($d['results_summary'] as $i => $r) {
            $st = $i === count($d['results_summary']) - 1 ? " style='font-weight:bold; padding-top:7mm'" : '';
            $sum .= "<tr><td{$st}>" . self::e($r[0]) . "</td><td class='n'{$st}>" . self::f($r[1]) . "</td><td class='n'{$st}>" . self::f($r[2]) . '</td></tr>';
        }
        $sum .= '</table>';
        $h .= $this->sec(11, 'Results and performance of the year', "<p style='margin-top:3mm'>The results for the year ended {$yeShort} are set out on page " . $this->page('soci') . " and summarized as follows;</p>{$sum}", 10);

        $h .= $this->brk('directors');
        $h .= "<div class='title'>DIRECTORS REPORT CONTINUING….</div>";
        $h .= $this->sec(12, 'Related party Transactions', "<p style='margin-top:2mm'>There were no related party transactions during the year</p>", 1);
        $h .= $this->sec(13, 'Solvency evaluation &amp; assessment', "The company’s state of affairs as at 31<sup>st</sup> December {$d['year']} is set out on page " . $this->page('sofp') . ' of these financial statements. The directors have reviewed the current financial position of the company and on the basis of the review together with current business plan, directors are satisfied that the company is a solvent going concern with the meaning ascribed by the IFRS and companies act, 2002 of the laws of united republic of Tanzania. The financial statements have been prepared on a going concern basis which assumes that the company will continue in operational existence for foreseeable future.', 2);
        $h .= $this->sec(14, 'Statement of Director’s Responsibility in respect of the Financial Statements:',
            "<div style='margin-top:2mm'></div>"
            . '<p>The Directors are required by the company Act 2002 to prepare financial statements for each Financial year that give a true and fair view of the state of affairs of the company. It also require the directors to ensure the company keeps proper accounting records which disclose with reasonable accuracy at any time the financial position of the company. They are also responsible for safeguarding the assets of the company.</p>'
            . '<p>The directors therefore accept the responsibility for the annual financial statements, which have been prepared using appropriate accounting policies supported by reasonable and prudent judgment and estimates in conformity with IFRS and in manners required by Company 2002 Act.</p>'
            . '<p>The directors are of the opinion that the financial statements give a true and fair view of the state of the financial affairs of the company and its operating results for the period ended.</p>'
            . '<p>The Directors further accept responsibilities for maintenance of accounting records, which may be relied upon in preparation of financial statements as well as adequate systems of internal financial control.</p>'
            . '<p>Nothing has come to the attention of the directors to indicate the company will not remain in going concern for at least the next twelve months from the date of this statements.</p>', 12);
        $h .= "<div class='b' style='margin-top:3mm'>Approved by the board of directors and signed on its behalf by</div>";
        $h .= $this->signBlock(self::e($d['chairman']) . '<br>Chairman of the board');
        $chunks[] = ['directors', $h];

        // ---- Auditor's report (audited statements only) ----
        if ($d['audited']) {
            $co = self::e(ucwords(strtolower($d['company'])));
            $h = $this->brk('auditor');
            $h .= "<table class='lh'><tr><td class='lhname'><div class='lhfirm1'>" . self::e($au['firm']) . '</div></td>'
                . "<td class='lhaddr'><b>" . self::e($au['tagline']) . '<br>' . implode('<br>', array_map([self::class, 'e'], $au['letterhead'])) . '</b><br>'
                . "Email: <span style='color:#0563c1; text-decoration:underline'>" . self::e($au['email']) . '</span></td></tr></table>';
            $h .= "<hr style='height:1.6mm; color:#000000; background-color:#000000; margin:0' />";
            $h .= "<div style='text-align:right; font-weight:bold; font-size:11pt; margin:3mm 10mm 2mm 0'>" . self::e($d['report_date']) . '</div>';
            $h .= "<div class='audh'>INDEPENDENT AUDITORS REPORT</div>";
            $h .= "<div class='audh'>To the Members of {$co} for the</div><div class='audh'>Year Ended 31st December {$d['year']}</div>";
            $h .= "<div class='audh'>Report on the financial statements</div>";
            $h .= "<div class='audin'>We have audited the accompanying financial statements of <i>{$co},</i> set out on pages " . $this->page('sofp') . ' to ' . $this->lastNotePage() . " which comprise the statement of financial position, statements of comprehensive income, cash flows for the year ended 31 December {$d['year']}, and a summary of significant accounting policies and notes to the financial statements.</div>";
            $h .= "<div class='audh' style='margin-top:6mm'>Directors' responsibility for the financial statements</div>";
            $h .= "<div class='audin'>The Directors are responsible for the preparation of financial statements that give a true and fair view in accordance with International Financial Reporting Standards and the requirements of the Tanzanian Companies Act 2002, and for such internal control as the Directors determine is necessary to enable the preparation of financial statements that are free from material misstatement, whether due to fraud or error.</div>";
            $h .= "<div class='audh' style='margin-top:6mm'>Auditor's responsibility</div>";
            $h .= "<div class='audin'>Our responsibility is to express an opinion on these financial statements based on our audit. We conducted our audit in accordance with International Standards on Auditing. Those standards require that we comply with ethical requirements and plan and perform the audit to obtain reasonable assurance whether the financial statements are free from material misstatement.</div>";
            $h .= "<div class='aud'>An audit involves performing procedures to obtain audit evidence about the amounts and disclosures in the financial statements. The procedures selected depend on the auditor's judgment, including the assessment of the risks of material misstatement of the financial statements, whether due to fraud or error. In making those risk assessments, the auditor considers internal controls relevant to the company's preparation and fair presentation of the financial statements in order to design audit procedures that are appropriate in the circumstances, but not for the purpose of expressing an opinion on the effectiveness of the company's internal control. An audit also includes evaluating the appropriateness of accounting policies used and the reasonableness of accounting estimates made by the Directors, as well as evaluating the overall presentation of the financial statements.</div>";
            $h .= "<div class='aud'>We believe that the audit evidence we have obtained is sufficient and appropriate to provide a basis for our audit opinion.</div>";
            $h .= "<div class='audh' style='margin-top:5mm'>Opinion</div>";
            $h .= "<div class='audin'>In our opinion the accompanying financial statements give a true and fair view of the state of financial affairs of the company as at 31 December {$d['year']} and of its financial performance and cash flows for the year then ended in accordance with International Financial Reporting Standards and the Tanzanian Companies Act 2002.</div>";
            $h .= $this->brk('auditor');
            $h .= "<div class='audh' style='margin-top:0'>Report on other Legal requirements</div>";
            $h .= "<div class='aud'>This report, including the opinion, has been prepared for and only for the company members as body in accordance with companies Act 2002 and for no other purpose.</div>";
            $h .= "<div class='aud' style='margin-top:3mm'>As required by Tanzanian Companies Act 2002, we report to you based on our audit, that:</div>";
            $h .= "<table class='roman' style='margin-left:8mm'>";
            foreach ([
                'i)'   => 'The directors’ report is consistent with financial statements;',
                'ii)'  => 'We have obtained all the information and explanations which to the best of our knowledge and belief were necessary for purpose of our audit.',
                'iii)' => 'Proper Accounting records have been kept by the company, so far as appears from our examination of those books;',
                'iv)'  => 'The company statement of financial position and comprehensive income are in agreement with accounting records.',
            ] as $k => $v) {
                $h .= "<tr><td style='width:14mm'>{$k}</td><td>{$v}</td></tr>";
            }
            $h .= '</table>';
            $h .= "<div class='b' style='margin:9mm 0 0 8mm'>Yours</div><div style='height:18mm'></div>";
            $h .= "<table><tr><td style='border-top:0.3mm solid #000000; padding-top:0.5mm' class='partner'>"
                . self::e($au['partner']) . '<br>' . self::e($au['firm']) . '<br>' . self::e($au['title']) . '<br>' . self::e($au['city']) . '</td></tr></table>';
            $chunks[] = ['auditor', $h];
        }

        // ---- Statement of financial position ----
        $h = $this->brk('sofp');
        $h .= "<div class='b' style='margin-bottom:3mm'>STATEMENT OF FINANCIAL POSITION AS AT " . strtoupper($yeText) . '</div>';
        $h .= "<table class='fin'>" . $this->yearHead();
        $h .= $this->row('ASSETS', null, null, 'b') . $this->row('Non-current assets', null, null, 'b');
        $h .= $this->row('Property, Plant and Equipment', $s['ppe'][0], $s['ppe'][1], '', 15) . $this->gap(4);
        $h .= $this->row('Current assets', null, null, 'b');
        $h .= $this->row('Inventories', $s['inventories'][0], $s['inventories'][1], '', 3);
        $h .= $this->optRow('Taxation', $s['taxation'], '', 8);
        $h .= $this->row('Trade receivables', $s['trade_receivables'][0], $s['trade_receivables'][1], '', 11);
        $h .= $this->optRow('VAT receivables', $s['vat_receivables'], '', 11);
        $h .= $this->optRow('Other receivables &amp; prepayments', $s['other_receivables'], '', 11);
        $h .= $this->row('Cash and Cash Equivalents', $s['cash'][0], $s['cash'][1], 'ul', 10);
        $h .= $this->row('', $s['current_assets'][0], $s['current_assets'][1], 'b ul') . $this->gap(5);
        $h .= $this->row('TOTAL ASSETS', $s['total_assets'][0], $s['total_assets'][1], 'b dbl') . $this->gap(5);
        $h .= $this->row('EQUITY AND LIABILITIES', null, null, 'b') . $this->row('Capital and reserves', null, null, 'b') . $this->gap(3);
        $h .= $this->row('Share capital', $s['share_capital'][0], $s['share_capital'][1], '', 6);
        $h .= $this->row('Retained Earnings', $s['retained'][0], $s['retained'][1], 'ul', 9);
        $h .= $this->row('Total Equity', $s['total_equity'][0], $s['total_equity'][1], 'b') . $this->gap(7);
        $h .= $this->row('Current liabilities', null, null, 'b') . $this->gap(2);
        $h .= $this->row('Trade Creditors &amp; Accruals', $s['creditors'][0], $s['creditors'][1], '', 12);
        $h .= $this->optRow('Taxation payable', $s['tax_payable'], '', 8);
        $h .= $this->row('Short term financing', $s['short_term'][0], $s['short_term'][1], '', 14);
        $h .= $this->row('Bank overdraft facilities', $s['overdraft'][0], $s['overdraft'][1], 'ul', 13);
        $h .= $this->row('Total Liabilities', $s['total_liabilities'][0], $s['total_liabilities'][1], 'b dbl') . $this->gap(5);
        $h .= $this->row('TOTAL EQUITY AND LIABILITIES', $s['total_eq_liab'][0], $s['total_eq_liab'][1], 'b tot dbl');
        $h .= '</table>' . $this->signBlock();
        $h .= "<p style='margin-top:5mm'>The financial position is to be read in conjunction with the notes to and forming part of the financial statements set out on pages " . $this->page('policies') . ' to ' . $this->lastNotePage() . '.</p>';
        $chunks[] = ['sofp', $h];

        // ---- Statement of comprehensive income ----
        $h = $this->brk('soci');
        $h .= "<div class='title' style='margin-bottom:5mm'>STATEMENT OF COMPREHENSIVE INCOME:</div>";
        $h .= "<table class='fin airy'>" . $this->yearHead();
        $h .= $this->row('Revenue', $p['revenue'][0], $p['revenue'][1], '', 2);
        $h .= $this->row('Cost of sales', $p['cost_of_sales'][0], $p['cost_of_sales'][1], 'ul', 3);
        $h .= $this->row('Gross profit', $p['gross_profit'][0], $p['gross_profit'][1], 'b') . $this->gap(6);
        $h .= $this->row('Operating and Administrative Expenses', null, null, 'b');
        $h .= $this->row('Operating Expenses', $p['opex'][0], $p['opex'][1], '', 4);
        $h .= $this->row('Depreciation', $p['depreciation'][0], $p['depreciation'][1], '', 15);
        $h .= $this->row('Finance Costs', $p['finance'][0], $p['finance'][1], 'ul', 5);
        $h .= "<tr><td></td><td></td><td class='num b' style='padding-top:0'>" . self::f($p['total_exp'][0]) . "</td><td class='num b' style='padding-top:0'>" . self::f($p['total_exp'][1]) . '</td></tr>';
        $h .= $this->row('Profit from operations', $p['pbt'][0], $p['pbt'][1], 'b', 7);
        $h .= $this->row('Income tax expense', $p['tax'][0], $p['tax'][1], 'ul', 8);
        $h .= $this->row('Profit after tax', $p['pat'][0], $p['pat'][1], 'b dbl');
        $h .= '</table>' . $this->signBlock();
        $h .= "<p style='margin-top:4mm'>The income statement is to be read in conjunction with the notes to and forming part of the financial statements set out on pages " . $this->page('policies') . ' to ' . $this->lastNotePage() . '.</p>';
        $chunks[] = ['soci', $h];

        // ---- Cash flow statement ----
        $h = $this->brk('cashflow');
        $h .= "<table class='fin'>";
        $h .= "<tr><td class='lbl b'>CASH FLOW STATEMENT</td><td class='note'></td><td class='num b' style='text-align:center'>{$d['year']}<br>{$d['currency']}</td><td class='num b' style='text-align:center'>{$d['prior_year']}<br>{$d['currency']}</td></tr>" . $this->gap(4);
        $h .= $this->row('CASH FLOWS FROM OPERATING ACTIVITIES', null, null, 'b');
        $h .= $this->row('(Loss)/Profit before taxation', $cf['pbt'][0], $cf['pbt'][1], 'b');
        $h .= $this->row('Adjustments for:', null, null);
        $h .= $this->row('Depreciation', $cf['depreciation'][0], $cf['depreciation'][1], 'ind ul');
        $h .= $this->row('Operating profit/(loss) before working capital changes', $cf['op_before_wc'][0], $cf['op_before_wc'][1], 'b') . $this->gap(4);
        $h .= $this->row('<u>Working Capital Changes</u>', null, null, 'b') . $this->gap(3);
        $h .= $this->rows($cf['wc']) . $this->gap(4);
        $h .= $this->row('Cash generated from/(used by) operations', $cf['cash_ops'][0], $cf['cash_ops'][1], 'b tot');
        $h .= $this->row('Tax paid', $cf['tax_paid'][0], $cf['tax_paid'][1], 'ul');
        $h .= $this->row('', $cf['after_tax'][0], $cf['after_tax'][1], 'b');
        foreach ($cf['other_operating'] as $r) {
            $h .= $this->optRow(self::e($r[0]), [$r[1], $r[2]]);
        }
        $h .= $this->gap(4);
        $h .= $this->row('Net cash from/(used by) operating activities', $cf['net_operating'][0], $cf['net_operating'][1], 'b tot') . $this->gap(4);
        $h .= $this->row('CASH FROM INVESTING ACTIVITIES', null, null, 'b') . $this->rows($cf['investing']) . $this->gap(4);
        $h .= $this->row('<u>CASH FLOW FROM FINANCING ACTIVITIES</u>', null, null, 'b') . $this->rows($cf['financing'], true);
        $h .= $this->row('', $cf['financing_total'][0], $cf['financing_total'][1], 'b ul') . $this->gap(5);
        $h .= $this->row('Net (decrease)/increase<br>in cash and cash equivalents', $cf['net_change'][0], $cf['net_change'][1], 'b') . $this->gap(4);
        $h .= $this->row('CASH AND CASH EQUIVALENTS AT', null, null, 'b');
        $h .= $this->row('Beginning of the year', $cf['opening'][0], $cf['opening'][1]);
        $h .= $this->row('End of the year', $cf['closing'][0], $cf['closing'][1], 'b ul') . $this->gap(7);
        $h .= $this->row('Net Movement of Cash/Bank Balances', $cf['closing'][0] - $cf['opening'][0], $cf['closing'][1] - $cf['opening'][1], 'b dbl');
        $h .= '</table>' . $this->signBlock();
        $chunks[] = ['cashflow', $h];

        // ---- Statement of changes in equity ----
        $h = $this->brk('equity');
        $h .= "<div class='title' style='margin-bottom:4mm'>STATEMENT &nbsp;OF CHANGES &nbsp;IN &nbsp;OWNERS &nbsp;EQUITY</div>";
        $h .= "<table class='eq'>";
        $h .= "<tr><td class='gh gtop' style='width:37%'></td><td class='gh gtop' style='width:21%'>Share<br><br>Capital</td>"
            . "<td class='gh gtop' style='width:21%'>Retained<br><br>Earnings</td><td class='gh gtop' style='width:21%'><br><br>TOTAL</td></tr>";
        $h .= "<tr><td class='gh gbot'></td><td class='gh gbot'>{$d['currency']}</td><td class='gh gbot'>{$d['currency']}</td><td class='gh gbot'>{$d['currency']}</td></tr>";
        foreach ($d['equity'] as $y) {
            $h .= "<tr><td class='yr'>{$y['year']}</td><td></td><td></td><td></td></tr>";
            $h .= "<tr><td class='l'>At 1st Jan, {$y['year']}</td><td>" . self::f($y['open'][0]) . '</td><td>' . self::f($y['open'][1]) . '</td><td>' . self::f(array_sum($y['open'])) . '</td></tr>';
            $h .= "<tr><td class='l'>Profit for the year</td><td>-</td><td>" . self::f($y['profit']) . '</td><td>' . self::f($y['profit']) . '</td></tr>';
            if ($y['capital']) {
                $h .= "<tr><td class='l'>Share capital introduced</td><td>" . self::f($y['capital']) . '</td><td>-</td><td>' . self::f($y['capital']) . '</td></tr>';
            }
            $h .= "<tr><td class='l'>Assessments &amp; adjustments</td><td></td><td>" . self::f($y['assess']) . '</td><td>' . self::f($y['assess']) . '</td></tr>';
            $h .= "<tr><td class='closel'>At 31st December, {$y['year']}</td><td class='close'>" . self::f($y['close'][0]) . "</td><td class='close'>" . self::f($y['close'][1]) . "</td><td class='close'>" . self::f(array_sum($y['close'])) . '</td></tr>';
        }
        $h .= '</table>' . $this->signBlock();
        $chunks[] = ['equity', $h];

        // ---- Notes: accounting policies ----
        $h = $this->brk('policies');
        $h .= "<div class='coline' style='margin-bottom:1mm'>NOTES TO THE FINANCIAL STATEMENTS</div>";
        $h .= "<div class='coline' style='margin-bottom:1mm'>1 SIGNIFICANT ACCOUNTING POLICIES</div>";
        $h .= '<p>The principal accounting policies adopted in the preparation of these financial statements are set out below:</p>';
        $h .= "<table class='pol'>";
        foreach (array_values($d['policies']) as $i => $pol) {
            $body = self::t($pol[1] ?? '');
            if (strcasecmp(trim($pol[0]), 'Depreciation') === 0 && $d['dep_rates']) {
                $body .= "<table class='rates'>";
                foreach ($d['dep_rates'] as $r) {
                    $body .= "<tr><td style='width:65mm'>" . self::e($r[0]) . '</td><td>' . self::e($r[1] ?? '') . '</td></tr>';
                }
                $body .= '</table>' . self::t($d['dep_note']);
            }
            $h .= "<tr><td class='polk'>" . chr(97 + $i) . ")</td><td><div class='polh'>" . self::e($pol[0]) . "</div>{$body}</td></tr>";
        }
        $h .= '</table>';
        $chunks[] = ['policies', $h];

        // ---- Notes 2-5 ----
        $h = $this->brk('notes');
        $h .= "<div class='b' style='margin-top:3mm'>Notes to the financial statements cont'd</div>";
        $h .= "<table class='fin notes dense'>" . $this->yearHead(false);
        $h .= $this->noteHead(2, 'TURNOVER') . $this->rows($d['turnover'], true);
        $h .= $this->row('', $d['turnover_total'][0], $d['turnover_total'][1], 'b dbl');
        $ind = '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;';
        $h .= $this->noteHead(3, 'COST OF SALES') . $this->row('Inventory', null, null, 'b');
        $h .= $this->row('Opening inventory', $c['opening'][0], $c['opening'][1]);
        $h .= $this->row('&nbsp;&nbsp;&nbsp;&nbsp;Add: Purchases', null, null);
        $h .= $this->row("{$ind}Importations", $c['imports'][0], $c['imports'][1]);
        $h .= $this->row("{$ind}Local purchase", $c['local'][0], $c['local'][1], ($c['stockIn'][0] || $c['stockIn'][1]) ? '' : 'ul');
        $h .= $this->optRow("{$ind}Stock received into inventory", $c['stockIn'], 'ul');
        $h .= $this->row('Cost of inventory available for sale', $c['available'][0], $c['available'][1], 'b ul');
        $h .= $this->row('Direct costs', null, null, 'b') . $this->rows($c['direct'], true);
        $h .= $this->row('Sub total', $c['direct_total'][0], $c['direct_total'][1], 'b');
        $h .= $this->row('Total cost of inventory for sale', $c['total'][0], $c['total'][1], 'b');
        $h .= $this->row('Closing inventory', -$c['closing'][0], -$c['closing'][1], ($c['adjust'][0] || $c['adjust'][1]) ? '' : 'ul');
        $h .= $this->optRow('Other inventory movements (net)', $c['adjust'], 'ul');
        $h .= $this->row('Total cost of inventory sold', $c['costSold'][0], $c['costSold'][1], 'b');
        $h .= $this->row('GROSS PROFIT', $c['gross'][0], $c['gross'][1], 'b');
        $h .= $this->noteHead(4, '<u>OPERATING &amp; ADMINISTRATIVE EXPENSES:</u>');
        $h .= '</table>';
        $h .= "<table class='fin notes airy'>" . $this->rows($d['opex'], true);
        $h .= $this->row('', $d['opex_total'][0], $d['opex_total'][1], 'b dbl') . '</table>';
        $h .= "<table class='fin notes' style='margin-top:5mm'>" . $this->noteHead(5, 'Finance Charges') . $this->rows($d['finance'], true) . $this->gap(3);
        $h .= $this->row('', $d['finance_total'][0], $d['finance_total'][1], 'b ul') . '</table>';

        // ---- Notes 6-9 ----
        $h .= $this->brk('notes');
        $h .= "<div class='b' style='margin:3mm 0 3mm 0'>Notes to the financial statements cont'd</div>";
        $h .= "<table class='fin notes'>" . $this->yearHead(false);
        $h .= $this->noteHead(6, 'CAPITAL STRUCTURE:') . $this->gap(3);
        $h .= $this->rows($d['share_capital_note']) . $this->gap(6);
        $h .= $this->row('<b>Total amount</b>', $d['share_capital'][0], $d['share_capital'][1], 'tot dbl') . $this->gap(8);
        $h .= $this->noteHead(7, 'PROFIT/(LOSS) BEFORE TAXATION');
        $h .= $this->row('The profit/(loss) before taxation', $t['pbt'][0], $t['pbt'][1], 'b') . $this->gap(3);
        $h .= $this->noteHead(8, 'TAXATION');
        $h .= $this->row('The profit/(loss) before taxation', $t['pbt'][0], $t['pbt'][1]);
        $h .= $this->row('Add back: Depreciation', $t['dep'][0], $t['dep'][1], 'ul');
        $h .= $this->row('Adjusted Taxable Income', $t['taxable'][0], $t['taxable'][1], 'b');
        $h .= $this->row('Income tax expense for the year', $t['tax_year'][0], $t['tax_year'][1], 'b') . $this->gap(3);
        $h .= $this->row('<u>Income tax account</u>', null, null, 'b');
        $h .= $this->row('Balance brought forward', $t['bal_bf'][0], $t['bal_bf'][1]);
        $h .= $this->row('Tax charged during the year', $t['charged'][0], $t['charged'][1]);
        $h .= $this->row('Tax paid during the year', $t['paid'][0], $t['paid'][1], 'ul');
        $h .= $this->row('Balance carried forward', $t['bal_cf'][0], $t['bal_cf'][1], 'b ul');
        $h .= "<tr><td class='lbl i' colspan='4' style='font-size:8.5pt'>A bracketed balance is tax recoverable (shown under current assets).</td></tr>" . $this->gap(4);
        $h .= $this->noteHead(9, 'CUMULATIVE RETAINED EARNINGS') . $this->rows($d['retained'], true);
        $h .= $this->row('', $d['retained_total'][0], $d['retained_total'][1], 'b dbl');
        $h .= '</table>';

        // ---- Notes 10-14 ----
        $h .= $this->brk('notes');
        $h .= "<div class='b' style='margin:1mm 0 4mm 0'>Notes to the financial statements cont'd</div>";
        $h .= "<table class='fin notes'>" . $this->yearHead(false);
        $h .= $this->noteHead(10, 'CASH AND BANK') . $this->rows($d['cash_note'], true) . $this->row('', $d['cash_total'][0], $d['cash_total'][1], 'b dbl') . $this->gap(4);
        $h .= $this->noteHead(11, 'RECEIVABLES') . $this->rows($d['receivables'], true) . $this->row('', $d['receivables_total'][0], $d['receivables_total'][1], 'b dbl');
        $h .= $this->noteHead(12, 'TRADE CREDITORS &amp; ACCRUALS') . $this->row('Payables', null, null, 'b') . $this->gap(2);
        $h .= $this->rows($d['payables'], true) . $this->row('', $d['payables_total'][0], $d['payables_total'][1], 'b dbl');
        if ($d['accrued_interest']) {
            $h .= $this->row('Accrued financing interests &amp; costs', null, null, 'b');
            $h .= $this->rows($d['accrued_interest'], true) . $this->row('Total', $d['accrued_interest_total'][0], $d['accrued_interest_total'][1], 'b dbl');
        }
        $h .= $this->gap(4);
        $h .= $this->noteHead(13, 'Bank overdraft facilities') . $this->rows($d['overdrafts'], true) . $this->row('', $d['overdrafts_total'][0], $d['overdrafts_total'][1], 'b dbl');
        $h .= $this->noteHead(14, 'Short term financing facilities') . $this->rows($d['short_term_note'], true) . $this->row('', $d['short_term_total'][0], $d['short_term_total'][1], 'b dbl');
        $h .= '</table>';
        $chunks[] = ['notes', $h];

        // ---- Non-current asset schedule + notes 15-17 ----
        $a = $d['assets'];
        $n = count($a['classes']);
        $sumv = fn ($arr) => array_sum(array_map(fn ($v) => $v ?? 0, $arr));
        $costClose = $depClose = $nbv = [];
        for ($i = 0; $i < $n; $i++) {
            $costClose[$i] = ($a['cost_open'][$i] ?? 0) + ($a['additions'][$i] ?? 0) - ($a['disposal'][$i] ?? 0);
            $depClose[$i] = ($a['dep_open'][$i] ?? 0) + ($a['dep_charge'][$i] ?? 0);
            $nbv[$i] = $costClose[$i] - $depClose[$i];
        }
        $faRow = function ($label, $vals, $total, $cls = '', $totCls = '') {
            $r = "<tr><td class='falbl {$cls}'>{$label}</td>";
            foreach ($vals as $v) {
                $r .= "<td class='fanum {$cls}'>" . self::f($v, 2) . '</td>';
            }

            return $r . "<td class='fanum {$cls} {$totCls}'>" . ($total === null ? '' : self::f($total, 2)) . '</td></tr>';
        };
        $faBlank = fn ($label = '', $cls = '') => "<tr><td class='falbl {$cls}'>{$label}</td>" . str_repeat("<td class='fanum {$cls}'></td>", $n + 1) . '</tr>';

        $h = $this->brk('assets');
        $h .= "<div class='b' style='margin-bottom:3mm'>15 <u>NON CURRENT ASSET SCHEDULE</u></div>";
        $h .= "<table class='fa'>";
        $h .= "<tr><td class='falbl fah' style='text-align:left'>COST/VALUATION</td>";
        foreach ($a['classes'] as $cl) {
            $h .= "<td class='fah'>" . self::e($cl[0]) . '</td>';
        }
        $h .= "<td class='fah'><br>TOTAL</td></tr>";
        $h .= "<tr><td class='fahc'></td>" . str_repeat("<td class='fahc'>{$d['currency']}</td>", $n + 1) . '</tr>';
        $h .= $faRow("At 1st Jan, {$d['year']}", $a['cost_open'], $sumv($a['cost_open']), '', 'fabold');
        $h .= $faRow('Additions', $a['additions'], $sumv($a['additions']), '', 'fabold');
        $h .= $faRow('Disposal', $a['disposal'], $sumv($a['disposal']));
        $h .= $faRow("At 31st December, {$d['year']}", $costClose, array_sum($costClose), 'fatot');
        $h .= $faBlank() . $faBlank('<b><u>DEPRECIATION</u></b>');
        $h .= $faRow("At 1st Jan, {$d['year']}", $a['dep_open'], $sumv($a['dep_open']));
        $h .= $faRow('Charge for the year', $a['dep_charge'], $sumv($a['dep_charge']));
        $h .= $faRow('Disposal', array_fill(0, $n, null), 0);
        $h .= $faRow("At 31st December, {$d['year']}", $depClose, array_sum($depClose), 'fatot');
        $h .= $faBlank() . $faBlank('<b>NET BOOK VALUE</b>');
        $h .= $faRow("At 31st December, {$d['year']}", $nbv, array_sum($nbv), 'fatot');
        $h .= $faRow("At 31st December, {$d['prior_year']}", $a['nbv_prior'], $sumv($a['nbv_prior']), 'fatot');
        $h .= '</table>';
        $h .= $this->sec(16, 'CAPITAL COMMITMENTS', "As at 31 December {$d['year']}, the Company did not have contractual commitments for any capital expenditure.", 10);
        $h .= $this->sec(17, 'CONTINGENT LIABILITIES &amp; COMMITMENTS', 'As at the end of the reporting period, there were no pending or unresolved petitions against the Company. Even for unforeseen events of such nature, the Directors are confident that the Company’s position will always be strong, and it is not possible to estimate any potential liability, if any, at this stage.', 8);
        $h .= $this->sec(18, 'SUBSEQUENT EVENTS TO PERIOD END.', 'At the date of signing the financial statements, the Directors are not aware of any other matter or circumstance arising since the end of the financial period, not otherwise dealt with in these financial statements, which significantly affected the financial position of the Company and results of its operations.', 8);
        $chunks[] = ['assets', $h];

        // ---- Back cover ----
        $chunks[] = ['back', "<pagebreak page-selector='back' /><div>&nbsp;</div>"];

        return $chunks;
    }

    // ---------------------------------------------------------------- styles

    private function css(): string
    {
        $l = $this->logos;

        return "
  @page       { margin: 33mm 15mm 29mm 15mm; margin-header: 7mm; margin-footer: 8mm;
                background-image: url({$l}/fs_topbar.png); background-repeat: no-repeat;
                background-position: top left; background-image-resize: 4; }
  @page cover { margin: 0; margin-header: 0; margin-footer: 0;
                background-image: url({$l}/fs_cover_bg.png); background-image-resize: 6; odd-header-name: _blank; even-header-name: _blank; odd-footer-name: _blank; even-footer-name: _blank; }
  @page back  { margin: 0; margin-header: 0; margin-footer: 0; background-image: none; background-color: #1d2d3d; odd-header-name: _blank; even-header-name: _blank; odd-footer-name: _blank; even-footer-name: _blank; }
  @page front { odd-footer-name: html_foot; even-footer-name: html_foot; }
  @page body  { odd-footer-name: html_foot; even-footer-name: html_foot; }

  html, body { font-family: tildasans, dejavusans, sans-serif; font-size: 10pt; color: #000000; line-height: 1.3; }
  table { border-collapse: collapse; }
  p { margin: 0 0 3mm 0; }
  .j { text-align: justify; }
  .b { font-weight: bold; }
  .i { font-style: italic; }

  .coline { font-family: barlowcondensed, tildasans, sans-serif; font-weight: bold; font-size: 10.5pt; margin-bottom: 3mm; }
  .title  { font-family: barlowcondensed, tildasans, sans-serif; font-weight: bold; font-size: 11.5pt; text-decoration: underline; margin-bottom: 2mm; }
  .sech   { font-family: barlowcondensed, tildasans, sans-serif; font-weight: bold; font-size: 11pt; margin-bottom: 1mm; }
  .subh   { font-family: barlowcondensed, tildasans, sans-serif; font-weight: bold; font-size: 11pt; margin: 2mm 0 1mm 12mm; }

  .cvlbl   { font-family: barlowcondensed, tildasans, sans-serif; font-weight: bold; font-size: 8pt; letter-spacing: 0.45mm; color: #416180; }
  .cvtitle { font-family: barlowcondensed, tildasans, sans-serif; font-weight: bold; font-size: 63pt; line-height: 0.9; color: #1d1f20; }
  .cvsub   { font-family: barlowcondensed, tildasans, sans-serif; font-weight: bold; font-size: 15pt; letter-spacing: 0.3mm; color: #1d1f20; }
  .cventity { font-family: barlowcondensed, tildasans, sans-serif; font-weight: bold; font-size: 22.5pt; line-height: 1; color: #1d1f20; white-space: nowrap; }
  .cvname  { font-family: barlowcondensed, tildasans, sans-serif; font-weight: bold; font-size: 13.5pt; letter-spacing: 0.1mm; color: #1d1f20; }
  .cvgrey  { font-size: 9.5pt; line-height: 1.55; color: #5d5d60; }

  table.toc { width: 75%; }
  table.toc td { padding: 0 0 13mm 0; font-size: 11pt; }
  table.toc td.pg { text-align: center; width: 25mm; }

  table.box { width: 95%; }
  table.box td { border: 0.3mm solid #bfbfbf; padding: 4mm 3mm 8mm 3mm; font-size: 10.5pt; }

  table.sec { width: 100%; }
  td.secno   { width: 7mm; vertical-align: top; font-weight: bold; font-family: barlowcondensed, tildasans, sans-serif; font-size: 11pt; }
  td.secbody { vertical-align: top; text-align: justify; }
  table.plain { width: 94%; margin: 1mm 0 3mm 5mm; }
  table.plain td { padding: 0.4mm 0; }
  table.plain td.hd { font-weight: bold; }
  table.bul { margin: 3mm 0 3mm 22mm; }
  table.bul td { padding: 0 0 3mm 0; vertical-align: top; }
  table.summary { width: 88%; margin: 4mm 0 0 6mm; }
  table.summary td { padding: 3mm 0; }
  table.summary td.n { text-align: right; width: 23%; }

  table.fin { width: 100%; }
  table.fin td { padding: 0.45mm 0; vertical-align: bottom; font-size: 10pt; }
  table.airy td { padding: 1.5mm 0; }
  td.lbl  { padding-right: 3mm; }
  td.ind  { padding-left: 6mm; }
  td.nh   { font-weight: bold; font-family: barlowcondensed, tildasans, sans-serif; font-size: 11pt; padding-top: 1mm; }
  td.note { width: 13%; text-align: center; font-weight: bold; }
  td.num  { width: 19%; text-align: right; padding-left: 4mm; white-space: nowrap; }
  td.ul   { border-bottom: 0.3mm solid #000000; }
  td.tot  { border-top: 0.3mm solid #000000; }
  td.dbl  { border-bottom: 0.8mm double #000000; }
  table.notes td.lbl { padding-left: 9mm; }
  table.dense td { padding: 0.25mm 0; font-size: 9.5pt; }
  table.notes td.nh  { padding-left: 0; }

  table.sign { width: 100%; margin-top: 12mm; }
  table.sign td { vertical-align: bottom; font-size: 10pt; }
  .dots { letter-spacing: 0.3mm; margin-bottom: 1.5mm; }
  .hand { font-family: barlowcondensed, tildasans, sans-serif; font-style: italic; font-size: 15pt; }

  table.eq { width: 88%; }
  table.eq td { border-left: 0.5mm solid #000000; border-right: 0.5mm solid #000000; padding: 0 1mm; text-align: right; }
  table.eq td.l { text-align: left; }
  table.eq td.gh { background-color: #a6a6a6; font-weight: bold; text-align: center; }
  table.eq td.gtop { border-top: 0.5mm solid #000000; }
  table.eq td.gbot { border-top: 0.3mm solid #000000; border-bottom: 0.5mm solid #000000; }
  table.eq td.yr { text-align: center; font-weight: bold; padding-top: 3mm; }
  table.eq td.close { font-weight: bold; border-top: 0.3mm solid #000000; border-bottom: 0.5mm solid #000000; }
  table.eq td.closel { border-top: 0.3mm solid #000000; border-bottom: 0.5mm solid #000000; text-align: left; }

  table.pol { width: 100%; }
  table.pol td { vertical-align: top; padding: 0 0 1.2mm 0; }
  td.polk { width: 9mm; font-weight: bold; }
  .polh { font-weight: bold; }
  table.rates td { padding: 0; }

  table.lh { width: 100%; }
  table.lh td { vertical-align: top; }
  td.lhname { border-right: 0.5mm solid #000000; padding: 0 5mm 8mm 0; width: 45%; }
  td.lhaddr { padding: 0 0 8mm 4mm; font-size: 9pt; line-height: 1.35; }
  .lhfirm1 { font-family: barlowcondensed, tildasans, sans-serif; font-weight: bold; font-size: 24pt; color: #2f3f73; line-height: 1; }
  .audh { font-weight: bold; margin: 3.5mm 0 1.5mm 0; }
  .aud  { text-align: justify; margin-bottom: 1.5mm; }
  .audin { text-align: justify; margin-bottom: 1.5mm; text-indent: 5mm; }
  table.roman td { vertical-align: top; padding: 0 0 0.6mm 0; }
  .partner { font-weight: bold; line-height: 1.2; }

  table.fa { width: 100%; }
  table.fa td { border-left: 0.4mm solid #000000; border-right: 0.4mm solid #000000; padding: 0.3mm 1mm; font-size: 8.5pt; }
  td.falbl { text-align: left; width: 22%; }
  td.fanum { text-align: right; white-space: nowrap; }
  td.fah   { background-color: #a6a6a6; font-weight: bold; text-align: center; border-top: 0.4mm solid #000000; border-bottom: 0.4mm solid #000000; }
  td.fahc  { background-color: #a6a6a6; font-weight: bold; text-align: center; border-top: 0.3mm solid #000000; border-bottom: 0.4mm solid #000000; }
  td.fatot { font-weight: bold; border-top: 0.4mm solid #000000; border-bottom: 0.4mm solid #000000; }
  td.fabold { font-weight: bold; }
";
    }

    private function mpdf(): Mpdf
    {
        $defaultFontDirs = (new \Mpdf\Config\ConfigVariables())->getDefaults()['fontDir'];
        $defaultFontData = (new \Mpdf\Config\FontVariables())->getDefaults()['fontdata'];

        return new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'tempDir' => storage_path('app/mpdf-tmp'),
            'fontDir' => array_merge($defaultFontDirs, [resource_path('pdf-assets/fonts')]),
            'fontdata' => $defaultFontData + [
                'tildasans' => ['R' => 'TildaSans.ttf'],
                'barlowcondensed' => [
                    'R' => 'BarlowCondensed-Regular.ttf',
                    'B' => 'BarlowCondensed-Bold.ttf',
                    'I' => 'BarlowCondensed-Italic.ttf',
                    'BI' => 'BarlowCondensed-BoldItalic.ttf',
                ],
            ],
        ]);
    }
}
