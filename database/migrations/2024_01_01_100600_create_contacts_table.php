<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('job_title')->nullable();
            $table->string('department')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('whatsapp')->nullable();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->timestamp('last_contacted_at')->nullable();
            $table->timestamp('next_followup_at')->nullable();
            $table->timestamps();
        });

        Schema::create('contact_tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->string('tag');
            $table->timestamps();
        });

        Schema::create('contact_interactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['call', 'email', 'meeting', 'whatsapp', 'visit']);
            $table->text('summary')->nullable();
            $table->text('outcome')->nullable();
            $table->text('next_action')->nullable();
            $table->date('next_action_date')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_interactions');
        Schema::dropIfExists('contact_tags');
        Schema::dropIfExists('contacts');
    }
};
