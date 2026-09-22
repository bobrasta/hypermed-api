<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Quotation;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfInstance;
use Illuminate\Http\Response;
use Mpdf\Mpdf;

class DocumentPdfService
{
    // Quotations and Invoices share one physical layout — ported from the
    // "Hypermed Proforma Invoice" mPDF design prototyped at
    // /var/www/html/me/system/invoice.php (dark navy header/footer bands
    // repeating on every page, big condensed uppercase title, bordered
    // doc-no box, clean-line item rows). The only differences between the
    // two document types are the title/label/tag text and which model's
    // data feeds the same template — see render().
    public function quotationPdf(Quotation $quotation): Response
    {
        $quotation->loadMissing('items');
        $company = config('company');

        $items = $quotation->items->values()->map(fn ($i, $idx) => [
            $idx + 1, $i->description, '-', '-', $i->unit_of_measure ?: '-',
            $this->trimmedQuantity($i->quantity), number_format($i->unit_price, 2),
            number_format($i->total_price, 2),
        ])->all();

        $client = array_values(array_filter([$quotation->client_name, $quotation->client_contact, $quotation->client_email]));

        return $this->render([
            'title'        => 'PROFORMA INVOICE',
            'docLabel'     => 'Proforma No:',
            'docNumber'    => $quotation->quotation_number,
            'date'         => ($quotation->created_at ?? now())->format('d M Y, H:i'),
            'tag'          => 'QUOTATION · VALID UNTIL ' . ($quotation->valid_until?->format('d M Y') ?? 'N/A'),
            'client'       => $client,
            'items'        => $items,
            'subtotal'     => number_format($quotation->subtotal, 2),
            'discount'     => number_format($quotation->discount_amount, 2),
            'tax'          => number_format($quotation->tax_amount, 2),
            'total'        => number_format($quotation->total_amount, 2),
            'currencyCode' => $this->currencyPrefix($quotation->currency),
            'currency'     => $this->currencyLabel($quotation->currency),
            'terms'        => $quotation->terms ? [['label' => 'Terms', 'text' => $quotation->terms]] : $company['default_terms'],
            'filename'     => "{$quotation->quotation_number}.pdf",
        ]);
    }

    public function invoicePdf(Invoice $invoice): Response
    {
        $invoice->loadMissing(['lineItems', 'hospital']);
        $company = config('company');

        $items = $invoice->lineItems->values()->map(fn ($i, $idx) => [
            $idx + 1, $i->description, '-', '-', '-',
            $this->trimmedQuantity($i->quantity), number_format($i->unit_price, 2),
            number_format($i->total, 2),
        ])->all();

        $client = array_values(array_filter([
            $invoice->hospital?->name ?? $invoice->client_name, $invoice->client_contact, $invoice->client_email,
        ]));

        return $this->render([
            'title'        => 'INVOICE',
            'docLabel'     => 'Invoice No:',
            'docNumber'    => $invoice->invoice_number,
            'date'         => ($invoice->issue_date ?? now())->format('d M Y'),
            'tag'          => 'INVOICE · DUE ' . ($invoice->due_date?->format('d M Y') ?? 'N/A'),
            'client'       => $client,
            'items'        => $items,
            'subtotal'     => number_format($invoice->subtotal, 2),
            'discount'     => '0.00',
            'tax'          => number_format($invoice->tax_amount, 2),
            'total'        => number_format($invoice->total, 2),
            'currencyCode' => $this->currencyPrefix($invoice->currency),
            'currency'     => $this->currencyLabel($invoice->currency),
            'terms'        => $company['default_terms'],
            'filename'     => "{$invoice->invoice_number}.pdf",
        ]);
    }

    public function hrReportPdf(array $data): PdfInstance
    {
        return Pdf::loadView('pdf.hr_report', array_merge($data, [
            'company' => config('company'),
        ]))->setPaper('a4');
    }

    // Section 7: "the plan PDF always reflects the latest version, marks
    // changed days, and includes the revision history." Takes the model
    // directly (not a pre-shaped array like hrReportPdf) since there's no
    // separate report-building step — the plan's own current state and
    // relations are the whole document.
    public function perDiemPdf(\App\Models\PerDiemRequest $perDiemRequest, bool $viewerCanSeeFullPayment = false): PdfInstance
    {
        $perDiemRequest->loadMissing([
            'user', 'lines', 'reviewer', 'teamLeadReviewer', 'paymentInitiatedBy', 'paidBy',
            'revisions.editor', 'adjustments.createdBy',
        ]);

        // Section 15.4/15.5: same layout, order and computed numbers as
        // the XLSX export and in-app viewer, so the three never disagree.
        return Pdf::loadView('pdf.per_diem', [
            'plan'             => $perDiemRequest,
            'company'          => config('company'),
            'summary'          => $perDiemRequest->summary(),
            'signatureBlock'   => app(PerDiemSignatureBlockService::class)->build($perDiemRequest),
            'canSeeFullPayment' => $viewerCanSeeFullPayment,
        ])->setPaper('a4');
    }

    public function payslipPdf(array $data): PdfInstance
    {
        return Pdf::loadView('pdf.payslip', array_merge($data, [
            'company' => config('company'),
        ]))->setPaper('a4');
    }

    // The items table's QTY column is narrow (design-matched to the
    // prototype's whole-number sample data), so a flat 2-decimal format
    // wraps onto two lines for common whole quantities — drop trailing
    // zeros instead of always showing ".00".
    private function trimmedQuantity(float $qty): string
    {
        return rtrim(rtrim(number_format($qty, 2), '0'), '.');
    }

    private function currencyPrefix(?string $code): string
    {
        return $code && $code !== 'TZS' ? $code : 'TSh';
    }

    private function currencyLabel(?string $code): string
    {
        return match ($code) {
            'USD' => 'USD (US Dollar)',
            'EUR' => 'EUR (Euro)',
            'KES' => 'KES (Kenyan Shilling)',
            default => 'TSh (Tanzanian Shilling)',
        };
    }

    /**
     * @param array{title:string,docLabel:string,docNumber:string,date:string,tag:string,client:array,
     *              items:array,subtotal:string,discount:string,tax:string,total:string,
     *              currencyCode:string,currency:string,terms:array,filename:string} $d
     */
    private function render(array $d): Response
    {
        $letterhead = config('company')['letterhead'];
        $banks = config('company')['banks'];
        $logosDir = resource_path('pdf-assets/logos');
        $fontsDir = resource_path('pdf-assets/fonts');

        // The real letterhead phone lives inside address_lines (prefixed
        // "Mobile phone: "); the top-level company.phone config resolves to
        // an unset-env placeholder ("+255 XXX XXX XXX"), so it isn't used here.
        $phone = preg_replace('/^Mobile phone:\s*/', '', $letterhead['address_lines'][6] ?? '');
        $phone = preg_replace('/^\+\s+/', '+', $phone);
        $email = config('company')['email'];

        $addrLine1 = $letterhead['address_lines'][2] . ' &middot; ' . $letterhead['address_lines'][1];
        $addrLine2 = $letterhead['address_lines'][0] . ', ' . $letterhead['address_lines'][3] . ' &middot; ' . $letterhead['address_lines'][4];

        // (A) STYLES — ported from the invoice.php mPDF prototype. Its
        // ID-descendant-selector gotcha carries over unchanged: a <div>
        // nested inside a <td> is NOT reachable through '#id .class', only
        // through a bare page-unique class — so table-structural rules
        // (background/border/padding/alignment) stay ID-scoped on td/th/tr,
        // and every div's text styling uses a flat classname instead.
        $style = "
        <style>
          html, body { font-family: tildasans, dejavusans, helvetica, arial, sans-serif; color:#1d1f20; font-size:9.5pt; }
          #sheet { width:100%; }
          table { width:100%; border-collapse:collapse; }
          #body { padding:6mm 12mm 6mm; }

          #titlerow td { vertical-align:top; padding:0; }
          .doctitle { font-family:barlowcondensed,tildasans,dejavusans,sans-serif; font-weight:700; font-size:40px; letter-spacing:-1px; line-height:1; margin-top:2px; color:#1d1f20; }
          #tag { border:1px solid #597ea3; width:auto; margin-top:10px; }
          #tag td { padding:8px; color:#416180; font-weight:700; font-size:7.5pt; letter-spacing:0.8px; white-space:nowrap; }
          #invbox { border:1px solid #1d1f20; width:165px; }
          #invbox td { padding:14px 16px; }
          .invbox-lbl { font-weight:700; font-size:7pt; letter-spacing:1px; color:#416180; float:left; }
          .invbox-num { font-weight:700; font-size:15pt; margin-top:3px; white-space:nowrap; color:#1d1f20; }

          #meta { margin-top:22px; border-top:1px solid #1d1f20; border-bottom:1px solid #ccc; }
          #meta td { vertical-align:top; padding:12px 10px 12px 0; font-size:9pt; }
          .meta-lbl { font-weight:700; font-size:7pt; letter-spacing:1px; color:#416180; margin-bottom:4px; }
          .meta-bigval { font-weight:700; font-size:11pt; }
          .meta-val { font-size:9.5pt; white-space:nowrap; }

          #items { margin-top:18px; }
          #items th { text-align:left; font-size:7pt; font-weight:700; letter-spacing:0.5px; padding:8px; background:#e7e7ea; border-bottom:1px solid #1d1f20; white-space:nowrap; }
          #items td { padding:9px 8px; font-size:9pt; border-bottom:1px solid #ddd; vertical-align:top; }
          #items th.num, #items td.num { text-align:right; }
          #items td.dim { color:#7a7a7d; white-space:nowrap; }

          #totals { width:45%; margin-left:55%; margin-top:14px; }
          #totals td { padding:4px 0; font-size:9.5pt; white-space:nowrap; }
          #totals td.lbl { width:50%; text-align:right; padding-right:8px; border-bottom:1px solid #ddd; }
          #totals td.val { width:50%; text-align:right; font-weight:700; border-bottom:1px solid #ddd; }
          #totals tr.grand td { border-top:2px solid #1d1f20; padding-top:9px; font-size:11pt; font-weight:700; }

          #terms { margin-top:28px; }
          .terms-h { font-weight:700; font-size:7pt; letter-spacing:1px; color:#416180; padding-bottom:6px; border-bottom:1px solid #1d1f20; }
          #termsrow td { vertical-align:top; padding:12px 12px 0 0; font-size:8.5pt; line-height:1.55; }
          .terms-t { font-weight:700; margin-bottom:3px; font-family:barlowcondensed,tildasans,dejavusans,sans-serif; }

          #footrow { margin-top:26px; }
          #footrow td { padding:0; font-size:8.5pt; }
          .bankcell { width:64%; vertical-align:top; padding-right:16px; }
          .bankcol-lbl { font-weight:700; font-size:7pt; letter-spacing:1px; color:#416180; }
          .banktable { margin-top:8px; border-top:1px solid #ccc; width:100%; }
          .banktable td { vertical-align:top; padding:7px 10px 7px 0; border-bottom:1px solid #ccc; font-size:8.5pt; }
          .sigcell { width:36%; vertical-align:bottom; text-align:right; }
          .sigline-table { width:220px; border-collapse:collapse; }
          .sigline-cell { border-bottom:1px solid #1d1f20; height:40px; padding:0; font-size:1px; line-height:1px; }
          .sigcol-lbl { font-weight:700; font-size:7pt; letter-spacing:1px; color:#416180; margin-top:70px; }
        </style>";

        // (B) HEADER — dark navy band, repeats on every page via SetHTMLHeader().
        // Self-contained inline styles (no dependency on the main <style> block).
        $headerHtml = "<table style='width:100%; border-collapse:collapse; background:#1d2d3d;'><tr>";
        $headerHtml .= "<td style='width:9%; padding:13px 12mm; vertical-align:middle;'>"
            . "<img src='{$logosDir}/hypermed_icon.png' height='30' width='auto'/></td>";
        $headerHtml .= "<td style='width:51%; padding:13px 0; vertical-align:middle;'>";
        $headerHtml .= "<div style='font-family:barlowcondensed,tildasans,dejavusans,sans-serif; font-weight:700;"
            . " font-size:16pt; letter-spacing:0.3px; color:#f2f2f3;'><strong>" . e($letterhead['name_header']) . '</strong></div>';
        $headerHtml .= "<div style='font-family:barlowcondensed,tildasans,dejavusans,sans-serif; font-size:8pt;"
            . " letter-spacing:1px; color:#9fb3c8; margin-top:4px;'><strong>" . e($letterhead['tin']) . ' &middot; ' . e($d['title']) . ' ' . e($d['docNumber']) . '</strong></div>';
        $headerHtml .= '</td>';
        $headerHtml .= "<td style='width:40%; padding:13px 12mm; text-align:right; vertical-align:middle;"
            . " font-family:tildasans,dejavusans,sans-serif; font-size:8.5pt; color:#d7dee6; line-height:1.7;'>"
            . e($phone) . '<br>' . e($email) . '</td>';
        $headerHtml .= '</tr></table>';

        // (C) FOOTER — company recap band, repeats on every page via SetHTMLFooter().
        $footerHtml = "<table style='width:100%; border-collapse:collapse; font-family:tildasans,dejavusans,sans-serif;"
            . " font-size:8pt; color:#5d5d60;'>";
        $footerHtml .= "<tr><td colspan='3' style='border-top:1px solid #1d1f20; padding:0; font-size:1px; line-height:1px;'>&nbsp;</td></tr>";
        $footerHtml .= '<tr>';
        $footerHtml .= "<td style='width:34%; border-right:1px solid #ccc; padding:9px 14px 0 12mm; vertical-align:top;'>";
        $footerHtml .= "<table style='width:100%; border-collapse:collapse;'><tr>";
        $footerHtml .= "<td style='width:20px; vertical-align:top; padding:0;'>"
            . "<img src='{$logosDir}/hypermed_icon.png' height='15' width='auto'/></td>";
        $footerHtml .= "<td style='vertical-align:top; padding:0 0 0 6px;'>"
            . "<div style='font-weight:700; font-size:8.5pt; color:#1d1f20; white-space:nowrap;'>" . e($letterhead['name_header']) . '</div>'
            . '<div>' . e($letterhead['tin']) . '</div></td>';
        $footerHtml .= '</tr></table>';
        $footerHtml .= '</td>';
        $footerHtml .= "<td style='width:38%; border-right:1px solid #ccc; padding:9px 14px 0; vertical-align:top;'>"
            . $addrLine1 . '<br>' . $addrLine2 . '</td>';
        $footerHtml .= "<td style='width:28%; text-align:right; vertical-align:top; padding:9px 12mm 0 14px;'>"
            . "<div style='font-weight:700; color:#416180;'>" . e($d['title']) . ' ' . e($d['docNumber']) . '</div>'
            . '<div>' . e($d['date']) . ' &middot; www.hypermed.co.tz</div></td>';
        $footerHtml .= '</tr></table>';

        // (D) BODY
        $html = "<!DOCTYPE html><html><head>{$style}</head><body><div id='sheet'>";
        $html .= "<div id='body'>";

        // title row
        $html .= "<table id='titlerow'><tr>";
        $html .= "<td style='width:60%'>";
        $html .= "<div class='doctitle'><strong>" . e($d['title']) . '</strong></div>';
        $html .= "<table id='tag'><tr><td><strong>" . e($d['tag']) . '</strong></td></tr></table>';
        $html .= '</td>';
        $html .= "<td style='width:40%; text-align:right'>";
        $html .= "<table id='invbox' style='margin-left:auto; text-align:left'><tr><td>";
        $html .= "<div class='invbox-lbl'><strong>" . e(strtoupper(rtrim($d['docLabel'], ':')) . '.') . '</strong></div>';
        $html .= "<div class='invbox-num'><strong>" . e($d['docNumber']) . '</strong></div>';
        $html .= '</td></tr></table>';
        $html .= '</td>';
        $html .= '</tr></table>';

        // meta strip
        $html .= "<table id='meta'><tr>";
        $html .= "<td style='width:45%'>";
        $html .= "<div class='meta-lbl'><strong>BILLED TO</strong></div><br>";
        $html .= "<div class='meta-bigval'><strong>" . e($d['client'][0] ?? '—') . '</strong></div>';
        if (count($d['client']) > 1) {
            $html .= "<div style='margin-top:2px'>" . e(implode(' · ', array_slice($d['client'], 1))) . '</div>';
        }
        $html .= '</td>';
        $html .= "<td style='width:27%'>";
        $html .= "<div class='meta-lbl'><strong>DATE ISSUED</strong></div><br>";
        $html .= "<div class='meta-val'>" . e($d['date']) . '</div>';
        $html .= '</td>';
        $html .= "<td style='width:28%'>";
        $html .= "<div class='meta-lbl'><strong>CURRENCY</strong></div><br>";
        $html .= "<div class='meta-val'>" . e($d['currency']) . '</div>';
        $html .= '</td>';
        $html .= '</tr></table>';

        // items — clean-line rows
        $html .= "<table id='items'><tr>
            <th style='width:6%'>#</th><th style='white-space:normal'>DESCRIPTION</th>
            <th style='width:11%'>LOT</th><th style='width:12%'>EXP.</th><th style='width:7%'>UOM</th>
            <th class='num' style='width:6%'>QTY</th><th class='num' style='width:13%'>UNIT PRICE</th>
            <th class='num' style='width:13%'>AMOUNT</th>
          </tr>";
        foreach ($d['items'] as $i) {
            $html .= '<tr><td class="dim">' . e($i[0]) . '</td><td style="font-weight:500">' . e($i[1]) . '</td>'
                . '<td class="dim">' . e($i[2]) . '</td><td class="dim">' . e($i[3]) . '</td><td>' . e($i[4]) . '</td>'
                . '<td class="num">' . e($i[5]) . '</td><td class="num">' . e($i[6]) . '</td>'
                . '<td class="num" style="font-weight:700">' . e($i[7]) . '</td></tr>';
        }
        $html .= '</table>';

        // totals
        $html .= "<table id='totals' align='right'>";
        $html .= "<tr><td class='lbl' style='width:90%; text-align:left'>Subtotal</td><td class='val'>" . e($d['currencyCode']) . ' ' . e($d['subtotal']) . '</td></tr>';
        $html .= "<tr><td class='lbl' style='width:90%; text-align:left'>Discount</td><td class='val'>" . e($d['currencyCode']) . ' ' . e($d['discount']) . '</td></tr>';
        if ($d['tax'] !== '0.00') {
            $html .= "<tr><td class='lbl' style='width:90%; text-align:left'>Tax</td><td class='val'>" . e($d['currencyCode']) . ' ' . e($d['tax']) . '</td></tr>';
        }
        $html .= "<tr class='grand'><td class='lbl' style='width:90%; text-align:left'>Total Due</td><td class='val'><strong>" . e($d['currencyCode']) . ' ' . e($d['total']) . '</strong></td></tr>';
        $html .= '</table>';

        // terms
        $html .= "<div id='terms'><div class='terms-h'><strong>TERMS &amp; CONDITIONS</strong></div>";
        $html .= "<table id='termsrow'><tr>";
        foreach ($d['terms'] as $t) {
            $html .= "<td style='width:" . round(100 / count($d['terms'])) . "%'>";
            $html .= "<div class='terms-t'><strong>" . e($t['label']) . '</strong></div><div>' . e($t['text']) . '</div>';
            $html .= '</td>';
        }
        $html .= '</tr></table></div>';

        // footer: bank info (left) + signature (right), signature bottom-aligned
        // so it lines up horizontally with the last bank row
        $html .= "<table id='footrow'><tr>";
        $html .= "<td class='bankcell'>";
        $html .= "<div class='bankcol-lbl'>PAYMENTS SHOULD BE MADE DIRECTLY TO</div>";
        $html .= "<table class='banktable'>";
        $html .= "<tr><td style='width:50%'><strong>Account name</strong><br>" . e($letterhead['name_payee']) . '</td>'
            . "<td style='width:50%'><strong>" . e($banks[0]['name']) . '</strong><br>' . e($banks[0]['currency']) . ' &middot; ' . e($banks[0]['account']) . '</td></tr>';
        if (isset($banks[1])) {
            $html .= "<tr><td></td><td><strong>" . e($banks[1]['name']) . '</strong><br>' . e($banks[1]['currency']) . ' &middot; ' . e($banks[1]['account']) . '</td></tr>';
        }
        $html .= '</table>';
        $html .= '</td>';
        $html .= "<td class='sigcell'>";
        // A <div> with margin-left:auto silently fails to right-align in mPDF, so
        // the signature line uses the same align='right' table trick #totals
        // already relies on above, instead of a div.
        $html .= "<table align='right' class='sigline-table'><tr><td class='sigline-cell'>&nbsp;</td></tr></table>";
        $html .= "<div class='sigcol-lbl'>AUTHORISED SIGNATURE &amp; STAMP</div>";
        $html .= '</td>';
        $html .= '</tr></table>';

        $html .= '</div>'; // #body
        $html .= '</div></body></html>';

        // (E) RENDER
        // Embed TildaSans (single static Regular weight) and Barlow Condensed
        // as custom mPDF fonts — merging into the default fontDir/fontdata
        // arrays, since mPDF requires that rather than a bare font-family
        // name in CSS. mPDF lowercases the CSS font-family before looking it
        // up here, so the key MUST be lowercase or the lookup silently falls
        // back to the next font in the stack.
        $defaultFontDirs = (new \Mpdf\Config\ConfigVariables())->getDefaults()['fontDir'];
        $defaultFontData = (new \Mpdf\Config\FontVariables())->getDefaults()['fontdata'];

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            // margin_top/margin_bottom reserve the page space the repeating
            // header/footer render into; margin_header/margin_footer position
            // the header/footer content within that reserved space (0 = flush
            // to the page edge, matching the header band's full-bleed look).
            'margin_top' => 24,
            'margin_bottom' => 26,
            'margin_header' => 0,
            'margin_footer' => 10,
            'margin_left' => 0,
            'margin_right' => 0,
            'tempDir' => storage_path('app/mpdf-tmp'),
            'fontDir' => array_merge($defaultFontDirs, [$fontsDir]),
            'fontdata' => $defaultFontData + [
                'tildasans' => [
                    'R' => 'TildaSans.ttf',
                ],
                'barlowcondensed' => [
                    'R' => 'BarlowCondensed-Regular.ttf',
                    'B' => 'BarlowCondensed-Bold.ttf',
                    'I' => 'BarlowCondensed-Italic.ttf',
                    'BI' => 'BarlowCondensed-BoldItalic.ttf',
                ],
            ],
        ]);

        // Faint background watermark within the body area between the navy
        // header band (top 24mm) and the footer recap (bottom 26mm) — the
        // header/footer bands have solid backgrounds anyway so a watermark
        // placed under them wouldn't be visible there.
        $mpdf->SetWatermarkImage("{$logosDir}/hypermed_icon.png", 0.06, [140, 86], [35, 104]);
        $mpdf->showWatermarkImage = true;

        $mpdf->SetHTMLHeader($headerHtml);
        $mpdf->SetHTMLFooter($footerHtml);
        $mpdf->WriteHTML($html);

        return response($mpdf->Output($d['filename'], \Mpdf\Output\Destination::STRING_RETURN), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $d['filename'] . '"',
        ]);
    }
}
