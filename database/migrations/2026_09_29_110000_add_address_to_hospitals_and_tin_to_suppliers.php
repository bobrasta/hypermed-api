<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Client postal/physical address (e.g. "P.O. Box 36463") and supplier TIN —
// both carried by the legacy Clickhuduma contacts but had nowhere to go.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hospitals', fn (Blueprint $t) => $t->string('address')->nullable()->after('contact_email'));
        Schema::table('suppliers', fn (Blueprint $t) => $t->string('tin', 11)->nullable()->after('contact_phone'));
    }

    public function down(): void
    {
        Schema::table('hospitals', fn (Blueprint $t) => $t->dropColumn('address'));
        Schema::table('suppliers', fn (Blueprint $t) => $t->dropColumn('tin'));
    }
};
