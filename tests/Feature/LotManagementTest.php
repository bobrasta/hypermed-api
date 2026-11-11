<?php

namespace Tests\Feature;

use App\Models\BatchLot;
use App\Models\InventoryItem;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// Item → Lots tab: add a lot (stock goes up), correct its quantity (stock moves
// by the difference from what was left), remove it (what was left comes off).
// Needs a Postgres database with the seeded roles; skipped on SQLite.
class LotManagementTest extends TestCase
{
    use DatabaseTransactions;

    private InventoryItem $item;

    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('needs a migrated Postgres database');
        }
        $u = User::forceCreate(['name' => 'Test admin', 'email' => 'lot-admin@example.com',
            'password' => bcrypt('x'), 'role' => 'admin', 'is_active' => true]);
        $u->assignRole('admin');
        Sanctum::actingAs($u);
        $this->item = InventoryItem::forceCreate(['sku' => 'TEST-LOTS', 'name' => 'Reagent A', 'stock_qty' => 0]);
        Location::query()->firstOrCreate(['name' => 'Test store'], ['type' => 'store', 'is_active' => true]);
    }

    private function url(string $tail = ''): string
    {
        return "/api/v1/inventory/{$this->item->id}/batches{$tail}";
    }

    public function test_add_edit_remove_lots_moves_stock(): void
    {
        $this->postJson($this->url(), ['lot_number' => 'abc', 'expiry_date' => '2027-05-01', 'quantity' => 3])->assertCreated()
            ->assertJsonPath('data.qty_left', 3);
        $this->postJson($this->url(), ['lot_number' => 'def', 'expiry_date' => '2027-01-01', 'quantity' => 4])->assertCreated();
        $hij = $this->postJson($this->url(), ['lot_number' => 'hij', 'expiry_date' => '2028-01-01', 'quantity' => 3])->json('data.id');
        $this->postJson($this->url(), ['lot_number' => ' ABC ', 'quantity' => 1])->assertStatus(422);

        $res = $this->getJson($this->url())->assertOk();
        $this->assertSame(['def', 'abc', 'hij'], array_column($res->json('data'), 'lot_number')); // earliest expiry first
        $res->assertJsonPath('meta.total_left', 10)->assertJsonPath('meta.stock_qty', 10);

        $this->putJson($this->url("/{$hij}"), ['quantity' => 1, 'expiry_date' => '2028-02-01'])->assertOk()
            ->assertJsonPath('data.qty_left', 1)->assertJsonPath('data.expiry_date', '2028-02-01');
        $this->assertSame(8, (int) $this->item->fresh()->stock_qty);

        $this->deleteJson($this->url("/{$hij}"))->assertNoContent();
        $this->assertSame(7, (int) $this->item->fresh()->stock_qty);
        $this->assertSame(0, BatchLot::whereKey($hij)->count());
    }

    public function test_needs_stock_adjust_permission(): void
    {
        $u = User::forceCreate(['name' => 'Test sales', 'email' => 'lot-sales@example.com',
            'password' => bcrypt('x'), 'role' => 'sales', 'is_active' => true]);
        $u->assignRole('sales');
        Sanctum::actingAs($u);
        $this->postJson($this->url(), ['lot_number' => 'x', 'quantity' => 1])->assertForbidden();
        $this->getJson($this->url())->assertOk();
    }
}
