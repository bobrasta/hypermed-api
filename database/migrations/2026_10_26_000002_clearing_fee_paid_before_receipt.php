<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Clearing fees (user, 2026-10-26): the clearing agent is paid first and the
 * receipt follows after payment — Section 16's "no receipt, no payment" gate
 * meant these fees were never paid in the system, so nothing reached the
 * books. A shipment's clearing fee is paid on the assessment (accountant
 * requests, Director approves as before) and its receipt is attached after.
 * The accountant is notified when the assessed cost is entered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendor_fees', function (Blueprint $t) {
            $t->boolean('receipt_after_payment')->default(false)->after('status');
        });
        DB::table('vendor_fees')->whereIn('id', DB::table('shipments')->whereNotNull('vendor_fee_id')->select('vendor_fee_id'))
            ->update(['receipt_after_payment' => true]);

        DB::table('notification_templates')->insertOrIgnore([
            'template_key' => 'vendor_fee.clearing_to_pay', 'notification_type' => 'vendor_fee_ready_for_payment',
            'title_template' => 'Clearing fee to pay — {reference}',
            'body_template' => 'Shipment {reference}: {vendor_name} assessed TZS {billed_amount} (control no. {control_number}). Request the payment in Vendors & Delivery — the receipt follows after payment.',
            'description' => 'Accountant — the assessed clearing cost of a shipment was entered',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('notification_templates')->where('template_key', 'vendor_fee.clearing_to_pay')->delete();
        Schema::table('vendor_fees', fn (Blueprint $t) => $t->dropColumn('receipt_after_payment'));
    }
};
