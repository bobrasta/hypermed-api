<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Section 15.2: staff name/designation and payment details are
 * snapshotted onto the plan at submission time, so a later profile
 * edit (a new position, a changed bank account) never rewrites what an
 * already-submitted or already-approved travel plan shows/exports.
 * `purpose` already covers "trip_description" 1:1 (required text, the
 * purpose of the trip) — no separate column needed for that one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('per_diem_requests', function (Blueprint $table) {
            $table->string('staff_name_snapshot')->nullable()->after('user_id');
            $table->string('staff_designation_snapshot')->nullable()->after('staff_name_snapshot');
            $table->json('payment_snapshot')->nullable()->after('staff_designation_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('per_diem_requests', function (Blueprint $table) {
            $table->dropColumn(['staff_name_snapshot', 'staff_designation_snapshot', 'payment_snapshot']);
        });
    }
};
