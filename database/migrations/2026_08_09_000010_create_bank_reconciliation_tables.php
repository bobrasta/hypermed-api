<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->date('period_from');
            $table->date('period_to');
            $table->string('currency', 10)->default('TZS');
            $table->bigInteger('statement_closing_balance')->default(0);
            $table->enum('status', ['draft', 'complete'])->default('draft');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['period_from', 'period_to', 'currency']);
        });

        // Imported bank statement lines. A line is matched to at most one
        // system-side money movement: a Payment (bank credit — money received)
        // or an Expense / VendorBillPayment (bank debit — money paid out).
        Schema::create('bank_statement_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reconciliation_id')->constrained('bank_reconciliations')->cascadeOnDelete();
            $table->date('txn_date');
            $table->string('description', 500)->default('');
            $table->bigInteger('debit')->default(0);
            $table->bigInteger('credit')->default(0);
            $table->foreignId('matched_payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->foreignId('matched_expense_id')->nullable()->constrained('expenses')->nullOnDelete();
            $table->foreignId('matched_vendor_bill_payment_id')->nullable()->constrained('vendor_bill_payments')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index('reconciliation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_statement_lines');
        Schema::dropIfExists('bank_reconciliations');
    }
};
