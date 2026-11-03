<?php

namespace Tests\Feature;

use App\Services\DocumentNumberService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// Quotations continue Clickhuduma's series: 2026/1275 → 2026/1276 …
//
// Needs a migrated Postgres database; skipped on SQLite.
class DocumentNumberFormatTest extends TestCase
{
    use DatabaseTransactions;

    public function test_quotation_numbers_continue_the_clickhuduma_series(): void
    {
        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('needs a migrated Postgres database');
        }
        DB::table('document_sequences')->where('document_type', 'quotation')->update(['next_number' => 1276]);
        $y = now()->format('Y');
        $svc = app(DocumentNumberService::class);
        $this->assertSame("{$y}/1276", $svc->next('quotation'));
        $this->assertSame("{$y}/1277", $svc->next('quotation'));
        // Other documents keep their own style.
        $this->assertMatchesRegularExpression("/^INV-{$y}-\\d{4}$/", $svc->next('invoice'));
    }
}
