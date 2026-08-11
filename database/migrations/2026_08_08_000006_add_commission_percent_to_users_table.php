<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Default commission rate for this user when they're the commission
            // agent on a sales order. Null = does not earn commission.
            $table->decimal('commission_percent', 5, 2)->nullable()->after('max_discount_percent');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('commission_percent');
        });
    }
};
