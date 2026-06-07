<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotations', function (Blueprint $table) {
            $table->id();
            $table->string('quotation_number', 20)->unique();
            $table->foreignId('lead_id')->nullable()->constrained('sales_leads')->nullOnDelete();
            $table->string('client_name');
            $table->string('client_contact')->nullable();
            $table->string('client_email')->nullable();
            $table->string('status', 20)->default('draft'); // draft, sent, accepted, rejected, expired, converted
            $table->date('valid_until')->nullable();
            $table->string('currency', 10)->default('TZS');
            $table->unsignedBigInteger('subtotal')->default(0);
            $table->unsignedBigInteger('discount_amount')->default(0);
            $table->unsignedBigInteger('tax_amount')->default(0);
            $table->unsignedBigInteger('total_amount')->default(0);
            $table->text('notes')->nullable();
            $table->text('terms')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotations');
    }
};
