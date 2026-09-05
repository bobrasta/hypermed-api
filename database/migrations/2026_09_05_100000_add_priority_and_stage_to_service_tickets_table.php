<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// priority and stage are plain varchar columns with NO Postgres CHECK
// constraint — same style as PerDiemRequest's status/stage columns (see
// 2026_09_03_115534_add_payment_initiation_to_per_diem_requests_table.php's
// note) — so future allowed-value changes don't need a constraint
// migration. Application-level allowed values:
//   priority: critical|high|medium|low
//   stage:    assigned|travelling|on_site|repair|signed_off
// stage is deliberately separate from the existing status enum column
// (open/in_progress/resolved/overdue) — other code already depends on
// that enum's exact values, so it's untouched here. The "assigned" stage's
// timestamp is just the ticket's existing created_at — no new column for
// it; travelling_at/on_site_at/repair_at/signed_off_at cover the rest.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_tickets', function (Blueprint $table) {
            $table->string('priority', 20)->default('medium')->after('type');
            $table->string('stage', 20)->default('assigned')->after('status');
            $table->timestamp('travelling_at')->nullable()->after('acknowledged_at');
            $table->timestamp('on_site_at')->nullable()->after('travelling_at');
            $table->timestamp('repair_at')->nullable()->after('on_site_at');
            $table->timestamp('signed_off_at')->nullable()->after('repair_at');
        });
    }

    public function down(): void
    {
        Schema::table('service_tickets', function (Blueprint $table) {
            $table->dropColumn(['priority', 'stage', 'travelling_at', 'on_site_at', 'repair_at', 'signed_off_at']);
        });
    }
};
