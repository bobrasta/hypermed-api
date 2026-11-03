<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('msg_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('msg_conversations')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id');
            $table->enum('role', ['member', 'admin'])->default('member');
            $table->unsignedBigInteger('last_read_message_id')->nullable();
            $table->timestamp('muted_until')->nullable();
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('left_at')->nullable();

            $table->unique(['conversation_id', 'user_id']);
            $table->index(['user_id', 'left_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('msg_participants');
    }
};
