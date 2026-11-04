<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\ApprovalLog;
use App\Models\ExpenseCategory;
use App\Models\InventoryItem;
use App\Models\LeaveType;
use App\Models\Location;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// The Director approves past every stage (2026-11-04): their own requests
// are approved at once, "Approve now" skips the people still to act, and
// Finance still records payments.
//
// Needs a migrated Postgres database with the seeded roles; skipped on SQLite.
class DirectorFastTrackTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('needs a migrated Postgres database');
        }
        Cache::flush();
    }

    private function user(string $role, string $slug, array $extra = []): User
    {
        return User::where('email', "{$slug}@example.com")->first()
            ?? tap(User::forceCreate(['name' => "Test {$slug}", 'email' => "{$slug}@example.com", 'password' => bcrypt('x'), 'role' => $role, 'is_active' => true] + $extra),
                fn ($u) => $u->assignRole($role));
    }

    private function as(string $role, string $slug, array $extra = []): User
    {
        $u = $this->user($role, $slug, $extra);
        Sanctum::actingAs($u);

        return $u;
    }

    private function draftPo(): int
    {
        $store = Location::create(['name' => 'FT store', 'code' => 'FT-STORE', 'type' => 'warehouse', 'is_active' => true]);
        $item = InventoryItem::forceCreate(['sku' => 'FT-1', 'name' => 'FT reagent', 'unit_cost' => 1000]);
        $supplier = Supplier::create(['name' => 'ZZ FT Supplier', 'type' => 'distributor', 'country' => 'Tanzania', 'currency' => 'TZS', 'is_active' => true]);

        return $this->postJson('/api/v1/purchase-orders', [
            'supplier_id' => $supplier->id, 'location_id' => $store->id, 'currency' => 'TZS',
            'items' => [['inventory_item_id' => $item->id, 'quantity_ordered' => 4, 'unit_cost' => 2500]],
        ])->assertCreated()->json('data.id');
    }

    public function test_the_directors_own_po_skips_to_finance_then_finishes_on_its_own(): void
    {
        $director = $this->as('admin', 'ft-director');
        $po = $this->draftPo();

        $this->postJson("/api/v1/purchase-orders/{$po}/submit-for-approval")->assertOk()
            ->assertJsonPath('data.status', 'pending_payment_initiation');

        // Finance records it (on credit); the Director's final approval follows by itself.
        $this->as('accountant', 'ft-acct');
        $this->postJson("/api/v1/purchase-orders/{$po}/initiate-payment", ['on_credit' => true])->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->assertDatabaseHas('purchase_orders', ['id' => $po, 'director_approved_by' => $director->id]);
        $this->assertTrue(ApprovalLog::where('subject_type', 'PurchaseOrder')->where('subject_id', $po)->where('action', 'fast_tracked')->exists());
    }

    public function test_approve_now_skips_the_sales_manager_and_tells_them(): void
    {
        $this->as('procurement_manager', 'ft-proc');
        $po = $this->draftPo();
        $this->postJson("/api/v1/purchase-orders/{$po}/submit-for-approval")->assertJsonPath('data.status', 'pending_sales_manager');
        $salesManager = $this->user('sales_manager', 'ft-salesmgr');

        // Not the Director: refused.
        $this->as('cto', 'ft-cto');
        $this->postJson('/api/v1/approvals/fast-track', ['type' => 'purchase_order', 'id' => $po])->assertForbidden();

        $this->as('admin', 'ft-director');
        $this->postJson('/api/v1/approvals/fast-track', ['type' => 'purchase_order', 'id' => $po])->assertOk()
            ->assertJsonPath('data.status', 'pending_payment_initiation');

        $this->assertTrue(AppNotification::where('user_id', $salesManager->id)->where('entity_id', $po)->where('type', 'approval_fast_tracked')->exists());
        $this->assertTrue(AppNotification::where('user_id', User::where('email', 'ft-proc@example.com')->value('id'))->where('entity_id', $po)->where('type', 'approval_fast_tracked')->exists());

        // Nothing left to approve now: Finance's turn.
        $this->postJson('/api/v1/approvals/fast-track', ['type' => 'purchase_order', 'id' => $po])->assertStatus(422);
    }

    public function test_approve_now_on_an_expense_waiting_on_a_manager_goes_to_finance(): void
    {
        $manager = $this->user('sales_manager', 'ft-mgr');
        $this->as('sales', 'ft-rep', ['manager_id' => $manager->id]);
        $category = ExpenseCategory::query()->firstOrFail();
        $expense = $this->postJson('/api/v1/expenses', [
            'name' => 'FT taxi', 'category_id' => $category->id, 'amount' => 20000,
            'payment_mode' => 'cash', 'expense_date' => now()->toDateString(),
        ])->assertCreated()->assertJsonPath('data.status', 'pending_manager')->json('data.id');

        $this->as('admin', 'ft-director');
        $this->postJson('/api/v1/approvals/fast-track', ['type' => 'expense', 'id' => $expense])->assertOk()
            ->assertJsonPath('data.status', 'pending_payment');
        $this->assertTrue(AppNotification::where('user_id', $manager->id)->where('entity_id', $expense)->where('type', 'approval_fast_tracked')->exists());
    }

    public function test_the_directors_own_expense_and_leave_are_approved_at_once(): void
    {
        $this->as('admin', 'ft-director');
        $category = ExpenseCategory::query()->firstOrFail();
        $this->postJson('/api/v1/expenses', [
            'name' => 'FT director fuel', 'category_id' => $category->id, 'amount' => 50000,
            'payment_mode' => 'cash', 'expense_date' => now()->toDateString(),
        ])->assertCreated()->assertJsonPath('data.status', 'pending_payment');

        $type = LeaveType::where('active', true)->where('auto_from_calendar', false)->where('requires_manual_days', false)->firstOrFail();
        $this->postJson('/api/v1/leave-requests', [
            'leave_type_id' => $type->id, 'start_date' => now()->addMonth()->toDateString(), 'end_date' => now()->addMonth()->addDay()->toDateString(),
        ])->assertCreated()->assertJsonPath('data.status', 'approved');
    }
}
