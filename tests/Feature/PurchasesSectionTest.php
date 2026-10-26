<?php

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\InventoryItem;
use App\Models\Location;
use App\Models\PurchaseOrder;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Models\VendorBill;
use App\Services\EffectivePermissionResolver;
use App\Services\StockService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// Purchases menu (Clickhuduma's): who holds it, adding a purchase (a PO in
// the approval chain), purchase returns taking stock out and — when the
// order's supplier invoice is on the books — crediting the supplier, plus
// the PO and return PDFs and the Connections web.
//
// Needs a migrated Postgres database with the seeded roles; skipped on
// SQLite. The new migration runs inside the test transaction if needed.
class PurchasesSectionTest extends TestCase
{
    use DatabaseTransactions;

    private User $sales;
    private User $accountant;
    private Location $store;
    private InventoryItem $item;
    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('needs a migrated Postgres database');
        }
        if (! Schema::hasTable('purchase_returns')) {
            (require database_path('migrations/2026_10_26_000001_create_purchases_section.php'))->up();
        }
        Cache::flush();
        $this->sales = $this->user('sales', 'pu-sales');
        $this->accountant = $this->user('accountant', 'pu-acct');
        $this->store = Location::create(['name' => 'PU test store', 'code' => 'PU-TEST', 'type' => 'warehouse', 'is_active' => true]);
        $this->item = InventoryItem::forceCreate(['sku' => 'PU-ITEM-1', 'name' => 'PU test reagent', 'unit_cost' => 1000]);
        $this->supplier = Supplier::create(['name' => 'ZZ PU Supplier', 'type' => 'distributor', 'country' => 'Tanzania', 'currency' => 'TZS', 'is_active' => true]);
    }

    private function user(string $role, string $slug): User
    {
        $u = User::forceCreate(['name' => "Test {$slug}", 'email' => "{$slug}@example.com", 'password' => bcrypt('x'), 'role' => $role, 'is_active' => true]);
        $u->assignRole($role);

        return $u;
    }

    /** A received purchase of 5 × TSh 1,000 with the stock in the test store. */
    private function receivedPurchase(): PurchaseOrder
    {
        $po = PurchaseOrder::create([
            'po_number' => 'PU-TEST-' . uniqid(), 'supplier_id' => $this->supplier->id, 'location_id' => $this->store->id,
            'status' => 'received', 'ordered_by' => $this->sales->id, 'currency' => 'TZS', 'total_amount' => 5000,
        ]);
        $po->items()->create(['inventory_item_id' => $this->item->id, 'quantity_ordered' => 5, 'quantity_received' => 5, 'unit_cost' => 1000, 'currency' => 'TZS']);
        $this->actingAs($this->sales);
        app(StockService::class)->add($this->item, $this->store, 5, 1000, 'test receipt', $po);

        return $po->fresh('items');
    }

    private function onHand(): int
    {
        return (int) DB::table('stock_levels')->where('inventory_item_id', $this->item->id)->where('location_id', $this->store->id)->value('quantity_on_hand');
    }

    public function test_the_four_roles_hold_purchases_and_others_do_not(): void
    {
        $r = app(EffectivePermissionResolver::class);
        foreach (['administrator', 'sales', 'sales_manager', 'admin'] as $role) {
            $u = $this->user($role, "pu-role-{$role}");
            $this->assertTrue($r->can($u, 'screens.purchases'), "{$role} screens.purchases");
            $this->assertTrue($u->hasPurchaseCreateAuthority(), "{$role} purchases.create");
        }
        // Purchases doesn't open the rest of procurement (vendors, vendor fees).
        $this->assertFalse($r->can($this->sales, 'procurement.create_po'));
        $this->assertFalse($r->can($this->user('storekeeper', 'pu-store'), 'screens.purchases'));
        $this->assertFalse($r->can($this->user('technician', 'pu-tech'), 'purchases.create'));
    }

    public function test_sales_officer_adds_a_purchase_into_the_approval_chain(): void
    {
        Sanctum::actingAs($this->sales);
        $po = $this->postJson('/api/v1/purchase-orders', [
            'supplier_id' => $this->supplier->id, 'location_id' => $this->store->id, 'currency' => 'TZS',
            'items' => [['inventory_item_id' => $this->item->id, 'quantity_ordered' => 3, 'unit_cost' => 2000]],
        ])->assertCreated()->json('data');
        $this->assertSame('draft', $po['status']);
        $this->postJson("/api/v1/purchase-orders/{$po['id']}/submit-for-approval")->assertOk()->assertJsonPath('data.status', 'pending_sales_manager');
        // and can't approve it (no sales-stage authority, and it's their own)
        $this->postJson("/api/v1/purchase-orders/{$po['id']}/approve-sales-manager")->assertForbidden();

        $row = collect($this->getJson('/api/v1/purchase-orders?period=all&per_page=2000')->assertOk()->json('data'))->firstWhere('id', $po['id']);
        $this->assertNotNull($row);
        $this->assertSame('Test pu-sales', $row['ordered_by']['name']);

        $this->user('technician', 'pu-tech2');
        Sanctum::actingAs(User::where('email', 'pu-tech2@example.com')->first());
        $this->getJson('/api/v1/purchase-orders')->assertForbidden();
    }

    public function test_return_without_an_invoice_only_takes_the_stock_back(): void
    {
        $po = $this->receivedPurchase();
        $line = $po->items->first();
        Sanctum::actingAs($this->sales);

        $ret = $this->postJson('/api/v1/purchase-returns', [
            'purchase_order_id' => $po->id, 'reason' => 'Damaged on arrival',
            'items' => [['purchase_order_item_id' => $line->id, 'quantity' => 2]],
        ])->assertCreated()->json('data');
        $this->assertStringStartsWith('PRET-', $ret['return_number']);
        $this->assertSame(2000, $ret['total_amount']);
        $this->assertFalse($ret['posted']);
        $this->assertSame(3, $this->onHand());
        $this->assertSame('supplier_return', StockMovement::where('reference_type', (new \App\Models\PurchaseReturn)->getMorphClass())
            ->where('reference_id', $ret['id'])->value('type'));

        // Only 3 left to return.
        $this->postJson('/api/v1/purchase-returns', ['purchase_order_id' => $po->id,
            'items' => [['purchase_order_item_id' => $line->id, 'quantity' => 4]]])->assertStatus(422);

        $show = $this->getJson("/api/v1/purchase-orders/{$po->id}")->assertOk()->json('data');
        $this->assertEquals(2, $show['items'][0]['quantity_returned']);
        $row = collect($this->getJson('/api/v1/purchase-orders?period=all&per_page=2000')->json('data'))->firstWhere('id', $po->id);
        $this->assertEquals(2000, $row['returned_amount']);
        $this->assertCount(1, $this->getJson('/api/v1/purchase-returns?period=all&purchase_order_id=' . $po->id)->assertOk()->json('data'));

        $this->get("/api/v1/purchase-orders/{$po->id}/pdf")->assertOk();
        $this->get("/api/v1/purchase-returns/{$ret['id']}/pdf")->assertOk();
    }

    public function test_return_against_an_invoiced_purchase_credits_the_supplier(): void
    {
        $po = $this->receivedPurchase();
        Sanctum::actingAs($this->accountant);
        $bill = $this->postJson('/api/v1/vendor-bills', ['supplier_id' => $this->supplier->id, 'purchase_order_id' => $po->id,
            'supplier_reference' => 'PU-INV-1', 'issue_date' => now()->toDateString(), 'due_date' => now()->addMonth()->toDateString(),
            'line_items' => [['description' => 'Reagent', 'quantity' => 5, 'unit_price' => 1000]]])->assertCreated()->json('data');
        $before = ChartOfAccount::whereIn('code', ['2025', '1006'])->pluck('balance', 'code')->map(fn ($v) => (int) $v);

        Sanctum::actingAs($this->sales);
        $ret = $this->postJson('/api/v1/purchase-returns', ['purchase_order_id' => $po->id,
            'items' => [['purchase_order_item_id' => $po->items->first()->id, 'quantity' => 1]]])->assertCreated()->json('data');
        $this->assertTrue($ret['posted']);
        $this->assertSame(1000, $ret['total_tzs']);

        $after = ChartOfAccount::whereIn('code', ['2025', '1006'])->pluck('balance', 'code')->map(fn ($v) => (int) $v);
        $this->assertSame(-1000, $after['2025'] - $before['2025']);   // Trade Creditors — Local down
        $this->assertSame(-1000, $after['1006'] - $before['1006']);   // Inventory down
        $b = VendorBill::find($bill['id']);
        $this->assertEquals(1000, $b->amount_paid);                   // the return settles part of the invoice
        $this->assertSame('partial', $b->status);

        Sanctum::actingAs($this->accountant);
        $st = $this->getJson("/api/v1/creditors/supplier/{$this->supplier->id}/statement")->assertOk()->json('data');
        $this->assertEquals(4000, $st['closing_balance']);
        $this->assertStringStartsWith('PURCHASE RETURN', collect($st['entries'])->last()['narration']);
        $this->assertSame(4000, $st['book_balance_tzs']);

        $web = $this->getJson("/api/v1/connections/purchase_return/{$ret['id']}")->assertOk()->json('data');
        $this->assertNotEmpty($web['ledger']);
    }
}
