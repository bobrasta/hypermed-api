<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_categories', function (Blueprint $table) {
            // Nullable self-reference — a subcategory shares its parent's
            // GL account_id (set by the controller, not the DB), so the
            // chart of accounts doesn't explode with one line per
            // subcategory; parent_id is purely for the reporting tree.
            $table->foreignId('parent_id')->nullable()->after('account_id')
                ->constrained('expense_categories')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('expense_categories', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_id');
        });
    }
};
