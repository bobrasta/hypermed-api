<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The rep's own call on when a deal closes and how sure they are of it —
// deliberately entered, never inferred from the pipeline stage.
// forecast_category: commit | best_case | pipeline (null = pipeline).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_leads', function (Blueprint $table) {
            $table->date('expected_close_date')->nullable()->after('follow_up_date');
            $table->string('forecast_category', 20)->nullable()->after('expected_close_date');
            $table->index('expected_close_date');
        });
    }

    public function down(): void
    {
        Schema::table('sales_leads', function (Blueprint $table) {
            $table->dropIndex(['expected_close_date']);
            $table->dropColumn(['expected_close_date', 'forecast_category']);
        });
    }
};
