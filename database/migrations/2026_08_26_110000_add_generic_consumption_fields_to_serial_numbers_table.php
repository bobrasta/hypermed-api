<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extends per-unit tracking (previously adopted per-item, mostly for
 * capital equipment that becomes a Machine record) to every product per
 * the user's 2026-08-26 request ("item tracking is for all products").
 * Equipment items keep their richer Machine-based chain (sales_order_id/
 * installed_by/signed_off_by on `machines`, unchanged) — these generic
 * fields are for everything else (consumables, spares) that gets consumed
 * straight out of stock with no installation/sign-off step, mirroring the
 * reference_type/reference_id polymorphic pattern already used on
 * stock_movements rather than inventing a new shape.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('serial_numbers', function (Blueprint $table) {
            $table->string('consumed_reference_type')->nullable()->after('assigned_to_machine_id');
            $table->unsignedBigInteger('consumed_reference_id')->nullable()->after('consumed_reference_type');
            $table->timestamp('consumed_at')->nullable()->after('consumed_reference_id');

            $table->index(['consumed_reference_type', 'consumed_reference_id']);
        });
    }

    public function down(): void
    {
        Schema::table('serial_numbers', function (Blueprint $table) {
            $table->dropIndex(['consumed_reference_type', 'consumed_reference_id']);
            $table->dropColumn(['consumed_reference_type', 'consumed_reference_id', 'consumed_at']);
        });
    }
};
