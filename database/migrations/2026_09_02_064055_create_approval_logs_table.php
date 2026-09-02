<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Generic append-only audit trail for finance/payroll approval actions —
// one row per state transition (initiated/approved/rejected/paid/applied/
// escalated/reopened...). Polymorphic by subject_type/subject_id rather
// than a table per module, since the shape (who did what, to what, when,
// under what delegation) is identical across Expense/PayrollRun/CreditNote/
// VendorBill/SalaryAdjustment/PurchaseOrder — avoids five near-duplicate
// logging mechanisms for the same concept.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_logs', function (Blueprint $table) {
            $table->id();
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->string('action'); // initiated | approved | rejected | escalated | paid | applied | reopened | ...
            $table->foreignId('actor_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('delegation_id')->nullable()->constrained('delegations')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_logs');
    }
};
