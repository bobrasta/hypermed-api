<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inventory_item_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('unit_price')->default(0);
            $table->string('currency', 10)->default('USD');
            $table->string('supplier_sku')->nullable();   // supplier's own part number
            $table->unsignedInteger('lead_time_days')->nullable();
            $table->unsignedInteger('minimum_order_qty')->default(1);
            $table->boolean('is_preferred')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['supplier_id', 'inventory_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_items');
    }
};
