<?php

namespace Tests\Feature;

use App\Services\SalesExportService;
use Tests\TestCase;

class SalesExportPdfTest extends TestCase
{
    private function rows(int $n): array
    {
        return array_map(fn ($i) => [
            'date' => '2026-09-01', 'number' => "HH-{$i}", 'customer' => 'Some Long Hospital Name Limited',
            'contact' => '0754000000', 'status' => 'Paid', 'method' => 'Bank transfer',
            'total' => 1000000, 'paid' => 500000, 'due' => 500000, 'added_by' => 'Leticia Mvukiye',
        ], range(1, $n));
    }

    public function test_pdf_puts_one_table_chunk_on_each_page(): void
    {
        $pdf = app(SalesExportService::class)->pdf($this->rows(120), 'test', ['total' => 1, 'paid' => 1, 'due' => 1]);

        $this->assertStringStartsWith('%PDF', $pdf);
        // 120 rows = 4 chunks of 36; a chunk that overflowed its page would add pages.
        $this->assertSame(4, preg_match_all('#/Type\s*/Page[^s]#', $pdf));
    }

    public function test_pdf_with_no_rows_still_renders(): void
    {
        $pdf = app(SalesExportService::class)->pdf([], 'test', ['total' => 0, 'paid' => 0, 'due' => 0]);

        $this->assertSame(1, preg_match_all('#/Type\s*/Page[^s]#', $pdf));
    }
}
