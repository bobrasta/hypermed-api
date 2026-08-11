<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['quotations', 'sales_orders'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->string('approval_status', 20)->default('not_required')->after('status');
                // not_required, pending, approved, rejected
                $t->text('approval_reason')->nullable()->after('approval_status');
                $t->foreignId('approved_by')->nullable()->after('approval_reason')
                    ->constrained('users')->nullOnDelete();
                $t->timestamp('approved_at')->nullable()->after('approved_by');
                $t->text('rejection_reason')->nullable()->after('approved_at');
            });
        }
    }

    public function down(): void
    {
        foreach (['quotations', 'sales_orders'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropConstrainedForeignId('approved_by');
                $t->dropColumn(['approval_status', 'approval_reason', 'approved_at', 'rejection_reason']);
            });
        }
    }
};
