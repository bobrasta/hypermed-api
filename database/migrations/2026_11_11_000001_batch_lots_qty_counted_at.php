<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// When a lot's quantity was last counted or set by hand. A lot's "left" is its
// quantity less what invoices sold from it after this moment (falls back to
// created_at), so a recount isn't reduced again by sales it already reflects.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('batch_lots', 'qty_counted_at')) {
            Schema::table('batch_lots', function (Blueprint $table) {
                $table->timestamp('qty_counted_at')->nullable()->after('qty_remaining');
            });
        }
    }

    public function down(): void
    {
        Schema::table('batch_lots', function (Blueprint $table) {
            $table->dropColumn('qty_counted_at');
        });
    }
};
