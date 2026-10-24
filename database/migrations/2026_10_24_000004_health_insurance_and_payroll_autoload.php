<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 2026-10-24 (user): health insurance is a fixed monthly premium per person,
// paid by the company — an employer cost on payroll, not deducted from pay.
// Payroll runs now load everyone with a salary on record; nobody is added by
// hand ("what if there are 1000 of them?").
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $t) {
            $t->bigInteger('health_insurance_amount')->nullable()->after('base_salary');
        });
        Schema::table('payroll_items', function (Blueprint $t) {
            $t->bigInteger('health_insurance_employer')->default(0)->after('nssf_employer_amount');
        });
        Schema::table('payroll_runs', function (Blueprint $t) {
            $t->bigInteger('health_insurance_total')->default(0)->after('net_total');
            $t->foreignId('health_insurance_expense_id')->nullable()->after('expense_id')->constrained('expenses')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payroll_runs', function (Blueprint $t) {
            $t->dropConstrainedForeignId('health_insurance_expense_id');
            $t->dropColumn('health_insurance_total');
        });
        Schema::table('payroll_items', fn (Blueprint $t) => $t->dropColumn('health_insurance_employer'));
        Schema::table('contracts', fn (Blueprint $t) => $t->dropColumn('health_insurance_amount'));
    }
};
