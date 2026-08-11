<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // expenses.amount stays the NET (pre-VAT) amount — matches Invoice's
        // subtotal/tax_amount/total convention. Cash actually paid = amount + tax_amount.
        Schema::table('expenses', function (Blueprint $table) {
            $table->decimal('tax_rate', 5, 2)->default(0)->after('amount');
            $table->unsignedBigInteger('tax_amount')->default(0)->after('tax_rate');
        });

        Schema::table('vendor_bills', function (Blueprint $table) {
            $table->decimal('tax_rate', 5, 2)->default(0)->after('subtotal');
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropColumn(['tax_rate', 'tax_amount']);
        });
        Schema::table('vendor_bills', function (Blueprint $table) {
            $table->dropColumn('tax_rate');
        });
    }
};
