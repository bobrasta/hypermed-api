<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links the formal, CTO-approval-gated stock-out request path to the ticket
 * it's for, when it's for one — closes the traceability gap found in the
 * 2026-08-25 role-flow planning pass (a storekeeper reviewing a pending
 * request couldn't tell which repair it belonged to). Doesn't remove or
 * gate the technician's separate self-service parts_used path on
 * ServiceTicket (resolve()/addPart()) — that stays ungated deliberately, so
 * urgent field repairs aren't blocked on approval.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_out_requests', function (Blueprint $table) {
            $table->foreignId('service_ticket_id')->nullable()->after('location_id')
                ->constrained('service_tickets')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_out_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('service_ticket_id');
        });
    }
};
