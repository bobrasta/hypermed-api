<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit trail for the 4-stage PO approval/payment chain (see
 * PurchaseOrderController): draft -> pending_sales_manager ->
 * pending_director_review -> pending_payment_initiation ->
 * pending_director_final -> approved, then the existing send/receive
 * fulfillment lifecycle continues unchanged. Mirrors the same
 * one-column-pair-per-stage pattern already used on per_diem_requests
 * (team_lead_reviewed_by/at, reviewed_by/at) and expenses.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->foreignId('sales_approved_by')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->timestamp('sales_approved_at')->nullable()->after('sales_approved_by');

            $table->foreignId('director_reviewed_by')->nullable()->after('sales_approved_at')->constrained('users')->nullOnDelete();
            $table->timestamp('director_reviewed_at')->nullable()->after('director_reviewed_by');

            $table->foreignId('payment_initiated_by')->nullable()->after('director_reviewed_at')->constrained('users')->nullOnDelete();
            $table->timestamp('payment_initiated_at')->nullable()->after('payment_initiated_by');

            $table->foreignId('director_approved_by')->nullable()->after('payment_initiated_at')->constrained('users')->nullOnDelete();
            $table->timestamp('director_approved_at')->nullable()->after('director_approved_by');

            $table->foreignId('rejected_by')->nullable()->after('director_approved_at')->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable()->after('rejected_by');
            $table->text('rejection_reason')->nullable()->after('rejected_at');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sales_approved_by');
            $table->dropColumn('sales_approved_at');
            $table->dropConstrainedForeignId('director_reviewed_by');
            $table->dropColumn('director_reviewed_at');
            $table->dropConstrainedForeignId('payment_initiated_by');
            $table->dropColumn('payment_initiated_at');
            $table->dropConstrainedForeignId('director_approved_by');
            $table->dropColumn('director_approved_at');
            $table->dropConstrainedForeignId('rejected_by');
            $table->dropColumn('rejected_at');
            $table->dropColumn('rejection_reason');
        });
    }
};
