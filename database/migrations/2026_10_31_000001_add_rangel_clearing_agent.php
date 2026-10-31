<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Rangel is the company's clearing agent. Added as a registered clearing
// vendor so it can be picked on shipments and vendor fees. Reuses an
// existing vendor with the same name (any case) rather than duplicating it.
return new class extends Migration
{
    private const NAME = 'RANGEL MARKET ACCESS LOGISTICS LIMITED';

    public function up(): void
    {
        $existing = DB::table('vendors')->whereRaw('lower(name) = ?', [mb_strtolower(self::NAME)])->first();
        if ($existing) {
            DB::table('vendors')->where('id', $existing->id)
                ->update(['type' => 'clearing', 'is_active' => true, 'updated_at' => now()]);

            return;
        }

        DB::table('vendors')->insert([
            'name' => self::NAME,
            'type' => 'clearing',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Left in place: fees and staff may already point at it.
    }
};
