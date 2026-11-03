<?php

namespace Tests\Feature;

use App\Models\ProcuringEntity;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// Tenders: a Pre-qualification type; a 'Not submitted' outcome; not
// submitted / cancelled / lost need a reason, which shows on the tender.
//
// Needs a migrated Postgres database with the seeded roles; skipped on SQLite.
class TenderOutcomeReasonTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('needs a migrated Postgres database');
        }
        if (! Schema::hasColumn('tenders', 'status_reason')) {
            (require database_path('migrations/2026_11_03_000004_tender_not_submitted_and_reasons.php'))->up();
        }
        Cache::flush();
        $u = User::forceCreate(['name' => 'Test proc', 'email' => 'tender-reason@example.com', 'password' => bcrypt('x'), 'role' => 'procurement_manager', 'is_active' => true]);
        $u->assignRole('procurement_manager');
        Sanctum::actingAs($u);
    }

    public function test_pre_qualification_type_and_reasons_for_ending_a_tender(): void
    {
        $entity = ProcuringEntity::create(['name' => 'ZZ Reason Hospital']);
        $id = $this->postJson('/api/v1/tenders', [
            'tender_number' => 'PQ/2026/01', 'title' => 'Pre-qualification of medical equipment suppliers',
            'procuring_entity_id' => $entity->id, 'tender_type' => 'pre_qualification',
        ])->assertCreated()->assertJsonPath('data.tender_type', 'pre_qualification')->json('data.id');

        $this->postJson("/api/v1/tenders/{$id}/status", ['status' => 'not_submitted'])->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/tenders/{$id}/status", ['status' => 'not_submitted', 'reason' => 'Bid security not ready in time'])
            ->assertOk()->assertJsonPath('data.status', 'not_submitted')->assertJsonPath('data.status_reason', 'Bid security not ready in time');

        $this->postJson("/api/v1/tenders/{$id}/status", ['status' => 'cancelled'])->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/tenders/{$id}/status", ['status' => 'preparing_bid'])->assertOk()->assertJsonPath('data.status_reason', null);
    }
}
