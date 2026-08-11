<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            // Explicit opt-in — only items that are actual installable equipment
            // (not consumables/spare parts) should spawn a Machine record when
            // a sales order delivering them to a hospital is fulfilled.
            $table->boolean('creates_machine_record')->default(false)->after('is_active');
            $table->unsignedInteger('warranty_months')->nullable()->after('creates_machine_record');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->dropColumn(['creates_machine_record', 'warranty_months']);
        });
    }
};
