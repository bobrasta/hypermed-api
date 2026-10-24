<?php

namespace Tests\Feature;

use App\Models\Delegation;
use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// 2026-10-24: "Gabriel can only stand as Director if Sharif is not around."
// A standby delegation only takes effect while the person it stands by for
// is on approved leave covering today.
class StandbyDelegationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('needs a migrated Postgres database');
        }
        if (! Schema::hasColumn('delegations', 'standby_for_id')) {
            (require database_path('migrations/2026_10_24_000002_add_standby_for_to_delegations.php'))->up();
        }
    }

    private function user(string $name, string $role): User
    {
        $u = User::forceCreate(['name' => $name, 'email' => str_replace(' ', '.', strtolower($name)) . '@example.com',
            'password' => bcrypt('x'), 'role' => $role, 'is_active' => true]);
        $u->assignRole($role);

        return $u;
    }

    public function test_gabriel_stands_in_only_while_sharif_is_on_leave(): void
    {
        $director = $this->user('Director SB', 'admin');
        $sharif = $this->user('Sharif SB', 'cto');
        $gabriel = $this->user('Gabriel SB', 'sales_manager');

        Sanctum::actingAs($director);
        $d = $this->postJson('/api/v1/delegations', [
            'delegate_id' => $gabriel->id, 'standby_for_id' => $sharif->id, 'reason' => 'Director travelling',
            'starts_at' => now()->subHour()->toIso8601String(), 'ends_at' => now()->addWeek()->toIso8601String(),
        ])->assertCreated()->json('data');
        $this->assertSame(['Sharif SB', true, false], [$d['standby_for_name'], $d['in_period'], $d['is_active']]);

        // Sharif around: Gabriel has no Director authority.
        $this->assertFalse($gabriel->fresh()->hasFinalMoneyAuthority());
        Sanctum::actingAs($gabriel);
        $this->assertNotContains('authority.final_money_approval', $this->getJson('/api/v1/me/permissions')->json('data'));

        // Sharif on approved leave today: Gabriel stands in.
        LeaveRequest::forceCreate(['user_id' => $sharif->id, 'type' => 'annual', 'start_date' => today()->subDay(), 'end_date' => today()->addDay(),
            'days_count' => 3, 'reason' => 'Leave', 'status' => 'approved']);
        $this->assertTrue($gabriel->fresh()->hasFinalMoneyAuthority());
        $this->assertContains('authority.final_money_approval', $this->getJson('/api/v1/me/permissions')->json('data'));
        $this->assertTrue(Delegation::find($d['id'])->isEffective());
    }
}
