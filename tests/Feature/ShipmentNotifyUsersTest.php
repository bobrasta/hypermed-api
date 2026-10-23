<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

// Shipments 2026-10-23: people picked on the shipment are notified of its
// creation and status changes, and can open it; nobody else gains access.
//
// Needs a migrated Postgres database with the seeded roles; skipped on
// SQLite. Missing migrations run inside the test transaction.
class ShipmentNotifyUsersTest extends TestCase
{
    use DatabaseTransactions;

    private User $officer;

    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('needs a migrated Postgres database');
        }
        foreach ([
            'cost_bearer' => '2026_10_20_000001_add_courier_coa_and_cost_bearer_to_shipments',
            'goods_category' => '2026_10_23_000001_add_goods_category_to_shipments',
            'cost_note' => '2026_10_23_000002_add_cost_note_and_permit_sent_to_shipments',
        ] as $column => $migration) {
            if (! Schema::hasColumn('shipments', $column)) {
                (require database_path("migrations/{$migration}.php"))->up();
            }
        }
        if (! Schema::hasTable('shipment_notify_users')) {
            (require database_path('migrations/2026_10_23_000003_create_shipment_notify_users_table.php'))->up();
        }
        $this->officer = $this->user('Test ship officer', 'procurement_manager');
        Sanctum::actingAs($this->officer);
    }

    private function user(string $name, string $role): User
    {
        $u = User::forceCreate([
            'name' => $name, 'email' => str_replace(' ', '.', strtolower($name)) . '@example.com',
            'password' => bcrypt('x'), 'role' => $role, 'is_active' => true,
        ]);
        $u->assignRole($role);

        return $u;
    }

    public function test_picked_people_are_notified_and_can_open_it(): void
    {
        $a = $this->user('Notify Engineer A', 'technician');
        $b = $this->user('Notify Engineer B', 'technician');
        $c = $this->user('Notify Engineer C', 'technician');

        $s = $this->postJson('/api/v1/shipments', [
            'direction' => 'import', 'freight_mode' => 'air', 'description' => 'Liaison XL reagents',
            'notify_user_ids' => [$a->id, $b->id],
        ])->assertCreated()->json('data');
        $this->assertEqualsCanonicalizing([$a->id, $b->id], collect($s['notify_users'])->pluck('id')->all());
        $this->assertTrue(collect($s['recipients'])->pluck('name')->contains('Notify Engineer A'));

        foreach ([$a, $b] as $u) {
            $this->assertTrue(AppNotification::where('user_id', $u->id)->where('entity_type', 'shipment')->where('entity_id', $s['id'])->exists());
        }
        $this->assertFalse(AppNotification::where('user_id', $c->id)->where('entity_id', $s['id'])->exists());

        // Edit: swap B for C — C hears about the next status change, B doesn't.
        $this->putJson("/api/v1/shipments/{$s['id']}", ['notify_user_ids' => [$a->id, $c->id]])->assertOk();
        $this->assertTrue(Activity::where('subject_id', $s['id'])->where('description', 'people to notify updated')->exists());

        Sanctum::actingAs($a);
        $this->getJson("/api/v1/shipments/{$s['id']}")->assertOk();
        $this->assertContains($s['id'], collect($this->getJson('/api/v1/shipments')->assertOk()->json('data.shipments'))->pluck('id')->all());
        Sanctum::actingAs($b);
        $this->getJson("/api/v1/shipments/{$s['id']}")->assertForbidden();
    }

    public function test_inactive_people_cannot_be_picked(): void
    {
        $gone = $this->user('Notify Gone', 'technician');
        $gone->forceFill(['is_active' => false])->save();
        $this->postJson('/api/v1/shipments', [
            'direction' => 'import', 'freight_mode' => 'air', 'description' => 'x', 'notify_user_ids' => [$gone->id],
        ])->assertStatus(422)->assertJsonValidationErrors('notify_user_ids.0');
    }

    public function test_multipart_create_takes_the_list(): void
    {
        $a = $this->user('Notify Multipart', 'technician');
        $s = $this->post('/api/v1/shipments', [
            'direction' => 'import', 'freight_mode' => 'air', 'description' => 'x', 'coa_required' => '1',
            'notify_user_ids' => [(string) $a->id],
        ], ['Accept' => 'application/json'])->assertCreated()->json('data');
        $this->assertSame([$a->id], collect($s['notify_users'])->pluck('id')->all());
    }
}
