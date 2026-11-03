<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// A sequence can carry its own format ({Y} year, {N} padded number).
// Quotations continue Clickhuduma's series (2026/1275 → 2026/1276 …) instead
// of a separate QT-2026-0001 series; it keeps counting across years as
// Clickhuduma did.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('document_sequences', 'format')) {
            Schema::table('document_sequences', fn (Blueprint $t) => $t->string('format', 40)->nullable()->after('prefix'));
        }
        $year = (int) now()->format('Y');
        $last = DB::table('quotations')->where('quotation_number', 'like', "{$year}/%")->pluck('quotation_number')
            ->map(fn ($n) => (int) substr($n, 5))->max() ?? 0;
        DB::table('document_sequences')->updateOrInsert(['document_type' => 'quotation'], [
            'label' => 'Quotation', 'prefix' => '', 'format' => '{Y}/{N}', 'digits' => 4, 'reset_yearly' => false,
            'next_number' => max($last + 1, (int) DB::table('document_sequences')->where('document_type', 'quotation')->value('next_number') ?: 1),
            'year' => $year, 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
    }
};
