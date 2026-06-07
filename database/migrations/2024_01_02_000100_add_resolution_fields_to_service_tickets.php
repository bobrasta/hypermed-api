<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_tickets', function (Blueprint $table) {
            $table->text('resolution_notes')->nullable()->after('description');
            $table->timestamp('resolved_at')->nullable()->after('resolution_notes');
        });
    }

    public function down(): void
    {
        Schema::table('service_tickets', function (Blueprint $table) {
            $table->dropColumn(['resolution_notes', 'resolved_at']);
        });
    }
};
