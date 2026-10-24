<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// 2026-10-24: expenses go to the submitter's own manager first, then
// straight to Finance; Director-only categories go on to the Director;
// a manager who is the Director takes it at the Director stage; nobody
// above you → the old CTO stage.
//
// Needs a migrated Postgres database with the seeded roles; skipped on
// SQLite. The new migration runs inside the test transaction if needed.
class LineManagerExpenseApprovalTest extends TestCase
{
    use DatabaseTransactions;

    private User $director;
    private User $gabriel;
    private User $florian;

    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('needs a migrated Postgres database');
        }
        if (! Schema::hasColumn('expenses', 'approver_id')) {
            (require database_path('migrations/2026_10_24_000001_line_manager_expense_approval.php'))->up();
        }
        $this->director = $this->user('Director LM', 'admin');
        $this->gabriel = $this->user('Gabriel LM', 'sales_manager', $this->director);
        $this->florian = $this->user('Florian LM', 'sales', $this->gabriel);
    }

    private function user(string $name, string $role, ?User $manager = null): User
    {
        $u = User::forceCreate([
            'name' => $name, 'email' => str_replace(' ', '.', strtolower($name)) . '@example.com',
            'password' => bcrypt('x'), 'role' => $role, 'is_active' => true, 'manager_id' => $manager?->id,
        ]);
        $u->assignRole($role);

        return $u;
    }

    private function submit(User $as, array $over = []): array
    {
        Sanctum::actingAs($as);
        $category = ExpenseCategory::where('requires_director_approval', false)->firstOrFail();

        return $this->postJson('/api/v1/expenses', $over + [
            'name' => 'Fuel to Bugando', 'category_id' => $category->id, 'amount' => 80_000,
            'payment_mode' => 'cash', 'expense_date' => now()->toDateString(),
        ])->assertCreated()->json('data');
    }

    public function test_goes_to_own_manager_then_finance(): void
    {
        $e = $this->submit($this->florian);
        $this->assertSame(['pending_manager', $this->gabriel->id], [$e['status'], $e['approver_id']]);
        $this->assertTrue(AppNotification::where('user_id', $this->gabriel->id)->where('entity_type', 'expense')->where('entity_id', $e['id'])->exists());

        // Florian sees it in My Expenses, without Finance access.
        $this->assertContains($e['id'], collect($this->getJson('/api/v1/expenses?mine=1')->assertOk()->json('data'))->pluck('id')->all());
        $this->postJson("/api/v1/expenses/{$e['id']}/approve")->assertForbidden(); // not his own

        // Someone else's manager can't act on it.
        Sanctum::actingAs($this->user('Other Lead LM', 'team_leader'));
        $this->postJson("/api/v1/expenses/{$e['id']}/approve")->assertForbidden();

        // Gabriel sees it waiting on him and approves → Finance.
        Sanctum::actingAs($this->gabriel);
        $this->assertContains($e['id'], collect($this->getJson('/api/v1/expenses')->assertOk()->json('data'))->pluck('id')->all());
        $this->getJson("/api/v1/expenses/{$e['id']}")->assertOk();
        $r = $this->postJson("/api/v1/expenses/{$e['id']}/approve")->assertOk()->json('data');
        $this->assertSame('pending_payment', $r['status']);
    }

    public function test_director_only_category_goes_on_to_the_director(): void
    {
        $category = ExpenseCategory::where('requires_director_approval', false)->firstOrFail();
        $category->update(['requires_director_approval' => true]);
        $e = $this->submit($this->florian, ['category_id' => $category->id]);
        $this->assertSame('pending_manager', $e['status']);

        Sanctum::actingAs($this->gabriel);
        $this->assertSame('pending_director', $this->postJson("/api/v1/expenses/{$e['id']}/approve")->assertOk()->json('data.status'));
        Sanctum::actingAs($this->director);
        $this->assertSame('pending_payment', $this->postJson("/api/v1/expenses/{$e['id']}/approve")->assertOk()->json('data.status'));
    }

    public function test_manager_who_is_director_and_no_manager_fallback(): void
    {
        $e = $this->submit($this->gabriel);
        $this->assertSame(['pending_director', $this->director->id], [$e['status'], $e['approver_id']]);

        $loner = $this->user('No Manager LM', 'storekeeper');
        $this->assertSame('pending_cto', $this->submit($loner)['status']);
    }

    /** "Yusuph only acts as a filter": a team leader passes it on to their manager, who approves. */
    public function test_team_leader_filters_then_their_manager_approves(): void
    {
        $sharif = $this->user('Sharif LM', 'cto', $this->director);
        $yusuph = $this->user('Yusuph LM', 'team_leader', $sharif);
        $tech = $this->user('Technician LM', 'technician', $yusuph);

        $e = $this->submit($tech);
        $this->assertSame(['pending_manager', $yusuph->id, true], [$e['status'], $e['approver_id'], $e['approver_is_filter']]);
        $this->assertSame('Sharif LM', $e['next_approver_name']);

        Sanctum::actingAs($yusuph);
        $r = $this->postJson("/api/v1/expenses/{$e['id']}/approve")->assertOk()->json('data');
        $this->assertSame(['pending_manager', $sharif->id, false], [$r['status'], $r['approver_id'], $r['approver_is_filter']]);
        $this->assertTrue(AppNotification::where('user_id', $sharif->id)->where('entity_id', $e['id'])->exists());
        $this->postJson("/api/v1/expenses/{$e['id']}/approve")->assertForbidden(); // passed on: no longer his

        Sanctum::actingAs($sharif);
        $this->assertSame('pending_payment', $this->postJson("/api/v1/expenses/{$e['id']}/approve")->assertOk()->json('data.status'));

        // Yusuph's own expense goes straight to Sharif; a filter can also reject.
        $own = $this->submit($yusuph);
        $this->assertSame([$sharif->id, false], [$own['approver_id'], $own['approver_is_filter']]);
        $junk = $this->submit($tech);
        Sanctum::actingAs($yusuph);
        $this->postJson("/api/v1/expenses/{$junk['id']}/reject", ['rejection_reason' => 'Not needed'])->assertOk();
    }

    public function test_manager_can_reject_and_requester_is_told(): void
    {
        $e = $this->submit($this->florian);
        Sanctum::actingAs($this->gabriel);
        $this->postJson("/api/v1/expenses/{$e['id']}/reject", ['rejection_reason' => 'No receipt'])->assertOk();
        $this->assertSame('rejected', Expense::find($e['id'])->status);
        $this->assertTrue(AppNotification::where('user_id', $this->florian->id)->where('entity_id', $e['id'])->exists());
    }
}
