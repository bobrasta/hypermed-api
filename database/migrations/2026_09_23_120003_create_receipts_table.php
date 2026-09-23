<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Section 16.1: a receipt attached to a vendor fee. Uniqueness of
// (receipt_number, issuer) is enforced in ReceiptController::store() rather
// than a DB constraint alone — issuer_tin is often blank for small vendors,
// so a single DB unique index can't reliably express "same issuer" across
// both the TIN and name cases. The partial index below still catches the
// TIN-present case at the schema level as a safety net.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_fee_id')->constrained()->cascadeOnDelete();
            $table->string('receipt_type'); // efd|other
            $table->string('receipt_number');
            $table->string('issuer_name');
            $table->string('issuer_tin')->nullable();
            $table->date('receipt_date');
            $table->unsignedBigInteger('amount');

            $table->string('file_original_name');
            $table->string('file_stored_name');
            $table->string('file_mime');
            $table->unsignedInteger('file_size');

            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();

            $table->timestamps();

            $table->index(['receipt_number', 'issuer_tin']);
        });

        DB::statement("ALTER TABLE receipts ADD CONSTRAINT receipts_receipt_type_check CHECK (receipt_type IN ('efd','other'))");
        DB::statement('CREATE UNIQUE INDEX receipts_number_issuer_tin_unique ON receipts (receipt_number, issuer_tin) WHERE issuer_tin IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('receipts');
    }
};
