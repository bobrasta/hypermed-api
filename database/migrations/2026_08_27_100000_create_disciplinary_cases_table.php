<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('disciplinary_cases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('stage', ['verbal_warning', 'written_warning', 'final_warning', 'action_taken'])
                ->default('verbal_warning');
            $table->date('incident_date');
            $table->text('description');
            $table->text('action_taken')->nullable();
            $table->foreignId('raised_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('status', ['open', 'closed'])->default('open');
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });

        Schema::create('disciplinary_case_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('disciplinary_case_id')->constrained()->cascadeOnDelete();
            $table->text('note');
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disciplinary_case_notes');
        Schema::dropIfExists('disciplinary_cases');
    }
};
