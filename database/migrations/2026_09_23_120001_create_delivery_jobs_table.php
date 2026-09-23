<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Section 16.3: a USIRI delivery job. goods_list is a JSON snapshot (item
// name, serial, quantity) captured at creation — deliberately not a
// normalized line-item table, since it's a point-in-time record to compare
// against the signed delivery note, not something re-edited later.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('job_number')->unique();
            $table->foreignId('vendor_id')->constrained()->restrictOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('origin_location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->foreignId('destination_hospital_id')->nullable()->constrained('hospitals')->nullOnDelete();
            $table->string('destination_name')->nullable();
            $table->text('destination_address')->nullable();
            $table->json('goods_list'); // [{item, serial, quantity}, ...]
            $table->string('status')->default('pending'); // pending|delivered|billed|paid

            // Signed delivery note (separate from the fee's own receipt).
            $table->string('delivery_note_original_name')->nullable();
            $table->string('delivery_note_stored_name')->nullable();
            $table->string('delivery_note_mime')->nullable();
            $table->unsignedInteger('delivery_note_size')->nullable();
            $table->string('delivery_note_receiver_name')->nullable();
            $table->date('delivery_note_date')->nullable();
            $table->boolean('delivery_note_goods_mismatch')->default(false);
            $table->foreignId('delivery_note_uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('delivery_note_uploaded_at')->nullable();

            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index('status');
        });

        DB::statement("ALTER TABLE delivery_jobs ADD CONSTRAINT delivery_jobs_status_check CHECK (status IN ('pending','delivered','billed','paid'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_jobs');
    }
};
