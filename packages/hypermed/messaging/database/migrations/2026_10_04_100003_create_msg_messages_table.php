<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('msg_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('msg_conversations')->cascadeOnDelete();
            // NULL = system message.
            $table->unsignedBigInteger('sender_id')->nullable();
            $table->enum('type', ['text', 'file', 'system', 'entity_ref']);
            $table->text('body')->nullable();
            $table->json('meta')->nullable();
            $table->char('client_uuid', 36)->unique();
            $table->unsignedBigInteger('reply_to_id')->nullable();
            $table->timestamp('edited_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['conversation_id', 'id']);
            $table->index('sender_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('msg_messages');
    }
};
