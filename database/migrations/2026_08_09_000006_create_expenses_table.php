<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Small operating costs, paid immediately — no AP step. Larger supplier
        // purchases on payment terms go through vendor_bills instead.
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255);
            $table->foreignId('category_id')->constrained('expense_categories');
            $table->unsignedBigInteger('amount');
            $table->enum('payment_mode', ['cash', 'bank', 'mobile_money'])->default('cash');
            $table->date('expense_date');
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
