<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Per-document TERMS & CONDITIONS: [{label, text}], edited on the sale /
// quotation form. Null (or a blank text) prints the company default.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', fn (Blueprint $t) => $t->json('term_items')->nullable());
        Schema::table('quotations', fn (Blueprint $t) => $t->json('term_items')->nullable());
    }

    public function down(): void
    {
        Schema::table('invoices', fn (Blueprint $t) => $t->dropColumn('term_items'));
        Schema::table('quotations', fn (Blueprint $t) => $t->dropColumn('term_items'));
    }
};
