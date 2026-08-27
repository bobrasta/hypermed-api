<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Replaces the fixed sick/vacation/other enum on leave_requests with an
 * HR-editable catalog. Per the user's explicit preference (2026-08-26):
 * day-allocations are numbers HR types in via a settings screen, not a
 * Tanzania Employment Act accrual formula.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_types', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('label');
            $table->unsignedSmallInteger('default_days_per_year')->default(0);
            // Compassionate: the approver sets the day count at approval time
            // rather than the requester picking a date range up front.
            $table->boolean('requires_manual_days')->default(false);
            // Public Holiday: instances come from the public_holidays
            // calendar, not individual requests — see PublicHoliday.
            $table->boolean('auto_from_calendar')->default(false);
            // Public Holiday also doesn't deduct from anyone's balance.
            $table->boolean('deducts_balance')->default(true);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        DB::table('leave_types')->insert([
            ['key' => 'annual', 'label' => 'Annual', 'default_days_per_year' => 28, 'requires_manual_days' => false, 'auto_from_calendar' => false, 'deducts_balance' => true, 'active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'sick', 'label' => 'Sick', 'default_days_per_year' => 14, 'requires_manual_days' => false, 'auto_from_calendar' => false, 'deducts_balance' => true, 'active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'maternity', 'label' => 'Maternity', 'default_days_per_year' => 84, 'requires_manual_days' => false, 'auto_from_calendar' => false, 'deducts_balance' => true, 'active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'compassionate', 'label' => 'Compassionate', 'default_days_per_year' => 0, 'requires_manual_days' => true, 'auto_from_calendar' => false, 'deducts_balance' => true, 'active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'public_holiday', 'label' => 'Public Holiday', 'default_days_per_year' => 0, 'requires_manual_days' => false, 'auto_from_calendar' => true, 'deducts_balance' => false, 'active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_types');
    }
};
