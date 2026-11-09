<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Several files per shipment document type (user, 2026-11-09: "multidocument
// attachment"); each can be deleted on its own.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipment_documents', function (Blueprint $t) {
            $t->dropUnique(['shipment_id', 'type']);
            $t->index(['shipment_id', 'type']);
        });
    }

    public function down(): void
    {
    }
};
