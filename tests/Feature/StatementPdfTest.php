<?php

namespace Tests\Feature;

use App\Services\DocumentPdfService;
use Tests\TestCase;

// Customer statement PDF (Credit & Receivables). No database: the array
// ReceivablesController::buildStatement() returns, built by hand.
class StatementPdfTest extends TestCase
{
    public function test_statement_pdf_has_ledger_totals_and_ageing(): void
    {
        $text = $this->text($this->statement());

        $this->assertStringContainsString('STATEMENT', $text);
        $this->assertStringContainsString('Mwangaza Hospital', $text);
        $this->assertStringContainsString('Opening balance', $text);
        $this->assertStringContainsString('HH-48300', $text);
        $this->assertStringContainsString('198,000,000', $text);   // invoiced
        $this->assertStringContainsString('UNPAID INVOICES', $text);
        $this->assertStringContainsString('31–60 DAYS', $text);
        $this->assertStringNotContainsString('Prices exclude', $text);
    }

    public function test_statement_without_open_invoices_has_no_ageing(): void
    {
        $s = $this->statement();
        $s['open_invoices'] = [];
        $s['balance_due'] = $s['closing_balance'];
        $text = $this->text($s);
        $this->assertStringNotContainsString('UNPAID INVOICES', $text);
        $this->assertStringNotContainsString('BALANCE DUE TODAY', $text);
    }

    private function statement(): array
    {
        return [
            'customer' => ['hospital_id' => 12, 'client_name' => 'Mwangaza Hospital', 'tin' => '123-456-780',
                'phone' => '0712 000 000', 'email' => null, 'address' => 'Mwanza'],
            'period' => ['from' => '2026-01-01', 'to' => '2026-10-07'],
            'opening_balance' => 50_000_000,
            'total_invoiced' => 198_000_000,
            'total_paid' => 30_000_000,
            'closing_balance' => 218_000_000,
            'balance_due' => 220_000_000,
            'entries' => [
                ['date' => '2026-02-01', 'type' => 'invoice', 'ref' => 'HH-48300', 'invoice_id' => 1,
                    'description' => 'Invoice · terms 30 days', 'debit' => 198_000_000, 'credit' => 0, 'balance' => 248_000_000],
                ['date' => '2026-03-05', 'type' => 'payment', 'ref' => 'PAY-0001', 'invoice_id' => 1,
                    'description' => 'Payment 1 for HH-48300 · bank transfer', 'debit' => 0, 'credit' => 30_000_000, 'balance' => 218_000_000],
            ],
            'open_invoices' => [
                ['id' => 1, 'invoice_number' => 'HH-48300', 'issue_date' => '2026-02-01', 'due_date' => '2026-08-20', 'pay_term' => '30 days',
                    'total' => 198_000_000, 'paid' => 30_000_000, 'balance' => 168_000_000, 'status' => 'partial', 'days_overdue' => 48,
                    'payments_count' => 1, 'last_payment_at' => '2026-03-05'],
                ['id' => 2, 'invoice_number' => 'HH-40001', 'issue_date' => '2025-11-01', 'due_date' => '2025-12-01', 'pay_term' => null,
                    'total' => 52_000_000, 'paid' => 0, 'balance' => 52_000_000, 'status' => 'overdue', 'days_overdue' => 310,
                    'payments_count' => 0, 'last_payment_at' => null],
            ],
        ];
    }

    private function text(array $statement): string
    {
        if (! trim((string) shell_exec('command -v pdftotext'))) {
            $this->markTestSkipped('pdftotext not installed');
        }
        $file = tempnam(sys_get_temp_dir(), 'st');
        file_put_contents($file, app(DocumentPdfService::class)->statementPdf($statement)->getContent());
        $text = (string) shell_exec('pdftotext -layout ' . escapeshellarg($file) . ' -');
        unlink($file);

        return $text;
    }
}
