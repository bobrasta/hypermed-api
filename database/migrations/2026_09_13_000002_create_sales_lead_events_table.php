<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Backs the Lead detail page's "Stage history" panel with real, forward-
// logged events instead of fabricated data — the source design explicitly
// flags that today stage changes are overwritten with no history, so this
// starts real logging from here on rather than inventing a backfilled trail.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_lead_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_lead_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30); // stage_change | reassigned
            $table->string('title');
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_lead_events');
    }
};
