<?php

namespace Tests\Feature;

use App\Models\Shipment;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorFee;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// 2026-10-26 (user): on "We pay", entering the clearing agent's control number
// always saves — asking for "Payment made" before the fee is paid lands on
// "Assessment received", waiting for the receipt — and shipment staff
// (Diana's role) attach the clearing agent's receipt to the fee.
//
// Needs a migrated Postgres database with the seeded roles; skipped on SQLite.
class ShipmentClearingReceiptTest extends TestCase
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
        $this->officer = $this->user('procurement_manager', 'clr-officer');
        Sanctum::actingAs($this->officer);
    }

    private function user(string $role, string $slug): User
    {
        $u = User::forceCreate(['name' => "Test {$slug}", 'email' => "{$slug}@example.com", 'password' => bcrypt('x'), 'role' => $role, 'is_active' => true]);
        $u->assignRole($role);

        return $u;
    }

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

    public function test_control_number_saves_and_waits_for_the_receipt(): void
    {
        $s = $this->atClearingAgent();

        // Asked straight for "Payment made" with the control number: saved, held at "Assessment received".
        $r = $this->postJson("/api/v1/shipments/{$s['id']}/status", ['step' => 8, 'control_number' => 'CN-501', 'cost_bearer' => 'hypermed'])
            ->assertOk();
        $this->assertSame(7, $r->json('data.step'));
        $this->assertSame('CN-501', $r->json('data.control_number'));
        $this->assertStringContainsString("Awaiting the clearing agent's fee and receipt", $r->json('notice'));
        $this->assertSame('blocked', $r->json('data.flag'));

        // Correcting the control number on the current status saves too.
        $r = $this->postJson("/api/v1/shipments/{$s['id']}/status", ['step' => 7, 'control_number' => 'CN-502'])->assertOk();
        $this->assertSame('CN-502', Shipment::find($s['id'])->control_number);
        $this->assertStringContainsString('stays at', $r->json('notice'));
        // ...but the same status with nothing new is still refused.
        $this->postJson("/api/v1/shipments/{$s['id']}/status", ['step' => 7])->assertStatus(422);

        // Fee recorded: now it waits for the receipt itself.
        $vendor = Vendor::create(['name' => 'ZZ Clearing Co', 'type' => 'clearing', 'is_active' => true]);
        $this->postJson("/api/v1/shipments/{$s['id']}/clearing-fee", ['vendor_id' => $vendor->id, 'billed_amount' => 350000])->assertOk();
        $this->postJson("/api/v1/shipments/{$s['id']}/status", ['step' => 8])->assertStatus(422)
            ->assertJsonPath('errors.step.0', fn ($m) => str_contains($m, "Awaiting the clearing agent's receipt — no receipt attached"));
    }

    public function test_shipment_staff_attach_the_clearing_receipt(): void
    {
        $s = $this->atClearingAgent();
        $vendor = Vendor::create(['name' => 'ZZ Clearing Co', 'type' => 'clearing', 'is_active' => true]);
        $this->postJson("/api/v1/shipments/{$s['id']}/status", ['step' => 7, 'control_number' => 'CN-9'])->assertOk();
        $fee = $this->postJson("/api/v1/shipments/{$s['id']}/clearing-fee", ['vendor_id' => $vendor->id, 'billed_amount' => 350000])
            ->assertOk()->json('data.clearing_fee');

        $receipt = fn () => ['receipt_type' => 'efd', 'receipt_number' => 'R-' . uniqid(), 'issuer_name' => 'ZZ Clearing Co',
            'receipt_date' => now()->toDateString(), 'amount' => 350000, 'file' => UploadedFile::fake()->create('r.pdf', 20, 'application/pdf')];

        // Someone who runs shipments only through shipments.manage (Role Builder grant) can attach it.
        $shipOnly = $this->user('cs', 'clr-cs');
        $shipOnly->givePermissionTo('shipments.manage');
        Sanctum::actingAs($shipOnly);
        $this->post("/api/v1/vendor-fees/{$fee['id']}/receipts", $receipt(), ['Accept' => 'application/json'])->assertCreated();

        // Not for a fee that isn't a shipment's clearing fee, and not for others.
        $other = VendorFee::create(['vendor_id' => $vendor->id, 'description' => 'Delivery', 'billed_amount' => 1000, 'currency' => 'TZS',
            'status' => 'pending_receipt', 'created_by' => $this->officer->id]);
        $this->post("/api/v1/vendor-fees/{$other->id}/receipts", $receipt(), ['Accept' => 'application/json'])->assertForbidden();
        Sanctum::actingAs($this->user('technician', 'clr-tech'));
        $this->post("/api/v1/vendor-fees/{$fee['id']}/receipts", $receipt(), ['Accept' => 'application/json'])->assertForbidden();

        Sanctum::actingAs($this->officer);
        $detail = $this->getJson("/api/v1/shipments/{$s['id']}")->assertOk()->json('data');
        $this->assertTrue($detail['clearing_fee']['receipt_uploaded']);
        $this->assertStringContainsString('awaiting verification', $detail['flag_reason'] ?? $detail['clearing_fee']['block_reason']);
    }
}
