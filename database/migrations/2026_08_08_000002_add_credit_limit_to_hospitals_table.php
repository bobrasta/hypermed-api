<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hospitals', function (Blueprint $table) {
            // Null = unlimited credit. Set in TZS (or whatever currency the
            // hospital is invoiced in — this system is currently single-currency
            // per invoice, matching how the rest of the money columns work).
            $table->unsignedBigInteger('credit_limit')->nullable()->after('revenue_monthly');
        });
    }

    public function down(): void
    {
        Schema::table('hospitals', function (Blueprint $table) {
            $table->dropColumn('credit_limit');
        });
    }
};
