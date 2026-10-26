<?php

namespace Tests\Feature;

use App\Models\Shipment;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// Shipments 2026-10-23: goods category. Non-medical goods have no required
// documents and no TMDA permit gates, so they can be completed without any
// uploads; medical devices / reagents keep the checklist.
//
// Needs a migrated Postgres database with the seeded roles; skipped on
// SQLite. The new migrations run inside the test transaction if needed.
class ShipmentGoodsCategoryTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('needs a migrated Postgres database');
        }
        if (! Schema::hasColumn('shipments', 'cost_bearer')) {
            (require database_path('migrations/2026_10_20_000001_add_courier_coa_and_cost_bearer_to_shipments.php'))->up();
        }
        if (! Schema::hasColumn('shipments', 'goods_category')) {
            (require database_path('migrations/2026_10_23_000001_add_goods_category_to_shipments.php'))->up();
        }
        if (! Schema::hasColumn('shipments', 'cost_note')) {
            (require database_path('migrations/2026_10_23_000002_add_cost_note_and_permit_sent_to_shipments.php'))->up();
        }
        if (! Schema::hasTable('shipment_notify_users')) {
            (require database_path('migrations/2026_10_23_000003_create_shipment_notify_users_table.php'))->up();
        }
        Storage::fake('public');
        $officer = User::forceCreate([
            'name' => 'Test ship officer', 'email' => 'ship-officer-goods@example.com',
            'password' => bcrypt('x'), 'role' => 'procurement_manager', 'is_active' => true,
        ]);
        $officer->assignRole('procurement_manager');
        Sanctum::actingAs($officer);
    }

    private function create(array $over = []): array
    {
        return $this->postJson('/api/v1/shipments', $over + [
            'direction' => 'import', 'freight_mode' => 'sea', 'description' => 'Office chairs ×12',
        ])->assertCreated()->json('data');
    }

    public function test_non_medical_import_completes_without_uploads_or_tmda(): void
    {
        $s = $this->create(['goods_category' => 'non_medical', 'cost_bearer' => 'manufacturer', 'paid_by' => 'Diasorin']);
        $this->assertSame('non_medical', $s['goods_category']);
        $this->assertSame([0, 0], [$s['docs_uploaded'], $s['docs_required']]);
        $this->assertNotSame('action', $s['flag']);
        $this->assertNull($s['tmda']);
        $this->assertFalse(collect($s['documents'])->contains('required', true));
        $this->assertTrue(collect($s['documents'])->firstWhere('type', 'tmda_permit')['not_applicable']);

        $done = $this->postJson("/api/v1/shipments/{$s['id']}/status", ['step' => 10])->assertOk()->json('data');
        $this->assertSame('done', $done['flag']);
    }

    public function test_non_medical_paid_by_hypermed_still_needs_the_clearing_fee(): void
    {
        $s = $this->create(['goods_category' => 'non_medical']);
        // No documents, no TMDA: straight to the clearing agent.
        $this->postJson("/api/v1/shipments/{$s['id']}/status", ['step' => 6])->assertOk();
        // The control number is saved; the shipment waits at "Assessment received" for the fee.
        $r = $this->postJson("/api/v1/shipments/{$s['id']}/status", ['step' => 8, 'control_number' => 'CN1'])->assertOk();
        $this->assertSame(7, $r->json('data.step'));
        $this->assertStringContainsString('assessed cost', $r->json('notice'));
    }

    public function test_medical_is_the_default_and_keeps_the_checklist(): void
    {
        $s = $this->create();
        $this->assertSame('medical', $s['goods_category']);
        $this->assertSame(4, $s['docs_required']);
        $this->assertSame('action', $s['flag']);
        $this->postJson("/api/v1/shipments/{$s['id']}/status", ['step' => 2])->assertStatus(422);
    }

    public function test_switching_back_to_medical_is_refused_past_its_gates_and_is_logged(): void
    {
        $s = $this->create(['goods_category' => 'non_medical']);
        $this->postJson("/api/v1/shipments/{$s['id']}/status", ['step' => 6])->assertOk();
        $this->putJson("/api/v1/shipments/{$s['id']}", ['goods_category' => 'medical'])->assertStatus(422);

        $t = $this->create();
        $r = $this->putJson("/api/v1/shipments/{$t['id']}", ['goods_category' => 'non_medical'])->assertOk()->json('data');
        $this->assertStringContainsString('non-medical', collect($r['events'])->last()['note']);
        $this->assertSame('non_medical', Shipment::find($t['id'])->goods_category);
    }

    public function test_unknown_category_is_rejected(): void
    {
        $this->postJson('/api/v1/shipments', ['direction' => 'import', 'freight_mode' => 'air', 'description' => 'x', 'goods_category' => 'food'])
            ->assertStatus(422)->assertJsonValidationErrors('goods_category');
    }
}
