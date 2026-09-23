<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Section 16: a fee/charge billed by a transit/delivery vendor. Status enum
// matches the spec's own 4 named states exactly (16.1): pending_receipt is
// the only starting point, ready_for_payment is only reachable through
// VendorFeeController::submitForPayment()'s hard gate (16.2), never set
// directly. delivery_job_id has a DB-level unique constraint (nullable-
// unique — Postgres allows multiple NULLs) so "a delivery job can be billed
// exactly once" (16.3) is enforced at the schema level, not just app logic.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_fees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained()->restrictOnDelete();
            $table->foreignId('delivery_job_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->string('description');
            $table->unsignedBigInteger('billed_amount');
            $table->string('currency')->default('TZS');
            $table->string('status')->default('pending_receipt');

            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();

            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();

            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->string('payment_reference')->nullable();

            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();

            $table->timestamps();

            $table->index('status');
        });

        DB::statement("ALTER TABLE vendor_fees ADD CONSTRAINT vendor_fees_status_check CHECK (status IN ('pending_receipt','ready_for_payment','paid','rejected'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_fees');
    }
};
