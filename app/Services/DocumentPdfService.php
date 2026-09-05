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
    // Quotations and Invoices share one physical layout — literal
    // replication of the company's real proforma-invoice PDF (see
    // resources/pdf-assets and the mPDF prototype it was ported from,
    // /var/www/html/me/system/punch.php). The only differences between the
    // two document types are the title/label text and which model's data
    // feeds the same template — see render().
    public function quotationPdf(Quotation $quotation): Response
    {
        $quotation->loadMissing('items');
        $company = config('company');

        $items = $quotation->items->values()->map(fn ($i, $idx) => [
            $idx + 1, '', $i->description, '', $i->unit_of_measure ?: '-',
            number_format($i->quantity, 2), number_format($i->unit_price, 2),
            $i->discount_percent > 0 ? number_format($i->discount_percent, 1) . '%' : '0.00',
            number_format($i->total_price, 2),
        ])->all();

        $client = array_values(array_filter([$quotation->client_name, $quotation->client_contact, $quotation->client_email]));

        return $this->render([
            'title'     => 'PROFORMA INVOICE',
            'docLabel'  => 'Proforma No:',
            'docNumber' => $quotation->quotation_number,
            'date'      => ($quotation->created_at ?? now())->format('d/m/Y H:i'),
            'client'    => $client,
            'clientTin' => null,
            'items'     => $items,
            'subtotal'  => number_format($quotation->subtotal, 2),
            'total'     => number_format($quotation->total_amount, 2),
            'terms'     => $quotation->terms ? [['label' => 'Terms', 'text' => $quotation->terms]] : $company['default_terms'],
            'filename'  => "{$quotation->quotation_number}.pdf",
        ]);
    }

    public function invoicePdf(Invoice $invoice): Response
    {
        $invoice->loadMissing(['lineItems', 'hospital']);
        $company = config('company');

        $items = $invoice->lineItems->values()->map(fn ($i, $idx) => [
            $idx + 1, '', $i->description, '', '-',
            number_format($i->quantity, 2), number_format($i->unit_price, 2), '0.00',
            number_format($i->total, 2),
        ])->all();

        $client = array_values(array_filter([
            $invoice->hospital?->name ?? $invoice->client_name, $invoice->client_contact, $invoice->client_email,
        ]));

        return $this->render([
            'title'     => 'INVOICE',
            'docLabel'  => 'Invoice No:',
            'docNumber' => $invoice->invoice_number,
            'date'      => ($invoice->issue_date ?? now())->format('d/m/Y'),
            'client'    => $client,
            'clientTin' => null,
            'items'     => $items,
            'subtotal'  => number_format($invoice->subtotal, 2),
            'total'     => number_format($invoice->total, 2),
            'terms'     => $company['default_terms'],
            'filename'  => "{$invoice->invoice_number}.pdf",
        ]);
    }

    public function hrReportPdf(array $data): PdfInstance
    {
        return Pdf::loadView('pdf.hr_report', array_merge($data, [
            'company' => config('company'),
        ]))->setPaper('a4');
    }

    public function payslipPdf(array $data): PdfInstance
    {
        return Pdf::loadView('pdf.payslip', array_merge($data, [
            'company' => config('company'),
        ]))->setPaper('a4');
    }

    /**
     * @param array{title:string,docLabel:string,docNumber:string,date:string,client:array,clientTin:?string,
     *              items:array,subtotal:string,total:string,terms:array,filename:string} $d
     */
    private function render(array $d): Response
    {
        $company = config('company')['letterhead'];
        $banks = config('company')['banks'];
        $logosDir = resource_path('pdf-assets/logos');

        $style = "
        <style>
          html, body { font-family: dejavusans, helvetica, arial, sans-serif; color:#1a1a1a; font-size:9pt; }
          #sheet { width:90%; margin-left:5%; margin-right:5%; padding-top:8mm; }
          table { width:100%; border-collapse:collapse; }
          #hdr td { vertical-align:top; padding:0; }
          #hdr .right { text-align:right; }
          #hdr .tin { font-weight:700; font-size:40px; margin-top:6px; }
          #hdr .cname { font-weight:700; font-size:12pt; }
          #hdr .addr { font-size:8pt; line-height:1.6; color:#333; margin-top:2px; }
          #doctitle { text-align:center; font-size:19pt; font-weight:700; letter-spacing:1px; margin:18px 0 16px 0; }
          #meta td { vertical-align:top; padding:0; font-size:9pt; }
          #meta .k { font-weight:700; }
          #meta .client div { font-weight:700; margin-top:2px; }
          #meta .right { text-align:right; }
          #items { margin-top:22px; border:1px solid #333; }
          #items th { text-align:left; font-size:7.5px; font-weight:700; padding:6px 5px; border:1px solid #333; background:#f2f2f2; white-space:nowrap; }
          #items td { padding:6px 5px; font-size:8px; border:1px solid #333; vertical-align:top; }
          #items th.num, #items td.num { text-align:right; }
          #totals { width:27%; margin-left:73%; margin-top:14px; }
          #totals td { padding:4px 0; font-size:9.5pt; white-space:nowrap; vertical-align:top; }
          #totals .lbl { width:38%; text-align:right; padding-right:8px; font-weight:700; }
          #totals .val { width:62%; text-align:right; font-weight:700; line-height:1.6; }
          #totals .grand td { font-size:11pt; padding-top:8px; }
          #bcwrap { text-align:center; margin-top:18px; }
          #footrow { margin-top:26px; }
          #footrow td { vertical-align:top; padding:0; font-size:8pt; }
          #termscol { width:56%; padding-right:16px; line-height:1.7; }
          #termscol .h { font-weight:700; font-size:9pt; margin-bottom:4px; }
          #paycol { width:44%; }
          #paybox { width:100%; border:1px solid #333; border-collapse:collapse; font-size:8pt; }
          #paybox td { padding:5px 14px 0 14px; line-height:1.6; }
          #paybox td.h { font-weight:700; padding-top:12px; padding-bottom:8px; border-bottom:1px solid #ccc; }
          #paybox td.acct { padding-bottom:8px; }
          #paybox td.cur { text-align:right; font-weight:700; }
          #paybox td.sep { padding-top:12px; }
          #paybox td.last { padding-bottom:12px; }
        </style>";

        $html = "<!DOCTYPE html><html><head>{$style}</head><body><div id='sheet'>";

        $html .= "<table id='hdr'><tr>";
        $html .= "<td style='width:40%'>";
        $html .= "<img src='{$logosDir}/hypermed_lockup.png' width='200'/>";
        $html .= "<div class='tin'><p style='font-size:20px; margin-top:10px; font-weight:700 !important'>" . e($company['tin']) . "</p></div>";
        $html .= "</td>";
        $html .= "<td class='right' style='width:60%'>";
        $html .= "<div class='cname'>" . e($company['name_header']) . "</div>";
        $html .= "<div class='addr'>" . implode('<br>', array_map('e', $company['address_lines'])) . "</div>";
        $html .= "</td></tr></table>";

        $html .= "<div id='doctitle'>" . e($d['title']) . "</div>";

        $html .= "<table id='meta'><tr>";
        $html .= "<td style='width:60%'>";
        $html .= "<div><span class='k'>" . e($d['docLabel']) . "</span> " . e($d['docNumber']) . "</div>";
        $html .= "<div class='client'>";
        foreach ($d['client'] as $line) {
            $html .= '<div>' . e($line) . '</div>';
        }
        $html .= '</div>';
        if ($d['clientTin']) {
            $html .= "<div style='margin-top:10px'>" . e($d['clientTin']) . '</div>';
        }
        $html .= '</td>';
        $html .= "<td class='right' style='width:40%'><span class='k'>Date</span> " . e($d['date']) . '</td>';
        $html .= '</tr></table>';

        $html .= "<table id='items'><tr>
            <th style='width:4%'>S/N</th><th style='width:10%'>LOT Number</th><th style='white-space:normal'>Description</th>
            <th style='width:7%'>Exp. Date</th><th style='width:5%'>UOM</th><th class='num' style='width:6%'>Qty</th>
            <th class='num' style='width:13%'>Unit Price</th><th class='num' style='width:9%'>Item discount</th><th class='num' style='width:13%'>Subtotal</th>
          </tr>";
        foreach ($d['items'] as $i) {
            $html .= '<tr>' . implode('', [
                '<td>' . e($i[0]) . '</td>', '<td>' . e($i[1]) . '</td>', '<td>' . e($i[2]) . '</td>',
                '<td>' . e($i[3]) . '</td>', '<td>' . e($i[4]) . '</td>',
                "<td class='num'>" . e($i[5]) . '</td>', "<td class='num'>" . e($i[6]) . '</td>',
                "<td class='num'>" . e($i[7]) . '</td>', "<td class='num'>" . e($i[8]) . '</td>',
            ]) . '</tr>';
        }
        $html .= '</table>';

        $html .= "<table id='totals'>";
        $html .= "<tr><td class='lbl'>Subtotal:</td><td class='val'>TSh<br>" . e($d['subtotal']) . '</td></tr>';
        $html .= "<tr class='grand'><td class='lbl'>Total:</td><td class='val'>TSh<br>" . e($d['total']) . '</td></tr>';
        $html .= '</table>';

        $html .= "<div id='bcwrap'><barcode code='" . e($d['docNumber']) . "' type='C128B' text='1' size='0.9' /></div>";

        $html .= "<table id='footrow'><tr>";
        $html .= "<td id='termscol'>";
        $html .= "<div class='h'>Terms and conditions:</div>";
        foreach ($d['terms'] as $t) {
            $html .= '<div><strong>' . e($t['label']) . ':</strong> ' . e($t['text']) . '</div>';
        }
        $html .= '</td>';
        $html .= "<td id='paycol'><table id='paybox'>";
        $html .= "<tr><td class='h' colspan='2'>Payments should be made directly to:</td></tr>";
        $html .= "<tr><td class='acct' colspan='2'><strong>Account info:</strong> " . e($company['name_payee']) . '</td></tr>';
        $lastBank = count($banks) - 1;
        foreach ($banks as $idx => $b) {
            $sep = $idx === 0 ? '' : ' sep';
            $html .= "<tr><td class='{$sep}'><strong>Bank:</strong> " . e($b['name']) . "</td><td class='cur{$sep}'>" . e($b['currency']) . '</td></tr>';
            $lastCls = $idx === $lastBank ? ' last' : '';
            $html .= "<tr><td colspan='2' class='{$lastCls}'><strong>AC/NO:</strong> " . e($b['account']) . '</td></tr>';
        }
        $html .= '</table></td>';
        $html .= '</tr></table>';

        $html .= '</div></body></html>';

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'margin_top' => 15,
            'margin_bottom' => 15,
            'margin_left' => 0,
            'margin_right' => 0,
            'tempDir' => storage_path('app/mpdf-tmp'),
        ]);

        $mpdf->SetWatermarkImage("{$logosDir}/hypermed_icon.png", 0.08, [150, 92], [105, 5]);
        $mpdf->showWatermarkImage = true;

        $mpdf->WriteHTML($html);

        return response($mpdf->Output($d['filename'], \Mpdf\Output\Destination::STRING_RETURN), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $d['filename'] . '"',
        ]);
    }
}
