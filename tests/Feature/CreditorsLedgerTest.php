<?php

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\PerDiemRequest;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorBill;
use App\Models\VendorFee;
use App\Services\Creditors\CreditorService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// Creditors linked to the books: opening balances, supplier invoices in
// their currency, payments through initiate → Director approval with FX
// gain/loss, advances (IFRIC 22), LPO payments and LPOs on credit, vendor
// fees, per diem, statements/exports, and the ledgers reconciling with
// Trade Creditors. Replays the BIOBASE block of CREDITOR LEDGERS DRILLAS.xlsx.
//
// Needs a migrated Postgres database with the seeded roles; skipped on
// SQLite. The new migration runs inside the test transaction if needed.
class CreditorsLedgerTest extends TestCase
{
    use DatabaseTransactions;

    private User $accountant;
    private User $director;
    private User $director2;
    private int $bank;
    private array $before;

    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('needs a migrated Postgres database');
        }
        if (! Schema::hasTable('supplier_payments')) {
            (require database_path('migrations/2026_10_20_120000_creditors_supplier_payments_and_fx.php'))->up();
        }
        Cache::flush();
        $this->accountant = $this->user('accountant', 'cl-acct');
        $this->director = $this->user('super_admin', 'cl-dir');
        $this->director2 = $this->user('cto', 'cl-dir2');
        $this->assertTrue($this->accountant->hasAccountantAuthority(), 'accountant must initiate payments');
        $this->assertTrue($this->director->hasFinalMoneyAuthority(), 'super_admin must approve payments');
        $this->bank = ChartOfAccount::where('code', '1013')->value('id'); // Equity Bank USD
        $this->before = $this->balances();
    }

    private function user(string $role, string $slug): User
    {
        $u = User::forceCreate(['name' => "Test {$slug}", 'email' => "{$slug}@example.com", 'password' => bcrypt('x'), 'role' => $role, 'is_active' => true]);
        $u->assignRole($role);

        return $u;
    }

    private function balances(): array
    {
        return ChartOfAccount::whereIn('code', ['2024', '2025', '4005', '5059', '1013', '1006', '5016', '5017', '5018', '3006', '1002'])->pluck('balance', 'code')->map(fn ($v) => (int) $v)->all();
    }

    private function moved(string $code): int
    {
        return $this->balances()[$code] - $this->before[$code];
    }

    private function supplier(string $name, string $currency): Supplier
    {
        return Supplier::create(['name' => $name, 'type' => 'manufacturer', 'country' => 'China', 'currency' => $currency, 'is_active' => true,
            'contact_email' => 'sales@example.com', 'contact_name' => 'Ms Li']);
    }

    private function pay(Supplier $s, float $amount, float $rate, string $date, array $extra = []): array
    {
        Sanctum::actingAs($this->accountant);
        $p = $this->postJson('/api/v1/supplier-payments', ['supplier_id' => $s->id, 'amount' => $amount, 'exchange_rate' => $rate,
            'paid_from_account_id' => $this->bank, 'payment_date' => $date, 'reference' => 'BP ' . $date] + $extra)->assertCreated()->json('data');
        Sanctum::actingAs($this->director);

        return $this->postJson("/api/v1/supplier-payments/{$p['id']}/approve")->assertOk()->json('data');
    }

    public function test_biobase_example_opening_invoice_and_payments_with_exchange_losses(): void
    {
        $s = $this->supplier('ZZ BIOBASE MEDSTAR CO., LTD', 'USD');
        Sanctum::actingAs($this->accountant);
        $this->putJson("/api/v1/creditors/supplier/{$s->id}/opening-balance", ['as_at' => '2026-01-01', 'amount' => 120500, 'exchange_rate' => 2600])->assertCreated();
        $this->postJson('/api/v1/vendor-bills', ['supplier_id' => $s->id, 'supplier_reference' => 'PV1', 'exchange_rate' => 2650,
            'category_id' => \App\Models\ExpenseCategory::value('id'), 'issue_date' => '2026-02-02', 'due_date' => '2026-03-02',
            'line_items' => [['description' => 'Biosafety cabinet', 'quantity' => 1, 'unit_price' => 920]]])->assertCreated();

        $p1 = $this->pay($s, 95000, 2655, '2026-05-03');
        $this->assertSame(95000 * 55, $p1['fx_difference_tzs']);           // paid at 2655 what was carried at 2600
        $p2 = $this->pay($s, 25000, 2670, '2026-06-06');
        $this->assertSame(25000 * 70, $p2['fx_difference_tzs']);

        $st = $this->getJson("/api/v1/creditors/supplier/{$s->id}/statement")->assertOk()->json('data');
        $this->assertEquals(1420, $st['closing_balance']);                  // 120,500 + 920 − 95,000 − 25,000
        $this->assertEquals(121420, $st['total_credit']);
        $this->assertEquals(120000, $st['total_debit']);
        $this->assertSame(['OPENING BALANCE', 'INVOICE'], [substr($st['entries'][0]['narration'], 0, 15), substr($st['entries'][1]['narration'], 0, 7)]);
        $this->assertEquals(1420 * 2670, $st['entries'][3]['balance_tzs']);  // the sheet: balance × that line's rate
        // Trade Creditors carries opening + invoice − what the payments settled, at the carried rates.
        $book = 120500 * 2600 + 920 * 2650 - 120000 * 2600;
        $this->assertSame($book, $st['book_balance_tzs']);
        $this->assertSame($book, $this->moved('2024'));
        $this->assertSame((95000 * 55) + (25000 * 70), $this->moved('5059'));
        $this->assertSame(-(95000 * 2655 + 25000 * 2670), $this->moved('1013'));

        // The bill is untouched (the opening balance was older) — FIFO.
        $this->assertSame('pending', VendorBill::where('supplier_id', $s->id)->value('status'));

        // Exports.
        $this->get("/api/v1/creditors/supplier/{$s->id}/statement/xlsx")->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->get("/api/v1/creditors/supplier/{$s->id}/statement/pdf")->assertOk();
        $this->get('/api/v1/creditors/xlsx')->assertOk();

        $list = $this->getJson('/api/v1/creditors?category=international&with_balance=1')->assertOk()->json('data');
        $row = collect($list['creditors'])->firstWhere('id', $s->id);
        $this->assertEquals(1420, $row['balance']);
    }

    public function test_advance_before_invoice_uses_the_advance_rate_then_fx_on_the_rest(): void
    {
        $s = $this->supplier('ZZ DIASORIN', 'EUR');
        $adv = $this->pay($s, 1000, 3000, '2026-03-01');
        $this->assertEquals(1000, $adv['advance']);
        $this->assertSame(0, $adv['fx_difference_tzs']);

        Sanctum::actingAs($this->accountant);
        $bill = $this->postJson('/api/v1/vendor-bills', ['supplier_id' => $s->id, 'exchange_rate' => 3100,
            'category_id' => \App\Models\ExpenseCategory::value('id'), 'issue_date' => '2026-03-10', 'due_date' => '2026-04-10',
            'line_items' => [['description' => 'Liaison XL kits', 'quantity' => 2, 'unit_price' => 750.00]]])->assertCreated()->json('data');
        $this->assertSame(1000 * 3000 + 500 * 3100, $bill['total_tzs']);      // IFRIC 22: advance part at the advance's rate
        $this->assertSame('partial', $bill['status']);

        $p = $this->pay($s, 500, 3200, '2026-04-01', ['vendor_bill_id' => $bill['id']]);
        $this->assertSame(500 * 100, $p['fx_difference_tzs']);
        $this->assertSame('paid', VendorBill::find($bill['id'])->status);
        $this->assertSame(0, $this->moved('2024'));

        // A paid invoice can't be cancelled; an untouched one releases its advance.
        Sanctum::actingAs($this->accountant);
        $this->postJson("/api/v1/vendor-bills/{$bill['id']}/cancel")->assertStatus(422);
    }

    public function test_approval_rules_and_rejection(): void
    {
        $s = $this->supplier('ZZ LOCAL DISTRIBUTOR', 'TZS');
        Sanctum::actingAs($this->accountant);
        $p = $this->postJson('/api/v1/supplier-payments', ['supplier_id' => $s->id, 'amount' => 500000,
            'paid_from_account_id' => $this->bank])->assertCreated()->json('data');
        $this->postJson("/api/v1/supplier-payments/{$p['id']}/approve")->assertForbidden(); // not a Director
        $this->assertSame(0, Transaction::where('reference', "SPAY-{$p['id']}")->count());

        Sanctum::actingAs($this->director);
        $this->postJson("/api/v1/supplier-payments/{$p['id']}/reject", ['rejection_reason' => 'Wrong account'])->assertOk();
        $this->postJson("/api/v1/supplier-payments/{$p['id']}/approve")->assertStatus(422);

        // A foreign supplier needs a rate; a non-payment account is refused.
        $usd = $this->supplier('ZZ USD CO', 'USD');
        Sanctum::actingAs($this->accountant);
        $this->postJson('/api/v1/supplier-payments', ['supplier_id' => $usd->id, 'amount' => 10])->assertStatus(422)->assertJsonValidationErrors('exchange_rate');
        $this->postJson('/api/v1/supplier-payments', ['supplier_id' => $s->id, 'amount' => 10,
            'paid_from_account_id' => ChartOfAccount::where('code', '5010')->value('id')])->assertStatus(422);
    }

    public function test_lpo_paid_through_the_chain_and_lpo_on_credit(): void
    {
        $s = $this->supplier('ZZ ZYBIO', 'USD');
        $po = PurchaseOrder::create(['po_number' => 'ZZ-PO-1', 'supplier_id' => $s->id, 'status' => 'pending_payment_initiation',
            'ordered_by' => $this->director2->id, 'currency' => 'USD', 'total_amount' => 10000, 'payment_status' => 'unpaid']);

        Sanctum::actingAs($this->accountant);
        $this->postJson("/api/v1/purchase-orders/{$po->id}/initiate-payment", ['amount' => 3000, 'exchange_rate' => 2700,
            'paid_from_account_id' => $this->bank, 'reference' => 'TT 30% deposit'])->assertOk();
        $this->assertSame('pending_director', SupplierPayment::where('purchase_order_id', $po->id)->value('status'));

        Sanctum::actingAs($this->director);
        $this->postJson("/api/v1/purchase-orders/{$po->id}/approve-director-final")->assertOk();
        $po->refresh();
        $this->assertSame('approved', $po->status);
        $this->assertSame(3000, $po->amount_paid);
        $this->assertSame('partial', $po->payment_status);
        $this->assertSame(-3000 * 2700, $this->moved('2024'));               // a deposit before the invoice: a debit (advance)
        $this->assertSame(-3000 * 2700, $this->moved('1013'));

        $credit = PurchaseOrder::create(['po_number' => 'ZZ-PO-2', 'supplier_id' => $s->id, 'status' => 'pending_payment_initiation',
            'ordered_by' => $this->director2->id, 'currency' => 'USD', 'total_amount' => 500, 'payment_status' => 'unpaid']);
        Sanctum::actingAs($this->accountant);
        $this->postJson("/api/v1/purchase-orders/{$credit->id}/initiate-payment", ['on_credit' => true])->assertOk();
        Sanctum::actingAs($this->director);
        $this->postJson("/api/v1/purchase-orders/{$credit->id}/approve-director-final")->assertOk();
        $credit->refresh();
        $this->assertTrue($credit->on_credit);
        $this->assertSame(['approved', 'unpaid'], [$credit->status, $credit->payment_status]);
        $this->assertSame(0, SupplierPayment::where('purchase_order_id', $credit->id)->count());
    }

    public function test_vendor_fees_and_per_diem_reach_the_books(): void
    {
        $vendor = Vendor::create(['name' => 'ZZ USIRI TRANSPORT', 'type' => 'delivery']);
        $fee = VendorFee::create(['vendor_id' => $vendor->id, 'description' => 'Delivery to Mloganzila', 'billed_amount' => 2000000,
            'currency' => 'TZS', 'status' => 'pending_receipt', 'created_by' => $this->accountant->id]);
        $svc = app(CreditorService::class);
        $svc->postVendorFee($fee);
        $this->assertSame(2000000, $this->moved('5016'));
        $this->assertSame(2000000, $this->moved('2025'));
        $fee->update(['status' => 'paid', 'paid_at' => now(), 'payment_reference' => 'BPJ 001/2']);
        $svc->postVendorFeePaid($fee->fresh());
        $this->assertSame(0, $this->moved('2025'));
        $this->assertSame(-2000000, $this->moved('1002'));

        // Opening owed to a vendor, paid off through a payment.
        Sanctum::actingAs($this->accountant);
        $this->putJson("/api/v1/creditors/vendor/{$vendor->id}/opening-balance", ['as_at' => '2026-01-01', 'amount' => 5050000])->assertCreated();
        $p = $this->postJson('/api/v1/supplier-payments', ['vendor_id' => $vendor->id, 'amount' => 5000000, 'paid_from_account_id' => $this->bank])->json('data');
        Sanctum::actingAs($this->director);
        $this->postJson("/api/v1/supplier-payments/{$p['id']}/approve")->assertOk();
        $st = $this->getJson("/api/v1/creditors/vendor/{$vendor->id}/statement")->json('data');
        $this->assertEquals(50000, $st['closing_balance']);
        $this->assertSame(50000, $st['book_balance_tzs']);

        $pd = PerDiemRequest::first();
        if ($pd) {
            $pd->forceFill(['amount' => 150000, 'payment_method' => 'cash', 'status' => 'paid', 'paid_at' => now()])->save();
            \App\Models\Transaction::where('reference', "PDM-{$pd->id}")->delete();
            $svc->postPerDiemPaid($pd->fresh());
            $this->assertSame(150000, (int) Transaction::where('reference', "PDM-{$pd->id}")->where('type', 'debit')->sum('amount'));
        }
    }
}
