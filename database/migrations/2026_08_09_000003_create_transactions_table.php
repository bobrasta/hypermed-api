<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per debit-or-credit leg. Two or more rows sharing the same
        // `reference` form one balanced posting — grouping is by that string,
        // not a separate journal-entry header table.
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('chart_of_accounts');
            $table->enum('type', ['debit', 'credit']);
            $table->bigInteger('amount');
            $table->text('description')->nullable();
            $table->string('reference', 100)->nullable()->index();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
