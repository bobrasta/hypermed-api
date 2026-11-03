<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Purchase order and purchase return amounts take cents (USD 12.50), like
// supplier payments and bills already do. Existing whole amounts keep their value.
return new class extends Migration
{
    public function up(): void
    {
        // SQLite (tests) keeps any number in any column — nothing to change.
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        // total_cost is computed from unit_cost, so it is rebuilt around the change.
        DB::statement('ALTER TABLE purchase_order_items DROP COLUMN IF EXISTS total_cost');
        Schema::table('purchase_order_items', fn (Blueprint $t) => $t->decimal('unit_cost', 16, 2)->default(0)->change());
        DB::statement('ALTER TABLE purchase_order_items ADD COLUMN total_cost numeric(18,2) GENERATED ALWAYS AS (quantity_ordered * unit_cost) STORED');
        Schema::table('purchase_orders', function (Blueprint $t) {
            $t->decimal('total_amount', 16, 2)->default(0)->change();
            $t->decimal('amount_paid', 16, 2)->default(0)->change();
        });
        Schema::table('purchase_return_items', function (Blueprint $t) {
            $t->decimal('unit_cost', 16, 2)->default(0)->change();
            $t->decimal('total', 16, 2)->default(0)->change();
        });
        Schema::table('purchase_returns', fn (Blueprint $t) => $t->decimal('total_amount', 16, 2)->default(0)->change());
    }

    public function down(): void
    {
        // Not reversed: rounding cents back to whole units would lose money.
    }
};
