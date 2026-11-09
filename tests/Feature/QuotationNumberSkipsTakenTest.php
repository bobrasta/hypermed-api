<?php

namespace Tests\Feature;

use App\Models\Quotation;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// 2026-11-09: quotations continue Clickhuduma's 2026/NNNN series, and the
// Clickhuduma import had already brought in 2026/1276…1297, so every new
// quotation failed with a duplicate number. New numbers now skip any number
// a quotation or sale already has.
//
// Needs a migrated Postgres database; skipped on SQLite.
class QuotationNumberSkipsTakenTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('needs a migrated Postgres database');
        }
    }

    public function test_new_quotation_skips_numbers_taken_by_imported_quotations_and_sales(): void
    {
        $year = (int) now()->format('Y');
        DB::table('document_sequences')->updateOrInsert(['document_type' => 'quotation'], [
            'label' => 'Quotation', 'prefix' => '', 'format' => '{Y}/{N}', 'digits' => 4, 'reset_yearly' => false,
            'next_number' => 91276, 'year' => $year, 'updated_at' => now(),
        ]);
        $admin = User::forceCreate(['name' => 'Number Test', 'email' => 'number.test@example.com',
            'password' => bcrypt('x'), 'role' => 'super_admin', 'is_active' => true]);
        $admin->assignRole('super_admin');
        Quotation::forceCreate(['quotation_number' => "{$year}/91276", 'client_name' => 'Imported', 'status' => 'sent',
            'currency' => 'TZS', 'subtotal' => 0, 'discount_amount' => 0, 'tax_amount' => 0, 'total_amount' => 0,
            'created_by' => $admin->id]);
        DB::table('invoices')->insert(['invoice_number' => "{$year}/91277", 'client_name' => 'Imported', 'issue_date' => now(),
            'due_date' => now(), 'subtotal' => 0, 'tax_rate' => 0, 'tax_amount' => 0, 'total' => 0, 'amount_paid' => 0,
            'status' => 'paid', 'currency' => 'TZS', 'created_at' => now(), 'updated_at' => now()]);

        Sanctum::actingAs($admin);
        $q = $this->postJson('/api/v1/quotations', [
            'client_name' => 'Muhimbili National Hospital', 'client_tin' => '100-200-300',
            'items' => [['description' => 'Liaison XL service kit', 'quantity' => 1, 'unit_price' => 1_500_000]],
        ])->assertCreated()->json('data');

        $this->assertSame("{$year}/91278", $q['quotation_number']);
        $this->assertSame(91279, (int) DB::table('document_sequences')->where('document_type', 'quotation')->value('next_number'));
    }
}
