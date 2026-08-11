<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The free-text `location` column predates location_id/location_from_id/location_to_id
 * and is unused. Dropping it also removes a name collision with the location()
 * Eloquent relationship — with both present, $movement->location resolved to this
 * (always-null) column instead of the relationship.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropColumn('location');
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->string('location')->nullable();
        });
    }
};
