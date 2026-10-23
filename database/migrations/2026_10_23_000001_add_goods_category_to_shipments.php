<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Shipments, 2026-10-23: what kind of goods a shipment carries. Medical
// devices and reagents keep the document checklist and TMDA permit steps;
// non-medical goods have no required documents and no TMDA gates, so they
// can be completed without uploads.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $t) {
            $t->string('goods_category')->default('medical')->after('freight_mode');
        });
        DB::statement("ALTER TABLE shipments ADD CONSTRAINT shipments_goods_category_check CHECK (goods_category IN ('medical','non_medical'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE shipments DROP CONSTRAINT shipments_goods_category_check');
        Schema::table('shipments', function (Blueprint $t) {
            $t->dropColumn('goods_category');
        });
    }
};
