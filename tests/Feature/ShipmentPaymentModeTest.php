<?php

namespace Tests\Feature;

use App\Models\Shipment;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

// Shipments 2026-10-23: the payment mode chosen on the step itself ("We pay"
// vs "Manufacturer paid shipping / payment not required"), with who paid, an
// optional note, cost reported as 0, and the import permit sent to a courier.
//
// Needs a migrated Postgres database with the seeded roles; skipped on
// SQLite. Missing migrations run inside the test transaction.
class ShipmentPaymentModeTest extends TestCase
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
        Storage::fake('public');
        $this->officer = User::forceCreate([
            'name' => 'Test ship officer', 'email' => 'ship-officer-pay@example.com',
            'password' => bcrypt('x'), 'role' => 'procurement_manager', 'is_active' => true,
        ]);
        $this->officer->assignRole('procurement_manager');
        Sanctum::actingAs($this->officer);
    }

    /** A medical import with its documents in and the TMDA permit issued: ready for the clearing agent. */
    private function atClearingAgent(): array
    {
        $files = [];
        foreach (['air_waybill', 'packing_list', 'commercial_invoice'] as $t) {
            $files[$t] = UploadedFile::fake()->create("{$t}.pdf", 10, 'application/pdf');
        }
        $s = $this->post('/api/v1/shipments', [
            'direction' => 'import', 'freight_mode' => 'air', 'description' => 'Liaison XL reagents',
            'coa_required' => false, 'documents' => $files,
        ], ['Accept' => 'application/json'])->assertCreated()->json('data');
        Shipment::whereKey($s['id'])->update(['tmda_application_ref' => 'TMDA/1', 'tmda_issued_at' => now()->toDateString(), 'step' => 6]);

        return $s;
    }

    public function test_we_pay_still_needs_control_number_and_paid_fee(): void
    {
        $s = $this->atClearingAgent();
        $this->assertSame('hypermed', $s['cost_bearer']);
        $this->assertNull($s['clearing_cost']); // no fee recorded yet: missing, not zero

        $this->postJson("/api/v1/shipments/{$s['id']}/status", ['step' => 7])->assertStatus(422)
            ->assertJsonPath('errors.step.0', fn ($m) => str_contains($m, 'control number'));
        $this->postJson("/api/v1/shipments/{$s['id']}/status", ['step' => 7, 'control_number' => 'CN-77'])->assertOk();
        $this->postJson("/api/v1/shipments/{$s['id']}/status", ['step' => 8])->assertStatus(422)
            ->assertJsonPath('errors.step.0', fn ($m) => str_contains($m, 'assessed cost'));
    }

    public function test_manufacturer_paid_chosen_on_the_step_lets_it_through(): void
    {
        $s = $this->atClearingAgent();

        // Who paid is required.
        $this->postJson("/api/v1/shipments/{$s['id']}/status", ['step' => 7, 'cost_bearer' => 'manufacturer'])
            ->assertStatus(422)->assertJsonValidationErrors('paid_by');

        $r = $this->postJson("/api/v1/shipments/{$s['id']}/status", [
            'step' => 7, 'cost_bearer' => 'manufacturer', 'paid_by' => 'Diasorin', 'cost_note' => 'DHL called us; we emailed the permit.',
        ])->assertOk()->json('data');
        $this->assertSame(['manufacturer', 'Diasorin', 'DHL called us; we emailed the permit.', 0, 7],
            [$r['cost_bearer'], $r['paid_by'], $r['cost_note'], $r['clearing_cost'], $r['step']]);
        $note = collect($r['events'])->firstWhere(fn ($e) => str_contains((string) $e['note'], 'Paid by Diasorin'));
        $this->assertNotNull($note);
        $this->assertStringContainsString('DHL called us', $note['note']);

        // Audit trail: who chose it, and when.
        $log = Activity::where('subject_type', Shipment::class)->where('subject_id', $s['id'])->latest('id')->get()
            ->first(fn ($a) => ($a->properties['attributes']['cost_bearer'] ?? null) === 'manufacturer');
        $this->assertNotNull($log);
        $this->assertSame($this->officer->id, $log->causer_id);

        // No payment records needed for the rest of the way.
        $this->postJson("/api/v1/shipments/{$s['id']}/status", ['step' => 10])->assertOk();
    }

    public function test_back_to_we_pay_clears_who_paid_and_the_note(): void
    {
        $s = $this->atClearingAgent();
        $this->putJson("/api/v1/shipments/{$s['id']}", ['cost_bearer' => 'manufacturer', 'paid_by' => 'Diasorin', 'cost_note' => 'x'])->assertOk();
        $r = $this->putJson("/api/v1/shipments/{$s['id']}", ['cost_bearer' => 'hypermed', 'paid_by' => 'Diasorin', 'cost_note' => 'x'])->assertOk()->json('data');
        $this->assertSame(['hypermed', null, null], [$r['cost_bearer'], $r['paid_by'], $r['cost_note']]);
    }

    public function test_permit_sent_to_courier_is_recorded(): void
    {
        $s = $this->atClearingAgent();
        $dhl = Vendor::whereRaw("lower(name) = 'dhl'")->firstOrFail();
        $r = $this->putJson("/api/v1/shipments/{$s['id']}", ['permit_sent_at' => '2026-10-21', 'permit_sent_vendor_id' => $dhl->id])
            ->assertOk()->json('data');
        $this->assertSame(['2026-10-21', 'DHL'], [$r['tmda']['sent_at'], $r['tmda']['sent_vendor_name']]);

        $clearing = Vendor::forceCreate(['name' => 'Test clearing agent', 'type' => 'clearing', 'is_active' => true]);
        $this->putJson("/api/v1/shipments/{$s['id']}", ['permit_sent_vendor_id' => $clearing->id])
            ->assertStatus(422)->assertJsonValidationErrors('permit_sent_vendor_id');
    }

    public function test_exports_have_no_payment_mode(): void
    {
        $s = $this->postJson('/api/v1/shipments', ['direction' => 'export', 'freight_mode' => 'road', 'description' => 'RMA return'])
            ->assertCreated()->json('data');
        $this->postJson("/api/v1/shipments/{$s['id']}/status", ['step' => 2, 'cost_bearer' => 'manufacturer', 'paid_by' => 'X'])
            ->assertStatus(422)->assertJsonValidationErrors('cost_bearer');
    }
}
