<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Per-line TSh discount on sales (invoices, drafts, proformas) and
// quotations. A line's total is qty × unit price − discount.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_line_items', function (Blueprint $t) {
            $t->bigInteger('discount')->default(0)->after('unit_price');
        });
        Schema::table('quotation_items', function (Blueprint $t) {
            $t->bigInteger('discount_amount')->default(0)->after('discount_percent');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_line_items', fn (Blueprint $t) => $t->dropColumn('discount'));
        Schema::table('quotation_items', fn (Blueprint $t) => $t->dropColumn('discount_amount'));
    }
};
