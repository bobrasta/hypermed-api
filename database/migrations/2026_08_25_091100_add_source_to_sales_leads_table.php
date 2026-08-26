<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The gap that started the 2026-08-25 role-flow planning pass: no way to
 * record where a deal actually came from (tender, referral, inbound call,
 * walk-in). Together with the FK chain SalesLead -> Quotation (lead_id) ->
 * SalesOrder (quotation_id) and each stage's existing created_by/
 * approved_by/delivered_by columns, this is what a future director-only
 * bonus-split tool would walk — no separate attribution table needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_leads', function (Blueprint $table) {
            $table->string('source')->nullable()->after('contact_name_raw');
            $table->string('source_notes')->nullable()->after('source');
        });
        DB::statement("ALTER TABLE sales_leads ADD CONSTRAINT sales_leads_source_check CHECK (source IN ('referral','tender','inbound_call','walk_in','other') OR source IS NULL)");
    }

    public function down(): void
    {
        Schema::table('sales_leads', function (Blueprint $table) {
            $table->dropColumn(['source', 'source_notes']);
        });
    }
};
