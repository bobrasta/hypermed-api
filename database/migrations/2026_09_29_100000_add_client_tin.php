<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Client TIN (TRA taxpayer id, 9 digits stored as 123-456-789): kept on the
// client record and snapshotted onto each quotation/invoice like client_name.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hospitals', fn (Blueprint $t) => $t->string('tin', 11)->nullable()->after('contact_email'));
        Schema::table('quotations', fn (Blueprint $t) => $t->string('client_tin', 11)->nullable()->after('client_email'));
        Schema::table('invoices', fn (Blueprint $t) => $t->string('client_tin', 11)->nullable()->after('client_email'));
    }

    public function down(): void
    {
        Schema::table('hospitals', fn (Blueprint $t) => $t->dropColumn('tin'));
        Schema::table('quotations', fn (Blueprint $t) => $t->dropColumn('client_tin'));
        Schema::table('invoices', fn (Blueprint $t) => $t->dropColumn('client_tin'));
    }
};
