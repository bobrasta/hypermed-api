<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('msg_message_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('msg_messages')->cascadeOnDelete();
            $table->text('body')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['message_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('msg_message_revisions');
    }
};
