<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vacancies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('position_id')->constrained()->cascadeOnDelete();
            $table->text('requirements')->nullable();
            $table->enum('status', ['open', 'on_hold', 'closed'])->default('open');
            $table->date('opened_at');
            $table->date('closed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('status');
        });

        // Independent entity — never hard-deleted, so HR can search past
        // applicants (talent_pool) before posting externally, per the spec.
        Schema::create('applicants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->text('cover_letter')->nullable();
            $table->string('source_channel')->nullable();
            $table->boolean('talent_pool')->default(false);
            // Comma-separated tags — simple contains-filter for the talent
            // pool search, not worth a full tag-relation table for this.
            $table->string('skills_tags')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('talent_pool');
        });

        // A fresh row each time the applicant (re)submits a CV — "versioned
        // on reapply" per the spec, so HR can see what changed between
        // applications rather than only the latest file.
        Schema::create('applicant_cv_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('applicant_id')->constrained()->cascadeOnDelete();
            $table->string('file_path');
            $table->string('original_name');
            $table->unsignedInteger('version');
            $table->timestamp('uploaded_at');
        });

        // Join table: applicant <-> vacancy, one row per attempt — an
        // applicant can apply to the same or different vacancies more than
        // once over time, each a separate pipeline to track.
        Schema::create('applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('applicant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vacancy_id')->constrained()->cascadeOnDelete();
            $table->enum('status', ['applied', 'shortlisted', 'interviewed', 'offered', 'hired', 'rejected'])
                ->default('applied');
            $table->date('applied_at');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['vacancy_id', 'status']);
        });

        Schema::create('interviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->timestamp('scheduled_at');
            $table->string('stage')->nullable();
            $table->string('panel')->nullable();
            $table->foreignId('interviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->unsignedTinyInteger('rating')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interviews');
        Schema::dropIfExists('applications');
        Schema::dropIfExists('applicant_cv_versions');
        Schema::dropIfExists('applicants');
        Schema::dropIfExists('vacancies');
    }
};
