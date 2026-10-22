<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// GET /dashboard/store — the storekeeper's dashboard. Needs a Postgres
// database with the seeded roles; skipped on SQLite.
class StoreDashboardTest extends TestCase
{
    use DatabaseTransactions;

    private function user(string $role): User
    {
        $u = User::forceCreate(['name' => "Test {$role}", 'email' => "store-dash-{$role}@example.com",
            'password' => bcrypt('x'), 'role' => $role, 'is_active' => true]);
        $u->assignRole($role);

        return $u;
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('needs a migrated Postgres database');
        }
    }

    public function test_storekeeper_gets_stock_and_waiting_work(): void
    {
        InventoryItem::forceCreate(['sku' => 'SD-LOW', 'name' => 'SD low reagent', 'stock_qty' => 2, 'reorder_level' => 10, 'unit_cost' => 1000]);
        InventoryItem::forceCreate(['sku' => 'SD-BIG', 'name' => 'SD big machine', 'stock_qty' => 3, 'reorder_level' => 0, 'unit_cost' => 1_000_000_000_000]);
        Sanctum::actingAs($this->user('storekeeper'));

        $r = $this->getJson('/api/v1/dashboard/store')->assertOk();
        foreach (['kpis', 'waiting', 'low_stock', 'pos_to_receive', 'stock_out_pending', 'machines_in_stock', 'recent_movements', 'top_value', 'by_category'] as $k) {
            $r->assertJsonStructure(['data' => [$k]]);
        }
        $this->assertContains('SD low reagent', array_column($r->json('data.low_stock'), 'name'));
        $this->assertNotContains('SD big machine', array_column($r->json('data.low_stock'), 'name'));
        $this->assertSame('SD big machine', $r->json('data.top_value.0.name'));
        $this->assertSame(3_000_000_000_000, $r->json('data.top_value.0.value'));
    }

    public function test_role_without_inventory_is_refused(): void
    {
        Sanctum::actingAs($this->user('technician'));
        $this->getJson('/api/v1/dashboard/store')->assertForbidden();
    }
}
