<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\Invoice;
use App\Models\User;
use App\Services\DocumentPdfService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// Invoice lines keep the product, unit of measure, lot and expiry (as
// Clickhuduma's did); the API stores and returns them, edits replace them,
// and the invoice PDF still renders with them.
//
// Needs a Postgres database with the seeded roles; skipped on SQLite.
class InvoiceLineProductTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('needs a migrated Postgres database');
        }
        $u = User::forceCreate([
            'name' => 'Test accountant', 'email' => 'line-product-accountant@example.com',
            'password' => bcrypt('x'), 'role' => 'accountant', 'is_active' => true,
        ]);
        $u->assignRole('accountant');
        Sanctum::actingAs($u);
    }

    public function test_line_keeps_product_uom_lot_and_expiry(): void
    {
        $item = InventoryItem::forceCreate(['sku' => 'TEST-LOT-1', 'name' => 'Ichroma CRP', 'unit_of_measure' => 'box']);

        $res = $this->postJson('/api/v1/invoices', [
            'sale_status' => 'draft', 'client_name' => 'Test Clinic', 'issue_date' => '2026-10-22', 'pay_term_number' => 30, 'pay_term_type' => 'days',
            'line_items' => [
                ['inventory_item_id' => $item->id, 'description' => 'Ichroma CRP', 'quantity' => 2, 'unit_price' => 150000,
                 'unit_of_measure' => '25 T/Kit', 'lot_number' => ' CRPU2401 ', 'expiry_date' => '2027-03-31'],
                ['description' => 'Delivery', 'quantity' => 1, 'unit_price' => 20000, 'lot_number' => ''],
            ],
        ])->assertCreated();

        $res->assertJsonPath('data.line_items.0.inventory_item_id', $item->id)
            ->assertJsonPath('data.line_items.0.unit_of_measure', '25 T/Kit')
            ->assertJsonPath('data.line_items.0.lot_number', 'CRPU2401')
            ->assertJsonPath('data.line_items.0.expiry_date', '2027-03-31')
            ->assertJsonPath('data.line_items.1.lot_number', null)
            ->assertJsonPath('data.line_items.1.expiry_date', null);

        $id = $res->json('data.id');
        $this->patchJson("/api/v1/invoices/{$id}", ['line_items' => [
            ['description' => 'Ichroma CRP', 'quantity' => 2, 'unit_price' => 150000, 'lot_number' => 'CRPU2402', 'expiry_date' => '2027-06-30'],
        ]])->assertOk()
            ->assertJsonPath('data.line_items.0.lot_number', 'CRPU2402')
            ->assertJsonPath('data.line_items.0.expiry_date', '2027-06-30');

        $pdf = app(DocumentPdfService::class)->invoicePdf(Invoice::withoutGlobalScopes()->find($id));
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $note = app(DocumentPdfService::class)->deliveryNotePdf(Invoice::withoutGlobalScopes()->find($id));
        $this->assertStringStartsWith('%PDF', $note->getContent());
    }

    public function test_bad_expiry_is_refused(): void
    {
        $this->postJson('/api/v1/invoices', [
            'sale_status' => 'draft', 'client_name' => 'Test Clinic', 'issue_date' => '2026-10-22', 'pay_term_number' => 30, 'pay_term_type' => 'days',
            'line_items' => [['description' => 'X', 'quantity' => 1, 'unit_price' => 1, 'expiry_date' => 'soon']],
        ])->assertJsonValidationErrors('line_items.0.expiry_date');
    }
}
