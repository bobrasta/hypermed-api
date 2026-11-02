<?php

namespace Tests\Feature;

use App\Models\BatchLot;
use App\Models\InventoryItem;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// GET /inventory-lots feeds the product search on the sale / invoice /
// quotation forms: unexpired lots with stock, less what final invoices sold
// from them after the lot was recorded. Drafts and proformas don't count.
//
// Needs a Postgres database with the seeded roles; skipped on SQLite.
class OpenLotsTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('needs a migrated Postgres database');
        }
        $u = User::forceCreate([
            'name' => 'Test accountant', 'email' => 'open-lots-accountant@example.com',
            'password' => bcrypt('x'), 'role' => 'accountant', 'is_active' => true,
        ]);
        $u->assignRole('accountant');
        Sanctum::actingAs($u);
    }

    private function lot(InventoryItem $item, string $lot, ?string $expiry, int $left, string $at = '2026-10-30 11:25:00'): void
    {
        $b = BatchLot::forceCreate(['inventory_item_id' => $item->id, 'batch_number' => $lot, 'lot_number' => $lot,
            'expiry_date' => $expiry, 'qty_received' => $left, 'qty_remaining' => $left]);
        $b->forceFill(['created_at' => $at])->save();
    }

    private function sell(InventoryItem $item, string $lot, int $qty, string $status): void
    {
        $this->postJson('/api/v1/invoices', [
            'sale_status' => $status, 'client_name' => 'Test Clinic', 'client_tin' => '100-200-300', 'issue_date' => '2026-11-02', 'pay_term_number' => 30, 'pay_term_type' => 'days',
            'line_items' => [['inventory_item_id' => $item->id, 'description' => $item->name, 'quantity' => $qty,
                'unit_price' => 215000, 'lot_number' => $lot, 'expiry_date' => '2027-10-18']],
        ])->assertCreated();
    }

    public function test_lists_unexpired_lots_less_what_was_sold(): void
    {
        $fsh = InventoryItem::forceCreate(['sku' => 'TEST-FSH', 'name' => 'iChroma FSH test']);
        $this->lot($fsh, 'DFSWBF01', '2027-10-18', 27);
        $this->lot($fsh, 'DFSUXD02', '2026-05-24', 2);      // expired
        $this->lot($fsh, 'DFSVLE11', '2027-08-23', 10);
        $this->lot($fsh, 'DFSVLE11', '2027-08-23', 5);       // same lot twice → one row
        $this->lot($fsh, 'OLDSOLD', '2028-01-01', 3);

        $this->sell($fsh, 'dfswbf01 ', 4, 'final');          // counts (case/space-insensitive)
        $this->sell($fsh, 'DFSWBF01', 9, 'draft');           // drafts don't
        $this->sell($fsh, 'OLDSOLD', 3, 'final');            // sold out → hidden

        $rows = collect($this->getJson('/api/v1/inventory-lots')->assertOk()->json('data'))
            ->where('inventory_item_id', $fsh->id)->values()->all();

        $this->assertSame([
            ['inventory_item_id' => $fsh->id, 'lot_number' => 'DFSVLE11', 'expiry_date' => '2027-08-23', 'qty_left' => 15],
            ['inventory_item_id' => $fsh->id, 'lot_number' => 'DFSWBF01', 'expiry_date' => '2027-10-18', 'qty_left' => 23],
        ], $rows);
    }
}
