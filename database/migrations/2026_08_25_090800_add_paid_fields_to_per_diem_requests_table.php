<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Closes the "approved but nothing ever marks it paid" gap found in the
 * 2026-08-25 role-flow planning pass — status stays 'approved' (no new
 * status value), this just adds the missing closing action.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('per_diem_requests', function (Blueprint $table) {
            $table->foreignId('paid_by')->nullable()->after('reviewed_at')->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable()->after('paid_by');
        });
    }

    public function down(): void
    {
        Schema::table('per_diem_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('paid_by');
            $table->dropColumn('paid_at');
        });
    }
};
