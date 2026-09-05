<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Employer-side NSSF match, distinct from the existing nssf_amount column
// (the employee's own deduction). Left null until finance actually enters a
// real figure — no computed/backfilled value, no default.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_items', function (Blueprint $table) {
            $table->bigInteger('nssf_employer_amount')->nullable()->after('nssf_amount');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_items', function (Blueprint $table) {
            $table->dropColumn('nssf_employer_amount');
        });
    }
};
