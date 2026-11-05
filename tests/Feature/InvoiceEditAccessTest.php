<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// Whoever made a sale can edit it until it is fully paid; accountant-level
// users can edit any sale that isn't cancelled. The list says which via can_edit.
//
// Needs a Postgres database with the seeded roles; skipped on SQLite.
class InvoiceEditAccessTest extends TestCase
{
    use DatabaseTransactions;

    private User $seller;

    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('needs a migrated Postgres database');
        }
        $this->seller = $this->user('accountant', 'edit-access-seller@example.com');
    }

    private function user(string $role, string $email): User
    {
        $u = User::forceCreate(['name' => "Test $role", 'email' => $email, 'password' => bcrypt('x'), 'role' => $role, 'is_active' => true]);
        $u->assignRole($role);

        return $u;
    }

    private function draft(): int
    {
        Sanctum::actingAs($this->seller);

        return $this->postJson('/api/v1/invoices', [
            'sale_status' => 'draft', 'client_name' => 'Test Clinic', 'issue_date' => '2026-11-05', 'pay_term_number' => 30, 'pay_term_type' => 'days',
            'line_items' => [['description' => 'Reagent', 'quantity' => 2, 'unit_price' => 100000]],
        ])->assertCreated()->assertJsonPath('data.can_edit', true)->json('data.id');
    }

    public function test_seller_edits_own_unpaid_sale(): void
    {
        $id = $this->draft();
        $this->patchJson("/api/v1/invoices/{$id}", ['line_items' => [
            ['description' => 'Reagent', 'quantity' => 3, 'unit_price' => 100000],
        ]])->assertOk()->assertJsonPath('data.total', 300000);
    }

    public function test_sales_user_edits_only_own_unpaid_sale(): void
    {
        $id = $this->draft();
        $own = $this->user('sales', 'edit-access-own@example.com');
        \App\Models\Invoice::withUnfinal()->whereKey($id)->update(['created_by' => $own->id]);
        Sanctum::actingAs($own);
        $this->getJson("/api/v1/invoices/{$id}")->assertOk()->assertJsonPath('data.can_edit', true);
        $this->patchJson("/api/v1/invoices/{$id}", ['notes' => 'mine'])->assertOk();
        \App\Models\Invoice::withUnfinal()->whereKey($id)->update(['status' => 'paid', 'amount_paid' => 200000]);
        $this->getJson("/api/v1/invoices/{$id}")->assertJsonPath('data.can_edit', false);
        $this->patchJson("/api/v1/invoices/{$id}", ['notes' => 'x'])->assertForbidden();
        \App\Models\Invoice::withUnfinal()->whereKey($id)->update(['status' => 'draft', 'amount_paid' => 0]);
        Sanctum::actingAs($this->user('sales', 'edit-access-other@example.com'));
        $this->getJson("/api/v1/invoices/{$id}")->assertOk()->assertJsonPath('data.can_edit', false);
        $this->patchJson("/api/v1/invoices/{$id}", ['notes' => 'x'])->assertForbidden();
    }

    public function test_accountant_still_edits_paid_sale(): void
    {
        $id = $this->draft();
        \App\Models\Invoice::withUnfinal()->whereKey($id)->update(['status' => 'paid', 'amount_paid' => 200000]);
        $this->getJson("/api/v1/invoices/{$id}")->assertJsonPath('data.can_edit', true);
        $this->patchJson("/api/v1/invoices/{$id}", ['notes' => 'x'])->assertOk();
    }
}
