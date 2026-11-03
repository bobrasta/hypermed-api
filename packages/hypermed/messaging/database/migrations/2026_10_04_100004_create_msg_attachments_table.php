<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('msg_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('msg_messages')->cascadeOnDelete();
            $table->string('disk', 64);
            $table->string('path', 1024);
            $table->string('mime', 191);
            $table->unsignedBigInteger('size');
            $table->string('original_name');
            $table->string('thumb_path', 1024)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('msg_attachments');
    }
};
