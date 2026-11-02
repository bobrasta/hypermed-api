<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Storage & handling charges (Swissport etc.) that the clearing agent pays
// on our behalf: recorded at "Arrived at HQ" as a second vendor fee owed to
// the agent (user, 2026-11-02).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->foreignId('handling_fee_id')->nullable()->after('vendor_fee_id')->constrained('vendor_fees')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('handling_fee_id');
        });
    }
};
