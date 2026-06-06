<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('licenses', function (Blueprint $table) {
            $table->string('install_id')->nullable()->unique()->after('id');
            $table->string('machine_name')->nullable()->after('install_id');
            // pending = waiting for admin approval
            // active  = approved and within expiry
            // revoked = manually disabled
            $table->string('status')->default('pending')->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('licenses', function (Blueprint $table) {
            $table->dropColumn(['install_id', 'machine_name', 'status']);
        });
    }
};
