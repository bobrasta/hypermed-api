<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 2026-10-24 (user): "Gabriel can only stand as Director if Sharif is not
// around." A delegation can stand by for someone: it only takes effect while
// that person is not around — on approved leave covering the day.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delegations', function (Blueprint $t) {
            $t->foreignId('standby_for_id')->nullable()->after('delegate_id')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('delegations', fn (Blueprint $t) => $t->dropConstrainedForeignId('standby_for_id'));
    }
};
