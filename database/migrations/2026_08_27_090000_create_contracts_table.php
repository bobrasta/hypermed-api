<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('contract_type', ['permanent', 'fixed_term']);
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->unsignedSmallInteger('probation_period_days')->default(90);
            $table->date('probation_end_date')->nullable();
            $table->bigInteger('base_salary')->nullable();
            $table->enum('status', ['active', 'ended', 'resigned'])->default('active');
            $table->date('resignation_date')->nullable();
            $table->text('resignation_reason')->nullable();
            $table->foreignId('renewed_from_contract_id')->nullable()
                ->constrained('contracts')->nullOnDelete();
            $table->timestamp('expiry_notified_at')->nullable();
            $table->timestamp('probation_notified_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contracts');
    }
};
