<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('msg_device_tokens', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->text('token');
            // sha256(token): FCM tokens can exceed safe unique-index lengths.
            $table->char('token_hash', 64)->unique();
            $table->enum('platform', ['android', 'ios', 'windows', 'linux', 'macos']);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('msg_device_tokens');
    }
};
