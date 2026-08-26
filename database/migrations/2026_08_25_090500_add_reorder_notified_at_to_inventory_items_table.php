<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dedupe marker for the low-stock notification StockService fires when an
 * item's stock_qty crosses at/below reorder_level — without this, every
 * further deduction past the threshold would re-notify. Cleared once stock
 * is replenished back above reorder_level, so the next crossing notifies
 * again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->timestamp('reorder_notified_at')->nullable()->after('reorder_level');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->dropColumn('reorder_notified_at');
        });
    }
};
