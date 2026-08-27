<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Small key/value store for the few genuinely global HR settings that
 * don't warrant their own table: default probation length, expected
 * attendance start/end time, alert-engine lead days. Reused across
 * Phases 3-5 rather than one config table per feature.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('value')->nullable();
            $table->timestamps();
        });

        DB::table('hr_settings')->insert([
            ['key' => 'default_probation_days', 'value' => '90', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'expected_start_time', 'value' => '08:00', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'expected_end_time', 'value' => '17:00', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'reminder_lead_days', 'value' => '30', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_settings');
    }
};
