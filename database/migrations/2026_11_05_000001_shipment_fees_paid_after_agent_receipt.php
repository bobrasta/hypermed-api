<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * User, 2026-11-05: the clearing agent pays first and brings the receipts at
 * "Arrived at HQ"; only then does Finance ask for payment and the Director
 * pay the agent. Open shipment fees (not yet with the Director, not paid)
 * switch to receipt-first. Paid ones keep "receipt to follow".
 */
return new class extends Migration
{
    public function up(): void
    {
        $ids = DB::table('shipments')->whereNotNull('vendor_fee_id')->pluck('vendor_fee_id')
            ->merge(DB::table('shipments')->whereNotNull('handling_fee_id')->pluck('handling_fee_id'))
            ->unique()->values();
        if ($ids->isEmpty()) {
            return;
        }
        DB::table('vendor_fees')->whereIn('id', $ids)
            ->whereIn('status', ['pending_manager', 'pending_receipt'])
            ->update(['receipt_after_payment' => false]);
    }

    public function down(): void
    {
        // Not reversible: the old flag value isn't kept.
    }
};
