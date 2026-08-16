<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->string('status', 20)->default('pending_cto')->after('created_by');
            // pending_cto, pending_director, approved, rejected
            $table->boolean('requires_director_approval')->default(false)->after('status');
            $table->text('escalation_reason')->nullable()->after('requires_director_approval');
            $table->foreignId('escalated_by')->nullable()->after('escalation_reason')->constrained('users')->nullOnDelete();
            $table->timestamp('escalated_at')->nullable()->after('escalated_by');
            $table->foreignId('reviewed_by')->nullable()->after('escalated_at')->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
            $table->text('rejection_reason')->nullable()->after('reviewed_at');
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropColumn([
                'status', 'requires_director_approval', 'escalation_reason',
                'escalated_by', 'escalated_at', 'reviewed_by', 'reviewed_at', 'rejection_reason',
            ]);
        });
    }
};
