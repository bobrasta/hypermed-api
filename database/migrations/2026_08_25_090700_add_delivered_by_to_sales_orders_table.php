<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who physically delivered the order — closes both the "no logistics owner"
 * gap (SalesOrderController::deliver() had zero permission check before
 * this) and the sales per-stage attribution gap (see
 * project_hypermed_role_flow_planning.md memory).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->foreignId('delivered_by')->nullable()->after('delivered_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('delivered_by');
        });
    }
};
