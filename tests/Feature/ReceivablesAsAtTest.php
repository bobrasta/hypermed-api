<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Services\EffectivePermissionResolver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

// Customer ledger "as at" a date: the accounts team's ABC example — invoiced
// 100M, paid 30M, invoiced 170M, paid 80M, invoiced 50M, paid 120M — must
// show what was owed on any day, and each invoice's status then.
//
// Needs a Postgres database with the schema in place (DB_* env vars); the
// migrations can't build one from scratch, so with the default in-memory
// SQLite this test is skipped. Each test runs in a rolled-back transaction.
class ReceivablesAsAtTest extends TestCase
{
    use DatabaseTransactions;

    private const ABC = ['client_name' => 'ABC'];

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('needs a migrated Postgres database');
        }
        $this->mock(EffectivePermissionResolver::class, fn ($m) => $m->shouldReceive('can')->andReturn(true));

        $this->user = User::forceCreate(['name' => 'Accounts test', 'email' => 'asat-test@example.com', 'password' => bcrypt('x')]);
        $inv1 = $this->invoice('INV-001', '2026-07-05', 100_000_000);
        $inv2 = $this->invoice('INV-002', '2026-08-02', 170_000_000);
        $inv3 = $this->invoice('INV-003', '2026-09-10', 50_000_000);
        // As recorded: the 80M cleared INV-001's 70M and put 10M on INV-002.
        $this->pay($inv1, '2026-07-20', 30_000_000, 'RCPT-001');
        $this->pay($inv1, '2026-08-25', 70_000_000, 'RCPT-002a');
        $this->pay($inv2, '2026-08-25', 10_000_000, 'RCPT-002b');
        $this->pay($inv2, '2026-09-30', 120_000_000, 'RCPT-003');
        $inv3->refresh();
        Sanctum::actingAs($this->user);
    }

    public function test_statement_as_at_end_of_august(): void
    {
        $s = $this->getJson('/api/v1/receivables/statement?' . http_build_query(self::ABC + ['date_from' => '2026-07-01', 'date_to' => '2026-08-31']))
            ->assertOk()->json();

        $this->assertSame(0, $s['opening_balance']);
        $this->assertSame(270_000_000, $s['total_invoiced']);
        $this->assertSame(110_000_000, $s['total_paid']);
        $this->assertSame(160_000_000, $s['closing_balance']);   // owed on 31 Aug
        $this->assertSame(90_000_000, $s['balance_due']);        // owed today
        $this->assertSame('2026-08-31', $s['as_at']);
        $this->assertEqualsWithDelta(40.7, $s['collected_pct'], 0.05);

        $status = collect($s['invoice_status'])->keyBy('invoice_number');
        $this->assertSame(['INV-001', 'INV-002'], $status->keys()->all());   // INV-003 not issued yet
        $this->assertSame('paid', $status['INV-001']['status']);
        $this->assertSame(['partly_paid', 10_000_000, 160_000_000],
            [$status['INV-002']['status'], $status['INV-002']['paid'], $status['INV-002']['remaining']]);
    }

    public function test_september_opens_with_august_balance(): void
    {
        $s = $this->getJson('/api/v1/receivables/statement?' . http_build_query(self::ABC + ['date_from' => '2026-09-01', 'date_to' => '2026-09-30']))
            ->assertOk()->json();

        $this->assertSame(160_000_000, $s['opening_balance']);
        $this->assertSame(90_000_000, $s['closing_balance']);
        $status = collect($s['invoice_status'])->keyBy('invoice_number');
        // INV-001 was paid off before September: not listed. INV-002 still
        // owes 40M (10M + 120M paid of 170M), INV-003 was issued in it.
        $this->assertSame(['INV-002', 'INV-003'], $status->keys()->all());
        $this->assertSame(['partly_paid', 40_000_000], [$status['INV-002']['status'], $status['INV-002']['remaining']]);
        $this->assertSame('unpaid', $status['INV-003']['status']);
    }

    public function test_receivables_list_as_at_a_past_date(): void
    {
        $then = $this->getJson('/api/v1/receivables?as_at=2026-08-31')->assertOk()->json();
        $this->assertSame('2026-08-31', $then['as_at']);
        $this->assertSame(160_000_000, $then['summary']['outstanding']);
        $this->assertSame([160_000_000, 10_000_000, 1], [$then['data'][0]['balance'], $then['data'][0]['paid'], $then['data'][0]['open_invoices']]);

        $july = $this->getJson('/api/v1/receivables?as_at=2026-07-21')->assertOk()->json();
        $this->assertSame(70_000_000, $july['summary']['outstanding']);

        $now = $this->getJson('/api/v1/receivables')->assertOk()->json();
        $this->assertSame(90_000_000, $now['summary']['outstanding']);
    }

    public function test_excel_ledger_adds_up(): void
    {
        $res = $this->get('/api/v1/receivables/statement/xlsx?' . http_build_query(self::ABC + ['date_from' => '2026-07-01', 'date_to' => '2026-09-30']))
            ->assertOk();
        $this->assertStringContainsString('Ledger-ABC-2026-09-30.xlsx', $res->headers->get('Content-Disposition'));

        $file = tempnam(sys_get_temp_dir(), 'lg');
        file_put_contents($file, $res->getContent());
        $book = IOFactory::load($file);
        unlink($file);

        $ledger = $book->getSheetByName('Ledger');
        $this->assertSame('CUSTOMER LEDGER', $ledger->getCell('A6')->getValue());
        $this->assertEquals(320_000_000, $ledger->getCell('F8')->getCalculatedValue());   // total invoiced
        $this->assertEquals(230_000_000, $ledger->getCell('F9')->getCalculatedValue());   // total paid
        $this->assertEquals(90_000_000, $ledger->getCell('F10')->getCalculatedValue());   // outstanding

        $status = $book->getSheetByName('Invoice Status');
        $this->assertSame('INV-001', $status->getCell('A11')->getValue());
        $this->assertSame('Unpaid', $status->getCell('H13')->getValue());
        $this->assertSame('OK', $status->getCell('H16')->getCalculatedValue());
    }

    private function invoice(string $no, string $date, int $total): Invoice
    {
        return Invoice::create(['invoice_number' => $no, 'client_name' => 'ABC', 'issue_date' => $date,
            'due_date' => date('Y-m-d', strtotime("$date +30 days")), 'subtotal' => $total, 'tax_rate' => 0,
            'tax_amount' => 0, 'total' => $total, 'amount_paid' => 0, 'status' => 'pending', 'currency' => 'TZS']);
    }

    private function pay(Invoice $i, string $date, int $amount, string $ref): void
    {
        Payment::create(['payment_number' => $ref, 'invoice_id' => $i->id, 'amount' => $amount,
            'payment_method' => 'bank_transfer', 'paid_at' => $date, 'recorded_by' => $this->user->id]);
        $paid = (int) $i->payments()->sum('amount');
        $i->update(['amount_paid' => $paid, 'status' => $paid >= $i->total ? 'paid' : 'partial']);
    }
}
