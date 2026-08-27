<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Every amount is manually entered/reviewed by the accountant this
            // round — no PAYE/NSSF/HESLB auto-calculation engine yet (needs
            // real current TRA/NSSF/HESLB rate tables, a separate future
            // task). Columns exist now so that engine can slot values in
            // later without a schema change.
            $table->bigInteger('base_salary')->default(0);
            $table->bigInteger('allowances_total')->default(0);
            $table->bigInteger('overtime_amount')->default(0);
            $table->bigInteger('paye_amount')->default(0);
            $table->bigInteger('nssf_amount')->default(0);
            $table->bigInteger('heslb_amount')->default(0);
            $table->bigInteger('other_deductions')->default(0);
            $table->bigInteger('gross_pay')->default(0);
            $table->bigInteger('net_pay')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['payroll_run_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_items');
    }
};
