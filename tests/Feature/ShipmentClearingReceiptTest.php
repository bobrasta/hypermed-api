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
        if (! Schema::hasColumn('vendor_fees', 'receipt_after_payment')) {
            (require database_path('migrations/2026_10_26_000002_clearing_fee_paid_before_receipt.php'))->up();
        }
        if (! Schema::hasColumn('vendor_fees', 'approver_id')) {
            (require database_path('migrations/2026_10_26_000003_vendor_fee_manager_approval.php'))->up();
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
        $this->assertStringContainsString("Awaiting the clearing agent's assessed cost", $r->json('notice'));
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
        // Paid first, receipt after: it now waits for the accountant, not the receipt.
        $this->postJson("/api/v1/shipments/{$s['id']}/status", ['step' => 8])->assertStatus(422)
            ->assertJsonPath('errors.step.0', fn ($m) => str_contains($m, 'Awaiting Finance to request payment'));
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
        // The receipt doesn't hold up payment: next is the accountant's request.
        $this->assertStringContainsString('Awaiting Finance', $detail['flag_reason']);
    }

    public function test_assessment_documents_kept_for_the_record(): void
    {
        $s = $this->atClearingAgent();
        $types = fn ($d) => collect($d['documents'])->pluck('type')->all();
        $this->assertNotContains('assessment_notice', $types($this->getJson("/api/v1/shipments/{$s['id']}")->json('data')));

        $d = $this->postJson("/api/v1/shipments/{$s['id']}/status", ['step' => 7, 'control_number' => 'CN-7'])->assertOk()->json('data');
        foreach (['assessment_notice', 'customs_release', 'clearing_other'] as $t) {
            $doc = collect($d['documents'])->firstWhere('type', $t);
            $this->assertNotNull($doc, $t);
            $this->assertTrue($doc['for_records']);
            $this->assertFalse($doc['required']);
        }
        $up = $this->post("/api/v1/shipments/{$s['id']}/documents", ['type' => 'assessment_notice',
            'file' => UploadedFile::fake()->create('assessment.pdf', 30, 'application/pdf')], ['Accept' => 'application/json'])->assertOk()->json('data');
        $this->assertSame('assessment.pdf', collect($up['documents'])->firstWhere('type', 'assessment_notice')['file_name']);
        // Records gate nothing: the shipment still only waits for the clearing fee.
        $this->assertStringContainsString('assessed cost', $up['next_block_reason']);
    }

    public function test_cost_on_assessment_is_paid_first_and_the_receipt_follows(): void
    {
        $s = $this->atClearingAgent();
        $vendor = Vendor::create(['name' => 'ZZ Clearing Co', 'type' => 'clearing', 'is_active' => true]);
        $accountant = $this->user('accountant', 'clr-acct');
        $director = $this->user('super_admin', 'clr-dir');

        // The agent is needed with the cost.
        $this->postJson("/api/v1/shipments/{$s['id']}/status", ['step' => 7, 'control_number' => 'CN-1', 'clearing_cost' => 420000])
            ->assertStatus(422)->assertJsonValidationErrors('clearing_vendor_id');
        $r = $this->postJson("/api/v1/shipments/{$s['id']}/status", ['step' => 7, 'control_number' => 'CN-1',
            'clearing_cost' => 420000, 'clearing_vendor_id' => $vendor->id])->assertOk()->json('data');
        $fee = $r['clearing_fee'];
        $this->assertSame(420000, $fee['billed']);
        $this->assertSame('Awaiting Finance (payment request)', $fee['status_label']);
        $this->assertNull($fee['block_reason']);
        $this->assertTrue(\App\Models\AppNotification::where('user_id', $accountant->id)->where('entity_id', $fee['id'])
            ->where('title', 'like', 'Fee to pay%')->exists());

        // Corrected before payment is requested.
        $this->postJson("/api/v1/shipments/{$s['id']}/status", ['step' => 7, 'clearing_cost' => 450000])->assertOk();
        $this->assertSame(450000, VendorFee::find($fee['id'])->billed_amount);

        // Accountant requests payment with no receipt; the Director pays it.
        Sanctum::actingAs($accountant);
        $this->postJson("/api/v1/vendor-fees/{$fee['id']}/submit-for-payment")->assertOk();
        Sanctum::actingAs($director);
        $bank = \App\Models\ChartOfAccount::where('code', '1002')->value('id');
        $clearingBefore = (int) \App\Models\ChartOfAccount::where('code', '5016')->value('balance');
        $paid = $this->postJson("/api/v1/vendor-fees/{$fee['id']}/approve", ['payment_reference' => 'TT-1', 'paid_from_account_id' => $bank])
            ->assertOk()->json('data');
        $this->assertSame('Paid · receipt to follow', $paid['status_label']);
        // On the books as a clearing expense.
        $this->assertSame(450000, (int) \App\Models\ChartOfAccount::where('code', '5016')->value('balance') - $clearingBefore);

        // The shipment moves on to "Payment made"; the receipt comes after.
        Sanctum::actingAs($this->officer);
        $this->postJson("/api/v1/shipments/{$s['id']}/status", ['step' => 8])->assertOk()->assertJsonPath('data.step', 8);
        $this->post("/api/v1/vendor-fees/{$fee['id']}/receipts", ['receipt_type' => 'efd', 'receipt_number' => 'R-' . uniqid(),
            'issuer_name' => 'ZZ Clearing Co', 'receipt_date' => now()->toDateString(), 'amount' => 450000,
            'file' => UploadedFile::fake()->create('r.pdf', 20, 'application/pdf')], ['Accept' => 'application/json'])->assertCreated();
        $d = $this->getJson("/api/v1/shipments/{$s['id']}")->json('data');
        $this->assertStringContainsString('awaiting verification', $d['clearing_fee']['receipt_follow_up']);
    }

    public function test_new_supplier_is_saved_with_the_shipment_and_amounts_take_decimals(): void
    {
        $files = [];
        foreach (['air_waybill', 'packing_list', 'commercial_invoice'] as $t) {
            $files[$t] = UploadedFile::fake()->create("{$t}.pdf", 10, 'application/pdf');
        }
        $name = 'ZZ Brand New Labs ' . uniqid();
        $s = $this->post('/api/v1/shipments', ['direction' => 'import', 'freight_mode' => 'air', 'description' => 'Reagents',
            'coa_required' => false, 'supplier_name' => "  {$name} ", 'documents' => $files], ['Accept' => 'application/json'])
            ->assertCreated()->json('data');
        $supplier = \App\Models\Supplier::where('name', $name)->first();
        $this->assertNotNull($supplier);
        $this->assertSame($supplier->id, Shipment::find($s['id'])->supplier_id);

        // The same name again (any case) reuses it rather than duplicating.
        $this->putJson("/api/v1/shipments/{$s['id']}", ['supplier_name' => strtoupper($name)])->assertOk();
        $this->assertSame(1, \App\Models\Supplier::whereRaw('lower(name) = ?', [mb_strtolower($name)])->count());

        // Decimal amounts are accepted and kept in whole shillings.
        Shipment::whereKey($s['id'])->update(['tmda_application_ref' => 'T/1', 'tmda_issued_at' => now()->toDateString(), 'step' => 6]);
        $vendor = Vendor::create(['name' => 'ZZ Clearing Co', 'type' => 'clearing', 'is_active' => true]);
        $d = $this->postJson("/api/v1/shipments/{$s['id']}/status", ['step' => 7, 'control_number' => 'CN-D',
            'clearing_cost' => '350000.60', 'clearing_vendor_id' => $vendor->id])->assertOk()->json('data');
        $this->assertSame(350001, $d['clearing_fee']['billed']);
        $this->post("/api/v1/vendor-fees/{$d['clearing_fee']['id']}/receipts", ['receipt_type' => 'efd', 'receipt_number' => 'R-' . uniqid(),
            'issuer_name' => 'ZZ Clearing Co', 'receipt_date' => now()->toDateString(), 'amount' => '350000.60',
            'file' => UploadedFile::fake()->create('r.pdf', 20, 'application/pdf')], ['Accept' => 'application/json'])->assertCreated();
        $this->assertSame(350001, (int) VendorFee::find($d['clearing_fee']['id'])->receipts()->sum('amount'));
    }

    public function test_patrick_then_finance_then_director_each_notified(): void
    {
        $patrick = $this->user('procurement_manager', 'clr-patrick');
        $this->officer->update(['manager_id' => $patrick->id]);
        $accountant = $this->user('accountant', 'clr-acct2');
        $director = $this->user('super_admin', 'clr-dir2');
        $s = $this->atClearingAgent();
        $vendor = Vendor::create(['name' => 'ZZ Clearing Co', 'type' => 'clearing', 'is_active' => true]);
        $told = fn (User $u, int $feeId, string $title) => \App\Models\AppNotification::where('user_id', $u->id)
            ->where('entity_id', $feeId)->where('title', 'like', $title . '%')->exists();

        $d = $this->postJson("/api/v1/shipments/{$s['id']}/status", ['step' => 7, 'control_number' => 'CN-P',
            'clearing_cost' => 300000, 'clearing_vendor_id' => $vendor->id])->assertOk()->json('data');
        $feeId = $d['clearing_fee']['id'];
        $this->assertSame('pending_manager', VendorFee::find($feeId)->status);
        $this->assertStringContainsString("Awaiting Test clr-patrick's approval", $d['flag_reason']);
        $this->assertTrue($told($patrick, $feeId, 'Fee to approve'));
        $this->assertFalse($told($accountant, $feeId, 'Fee to pay'));

        // Finance can't request payment before Patrick approves.
        Sanctum::actingAs($accountant);
        $this->assertEmpty($this->getJson('/api/v1/vendor-fees?waiting_on_me=1')->json('data'));
        $this->postJson("/api/v1/vendor-fees/{$feeId}/submit-for-payment")->assertStatus(422);
        // Only Patrick approves at this stage.
        $this->postJson("/api/v1/vendor-fees/{$feeId}/manager-approve")->assertForbidden();

        Sanctum::actingAs($patrick);
        $this->assertSame([$feeId], collect($this->getJson('/api/v1/vendor-fees?waiting_on_me=1')->json('data'))->pluck('id')->all());
        $this->postJson("/api/v1/vendor-fees/{$feeId}/manager-approve")->assertOk()->assertJsonPath('data.status', 'pending_receipt');
        $this->assertTrue($told($accountant, $feeId, 'Fee to pay'));

        Sanctum::actingAs($accountant);
        $this->assertContains($feeId, collect($this->getJson('/api/v1/vendor-fees?waiting_on_me=1')->json('data'))->pluck('id')->all());
        $this->postJson("/api/v1/vendor-fees/{$feeId}/submit-for-payment")->assertOk();
        $this->assertTrue(\App\Models\AppNotification::where('user_id', $director->id)->where('entity_id', $feeId)->exists());
        Sanctum::actingAs($director);
        $this->assertContains($feeId, collect($this->getJson('/api/v1/vendor-fees?waiting_on_me=1')->json('data'))->pluck('id')->all());

        // A receipt attached later tells Finance to verify it.
        Sanctum::actingAs($this->officer);
        $this->post("/api/v1/vendor-fees/{$feeId}/receipts", ['receipt_type' => 'efd', 'receipt_number' => 'R-' . uniqid(),
            'issuer_name' => 'ZZ Clearing Co', 'receipt_date' => now()->toDateString(), 'amount' => 300000,
            'file' => UploadedFile::fake()->create('r.pdf', 20, 'application/pdf')], ['Accept' => 'application/json'])->assertCreated();
        $this->assertTrue($told($accountant, $feeId, 'Receipt to verify'));
    }

    public function test_patrick_can_reject_with_a_reason(): void
    {
        $patrick = $this->user('procurement_manager', 'clr-patrick2');
        $this->officer->update(['manager_id' => $patrick->id]);
        $s = $this->atClearingAgent();
        $vendor = Vendor::create(['name' => 'ZZ Clearing Co', 'type' => 'clearing', 'is_active' => true]);
        $feeId = $this->postJson("/api/v1/shipments/{$s['id']}/status", ['step' => 7, 'control_number' => 'CN-R',
            'clearing_cost' => 300000, 'clearing_vendor_id' => $vendor->id])->json('data.clearing_fee.id');
        Sanctum::actingAs($patrick);
        $this->postJson("/api/v1/vendor-fees/{$feeId}/manager-reject", ['rejection_reason' => 'short'])->assertStatus(422);
        $this->postJson("/api/v1/vendor-fees/{$feeId}/manager-reject", ['rejection_reason' => 'The agent charged the wrong rate.'])
            ->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->assertTrue(\App\Models\AppNotification::where('user_id', $this->officer->id)->where('entity_id', $feeId)->exists());
    }
}
