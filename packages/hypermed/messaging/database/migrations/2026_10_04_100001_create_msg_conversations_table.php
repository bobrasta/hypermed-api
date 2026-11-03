<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('msg_conversations', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['direct', 'group', 'department', 'entity']);
            $table->string('title')->nullable();
            // "12:57" (smaller user id first) for direct conversations only.
            $table->string('direct_key', 64)->nullable()->unique();
            $table->string('org_unit_key')->nullable()->index();
            $table->string('entity_type', 100)->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->unsignedBigInteger('last_message_id')->nullable()->index();
            // Cursor sort key for the conversation list (created_at or last message time).
            $table->timestamp('last_activity_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['last_activity_at', 'id']);
            $table->index(['entity_type', 'entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('msg_conversations');
    }
};
