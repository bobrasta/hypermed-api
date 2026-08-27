<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table) {
            // Set when the run is marked paid — links to the Expense created
            // (and posted to the ledger) at that point, so "Salaries & Wages"
            // actually reflects payroll instead of payroll living in its own
            // isolated module the rest of Finance never sees.
            $table->foreignId('expense_id')->nullable()->after('paid_at')->constrained('expenses')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('expense_id');
        });
    }
};
