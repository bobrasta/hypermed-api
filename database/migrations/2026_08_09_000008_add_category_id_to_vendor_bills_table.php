<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendor_bills', function (Blueprint $table) {
            // Used to pick the GL debit account when the bill isn't tied to a
            // Purchase Order (which always debits Inventory instead).
            $table->foreignId('category_id')->nullable()->after('purchase_order_id')
                ->constrained('expense_categories')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('vendor_bills', function (Blueprint $table) {
            $table->dropForeign(['category_id']);
            $table->dropColumn('category_id');
        });
    }
};
