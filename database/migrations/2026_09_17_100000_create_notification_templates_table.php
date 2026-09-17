<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_templates', function (Blueprint $table) {
            $table->id();
            // Distinct per (event, audience) pair — several of the app's real
            // notification 'type' values cover more than one title/body/
            // audience combination (e.g. 'per_diem_approved' fires 3
            // different messages at 3 different audiences), so template_key
            // is the actual lookup key; notification_type is just the value
            // stored on the resulting AppNotification row, unchanged from
            // today's hardcoded behavior.
            $table->string('template_key')->unique();
            $table->string('notification_type');
            $table->string('title_template');
            $table->text('body_template');
            $table->string('description')->nullable(); // admin-facing context: when this fires, who sees it
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_templates');
    }
};
