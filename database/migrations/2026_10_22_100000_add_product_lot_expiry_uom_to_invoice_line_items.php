<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Invoice lines carry the product, its unit of measure, lot number and
// expiry date (as Clickhuduma's did) — the invoice PDF's LOT / EXP. / UOM
// columns printed "-" because there was nothing to fill them with.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_line_items', function (Blueprint $t) {
            $t->foreignId('inventory_item_id')->nullable()->after('invoice_id')->constrained()->nullOnDelete();
            $t->string('unit_of_measure', 30)->nullable()->after('quantity');
            $t->string('lot_number', 100)->nullable()->after('unit_of_measure');
            $t->date('expiry_date')->nullable()->after('lot_number');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_line_items', function (Blueprint $t) {
            $t->dropConstrainedForeignId('inventory_item_id');
            $t->dropColumn(['unit_of_measure', 'lot_number', 'expiry_date']);
        });
    }
};
