<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// cancel() previously recorded nothing but the status flip — no who, no
// when, no why. Backfilling a reason on existing cancelled rows isn't
// possible (the information was never captured), so those keep
// cancellation_reason null; PerDiemController::cancel() now requires one
// going forward.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('per_diem_requests', function (Blueprint $table) {
            $table->foreignId('cancelled_by')->nullable()->after('paid_at')->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable()->after('cancelled_by');
            $table->string('cancellation_reason')->nullable()->after('cancelled_at');
        });
    }

    public function down(): void
    {
        Schema::table('per_diem_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['cancelled_at', 'cancellation_reason']);
        });
    }
};
