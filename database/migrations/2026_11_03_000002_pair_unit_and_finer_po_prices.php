<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Items can be counted in pairs (gloves) or bottles, as the app's unit list
// already offers; purchase order unit costs keep 4 decimals (USD 0.115 a pair).
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::statement('ALTER TABLE inventory_items DROP CONSTRAINT IF EXISTS inventory_items_unit_of_measure_check');
        DB::statement("ALTER TABLE inventory_items ADD CONSTRAINT inventory_items_unit_of_measure_check CHECK (unit_of_measure IN ('piece','box','litre','set','kg','roll','pair','bottle'))");
        DB::statement('ALTER TABLE purchase_order_items DROP COLUMN IF EXISTS total_cost');
        DB::statement('ALTER TABLE purchase_order_items ALTER COLUMN unit_cost TYPE numeric(18,4)');
        DB::statement('ALTER TABLE purchase_order_items ADD COLUMN total_cost numeric(20,2) GENERATED ALWAYS AS (round(quantity_ordered * unit_cost, 2)) STORED');
    }

    public function down(): void
    {
    }
};
