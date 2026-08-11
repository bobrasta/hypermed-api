<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_leads', function (Blueprint $table) {
            $table->date('follow_up_date')->nullable()->after('demo_date');
        });
    }

    public function down(): void
    {
        Schema::table('sales_leads', function (Blueprint $table) {
            $table->dropColumn('follow_up_date');
        });
    }
};
