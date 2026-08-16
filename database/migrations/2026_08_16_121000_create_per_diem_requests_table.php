<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('per_diem_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete(); // technician
            $table->foreignId('service_ticket_id')->nullable()->constrained('service_tickets')->nullOnDelete();
            $table->string('destination');
            $table->date('start_date');
            $table->date('end_date');
            $table->unsignedInteger('days_count');
            $table->unsignedBigInteger('daily_rate')->nullable(); // TZS, informational
            $table->unsignedBigInteger('amount'); // requester-entered total, authoritative
            $table->text('purpose')->nullable();
            $table->string('status', 20)->default('pending_team_lead');
            // pending_team_lead, pending_cto, approved, rejected, cancelled

            $table->foreignId('team_lead_reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('team_lead_reviewed_at')->nullable();
            $table->text('team_lead_rejection_reason')->nullable();

            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete(); // final decision
            $table->timestamp('reviewed_at')->nullable();
            $table->text('rejection_reason')->nullable();

            $table->timestamps();
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('per_diem_requests');
    }
};
