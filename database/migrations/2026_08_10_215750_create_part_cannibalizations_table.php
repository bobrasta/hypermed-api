<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('part_cannibalizations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_serial_number_id')->constrained('serial_numbers')->cascadeOnDelete();
            $table->foreignId('part_used_id')->nullable()->constrained('parts_used')->nullOnDelete();
            $table->foreignId('removed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('removed_at');
            $table->enum('status', ['open', 'replacement_ordered', 'resolved'])->default('open');
            $table->foreignId('replacement_purchase_order_id')->nullable()->constrained('purchase_orders')->nullOnDelete();
            $table->timestamp('replacement_ordered_at')->nullable();
            $table->timestamp('replacement_received_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['source_serial_number_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('part_cannibalizations');
    }
};
