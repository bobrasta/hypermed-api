<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_item_id')->constrained()->cascadeOnDelete();
            $table->enum('type', [
                'receive',      // stock in from PO or donation
                'issue',        // stock out to a service ticket or hospital
                'transfer',     // between locations (future: warehouse support)
                'write_off',    // expired, damaged, lost
                'adjustment',   // manual correction (replaces old "adjust" endpoint)
                'return',       // returned from field/hospital back to store
            ]);
            $table->integer('quantity');                      // positive = in, negative = out
            $table->integer('quantity_before');               // snapshot for audit trail
            $table->integer('quantity_after');
            $table->bigInteger('unit_cost')->nullable();      // cost at time of movement
            $table->string('currency', 10)->default('TZS');
            $table->string('reference_type')->nullable();     // 'purchase_order', 'service_ticket', 'manual'
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('location')->nullable();           // warehouse/location label
            $table->string('batch_number')->nullable();
            $table->date('expiry_date')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('performed_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index(['inventory_item_id', 'created_at']);
            $table->index(['reference_type', 'reference_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
