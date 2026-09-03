<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Splits the old single "approved" terminal-ish status (CTO approved, then
// whoever wanted could call markPaid()) into the real chain the business
// actually wants: CTO approves -> finance initiates payment (records
// method/reference, but hasn't moved money on their own authority) ->
// Director authorizes/marks paid. Mirrors the PurchaseOrder chain's
// initiate-then-final-approve split — same segregation-of-duty reasoning:
// the person who prepares a payment shouldn't be the one who finally signs
// it off.
//
// status column is a plain varchar(20) with no DB check constraint (unlike
// vendor_bills), so new string values need no constraint migration — just
// keep every new value <= 20 chars.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('per_diem_requests', function (Blueprint $table) {
            $table->foreignId('payment_initiated_by')->nullable()->after('rejection_reason')->constrained('users')->nullOnDelete();
            $table->timestamp('payment_initiated_at')->nullable()->after('payment_initiated_by');
            $table->string('payment_method', 20)->nullable()->after('payment_initiated_at');
            $table->string('payment_reference')->nullable()->after('payment_method');
        });

        DB::transaction(function () {
            // Old flow's "approved, not yet paid" == new flow's earliest
            // payment stage — finance simply hasn't initiated yet.
            DB::table('per_diem_requests')
                ->where('status', 'approved')->whereNull('paid_at')
                ->update(['status' => 'pending_payment']);

            // Old flow's "approved AND paid_at set" (markPaid() already ran,
            // under the old accountant-only single step) is equivalent to
            // the new flow's terminal state.
            DB::table('per_diem_requests')
                ->where('status', 'approved')->whereNotNull('paid_at')
                ->update(['status' => 'paid']);
        });
    }

    public function down(): void
    {
        DB::transaction(function () {
            DB::table('per_diem_requests')
                ->whereIn('status', ['pending_payment', 'pending_director'])
                ->update(['status' => 'approved']);
            DB::table('per_diem_requests')
                ->where('status', 'paid')
                ->update(['status' => 'approved']);
        });

        Schema::table('per_diem_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payment_initiated_by');
            $table->dropColumn(['payment_initiated_at', 'payment_method', 'payment_reference']);
        });
    }
};
