<?php

namespace Tests\Feature;

use App\Models\ApprovalLog;
use App\Models\ChartOfAccount;
use App\Models\InventoryItem;
use App\Models\Location;
use App\Models\PurchaseOrder;
use App\Models\Shipment;
use App\Models\Supplier;
use App\Models\User;
use App\Services\StockService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// The web behind a record: from an LPO and from its stock item, the
// approvals (Sales Manager included), how it was paid and from which
// account, the supplier invoice, the shipment and the store receipt, plus
// the ledger legs. Needs a migrated Postgres DB with seeded items/locations.
class ConnectionsTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.default') !== 'pgsql' || ! InventoryItem::exists() || ! Location::exists()) {
            $this->markTestSkipped('needs a migrated Postgres database with stock items and locations');
        }
        if (! Schema::hasTable('supplier_payments')) {
            (require database_path('migrations/2026_10_20_120000_creditors_supplier_payments_and_fx.php'))->up();
        }
        if (! Schema::hasTable('purchase_returns')) {
            (require database_path('migrations/2026_10_26_000001_create_purchases_section.php'))->up();
        }
        Cache::flush();
    }

    private function user(string $role, string $slug): User
    {
        $u = User::forceCreate(['name' => "Test {$slug}", 'email' => "{$slug}@example.com", 'password' => bcrypt('x'), 'role' => $role, 'is_active' => true]);
        $u->assignRole($role);

        return $u;
    }

    public function test_the_web_of_an_lpo_and_of_its_stock_item(): void
    {
        $sales = $this->user('sales_manager', 'cx-sales');
        $acct = $this->user('accountant', 'cx-acct');
        $dir = $this->user('super_admin', 'cx-dir');
        $buyer = $this->user('procurement_manager', 'cx-buyer');
        $item = InventoryItem::first();
        $store = Location::first();
        $s = Supplier::create(['name' => 'ZZ MINDRAY TEST', 'type' => 'manufacturer', 'currency' => 'USD', 'is_active' => true]);
        $po = PurchaseOrder::create(['po_number' => 'ZZ-PO-WEB', 'supplier_id' => $s->id, 'location_id' => $store->id, 'status' => 'pending_payment_initiation',
            'ordered_by' => $buyer->id, 'currency' => 'USD', 'total_amount' => 2000, 'payment_status' => 'unpaid']);
        $po->items()->create(['inventory_item_id' => $item->id, 'quantity_ordered' => 2, 'unit_cost' => 1000, 'currency' => 'USD']);
        ApprovalLog::record($po, 'sales_approved', $sales);

        Sanctum::actingAs($acct);
        $this->postJson("/api/v1/purchase-orders/{$po->id}/initiate-payment", ['amount' => 2000, 'exchange_rate' => 2700,
            'paid_from_account_id' => ChartOfAccount::where('code', '1013')->value('id'), 'reference' => 'TT 0042'])->assertOk();
        Sanctum::actingAs($dir);
        $this->postJson("/api/v1/purchase-orders/{$po->id}/approve-director-final")->assertOk();
        Sanctum::actingAs($acct);
        $this->postJson('/api/v1/vendor-bills', ['supplier_id' => $s->id, 'purchase_order_id' => $po->id, 'supplier_reference' => 'MR-INV-77',
            'exchange_rate' => 2710, 'issue_date' => now()->toDateString(), 'due_date' => now()->addMonth()->toDateString(),
            'line_items' => [['description' => 'BC-30s analyser', 'quantity' => 2, 'unit_price' => 1000]]])->assertCreated();
        Shipment::create(['reference' => 'ZZ-SHP-WEB', 'direction' => 'import', 'freight_mode' => 'air', 'description' => 'BC-30s ×2',
            'supplier_id' => $s->id, 'purchase_order_id' => $po->id, 'step' => 3, 'created_by' => $buyer->id]);
        app(StockService::class)->add($item, $store, 2, 2700000, "Received via {$po->po_number}", $po);

        Sanctum::actingAs($acct);
        $web = $this->getJson("/api/v1/connections/purchase_order/{$po->id}")->assertOk()->json('data');
        $types = collect($web['nodes'])->pluck('type')->unique()->values()->all();
        foreach (['purchase_order', 'supplier', 'location', 'inventory_item', 'supplier_payment', 'vendor_bill', 'shipment', 'stock_movement'] as $t) {
            $this->assertContains($t, $types, "web is missing {$t}");
        }
        $actions = collect($web['timeline'])->pluck('action')->all();
        $this->assertContains('Sales Manager approved', $actions);
        $this->assertContains('Payment initiated', $actions);
        $this->assertContains('Paid', $actions);
        $this->assertTrue(collect($actions)->contains(fn ($a) => str_starts_with($a, 'Received into')));
        $payment = collect($web['nodes'])->firstWhere('type', 'supplier_payment');
        $this->assertStringContainsString('Equity Bank USD', $payment['subtitle']);
        $refs = collect($web['ledger'])->pluck('reference')->unique()->values()->all();
        $this->assertContains("SPAY-{$payment['id']}", $refs);
        $this->assertTrue(collect($refs)->contains(fn ($r) => str_starts_with($r, 'BILL-')));

        // From the stock item, the same LPO with its payment is reachable.
        $fromItem = $this->getJson("/api/v1/connections/inventory_item/{$item->id}")->assertOk()->json('data');
        $this->assertTrue(collect($fromItem['nodes'])->contains(fn ($n) => $n['type'] === 'purchase_order' && $n['id'] === $po->id));

        // Someone without finance or procurement access can't open it; procurement sees no ledger.
        Sanctum::actingAs($this->user('technician', 'cx-tech'));
        $this->getJson("/api/v1/connections/purchase_order/{$po->id}")->assertForbidden();
        Sanctum::actingAs($buyer);
        $this->assertNull($this->getJson("/api/v1/connections/purchase_order/{$po->id}")->assertOk()->json('data.ledger'));
    }
}
