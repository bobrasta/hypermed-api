<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Section 13 of hypermed_claude_code_prompt.md: every lifecycle-stage move
// (Allocate: In Stock -> Allocated, Handover: Allocated -> Installed, and
// Return) gets its own row here — the audit trail of who moved a machine
// where, tied to the sale that justified it.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('machine_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('machine_id')->constrained()->cascadeOnDelete();
            $table->string('transfer_type'); // allocate | handover | return
            $table->foreignId('from_hospital_id')->nullable()->constrained('hospitals')->nullOnDelete();
            $table->foreignId('to_hospital_id')->nullable()->constrained('hospitals')->nullOnDelete();
            $table->foreignId('from_location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->string('reason')->nullable();
            $table->foreignId('quotation_id')->nullable()->constrained('quotations')->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            // Signed handover certificate PDF, once generated — nullable
            // since the Allocate step has no document, only Handover does.
            $table->string('document_path')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('machine_transfers');
    }
};
