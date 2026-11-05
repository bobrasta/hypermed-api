<?php

namespace Tests\Feature;

use App\Models\Shipment;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorFee;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// 2026-11-02 (user): at "Arrived at HQ" the storage & handling charges
// (Swissport etc.) the clearing agent paid for us are entered with their
// documents, as a second fee owed to the agent; the agent's receipt is
// attached there.
//
// Needs a migrated Postgres database with the seeded roles; skipped on SQLite.
class ShipmentHandlingChargesTest extends TestCase
{
    use DatabaseTransactions;

    private User $officer;

    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('needs a migrated Postgres database');
        }
        Storage::fake('public');
        $this->officer = User::forceCreate(['name' => 'Test sh-officer', 'email' => 'sh-officer@example.com',
            'password' => bcrypt('x'), 'role' => 'procurement_manager', 'is_active' => true]);
        $this->officer->assignRole('procurement_manager');
        Sanctum::actingAs($this->officer);
    }

    /** An import released by customs, its clearing fee to the agent paid. */
    private function released(): array
    {
        $files = [];
        foreach (['air_waybill', 'packing_list', 'commercial_invoice'] as $t) {
            $files[$t] = UploadedFile::fake()->create("{$t}.pdf", 10, 'application/pdf');
        }
        $s = $this->post('/api/v1/shipments', [
            'direction' => 'import', 'freight_mode' => 'air', 'description' => 'Liaison XL reagents',
            'coa_required' => false, 'documents' => $files,
        ], ['Accept' => 'application/json'])->assertCreated()->json('data');
        $agent = Vendor::create(['name' => 'ZZ Rangel Test', 'type' => 'clearing', 'is_active' => true]);
        $fee = VendorFee::create(['vendor_id' => $agent->id, 'description' => 'Clearing', 'billed_amount' => 500000,
            'currency' => 'TZS', 'status' => 'paid', 'receipt_after_payment' => true, 'created_by' => $this->officer->id]);
        Shipment::whereKey($s['id'])->update(['tmda_application_ref' => 'TMDA/1', 'tmda_issued_at' => now()->toDateString(),
            'control_number' => 'CN-1', 'vendor_fee_id' => $fee->id, 'step' => 9]);

        return [$s['id'], $agent];
    }

    public function test_handling_charges_at_arrival_become_a_fee_owed_to_the_agent(): void
    {
        [$id, $agent] = $this->released();

        $res = $this->postJson("/api/v1/shipments/{$id}/status", ['step' => 10, 'handling_cost' => 185000])
            ->assertOk();

        $this->assertSame(10, $res->json('data.step'));
        $h = $res->json('data.handling_fee');
        $this->assertSame($agent->id, $h['vendor_id']);
        $this->assertSame(185000, $h['billed']);
        $fee = VendorFee::find($h['id']);
        $this->assertFalse((bool) $fee->receipt_after_payment); // paid once the agent's receipt is in
        $this->assertNotSame('paid', $fee->status);
        $this->assertSame($fee->id, Shipment::find($id)->handling_fee_id);

        // Corrected at the same status while still waiting on approval.
        $this->postJson("/api/v1/shipments/{$id}/status", ['step' => 10, 'handling_cost' => 190000])->assertOk()
            ->assertJsonPath('data.handling_fee.billed', 190000)
            ->assertJsonPath('data.handling_fee.id', $fee->id);

        // Swissport papers go on the shipment; the agent's receipt goes on the fee.
        $this->post("/api/v1/shipments/{$id}/documents", [
            'type' => 'storage_handling', 'file' => UploadedFile::fake()->create('swissport.pdf', 20, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertOk();
        $doc = collect($this->getJson("/api/v1/shipments/{$id}")->json('data.documents'))->firstWhere('type', 'storage_handling');
        $this->assertSame('swissport.pdf', $doc['file_name']);
        $this->assertTrue($doc['for_records']);

        $this->post("/api/v1/vendor-fees/{$fee->id}/receipts", [
            'receipt_type' => 'other', 'receipt_number' => 'RGL-77', 'amount' => 190000, 'issuer_name' => $agent->name,
            'receipt_date' => now()->toDateString(),
            'file' => UploadedFile::fake()->create('receipt.pdf', 20, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertSuccessful();
    }

    public function test_handling_charges_only_at_arrival(): void
    {
        [$id] = $this->released();
        Shipment::whereKey($id)->update(['step' => 8]);

        $this->postJson("/api/v1/shipments/{$id}/status", ['step' => 9, 'handling_cost' => 1000])
            ->assertStatus(422)->assertJsonValidationErrors('handling_cost');
    }
}
