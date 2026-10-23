<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Shipments, 2026-10-23: an optional note on a manufacturer-paid shipment
// ("Diasorin paid shipping, DHL called us"), and a record that the import
// permit was sent to the courier — which still happens when we pay nothing.
// All nullable: existing shipments are untouched and stay "We pay"
// (cost_bearer already defaults to hypermed).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $t) {
            $t->text('cost_note')->nullable()->after('paid_by');
            $t->date('permit_sent_at')->nullable()->after('tmda_issued_at');
            $t->foreignId('permit_sent_vendor_id')->nullable()->after('permit_sent_at')->constrained('vendors')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $t) {
            $t->dropConstrainedForeignId('permit_sent_vendor_id');
            $t->dropColumn(['cost_note', 'permit_sent_at']);
        });
    }
};
