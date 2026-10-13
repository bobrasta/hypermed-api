<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\EffectivePermissionResolver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

// The CTO holds admin-tier through a per-user override: he sees and does
// everything the Director does except final authorisation of money, payment
// handling and the Role Builder — and nobody but the Director can hand out
// admin-tier or the money permission.
//
// Needs a Postgres database with the seeded roles (DB_* env vars); skipped on
// the default in-memory SQLite. Each test runs in a rolled-back transaction.
class CtoAdminTierTest extends TestCase
{
    use DatabaseTransactions;

    private User $director;
    private User $cto;
    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('needs a migrated Postgres database');
        }
        Cache::flush();

        // Same as the 2026_10_13 migration, in case it hasn't run locally.
        $money = Permission::firstOrCreate(
            ['name' => 'authority.final_money_approval', 'guard_name' => 'web'],
            ['module' => 'authority', 'label' => 'Final Authorisation of Money']
        );
        $admin = Role::where('name', 'admin')->firstOrFail();
        if (! $admin->hasPermissionTo($money)) {
            $admin->givePermissionTo($money);
        }

        $this->director = $this->user('admin');
        $this->cto = $this->user('cto');
        $this->hr = $this->user('hr');

        DB::table('user_permission_overrides')->insert([
            'user_id'       => $this->cto->id,
            'permission_id' => Permission::where('name', 'authority.admin_tier')->value('id'),
            'effect'        => 'allow',
            'created_at'    => now(),
        ]);
    }

    private function user(string $role): User
    {
        $u = User::forceCreate([
            'name' => "Test {$role}", 'email' => "cto-admin-test-{$role}@example.com",
            'password' => bcrypt('x'), 'role' => $role, 'is_active' => true,
        ]);
        $u->assignRole($role);

        return $u;
    }

    public function test_cto_gets_everything_except_reserved_permissions(): void
    {
        $r = app(EffectivePermissionResolver::class);

        foreach (['screens.finance', 'screens.hr_payroll', 'staff.manage', 'tenders.manage', 'sales.approve_order', 'hr.approve_leave'] as $key) {
            $this->assertTrue($r->can($this->cto, $key), $key);
        }
        foreach (EffectivePermissionResolver::RESERVED as $key) {
            $this->assertFalse($r->can($this->cto, $key), $key);
        }

        $this->assertTrue($this->cto->hasDirectorAuthority());
        $this->assertFalse($this->cto->hasFinalMoneyAuthority());
        $this->assertFalse($this->cto->isDirector());
        $this->assertTrue($this->director->hasFinalMoneyAuthority());
        $this->assertTrue($this->director->isDirector());
    }

    public function test_deny_override_beats_admin_tier_expansion(): void
    {
        DB::table('user_permission_overrides')->insert([
            'user_id'       => $this->cto->id,
            'permission_id' => Permission::where('name', 'tenders.manage')->value('id'),
            'effect'        => 'deny',
            'created_at'    => now(),
        ]);

        $this->assertFalse(app(EffectivePermissionResolver::class)->can($this->cto, 'tenders.manage'));
    }

    public function test_notifications_go_to_explicit_holders_only(): void
    {
        $hrQueue = User::holdingPermission('hr.approve_leave')->pluck('id');
        $this->assertTrue($hrQueue->contains($this->hr->id));
        $this->assertTrue($hrQueue->contains($this->director->id));
        $this->assertFalse($hrQueue->contains($this->cto->id));

        $directorQueue = User::holdingPermission('authority.final_money_approval')->pluck('id');
        $this->assertTrue($directorQueue->contains($this->director->id));
        $this->assertFalse($directorQueue->contains($this->cto->id));

        $this->assertTrue(User::holdingPermission('authority.cto_tier')->pluck('id')->contains($this->cto->id));
    }

    public function test_cto_cannot_change_money_routing_but_can_change_other_settings(): void
    {
        Sanctum::actingAs($this->cto);
        $this->putJson('/api/v1/settings/expense_director_threshold', ['value' => '1'])->assertForbidden();
        $this->putJson('/api/v1/settings/reminder_lead_days', ['value' => '5'])->assertOk();

        Sanctum::actingAs($this->director);
        $this->putJson('/api/v1/settings/expense_director_threshold', ['value' => '1'])->assertOk();
    }

    public function test_cto_cannot_delegate_or_use_the_role_builder(): void
    {
        Sanctum::actingAs($this->cto);
        $this->postJson('/api/v1/delegations', [])->assertForbidden();
        $this->postJson("/api/v1/users/{$this->hr->id}/permission-overrides", ['key' => 'screens.finance', 'effect' => 'allow'])
            ->assertForbidden();
    }

    public function test_only_the_director_grants_admin_tier_or_money_permissions(): void
    {
        // HR holds roles.manage, but that must not reach admin-tier.
        Sanctum::actingAs($this->hr);
        $this->postJson("/api/v1/users/{$this->hr->id}/permission-overrides", ['key' => 'authority.admin_tier', 'effect' => 'allow'])
            ->assertForbidden();
        $this->postJson("/api/v1/users/{$this->hr->id}/permission-overrides", ['key' => 'authority.final_money_approval', 'effect' => 'allow'])
            ->assertForbidden();
        $this->postJson("/api/v1/users/{$this->hr->id}/permission-overrides", ['key' => 'screens.finance', 'effect' => 'allow'])
            ->assertCreated();

        Sanctum::actingAs($this->director);
        $this->postJson("/api/v1/users/{$this->hr->id}/permission-overrides", ['key' => 'authority.admin_tier', 'effect' => 'allow'])
            ->assertCreated();
    }

    public function test_cto_cannot_edit_the_directors_account(): void
    {
        Sanctum::actingAs($this->cto);
        $this->putJson("/api/v1/staff/{$this->director->id}", ['name' => 'Renamed'])->assertForbidden();
    }
}
