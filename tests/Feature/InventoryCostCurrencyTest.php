<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// An item's unit cost keeps its currency (TZS unless given) — the add-item
// form and a new product added from a purchase order line both set it.
//
// Needs a migrated Postgres database with the seeded roles; skipped on SQLite.
class InventoryCostCurrencyTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('needs a migrated Postgres database');
        }
        Cache::flush();
        $u = User::forceCreate(['name' => 'Test admin', 'email' => 'cost-ccy-admin@example.com', 'password' => bcrypt('x'), 'role' => 'admin', 'is_active' => true]);
        $u->assignRole('admin');
        Sanctum::actingAs($u);
    }

    public function test_cost_currency_is_saved_on_create_update_and_quick_create(): void
    {
        $id = $this->postJson('/api/v1/inventory', [
            'sku' => 'CCY-1', 'name' => 'Imported analyser part', 'unit_cost' => 1200, 'cost_currency' => 'usd',
            'stock_qty' => 0, 'reorder_level' => 0,
        ])->assertCreated()->assertJsonPath('data.cost_currency', 'USD')->json('data.id');

        $this->putJson("/api/v1/inventory/{$id}", ['cost_currency' => 'EUR'])->assertOk()->assertJsonPath('data.cost_currency', 'EUR');

        $this->postJson('/api/v1/inventory', [
            'sku' => 'CCY-2', 'name' => 'Local gloves', 'unit_cost' => 15000, 'stock_qty' => 0, 'reorder_level' => 0,
        ])->assertCreated()->assertJsonPath('data.cost_currency', 'TZS');

        $this->postJson('/api/v1/inventory/quick-create', ['name' => 'New reagent from PO', 'cost_currency' => 'USD'])
            ->assertCreated()->assertJsonPath('data.cost_currency', 'USD')->assertJsonPath('data.needs_review', true);
    }
}
