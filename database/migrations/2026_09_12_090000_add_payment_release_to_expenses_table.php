<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Expenses were the one money-out flow with no separate payment-release step
// and no self-approval check on releasing funds — approve() posted straight
// to the ledger as if cash had already left. Per-Diem/PO/Vendor Bills all
// split "approved" from "paid" with an accountant-initiates /
// director-or-accountant-releases pair, and the releaser can never be the
// same person who initiated (see PerDiemController::markPaid()). This
// mirrors that exact split for Expenses.
//
// status column is a plain varchar(20) with no DB check constraint (same
// note as the per-diem migration this mirrors) — new string values need no
// constraint migration, just stay <= 20 chars. New chain:
//   pending_cto / pending_director (unchanged approval stage)
//   -> pending_payment (approved, nobody's touched payment yet)
//   -> pending_release (accountant initiated — method/reference recorded)
//   -> paid (released by accountant-or-director, never the initiator)
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->foreignId('payment_initiated_by')->nullable()->after('reviewed_at')->constrained('users')->nullOnDelete();
            $table->timestamp('payment_initiated_at')->nullable()->after('payment_initiated_by');
            $table->string('payment_method', 20)->nullable()->after('payment_initiated_at');
            $table->string('payment_reference')->nullable()->after('payment_method');
            $table->foreignId('paid_by')->nullable()->after('payment_reference')->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable()->after('paid_by');
        });

        // Every expense already sitting in 'approved' was posted to the
        // ledger the moment approve() ran under the old code — that IS the
        // new terminal 'paid' state, so backfill rather than leave them
        // stuck mid-chain with no way to reach 'paid' again (re-releasing
        // would double-post the ledger entry).
        DB::table('expenses')->where('status', 'approved')->update([
            'status'  => 'paid',
            'paid_by' => DB::raw('reviewed_by'),
            'paid_at' => DB::raw('reviewed_at'),
        ]);
    }

    public function down(): void
    {
        DB::table('expenses')->where('status', 'paid')->update(['status' => 'approved']);
        DB::table('expenses')->whereIn('status', ['pending_payment', 'pending_release'])->update(['status' => 'approved']);

        Schema::table('expenses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payment_initiated_by');
            $table->dropConstrainedForeignId('paid_by');
            $table->dropColumn(['payment_initiated_at', 'payment_method', 'payment_reference', 'paid_at']);
        });
    }
};
