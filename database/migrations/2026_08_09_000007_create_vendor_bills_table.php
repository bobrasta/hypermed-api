<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_bills', function (Blueprint $table) {
            $table->id();
            $table->string('bill_number')->unique();
            $table->foreignId('supplier_id')->constrained('suppliers');
            $table->foreignId('purchase_order_id')->nullable()->constrained('purchase_orders')->nullOnDelete();
            $table->date('issue_date');
            $table->date('due_date');
            $table->unsignedBigInteger('subtotal')->default(0);
            $table->unsignedBigInteger('tax_amount')->default(0);
            $table->unsignedBigInteger('total')->default(0);
            $table->unsignedBigInteger('amount_paid')->default(0);
            $table->enum('status', ['pending', 'partial', 'paid', 'overdue', 'cancelled'])->default('pending');
            $table->string('currency', 10)->default('TZS');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('vendor_bill_line_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_bill_id')->constrained()->cascadeOnDelete();
            $table->string('description');
            $table->decimal('quantity', 8, 2)->default(1.00);
            $table->unsignedBigInteger('unit_price')->default(0);
            $table->unsignedBigInteger('total')->default(0);
            $table->timestamps();
        });

        Schema::create('vendor_bill_payments', function (Blueprint $table) {
            $table->id();
            $table->string('payment_number')->unique();
            $table->foreignId('vendor_bill_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('amount');
            $table->enum('payment_method', ['cash', 'bank_transfer', 'mobile_money', 'cheque'])->default('cash');
            $table->string('reference')->nullable();
            $table->date('paid_at');
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_bill_payments');
        Schema::dropIfExists('vendor_bill_line_items');
        Schema::dropIfExists('vendor_bills');
    }
};
