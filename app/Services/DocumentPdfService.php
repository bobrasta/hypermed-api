<?php

namespace App\Services;

use App\Support\DocumentTerms;
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

        // Line discount = list amount less the line total (covers both the
        // older percentage and the TSh discount).
        $lineDiscount = fn ($i) => max(0, (int) round($i->quantity * $i->unit_price) - (int) $i->total_price);
        $items = $quotation->items->values()->map(fn ($i, $idx) => [
            $idx + 1, $i->description, '-', '-', $i->unit_of_measure ?: '-',
            $this->trimmedQuantity($i->quantity), number_format($i->unit_price, 2),
            number_format($i->total_price, 2), $lineDiscount($i),
        ])->all();
        $lineDiscounts = $quotation->items->sum($lineDiscount);

        $client = array_values(array_filter([$quotation->client_name, $quotation->client_contact, $quotation->client_email,
            $quotation->client_tin ? 'TIN: ' . $quotation->client_tin : null]));

        return $this->render([
            'title'        => 'PROFORMA INVOICE',
            'docLabel'     => 'Proforma No:',
            'docNumber'    => $quotation->quotation_number,
            'date'         => ($quotation->created_at ?? now())->format('d M Y, H:i'),
            'tag'          => 'QUOTATION · VALID UNTIL ' . ($quotation->valid_until?->format('d M Y') ?? 'N/A'),
            'client'       => $client,
            'clientAddress'=> array_values(array_filter([$quotation->lead?->hospital?->address])),
            'items'        => $items,
            'subtotal'     => number_format($quotation->subtotal + $lineDiscounts, 2),
            'discount'     => number_format($quotation->discount_amount + $lineDiscounts, 2),
            'tax'          => number_format($quotation->tax_amount, 2),
            'total'        => number_format($quotation->total_amount, 2),
            'currencyCode' => $this->currencyPrefix($quotation->currency),
            'currency'     => $this->currencyLabel($quotation->currency),
            'terms'        => DocumentTerms::resolve($quotation->term_items, $quotation->terms),
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
            number_format($i->total, 2), (int) $i->discount,
        ])->all();
        $lineDiscounts = (int) $invoice->lineItems->sum('discount');

        $client = array_values(array_filter([
            $invoice->hospital?->name ?? $invoice->client_name, $invoice->client_contact, $invoice->client_email,
            ($invoice->client_tin ?? $invoice->hospital?->tin) ? 'TIN: ' . ($invoice->client_tin ?? $invoice->hospital?->tin) : null,
        ]));

        $kind = match ($invoice->status) { 'proforma' => 'PROFORMA INVOICE', 'draft' => 'DRAFT INVOICE', default => 'INVOICE' };

        return $this->render([
            'title'        => $kind,
            'docLabel'     => $kind === 'PROFORMA INVOICE' ? 'Proforma No:' : 'Invoice No:',
            'docNumber'    => $invoice->invoice_number,
            'date'         => ($invoice->issue_date ?? now())->format('d M Y'),
            'tag'          => $kind . ' · DUE ' . ($invoice->due_date?->format('d M Y') ?? 'N/A')
                . ($invoice->pay_term_number !== null ? " · TERMS {$invoice->pay_term_number} " . strtoupper($invoice->pay_term_type ?? 'days') : ''),
            'client'       => $client,
            'clientAddress'=> array_values(array_filter([$invoice->hospital?->address])),
            'items'        => $items,
            // Subtotal before line discounts, then the discounts.
            'subtotal'     => number_format($invoice->subtotal + $lineDiscounts, 2),
            'discount'     => number_format($lineDiscounts, 2),
            'tax'          => number_format($invoice->tax_amount, 2),
            'shipping'     => number_format((int) $invoice->shipping_charges, 2),
            'total'        => number_format($invoice->total, 2),
            // Credit sales: show what's been paid so far and what's left.
            'paid'         => $invoice->amount_paid > 0 ? number_format($invoice->amount_paid, 2) : null,
            'balance'      => $invoice->amount_paid > 0 ? number_format($invoice->balance_due, 2) : null,
            'currencyCode' => $this->currencyPrefix($invoice->currency),
            'currency'     => $this->currencyLabel($invoice->currency),
            'terms'        => DocumentTerms::resolve($invoice->term_items),
            'filename'     => "{$invoice->invoice_number}.pdf",
        ]);
    }

    public function deliveryNotePdf(Invoice $invoice): Response
    {
        $invoice->loadMissing(['lineItems', 'hospital']);

        $client = array_values(array_filter([
            $invoice->hospital?->name ?? $invoice->client_name, $invoice->client_contact, $invoice->client_email,
            ($invoice->client_tin ?? $invoice->hospital?->tin) ? 'TIN: ' . ($invoice->client_tin ?? $invoice->hospital?->tin) : null,
        ]));

        return $this->render([
            'delivery'      => true,
            'title'         => 'DELIVERY NOTE',
            'docLabel'      => 'Delivery Note No:',
            'docNumber'     => 'DN-' . $invoice->invoice_number,
            'invoiceNumber' => $invoice->invoice_number,
            'date'          => now()->format('d M Y'),
            'tag'           => 'DELIVERY NOTE · INVOICE ' . $invoice->invoice_number
                . ($invoice->shipping_status ? ' · ' . strtoupper($invoice->shipping_status) : ''),
            'client'        => $client,
            'clientAddress' => array_values(array_filter([$invoice->shipping_address ?: $invoice->hospital?->address])),
            'items'         => $invoice->lineItems->values()->map(fn ($i, $idx) => [
                $idx + 1, $i->description, $this->trimmedQuantity($i->quantity),
            ])->all(),
            'shippingDetails' => $invoice->shipping_details,
            'deliveredTo'     => $invoice->delivered_to,
            'currency'      => '',
            'filename'      => "DN-{$invoice->invoice_number}.pdf",
        ]);
    }

    /** Items (quantities only), delivery details and receipt signatures. */
    private function deliveryNoteBody(array $d): string
    {
        $html = "<table id='items'><tr>
            <th style='width:6%'>#</th><th style='white-space:normal'>DESCRIPTION</th>
            <th class='num' style='width:14%'>QTY ORDERED</th><th class='num' style='width:16%'>QTY RECEIVED</th></tr>";
        foreach ($d['items'] as $i) {
            $html .= '<tr><td class="dim">' . e($i[0]) . '</td><td style="font-weight:500">' . e($i[1]) . '</td>'
                . '<td class="num" style="font-weight:700">' . e($i[2]) . '</td><td class="num">&nbsp;</td></tr>';
        }
        $html .= '</table>';

        $facts = array_filter([
            'Delivered to' => $d['deliveredTo'] ?? null,
            'Shipping details' => $d['shippingDetails'] ?? null,
        ]);
        if ($facts) {
            $html .= "<div id='terms'><div class='terms-h'><strong>DELIVERY DETAILS</strong></div><table id='termsrow'><tr>";
            foreach ($facts as $label => $text) {
                $html .= "<td style='width:50%'><div class='terms-t'><strong>" . e($label) . '</strong></div><div>' . nl2br(e($text)) . '</div></td>';
            }
            $html .= '</tr></table></div>';
        }

        $html .= "<div style='margin-top:10mm; font-size:8.5pt; color:#5d5d60;'>Goods received in good order and condition.</div>"
            . "<table style='margin-top:14mm'><tr>";
        foreach (['DELIVERED BY (NAME & SIGNATURE)', 'RECEIVED BY (NAME & SIGNATURE)', 'DATE & STAMP'] as $label) {
            $html .= "<td style='width:33%; padding-right:6mm;'><table class='sigline-table'><tr><td class='sigline-cell'>&nbsp;</td></tr>"
                . "<tr><td class='sigcol-lbl'><strong>{$label}</strong></td></tr></table></td>";
        }

        return $html . '</tr></table>';
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
     * Hairline rule with small accent '+' ticks at each end (the reference
     * design's corner marks, rebuilt as a table — mPDF ignores
     * position:absolute in flowed content). The '+' cells span two short
     * rows and the rule is the border between them, so it runs through the
     * middle of each '+'.
     */
    private function plusRule(string $sidePad = '0', string $marginBottom = '0'): string
    {
        $plus = "style='width:3mm; padding:0; font-family:dejavusans,sans-serif; font-size:8pt;"
            . " line-height:1; color:#597ea3; text-align:center; vertical-align:middle;'";
        $top = 'padding:0; height:1.7mm; font-size:1px; line-height:1px;';
        $bot = 'padding:0; height:0.9mm; font-size:1px; line-height:1px;';

        return "<div style='padding:0 {$sidePad}; margin-bottom:{$marginBottom};'>"
            . "<table style='width:100%; border-collapse:collapse;'>"
            . "<tr><td rowspan='2' {$plus}>+</td>"
            . "<td style='width:100%; {$top} border-bottom:1px solid #1d1f20;'>&nbsp;</td>"
            . "<td rowspan='2' {$plus}>+</td></tr>"
            . "<tr><td style='{$bot}'>&nbsp;</td></tr></table></div>";
    }

    /**
     * Quotation / invoice PDF — the "Proforma Invoice v3" design from the
     * me/system/invoice.php mPDF testbed: slim navy header band, a large
     * lockup letterhead with the company's full details, '+'-ticked rules,
     * big condensed title, bordered number box, clean-line items, lockup
     * watermark and a company-recap footer.
     *
     * mPDF gotcha carried over from the testbed: a <div> inside a <td> is
     * NOT reachable through an ID-scoped descendant selector ('#id .class'
     * silently no-ops), so table structure is styled via '#id td/th' and
     * every div's text styling uses a flat, page-unique class.
     *
     * @param array{title:string,docLabel:string,docNumber:string,date:string,tag:string,client:array,
     *              clientAddress?:array,items:array,subtotal:string,discount:string,tax:string,total:string,
     *              shipping?:string,paid?:?string,balance?:?string,
     *              currencyCode:string,currency:string,terms:array,filename:string} $d
     */
    private function render(array $d): Response
    {
        $company = config('company');
        $lh = $company['letterhead'];
        $banks = $company['banks'];
        $logosDir = resource_path('pdf-assets/logos');
        $fontsDir = resource_path('pdf-assets/fonts');
        $condensed = 'barlowcondensed,tildasans,dejavusans,sans-serif';

        // Letterhead facts, all from config('company.letterhead') — the
        // company's own printed documents. address_lines order:
        // 0 area, 1 road/plot, 2 building, 3 P.O. Box, 4 city, 5 email,
        // 6 mobile, 7 tel, 8 website.
        $a = $lh['address_lines'];
        $strip = fn ($s) => trim(preg_replace('/^[A-Za-z ]+:\s*/', '', (string) $s));
        $title = fn ($s) => ucwords(strtolower((string) $s));
        $mobile = preg_replace('/^\+\s+/', '+', $strip($a[6] ?? ''));
        $tel = preg_replace('/^\+\s+/', '+', $strip($a[7] ?? ''));
        $email = $strip($a[5] ?? $company['email']);
        $web = $strip($a[8] ?? 'www.hypermed.co.tz');
        $tin = 'TIN ' . preg_replace('/\s*-\s*/', '-', $strip($lh['tin']));
        $shortName = 'HYPERMED HEALTHCARE LTD';
        $docRef = e($d['title']) . ' ' . e($d['docNumber']);
        $addr = $lh['display_lines'];
        $delivery = ! empty($d['delivery']);

        // (A) STYLES
        $style = "
        <style>
          html, body { font-family: tildasans, dejavusans, helvetica, arial, sans-serif; color:#1d1f20; font-size:9.5pt; }
          #sheet { width:100%; }
          table { width:100%; border-collapse:collapse; }
          #body { padding:7mm 12mm 6mm; }

          /* letterhead: big lockup + TIN (left), full company details (right) */
          #letterhead td { padding:0 0 6mm; }
          .lh-tin { font-family:{$condensed}; font-weight:700; font-size:18pt; letter-spacing:0.5px; white-space:nowrap; margin-top:2mm; color:#1d1f20; }
          .lh-name { font-family:{$condensed}; font-weight:700; font-size:21pt; letter-spacing:0.3px; line-height:1.05; color:#1d1f20; }
          .lh-tag { font-family:{$condensed}; font-weight:700; font-size:9pt; letter-spacing:1.2px; color:#416180; margin-top:2px; }
          .lh-addr { font-size:9.5pt; line-height:1.6; color:#5d5d60; }
          .lh-contact { font-size:9.5pt; line-height:1.6; color:#1d1f20; }

          /* title row: big condensed title + bordered number box */
          #titlerow td { vertical-align:top; padding:0; }
          .doctitle { font-family:{$condensed}; font-weight:700; font-size:40px; letter-spacing:-1px; line-height:1; margin-top:2px; color:#1d1f20; }
          #tag { border:1px solid #597ea3; width:auto; margin-top:10px; }
          #tag td { padding:8px; color:#416180; font-weight:700; font-size:7.5pt; letter-spacing:0.8px; white-space:nowrap; }
          #invbox { border:1px solid #1d1f20; width:165px; }
          #invbox td { padding:12px 14px; }
          .invbox-lbl { font-weight:700; font-size:7pt; letter-spacing:1px; color:#416180; }
          .invbox-num { font-family:{$condensed}; font-weight:700; font-size:16pt; margin-top:3px; white-space:nowrap; color:#1d1f20; }

          /* meta strip: Billed To / Date / Currency */
          #meta { margin-top:22px; border-top:1px solid #1d1f20; border-bottom:1px solid #ccc; }
          #meta td { vertical-align:top; padding:12px 10px 12px 0; font-size:9pt; }
          .meta-lbl { font-weight:700; font-size:7pt; letter-spacing:1px; color:#416180; margin-bottom:4px; }
          .meta-bigval { font-family:{$condensed}; font-weight:700; font-size:15pt; }
          .meta-val { font-size:10.5pt; white-space:nowrap; }

          /* items: clean-line rows */
          #items { margin-top:20px; }
          #items th { text-align:left; font-size:7pt; font-weight:700; letter-spacing:0.5px; padding:8px; background:#e7e7ea; border-bottom:1px solid #1d1f20; white-space:nowrap; }
          #items td { padding:9px 8px; font-size:9pt; border-bottom:1px solid #ddd; vertical-align:top; }
          #items th.num, #items td.num { text-align:right; }
          #items td.dim { color:#7a7a7d; white-space:nowrap; }

          #totals { width:48%; margin-top:14px; }
          #totals td { padding:6px 0; font-size:9.5pt; white-space:nowrap; border-bottom:1px solid #ddd; }
          #totals td.lbl { text-align:left; }
          #totals td.val { text-align:right; }
          #totals tr.muted td { color:#5d5d60; }
          #totals tr.grand td { border-bottom:none; border-top:2px solid #1d1f20; padding-top:9px; vertical-align:bottom; }
          .grand-lbl { font-weight:700; font-size:7pt; letter-spacing:1px; color:#1d1f20; }
          .grand-val { font-family:{$condensed}; font-weight:700; font-size:19pt; white-space:nowrap; }
          .bal-val { font-family:{$condensed}; font-weight:700; font-size:15pt; white-space:nowrap; color:#b45309; }

          #terms { margin-top:30px; }
          .terms-h { font-weight:700; font-size:7pt; letter-spacing:1px; color:#416180; padding-bottom:6px; border-bottom:1px solid #1d1f20; }
          #termsrow td { vertical-align:top; padding:10px 14px 0 0; font-size:8.5pt; line-height:1.5; color:#5d5d60; }
          .terms-t { font-family:{$condensed}; font-weight:700; font-size:9.5pt; margin-bottom:2px; color:#1d1f20; }

          /* payment note + bank columns (left), signature (right), both bottom-aligned */
          #footrow { margin-top:24px; }
          #footrow td { padding:0; font-size:8.5pt; }
          .bankcell { width:64%; vertical-align:bottom; padding-right:22px; }
          .banknote { font-size:8pt; color:#7a7a7d; }
          .banktable { margin-top:10px; border-top:1px solid #ccc; width:100%; }
          .banktable td { vertical-align:top; padding:9px 16px 0 0; font-size:8.5pt; line-height:1.5; white-space:nowrap; }
          .bankcol-lbl { font-weight:700; font-size:7pt; letter-spacing:1px; color:#416180; }
          .sigcell { width:36%; vertical-align:bottom; }
          .sigline-table { width:100%; border-collapse:collapse; }
          .sigline-table td.sigline-cell { border-bottom:1px solid #1d1f20; padding:40px 0 0; font-size:1px; line-height:1px; }
          .sigline-table td.sigcol-lbl { padding:5px 0 0; font-weight:700; font-size:7pt; letter-spacing:1px; color:#416180; white-space:nowrap; }
        </style>";

        // (B) HEADER — slim navy band, repeats on every page.
        $headerHtml = "<table style='width:100%; border-collapse:collapse; background:#1d2d3d;'><tr>"
            . "<td style='width:12mm; padding:7px 0 7px 12mm; vertical-align:middle;'><img src='{$logosDir}/hypermed_icon.png' height='22' width='auto'/></td>"
            . "<td style='padding:7px 0 7px 8px; vertical-align:middle; font-family:{$condensed}; font-weight:700; font-size:11.5pt; letter-spacing:0.5px; color:#f2f2f3;'><strong>{$shortName}</strong></td>"
            . "<td style='padding:7px 12mm 7px 0; text-align:right; vertical-align:middle; white-space:nowrap; font-family:tildasans,dejavusans,sans-serif; font-weight:700; font-size:7.5pt; letter-spacing:1.2px; color:#b5d9fd;'><strong>{$docRef}</strong></td>"
            . '</tr></table>';

        // (C) FOOTER — company recap, repeats on every page.
        $footerHtml = $this->plusRule('12mm');
        $footerHtml .= "<table style='width:100%; border-collapse:collapse; font-family:tildasans,dejavusans,sans-serif; font-size:8pt; color:#5d5d60;'><tr>";
        $footerHtml .= "<td style='width:29%; border-right:1px solid #ccc; padding:5px 14px 0 12mm; vertical-align:top;'>"
            . "<table style='width:100%; border-collapse:collapse;'><tr>"
            . "<td style='width:26px; vertical-align:top; padding:0;'><img src='{$logosDir}/hypermed_icon.png' height='18' width='auto'/></td>"
            . "<td style='vertical-align:top; padding:0 0 0 6px;'><div style='font-family:{$condensed}; font-weight:700; font-size:9pt; letter-spacing:0.4px; color:#1d1f20; white-space:nowrap;'><strong>{$shortName}</strong></div>"
            . '<div>' . e($tin) . '</div></td></tr></table></td>';
        $footerHtml .= "<td style='width:40%; border-right:1px solid #ccc; padding:5px 14px 0; white-space:nowrap; vertical-align:top; line-height:1.5;'>"
            . e($addr[0]) . ' &middot; ' . e(explode(' · ', $addr[1])[0]) . '<br>'
            . 'Kinondoni, ' . e($addr[2]) . '</td>';
        $footerHtml .= "<td style='width:31%; text-align:right; vertical-align:top; padding:5px 12mm 0 14px; line-height:1.5; white-space:nowrap;'>"
            . "<div style='font-weight:700; font-size:7pt; letter-spacing:1.2px; color:#416180;'><strong>{$docRef}</strong></div>"
            . '<div>' . e($d['date']) . ' &middot; ' . e($web) . '</div></td>';
        $footerHtml .= '</tr></table>';

        // (D) BODY
        $html = "<!DOCTYPE html><html><head>{$style}</head><body><div id='sheet'><div id='body'>";

        // Letterhead — bigger than the testbed's, with the full legal name,
        // tagline, complete address and every contact channel.
        $html .= "<table id='letterhead'><tr>";
        $html .= "<td style='width:38%; vertical-align:middle;'>"
            . "<img src='{$logosDir}/hypermed_lockup.png' height='128' width='auto'/>"
            . "<div class='lh-tin'><strong>" . e($tin) . '</strong></div></td>';
        $html .= "<td style='width:62%; vertical-align:middle; text-align:right;'>"
            . "<div class='lh-name'><strong>" . e($lh['name_header']) . '</strong></div>'
            . "<div class='lh-tag'><strong>" . e(strtoupper($company['tagline'] ?? '')) . '</strong></div>'
            . "<div class='lh-addr' style='margin-top:6px'>" . implode('<br>', array_map(fn ($l) => str_replace(' · ', ' &middot; ', e($l)), $addr)) . '</div>'
            . "<div class='lh-contact' style='margin-top:6px'>Mob " . e($mobile) . ' &middot; Tel ' . e($tel) . '<br>'
            . e($email) . ' &middot; ' . e($web) . '</div></td>';
        $html .= '</tr></table>';
        $html .= $this->plusRule('0', '7mm');

        // title row
        $html .= "<table id='titlerow'><tr><td style='width:60%'>"
            . "<div class='doctitle'><strong>" . e($d['title']) . '</strong></div>'
            . "<table id='tag'><tr><td><strong>" . e($d['tag']) . '</strong></td></tr></table></td>'
            . "<td style='width:40%; text-align:right'><table id='invbox' style='margin-left:auto; text-align:left'><tr><td>"
            . "<div class='invbox-lbl'><strong>" . e(strtoupper(rtrim($d['docLabel'], ':')) . '.') . '</strong></div>'
            . "<div class='invbox-num'><strong>" . e($d['docNumber']) . '</strong></div>'
            . '</td></tr></table></td></tr></table>';

        // meta strip — client name, contact line, then address/TIN lines
        $client = $d['client'];
        $clientName = array_shift($client) ?? '—';
        $tinLines = array_values(array_filter($client, fn ($l) => str_starts_with((string) $l, 'TIN')));
        $contact = array_values(array_diff($client, $tinLines));
        $addressLines = array_merge($d['clientAddress'] ?? [], $tinLines);
        $html .= "<table id='meta'><tr><td style='width:42%'>"
            . "<div class='meta-lbl'><strong>" . ($delivery ? 'DELIVER TO' : 'BILLED TO') . "</strong></div><br>"
            . "<div class='meta-bigval'><strong>" . e($clientName) . '</strong></div>'
            . ($contact ? "<div style='margin-top:2px'>" . e(implode(' · ', $contact)) . '</div>' : '')
            . ($addressLines ? "<div style='margin-top:4px; font-size:8.5pt; line-height:1.5; color:#5d5d60;'>" . implode('<br>', array_map('e', $addressLines)) . '</div>' : '')
            . '</td>'
            . "<td style='width:29%'><div class='meta-lbl'><strong>DATE ISSUED</strong></div><br><div class='meta-val'>" . e($d['date']) . '</div></td>'
            . ($delivery
                ? "<td style='width:29%'><div class='meta-lbl'><strong>INVOICE NO.</strong></div><br><div class='meta-val'>" . e($d['invoiceNumber']) . '</div></td>'
                : "<td style='width:29%'><div class='meta-lbl'><strong>CURRENCY</strong></div><br><div class='meta-val'>" . e($d['currency']) . '</div></td>')
            . '</tr></table>';

        if ($delivery) {
            $html .= $this->deliveryNoteBody($d);
        } else {
            // items — clean-line rows
            // A DISC. column only when some line is discounted; LOT/EXP give it room.
            $hasDisc = collect($d['items'])->contains(fn ($i) => ($i[8] ?? 0) > 0);
            $html .= "<table id='items'><tr>
                <th style='width:6%'>#</th><th style='white-space:normal'>DESCRIPTION</th>
                <th style='width:" . ($hasDisc ? 8 : 11) . "%'>LOT</th><th style='width:" . ($hasDisc ? 9 : 12) . "%'>EXP.</th><th style='width:7%'>UOM</th>
                <th class='num' style='width:6%'>QTY</th><th class='num' style='width:13%'>UNIT PRICE</th>"
                . ($hasDisc ? "<th class='num' style='width:11%'>DISC.</th>" : '')
                . "<th class='num' style='width:13%'>AMOUNT</th></tr>";
            foreach ($d['items'] as $i) {
                $html .= '<tr><td class="dim">' . e($i[0]) . '</td><td style="font-weight:500">' . e($i[1]) . '</td>'
                    . '<td class="dim">' . e($i[2]) . '</td><td class="dim">' . e($i[3]) . '</td><td>' . e($i[4]) . '</td>'
                    . '<td class="num">' . e($i[5]) . '</td><td class="num">' . e($i[6]) . '</td>'
                    . ($hasDisc ? '<td class="num">' . (($i[8] ?? 0) > 0 ? e(number_format($i[8], 2)) : '-') . '</td>' : '')
                    . '<td class="num" style="font-weight:700">' . e($i[7]) . '</td></tr>';
            }
            $html .= '</table>';

            // totals
            $cc = e($d['currencyCode']);
            $row = fn ($label, $value, $class = '') => "<tr class='{$class}'><td class='lbl'>{$label}</td><td class='val'>{$cc} " . e($value) . '</td></tr>';
            $html .= "<table id='totals' align='right'>";
            $html .= $row('Subtotal', $d['subtotal']);
            $html .= $row('Discount', $d['discount'], 'muted');
            if ($d['tax'] !== '0.00') {
                $html .= $row('VAT', $d['tax']);
            }
            if (($d['shipping'] ?? '0.00') !== '0.00') {
                $html .= $row('Delivery / transport', $d['shipping']);
            }
            $html .= "<tr class='grand'><td class='lbl'><div class='grand-lbl'><strong>TOTAL DUE</strong></div></td>"
                . "<td class='val'><div class='grand-val'><strong>{$cc} " . e($d['total']) . '</strong></div></td></tr>';
            if (! empty($d['paid'])) {
                $html .= $row('Paid to date', $d['paid'], 'muted');
                $html .= "<tr class='grand'><td class='lbl'><div class='grand-lbl'><strong>BALANCE</strong></div></td>"
                    . "<td class='val'><div class='bal-val'><strong>{$cc} " . e($d['balance']) . '</strong></div></td></tr>';
            }
            $html .= '</table>';

            // terms
            $html .= "<div id='terms'><div class='terms-h'><strong>TERMS &amp; CONDITIONS</strong></div><table id='termsrow'><tr>";
            foreach ($d['terms'] as $t) {
                $html .= "<td style='width:" . round(100 / max(1, count($d['terms']))) . "%'>"
                    . "<div class='terms-t'><strong>" . e($t['label']) . '</strong></div><div>' . e($t['text']) . '</div></td>';
            }
            $html .= '</tr></table></div>';

            // payment note + bank columns + signature
            $html .= "<table id='footrow'><tr><td class='bankcell'>"
                . "<div class='banknote'>Prices exclude any charge not stated on this document. Payments should be made directly to the accounts below.</div>"
                . "<table class='banktable'><tr><td><div class='bankcol-lbl'><strong>ACCOUNT NAME</strong></div><strong>" . e($title($lh['name_payee'])) . '</strong></td>';
            foreach ($banks as $b) {
                $html .= "<td><div class='bankcol-lbl'><strong>" . e(strtoupper($b['name'])) . '</strong></div>' . e($b['currency']) . ' &middot; ' . e($b['account']) . '</td>';
            }
            $html .= '</tr></table></td>'
                . "<td class='sigcell'><table class='sigline-table'><tr><td class='sigline-cell'>&nbsp;</td></tr>"
                . "<tr><td class='sigcol-lbl'><strong>AUTHORISED SIGNATURE &amp; STAMP</strong></td></tr></table></td>"
                . '</tr></table>';
        }

        $html .= '</div></div></body></html>';

        // (E) RENDER — TildaSans + Barlow Condensed as custom mPDF fonts
        // (keys must be lowercase: mPDF lowercases the CSS family name).
        $defaultFontDirs = (new \Mpdf\Config\ConfigVariables())->getDefaults()['fontDir'];
        $defaultFontData = (new \Mpdf\Config\FontVariables())->getDefaults()['fontdata'];

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'margin_top' => 11,
            'margin_bottom' => 24,
            'margin_header' => 0,
            'margin_footer' => 7,
            'margin_left' => 0,
            'margin_right' => 0,
            'tempDir' => storage_path('app/mpdf-tmp'),
            'fontDir' => array_merge($defaultFontDirs, [$fontsDir]),
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

        // Faint full-lockup watermark behind the items, as in the v3 design.
        $mpdf->SetWatermarkImage("{$logosDir}/hypermed_lockup.png", 0.09, [153, 102], [28.5, 98]);
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
