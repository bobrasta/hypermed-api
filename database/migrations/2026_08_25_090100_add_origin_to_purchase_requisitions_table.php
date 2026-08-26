<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Provenance tag only — a requisition triggered by stock crossing
 * reorder_level looks identical to one for a brand-new product being
 * introduced once it reaches procurement; both flow through the same
 * approval chain (see PurchaseOrderController). This just records why it
 * was raised, for reporting/context, not a different workflow.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_requisitions', function (Blueprint $table) {
            $table->string('origin')->default('manual')->after('status');
        });
        DB::statement("ALTER TABLE purchase_requisitions ADD CONSTRAINT purchase_requisitions_origin_check CHECK (origin IN ('manual','reorder','new_product'))");
    }

    public function down(): void
    {
        Schema::table('purchase_requisitions', function (Blueprint $table) {
            $table->dropColumn('origin');
        });
    }
};
