<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\Location;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// A purchase order end to end, as the roles on prod do it: procurement
// creates → submits → sales manager → director review → accountant (on
// credit / payment) → a second director's final approval → send → store
// receives and the stock goes up.
//
// Needs a migrated Postgres database with the seeded roles; skipped on SQLite.
class PurchaseOrderFlowTest extends TestCase
{
    use DatabaseTransactions;

    private Location $store;
    private InventoryItem $item;
    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('needs a migrated Postgres database');
        }
        Cache::flush();
        $this->store = Location::create(['name' => 'PO flow store', 'code' => 'PO-FLOW', 'type' => 'warehouse', 'is_active' => true]);
        $this->item = InventoryItem::forceCreate(['sku' => 'PO-FLOW-1', 'name' => 'PO flow reagent', 'unit_cost' => 1000]);
        $this->supplier = Supplier::create(['name' => 'ZZ PO Flow Supplier', 'type' => 'distributor', 'country' => 'Tanzania', 'currency' => 'TZS', 'is_active' => true]);
    }

    private function as(string $role, string $slug): User
    {
        $u = User::where('email', "{$slug}@example.com")->first()
            ?? tap(User::forceCreate(['name' => "Test {$slug}", 'email' => "{$slug}@example.com", 'password' => bcrypt('x'), 'role' => $role, 'is_active' => true]),
                fn ($u) => $u->assignRole($role));
        Sanctum::actingAs($u);

        return $u;
    }

    private function onHand(): int
    {
        return (int) DB::table('stock_levels')->where('inventory_item_id', $this->item->id)->where('location_id', $this->store->id)->value('quantity_on_hand');
    }

    private function step(string $role, string $slug, string $action, array $body = [], int $status = 200)
    {
        $this->as($role, $slug);

        return $this->postJson("/api/v1/purchase-orders/{$this->po}/{$action}", $body)->assertStatus($status);
    }

    private int $po;

    public function test_a_purchase_order_goes_from_draft_to_stock(): void
    {
        $this->as('procurement_manager', 'po-proc');
        $this->po = $this->postJson('/api/v1/purchase-orders', [
            'supplier_id' => $this->supplier->id, 'location_id' => $this->store->id, 'currency' => 'TZS',
            'items' => [['inventory_item_id' => $this->item->id, 'quantity_ordered' => 10, 'unit_cost' => 1500]],
        ])->assertCreated()->assertJsonPath('data.status', 'draft')->json('data.id');

        $this->step('procurement_manager', 'po-proc', 'submit-for-approval')->assertJsonPath('data.status', 'pending_sales_manager');
        $this->step('sales_manager', 'po-salesmgr', 'approve-sales-manager')->assertJsonPath('data.status', 'pending_director_review');
        $this->step('admin', 'po-director', 'approve-director-review')->assertJsonPath('data.status', 'pending_payment_initiation');
        $this->step('accountant', 'po-acct', 'initiate-payment', ['on_credit' => true])->assertJsonPath('data.status', 'pending_director_final');

        // The director who reviewed it can't also give the final approval.
        $this->step('admin', 'po-director', 'approve-director-final', [], 403);
        $this->step('admin', 'po-director2', 'approve-director-final')->assertJsonPath('data.status', 'approved');

        $this->step('procurement_manager', 'po-proc', 'send')->assertJsonPath('data.status', 'sent');

        $line = DB::table('purchase_order_items')->where('purchase_order_id', $this->po)->value('id');
        $this->step('storekeeper', 'po-store', 'receive', ['items' => [
            ['purchase_order_item_id' => $line, 'quantity_received' => 10, 'batch_number' => 'LOT-PO-1', 'expiry_date' => '2028-01-31'],
        ]])->assertJsonPath('data.status', 'received');

        $this->assertSame(10, $this->onHand());

        // The PO PDF (letterhead, supplier contact, approvals) renders.
        $pdf = $this->get("/api/v1/purchase-orders/{$this->po}/pdf")->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
    }

    public function test_unit_cost_takes_cents_and_the_total_adds_up(): void
    {
        $this->as('procurement_manager', 'po-proc');
        $res = $this->postJson('/api/v1/purchase-orders', [
            'supplier_id' => $this->supplier->id, 'location_id' => $this->store->id, 'currency' => 'USD',
            'items' => [
                ['inventory_item_id' => $this->item->id, 'quantity_ordered' => 3, 'unit_cost' => 12.5],
                ['inventory_item_id' => $this->item->id, 'quantity_ordered' => 2, 'unit_cost' => 0.99],
            ],
        ])->assertCreated();
        $this->assertEquals(39.48, $res->json('data.total_amount'));
        $this->assertEquals(12.5, $res->json('data.items.0.unit_cost'));
        $this->assertEquals(37.5, $res->json('data.items.0.total_cost'));
    }

    public function test_a_draft_or_rejected_order_can_be_edited_items_included(): void
    {
        $this->as('procurement_manager', 'po-proc');
        $other = InventoryItem::forceCreate(['sku' => 'PO-FLOW-2', 'name' => 'PO flow gloves', 'unit_cost' => 0]);
        $this->po = $this->postJson('/api/v1/purchase-orders', [
            'supplier_id' => $this->supplier->id, 'location_id' => $this->store->id, 'currency' => 'TZS',
            'items' => [['inventory_item_id' => $this->item->id, 'quantity_ordered' => 10, 'unit_cost' => 1500]],
        ])->assertCreated()->json('data.id');

        $this->putJson("/api/v1/purchase-orders/{$this->po}", [
            'notes' => 'Size 7.5 only', 'currency' => 'USD',
            'items' => [
                ['inventory_item_id' => $this->item->id, 'quantity_ordered' => 4, 'unit_cost' => 2.25, 'currency' => 'USD'],
                ['inventory_item_id' => $other->id, 'quantity_ordered' => 2, 'unit_cost' => 10, 'currency' => 'USD'],
            ],
        ])->assertOk()->assertJsonPath('data.notes', 'Size 7.5 only')->assertJsonPath('data.currency', 'USD')
            ->assertJsonCount(2, 'data.items');
        $this->assertEquals(29.0, DB::table('purchase_orders')->where('id', $this->po)->value('total_amount'));

        // Rejected by the sales manager → edited → back to draft, ready to resubmit.
        $this->step('procurement_manager', 'po-proc', 'submit-for-approval');
        $this->step('sales_manager', 'po-salesmgr', 'reject-sales-manager', ['rejection_reason' => 'Wrong size']);
        $this->as('procurement_manager', 'po-proc');
        $this->putJson("/api/v1/purchase-orders/{$this->po}", ['notes' => 'Fixed'])->assertOk()
            ->assertJsonPath('data.status', 'draft')->assertJsonPath('data.rejection_reason', null);

        // Once in approval it can't be edited.
        $this->step('procurement_manager', 'po-proc', 'submit-for-approval');
        $this->putJson("/api/v1/purchase-orders/{$this->po}", ['notes' => 'late'])->assertStatus(422);
    }
}
