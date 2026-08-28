<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Quotation;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfInstance;

class DocumentPdfService
{
    public function quotationPdf(Quotation $quotation): PdfInstance
    {
        $quotation->loadMissing('items');

        return Pdf::loadView('pdf.quotation', [
            'quotation'     => $quotation,
            'company'       => config('company'),
            'currencyLabel' => $this->currencyLabel($quotation->currency),
        ])->setPaper('a4');
    }

    public function invoicePdf(Invoice $invoice): PdfInstance
    {
        $invoice->loadMissing(['lineItems', 'payments', 'hospital']);

        [$bg, $color] = match ($invoice->status) {
            'paid'    => ['#e8f9ee', '#22c55e'],
            'overdue' => ['#fdeaea', '#e63946'],
            'partial' => ['#fff6e5', '#c8860d'],
            'waived'  => ['#eef2ff', '#5980a6'],
            default   => ['#f2f2f2', '#666666'],
        };

        return Pdf::loadView('pdf.invoice', [
            'invoice'       => $invoice,
            'company'       => config('company'),
            'statusBg'      => $bg,
            'statusColor'   => $color,
            'currencyLabel' => $this->currencyLabel($invoice->currency),
        ])->setPaper('a4');
    }

    public function hrReportPdf(array $data): PdfInstance
    {
        return Pdf::loadView('pdf.hr_report', array_merge($data, [
            'company' => config('company'),
        ]))->setPaper('a4');
    }

    // Matches the "TSh" prefix used throughout the Flutter UI's money formatting
    // — only for TZS, so a genuinely different currency code still shows correctly.
    private function currencyLabel(?string $currency): string
    {
        return $currency === 'TZS' ? 'TSh' : ($currency ?? 'TZS');
    }
}
