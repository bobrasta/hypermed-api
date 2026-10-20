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
use Tests\TestCase;

// Shipments 2026-10-20: per-shipment "COA required", courier vendors (DHL,
// FedEx) as clearing agent / carrier / delivery vendor, and manufacturer-paid
// shipments that skip the control-number and clearing-payment gates.
//
// Needs a migrated Postgres database with the seeded roles; skipped on
// SQLite. The new migration runs inside the test transaction if needed.
class ShipmentCoaCourierCostBearerTest extends TestCase
{
    use DatabaseTransactions;

    private User $officer;

    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('needs a migrated Postgres database');
        }
        if (! Schema::hasColumn('shipments', 'cost_bearer')) {
            (require database_path('migrations/2026_10_20_000001_add_courier_coa_and_cost_bearer_to_shipments.php'))->up();
        }
        Storage::fake('public');
        $this->officer = User::forceCreate([
            'name' => 'Test ship officer', 'email' => 'ship-officer@example.com',
            'password' => bcrypt('x'), 'role' => 'procurement_manager', 'is_active' => true,
        ]);
        $this->officer->assignRole('procurement_manager');
        Sanctum::actingAs($this->officer);
    }

    private function create(array $over = []): array
    {
        $files = [];
        foreach (['air_waybill', 'packing_list', 'commercial_invoice'] as $t) {
            $files[$t] = UploadedFile::fake()->create("{$t}.pdf", 10, 'application/pdf');
        }

        return $this->post('/api/v1/shipments', $over + [
            'direction' => 'import', 'freight_mode' => 'air', 'description' => 'Liaison XL reagents',
            'documents' => $files,
        ], ['Accept' => 'application/json'])->assertCreated()->json('data');
    }

    public function test_couriers_are_seeded_and_offered_as_clearing_agent_and_carrier(): void
    {
        $dhl = Vendor::whereRaw("lower(name) = 'dhl'")->first();
        $this->assertNotNull($dhl);
        $this->assertSame('courier', $dhl->type);
        $this->assertTrue(Vendor::whereRaw("lower(name) = 'fedex'")->exists());

        $opts = $this->getJson('/api/v1/shipments/options')->assertOk()->json('data');
        $this->assertContains($dhl->id, array_column($opts['vendors'], 'id'));
        $this->assertContains($dhl->id, array_column($opts['carriers'], 'id'));

        $s = $this->create(['carrier_vendor_id' => $dhl->id]);
        $this->assertSame('DHL', $s['carrier_name']);

        // A clearing-only vendor is not a carrier.
        $clearing = Vendor::create(['name' => 'ZZ Clearing', 'type' => 'clearing']);
        $this->putJson("/api/v1/shipments/{$s['id']}", ['carrier_vendor_id' => $clearing->id])->assertStatus(422);

        // The procurement manager creates vendors (couriers included), but
        // can't redirect an existing vendor's payment account.
        $v = $this->postJson('/api/v1/vendors', ['name' => 'ZZ Courier', 'type' => 'courier',
            'payment_account_type' => 'bank', 'payment_account_number' => '111'])->assertCreated()->json('data');
        $this->putJson("/api/v1/vendors/{$v['id']}", ['contact_phone' => '0700', 'payment_account_number' => '111'])->assertOk();
        $this->putJson("/api/v1/vendors/{$v['id']}", ['payment_account_number' => '999'])->assertForbidden();
    }

    public function test_coa_not_required_completes_the_checklist(): void
    {
        $with = $this->create();
        $this->assertSame([3, 4], [$with['docs_uploaded'], $with['docs_required']]);
        $this->assertSame('action', $with['flag']);

        $without = $this->create(['coa_required' => false]);
        $this->assertSame([3, 3], [$without['docs_uploaded'], $without['docs_required']]);
        $this->assertNotSame('action', $without['flag']);
        $coa = collect($without['documents'])->firstWhere('type', 'coa');
        $this->assertTrue($coa['not_applicable']);
        $this->assertFalse($coa['required']);

        $this->postJson("/api/v1/shipments/{$with['id']}/status", ['step' => 2])->assertStatus(422);
        $this->postJson("/api/v1/shipments/{$without['id']}/status", ['step' => 2])->assertOk();
    }

    public function test_manufacturer_paid_skips_payment_gates_and_logs_who_paid(): void
    {
        $s = $this->create(['coa_required' => false]);
        $this->putJson("/api/v1/shipments/{$s['id']}", ['cost_bearer' => 'manufacturer'])->assertStatus(422); // paid_by required

        $r = $this->putJson("/api/v1/shipments/{$s['id']}", ['cost_bearer' => 'manufacturer', 'paid_by' => 'Diasorin'])->assertOk()->json('data');
        $this->assertSame('Diasorin', $r['paid_by']);
        $this->assertStringContainsString('Paid by Diasorin', collect($r['events'])->last()['note']);
        $this->assertSame($this->officer->name, collect($r['events'])->last()['by']);

        Shipment::whereKey($s['id'])->update(['tmda_application_ref' => 'TMDA/1', 'tmda_issued_at' => now()->toDateString()]);
        // Straight to "Released by customs": no control number, no vendor fee.
        $this->postJson("/api/v1/shipments/{$s['id']}/status", ['step' => 9])->assertOk();

        $this->postJson("/api/v1/shipments/{$s['id']}/clearing-fee", ['vendor_id' => Vendor::first()->id, 'billed_amount' => 1000])
            ->assertStatus(422);
    }

    public function test_hypermed_paid_still_needs_the_fee(): void
    {
        $s = $this->create(['coa_required' => false]);
        Shipment::whereKey($s['id'])->update(['tmda_application_ref' => 'TMDA/1', 'tmda_issued_at' => now()->toDateString(), 'control_number' => 'CN1']);
        $this->postJson("/api/v1/shipments/{$s['id']}/status", ['step' => 8])->assertStatus(422);
    }
}
