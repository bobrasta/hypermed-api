<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\InvoiceLineItem;
use App\Models\User;
use App\Services\DocumentPdfService;
use App\Support\LineDiscount;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

// Per-line TSh discounts (user, 2026-10-02). No database: plain arrays and
// unsaved models.
class LineDiscountTest extends TestCase
{
    public function test_line_total_is_gross_less_percent_less_amount(): void
    {
        $this->assertSame(9000, LineDiscount::net(['quantity' => 2, 'unit_price' => 5000, 'discount' => 1000]));
        $this->assertSame(10000, LineDiscount::net(['quantity' => 2, 'unit_price' => 5000]));
        // Quotation lines: older percentage first, then the TSh amount.
        $this->assertSame(8500, LineDiscount::net(['quantity' => 2, 'unit_price' => 5000, 'discount_percent' => 10, 'discount_amount' => 500], 'discount_amount'));
    }

    public function test_discount_over_the_line_amount_is_refused(): void
    {
        $this->expectException(ValidationException::class);
        LineDiscount::assertValid([['quantity' => 1, 'unit_price' => 100, 'discount' => 101]], 'line_items');
    }

    public function test_seller_cap_applies_only_when_set(): void
    {
        $lines = [['quantity' => 1, 'unit_price' => 100000, 'discount' => 15000]];
        LineDiscount::assertWithinCap(new User(['max_discount_percent' => null]), $lines);
        LineDiscount::assertWithinCap(new User(['max_discount_percent' => 15]), $lines);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('over your limit of 10.0%');
        LineDiscount::assertWithinCap(new User(['max_discount_percent' => 10]), $lines);
    }

    public function test_invoice_pdf_shows_disc_column_and_gross_subtotal(): void
    {
        $invoice = new Invoice(['invoice_number' => 'HH-1', 'client_name' => 'Clinic', 'subtotal' => 9000, 'tax_amount' => 0,
            'shipping_charges' => 0, 'total' => 9000, 'amount_paid' => 0, 'status' => 'pending', 'currency' => 'TZS']);
        $invoice->setRelation('hospital', null);
        $invoice->setRelation('lineItems', collect([
            new InvoiceLineItem(['description' => 'Gel', 'quantity' => 2, 'unit_price' => 5000, 'discount' => 1000, 'total' => 9000]),
        ]));
        $html = $this->html($invoice);
        $this->assertStringContainsString('DISC.', $html);
        $this->assertStringContainsString('1,000.00', $html);   // the line's discount
        $this->assertStringContainsString('10,000.00', $html);  // subtotal before discount

        $invoice->setRelation('lineItems', collect([
            new InvoiceLineItem(['description' => 'Gel', 'quantity' => 2, 'unit_price' => 5000, 'discount' => 0, 'total' => 10000]),
        ]));
        $this->assertStringNotContainsString('DISC.', $this->html($invoice));
    }

    // The rendered PDF's text (needs poppler's pdftotext).
    private function html(Invoice $invoice): string
    {
        if (! trim((string) shell_exec('command -v pdftotext'))) {
            $this->markTestSkipped('pdftotext not installed');
        }
        $file = tempnam(sys_get_temp_dir(), 'inv');
        file_put_contents($file, app(DocumentPdfService::class)->invoicePdf($invoice)->getContent());
        $text = (string) shell_exec('pdftotext -layout ' . escapeshellarg($file) . ' -');
        unlink($file);

        return $text;
    }
}
