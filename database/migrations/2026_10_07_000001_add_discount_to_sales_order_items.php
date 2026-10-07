<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// A sales order line keeps its TSh discount (from the quotation it came
// from) so invoicing it doesn't re-price at list price. Existing lines get
// theirs back from total_price, which conversion already copied discounted.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_order_items', function (Blueprint $t) {
            $t->bigInteger('discount')->default(0)->after('unit_price');
        });

        DB::table('sales_order_items')
            ->whereRaw('quantity_ordered * unit_price > total_price')
            ->update(['discount' => DB::raw('quantity_ordered * unit_price - total_price')]);
    }

    public function down(): void
    {
        Schema::table('sales_order_items', fn (Blueprint $t) => $t->dropColumn('discount'));
    }
};
