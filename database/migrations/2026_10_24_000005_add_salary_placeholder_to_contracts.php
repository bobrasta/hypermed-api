<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 2026-10-24 (user): "put a flat 1,000,000 TSh for all; it will be edited
// later by HR or the Accountant." A placeholder salary isn't agreed pay, so
// its first real figure is set directly (import or salary adjustment) without
// the Director's approval; after that the normal Director rule applies.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $t) {
            $t->boolean('salary_placeholder')->default(false)->after('base_salary');
        });
    }

    public function down(): void
    {
        Schema::table('contracts', fn (Blueprint $t) => $t->dropColumn('salary_placeholder'));
    }
};
