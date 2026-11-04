<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

// In-app messaging inside Hypermed: only active staff can chat (no vendor
// portal accounts, no deactivated users), and chat review needs the
// Director's switch (off by default) plus the reserved
// messaging.read_others permission, which admin-tier doesn't imply and only
// the Director can grant. Every review read is in the Activity Log.
//
// Needs a migrated Postgres database with the seeded roles; skipped on SQLite.
class MessagingStaffAndChatReviewTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('needs a migrated Postgres database');
        }
        Cache::flush();
        DB::table('settings')->where('key', 'messaging_admin_access')->delete();
    }

    private function user(string $role, string $slug, array $extra = []): User
    {
        $u = User::forceCreate($extra + ['name' => "Chat {$slug}", 'email' => "chat-{$slug}@example.com", 'password' => bcrypt('x'), 'role' => $role, 'is_active' => true]);
        $u->assignRole($role);

        return $u;
    }

    private function vendorUser(): User
    {
        $vendor = Vendor::create(['name' => 'ZZ Chat Vendor', 'type' => 'clearing']);

        return $this->user('vendor_staff', 'vendor', ['vendor_id' => $vendor->id]);
    }

    private function grantReview(User $u): void
    {
        $u->givePermissionTo('messaging.read_others');
        Cache::forget("user:{$u->id}:permissions");
    }

    public function test_only_active_staff_can_be_found_and_messaged(): void
    {
        $me = $this->user('sales', 'me');
        $colleague = $this->user('technician', 'colleague', ['avatar_path' => 'avatars/colleague.jpg']);
        $gone = $this->user('sales', 'gone', ['is_active' => false]);
        $vendor = $this->vendorUser();

        Sanctum::actingAs($me);
        $found = collect($this->getJson('/api/messaging/users?search=chat&limit=50')->assertOk()->json('data'))->keyBy('id');
        $this->assertTrue($found->has($colleague->id));
        $this->assertFalse($found->has($gone->id));
        $this->assertFalse($found->has($vendor->id));
        $this->assertFalse($found->has($me->id));
        $this->assertStringEndsWith('/storage/avatars/colleague.jpg', $found[$colleague->id]['avatar_url']);

        $this->postJson('/api/messaging/conversations/direct', ['user_id' => $colleague->id])->assertCreated();
        $this->postJson('/api/messaging/conversations/direct', ['user_id' => $gone->id])->assertStatus(422);
        $this->postJson('/api/messaging/conversations/direct', ['user_id' => $vendor->id])->assertStatus(422);
        $this->postJson('/api/messaging/conversations/group', ['title' => 'Team', 'user_ids' => [$colleague->id, $vendor->id]])->assertStatus(422);

        // A vendor portal account can't start chats either.
        Sanctum::actingAs($vendor);
        $this->postJson('/api/messaging/conversations/direct', ['user_id' => $me->id])->assertStatus(422);
    }

    public function test_apps_sign_in_to_their_own_live_channels_only(): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app',
        ]);
        app(\Illuminate\Broadcasting\BroadcastManager::class)->purge();
        \Illuminate\Support\Facades\Broadcast::clearResolvedInstances();
        \Hypermed\Messaging\MessagingServiceProvider::registerChannels();

        $me = $this->user('sales', 'live-me');
        $other = $this->user('sales', 'live-other');
        Sanctum::actingAs($me);

        $this->postJson('/api/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => "private-messaging.user.{$me->id}"])
            ->assertOk()->assertJsonStructure(['auth']);
        $this->postJson('/api/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => "private-messaging.user.{$other->id}"])
            ->assertForbidden();
        $this->postJson('/api/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => 'presence-messaging.presence'])
            ->assertOk()->assertJsonPath('channel_data', fn ($d) => json_decode($d, true)['user_id'] == $me->id);

        // Vendor portal accounts don't see who is online.
        Sanctum::actingAs($this->vendorUser());
        $this->postJson('/api/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => 'presence-messaging.presence'])
            ->assertForbidden();
    }

    public function test_chat_review_needs_the_switch_and_the_reserved_permission(): void
    {
        $director = $this->user('admin', 'director');
        $hr = $this->user('hr', 'hr');
        $a = $this->user('sales', 'anna');
        $b = $this->user('technician', 'ben');

        Sanctum::actingAs($a);
        $chatId = $this->postJson('/api/messaging/conversations/direct', ['user_id' => $b->id])->assertCreated()->json('data.id');
        $this->postJson("/api/messaging/conversations/{$chatId}/messages", ['client_uuid' => (string) Str::uuid(), 'type' => 'text', 'body' => 'private words'])->assertCreated();

        // Off by default, even for the Director.
        Sanctum::actingAs($director);
        $this->getJson('/api/messaging/settings')->assertOk()->assertJsonPath('data.audit_enabled', false);
        $this->getJson("/api/messaging/audit/conversations/{$chatId}/messages")->assertForbidden();

        // HR manages roles but can't grant the reserved permission or flip the switch.
        Sanctum::actingAs($hr);
        $this->postJson("/api/v1/users/{$hr->id}/permission-overrides", ['key' => 'messaging.read_others', 'effect' => 'allow'])->assertForbidden();
        $this->putJson('/api/v1/settings/messaging_admin_access', ['value' => '1'])->assertForbidden();

        // The Director switches it on (logged), but admin-tier alone still can't read.
        Sanctum::actingAs($director);
        $this->putJson('/api/v1/settings/messaging_admin_access', ['value' => 'yes'])->assertStatus(422);
        $this->putJson('/api/v1/settings/messaging_admin_access', ['value' => '1'])->assertOk();
        $this->assertTrue(Activity::where('causer_id', $director->id)->where('description', 'Switched chat review ON')->exists());
        $this->getJson('/api/messaging/settings')->assertOk()
            ->assertJsonPath('data.audit_enabled', true)->assertJsonPath('data.can_audit', false);
        $this->getJson("/api/messaging/audit/conversations/{$chatId}/messages")->assertForbidden();

        // With the permission granted, the read works and is logged by name.
        $this->grantReview($director);
        $this->getJson('/api/messaging/settings')->assertOk()->assertJsonPath('data.can_audit', true);
        $this->getJson("/api/messaging/audit/users/{$a->id}/conversations")->assertOk()->assertJsonPath('data.0.id', $chatId);
        $this->getJson("/api/messaging/audit/conversations/{$chatId}/messages")->assertOk()->assertJsonPath('data.0.body', 'private words');
        $this->assertTrue(Activity::where('causer_id', $director->id)->where('description', 'Opened the chat list of Chat anna')->exists());
        $this->assertTrue(Activity::where('causer_id', $director->id)->where('description', 'Read the chat between Chat anna and Chat ben')->exists());

        // Switched off again: the permission alone does nothing.
        $this->putJson('/api/v1/settings/messaging_admin_access', ['value' => '0'])->assertOk();
        $this->getJson("/api/messaging/audit/conversations/{$chatId}/messages")->assertForbidden();
    }
}
