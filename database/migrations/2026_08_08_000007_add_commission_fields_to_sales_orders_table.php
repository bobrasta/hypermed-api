<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->foreignId('commission_agent_id')->nullable()->after('created_by')
                ->constrained('users')->nullOnDelete();
            // Snapshotted at confirm() time from the agent's commission_percent —
            // never recalculated afterwards, even if the agent's rate changes later.
            $table->decimal('commission_percent', 5, 2)->nullable()->after('commission_agent_id');
            $table->unsignedBigInteger('commission_amount')->nullable()->after('commission_percent');
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('commission_agent_id');
            $table->dropColumn(['commission_percent', 'commission_amount']);
        });
    }
};
