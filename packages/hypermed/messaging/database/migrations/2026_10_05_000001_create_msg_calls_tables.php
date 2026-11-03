<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('msg_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('msg_conversations')->cascadeOnDelete();
            $table->unsignedBigInteger('initiated_by');
            $table->enum('type', ['audio', 'video']);
            $table->enum('status', ['ringing', 'ongoing', 'ended', 'missed', 'declined', 'cancelled', 'failed'])
                ->default('ringing');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->string('end_reason', 32)->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'status']);
            $table->index(['conversation_id', 'id']);
        });

        Schema::create('msg_call_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('call_id')->constrained('msg_calls')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id');
            $table->enum('status', ['invited', 'ringing', 'joined', 'declined', 'missed', 'left'])
                ->default('invited');
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('left_at')->nullable();
            $table->timestamps();

            $table->unique(['call_id', 'user_id']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('msg_call_participants');
        Schema::dropIfExists('msg_calls');
    }
};
