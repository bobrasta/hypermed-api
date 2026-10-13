<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Task;
use App\Models\User;
use App\Services\EffectivePermissionResolver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// The ADMINISTRATOR role (full HR + sales staff + org chart + meetings, no
// money or sales approvals) and the meetings register: attendees see their
// own meetings and, once circulated, the minutes; action items are tasks.
//
// Needs a Postgres database with the seeded roles; skipped on SQLite. The
// 2026_10_13 migrations run inside the test transaction when they haven't
// been applied yet, so nothing persists (Postgres DDL is transactional).
class AdministratorMeetingsTest extends TestCase
{
    use DatabaseTransactions;

    private User $administrator;
    private User $attendee;
    private User $outsider;

    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('needs a migrated Postgres database');
        }
        foreach ([
            '2026_10_13_000001_add_final_money_authority' => fn () => DB::table('permissions')->where('name', 'authority.final_money_approval')->exists(),
            '2026_10_13_000002_create_administrator_role' => fn () => DB::table('roles')->where('name', 'administrator')->exists(),
            '2026_10_13_000003_create_meetings_tables'    => fn () => Schema::hasTable('meetings'),
        ] as $file => $applied) {
            if (! $applied()) {
                (require database_path("migrations/{$file}.php"))->up();
            }
        }
        Cache::flush();

        $this->administrator = $this->user('administrator', 'admin-test');
        $this->attendee = $this->user('technician', 'attendee-test');
        $this->outsider = $this->user('technician', 'outsider-test');
    }

    private function user(string $role, string $slug): User
    {
        $u = User::forceCreate([
            'name' => "Test {$slug}", 'email' => "{$slug}@example.com",
            'password' => bcrypt('x'), 'role' => $role, 'is_active' => true,
        ]);
        $u->assignRole($role);

        return $u;
    }

    public function test_administrator_permissions(): void
    {
        $r = app(EffectivePermissionResolver::class);
        foreach (['hr.approve_leave', 'staff.manage', 'screens.hr_payroll', 'sales.create', 'sales.issue_quotation', 'screens.email', 'org_chart.manage', 'meetings.manage'] as $key) {
            $this->assertTrue($r->can($this->administrator, $key), $key);
        }
        foreach (['sales.approve_order', 'authority.admin_tier', 'authority.final_money_approval', 'procurement.initiate_payment'] as $key) {
            $this->assertFalse($r->can($this->administrator, $key), $key);
        }
        $this->assertTrue(User::holdingPermission('hr.approve_leave')->pluck('id')->contains($this->administrator->id));

        Sanctum::actingAs($this->administrator);
        $this->putJson('/api/v1/settings/org_chart_json', ['value' => '[]'])->assertOk();
        $this->putJson('/api/v1/settings/reminder_lead_days', ['value' => '5'])->assertForbidden();
        $this->postJson("/api/v1/users/{$this->attendee->id}/permission-overrides", ['key' => 'authority.admin_tier', 'effect' => 'allow'])
            ->assertForbidden();
    }

    public function test_meeting_lifecycle(): void
    {
        Sanctum::actingAs($this->administrator);
        $meeting = $this->postJson('/api/v1/meetings', [
            'title' => 'Monthly management meeting',
            'category' => 'management',
            'starts_at' => '2026-10-20 09:00:00',
            'location' => 'Board room',
            'agenda' => "1. Sales\n2. Service",
            'attendees' => [
                ['user_id' => $this->attendee->id],
                ['guest_name' => 'Dr. Guest', 'guest_organisation' => 'Muhimbili'],
            ],
        ])->assertCreated()->json('data');
        $id = $meeting['id'];
        $this->assertCount(2, $meeting['attendees']);
        $this->assertTrue(AppNotification::where('user_id', $this->attendee->id)->where('entity_type', 'meeting')->where('entity_id', $id)->exists());

        $this->putJson("/api/v1/meetings/{$id}", [
            'status' => 'held',
            'minutes' => 'Sales up 10%.',
            'decisions' => 'Hire one more engineer.',
            'attendees' => [
                ['user_id' => $this->attendee->id, 'attendance' => 'present'],
                ['guest_name' => 'Dr. Guest', 'attendance' => 'apologies'],
            ],
        ])->assertOk();

        $item = $this->postJson("/api/v1/meetings/{$id}/action-items", [
            'description' => 'Draft the engineer job advert',
            'owner_id' => $this->attendee->id,
            'due_date' => '2026-10-31',
        ])->assertCreated()->json('data');
        $task = Task::findOrFail($item['task_id']);
        $this->assertSame($this->attendee->id, $task->assigned_to);
        $this->assertSame('open', $item['status']);

        // Outsider can't see it; the attendee can, without draft minutes.
        Sanctum::actingAs($this->outsider);
        $this->getJson("/api/v1/meetings/{$id}")->assertForbidden();
        $this->assertCount(0, $this->getJson('/api/v1/meetings')->json('data'));

        Sanctum::actingAs($this->attendee);
        $this->assertCount(1, $this->getJson('/api/v1/meetings')->json('data'));
        $seen = $this->getJson("/api/v1/meetings/{$id}")->assertOk()->json('data');
        $this->assertNull($seen['minutes']);
        $this->assertFalse($seen['can_manage']);
        $this->putJson("/api/v1/meetings/{$id}", ['title' => 'x'])->assertForbidden();

        // Finishing the task marks the action item done.
        $this->putJson("/api/v1/tasks/{$task->id}", ['status' => 'completed'])->assertOk();
        $this->assertSame('done', $this->getJson("/api/v1/meetings/{$id}")->json('data.action_items.0.status'));

        Sanctum::actingAs($this->administrator);
        $this->postJson("/api/v1/meetings/{$id}/circulate")->assertOk();
        $this->assertTrue(AppNotification::where('user_id', $this->attendee->id)->where('type', 'meeting_minutes')->exists());

        Sanctum::actingAs($this->attendee);
        $this->assertSame('Sales up 10%.', $this->getJson("/api/v1/meetings/{$id}")->json('data.minutes'));
        $this->get("/api/v1/meetings/{$id}/pdf")->assertOk()->assertHeader('content-type', 'application/pdf');

        Sanctum::actingAs($this->administrator);
        $report = $this->getJson('/api/v1/meetings/report?' . http_build_query(['date_from' => '2026-10-01', 'date_to' => '2026-10-31']))
            ->assertOk()->json('data');
        $this->assertGreaterThanOrEqual(1, $report['meetings']['circulated']);
        $this->assertGreaterThanOrEqual(1, $report['action_items']['done']);

        Sanctum::actingAs($this->attendee);
        $this->getJson('/api/v1/meetings/report')->assertForbidden();
    }
}
