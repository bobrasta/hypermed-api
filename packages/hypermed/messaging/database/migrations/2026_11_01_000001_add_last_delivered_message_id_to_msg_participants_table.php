<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('msg_participants', function (Blueprint $table) {
            $table->unsignedBigInteger('last_delivered_message_id')->nullable()->after('last_read_message_id');
        });
    }

    public function down(): void
    {
        Schema::table('msg_participants', function (Blueprint $table) {
            $table->dropColumn('last_delivered_message_id');
        });
    }
};
