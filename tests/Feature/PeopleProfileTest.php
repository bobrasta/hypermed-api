<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// Viewing a colleague's profile: who they are and what they do, never the
// sensitive fields; staff only, and vendor accounts are not listed.
//
// Needs a migrated Postgres database with the seeded roles; skipped on SQLite.
class PeopleProfileTest extends TestCase
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
        $u = User::forceCreate($extra + ['name' => "People {$slug}", 'email' => "people-{$slug}@example.com", 'password' => bcrypt('x'), 'role' => $role, 'is_active' => true]);
        $u->assignRole($role);

        return $u;
    }

    public function test_profile_shows_public_fields_only(): void
    {
        $boss = $this->user('admin', 'boss');
        $anna = $this->user('technician', 'anna', [
            'manager_id' => $boss->id, 'bio' => 'Biomedical engineer, 8 years on Mindray analysers.',
            'qualifications' => ['BSc Biomedical Engineering', 'Mindray BC-5150 certified'], 'phone' => '+255 700 000 001',
            'nida_number' => '19900101-12345-00001-21', 'tin_number' => '123-456-789', 'nssf_number' => 'NS-99',
            'next_of_kin_name' => 'Secret Kin', 'gender' => 'female', 'avatar_path' => 'avatars/anna.jpg', 'hire_date' => '2021-03-01',
        ]);
        Sanctum::actingAs($this->user('sales', 'viewer'));

        $res = $this->getJson("/api/v1/people/{$anna->id}")->assertOk()
            ->assertJsonPath('data.name', 'People anna')
            ->assertJsonPath('data.bio', 'Biomedical engineer, 8 years on Mindray analysers.')
            ->assertJsonPath('data.qualifications.1', 'Mindray BC-5150 certified')
            ->assertJsonPath('data.reports_to.name', 'People boss')
            ->assertJsonPath('data.joined', '2021-03-01');
        $this->assertStringEndsWith('/storage/avatars/anna.jpg', $res->json('data.avatar_url'));
        $body = $res->getContent();
        foreach (['19900101', '123-456-789', 'NS-99', 'Secret Kin', 'female', 'nida', 'tin_number', 'nssf', 'payment', 'salary', 'commission', 'discount'] as $secret) {
            $this->assertStringNotContainsString($secret, $body, "leaked: {$secret}");
        }
    }

    public function test_vendor_accounts_neither_view_nor_appear(): void
    {
        $vendor = Vendor::create(['name' => 'ZZ People Vendor', 'type' => 'clearing']);
        $vendorUser = $this->user('vendor_staff', 'vendor', ['vendor_id' => $vendor->id]);
        $staff = $this->user('sales', 'staff');

        Sanctum::actingAs($staff);
        $this->getJson("/api/v1/people/{$vendorUser->id}")->assertNotFound();
        Sanctum::actingAs($vendorUser);
        $this->getJson("/api/v1/people/{$staff->id}")->assertForbidden();
    }
}
