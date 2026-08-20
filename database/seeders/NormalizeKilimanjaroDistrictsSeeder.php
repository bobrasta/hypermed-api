<?php

namespace Database\Seeders;

use App\Models\Hospital;
use Illuminate\Database\Seeder;

/**
 * One-time cleanup: the district field was imported as-is from 4 different
 * source spreadsheets, which named Kilimanjaro's districts inconsistently
 * ("Rombo" vs "Rombo DC", "Himo" being a ward of Moshi DC rather than its
 * own district, etc). Normalizes to the real 7 Kilimanjaro districts:
 * Moshi DC, Moshi MC, Rombo, Hai, Mwanga, Same, Siha.
 *
 * Guarded -- no-ops once "Himo" no longer appears as a district, safe to
 * leave in the deploy chain. Scoped to Kilimanjaro only; other regions may
 * have similar source-naming inconsistencies not yet audited.
 */
class NormalizeKilimanjaroDistrictsSeeder extends Seeder
{
    private const RENAMES = [
        'Rombo DC'   => 'Rombo',
        'Same DC'    => 'Same',
        'Hai DC'     => 'Hai',
        'Siha DC'    => 'Siha',
        'Mwanga DH'  => 'Mwanga',
        'Himo'       => 'Moshi DC',
    ];

    public function run(): void
    {
        if (! Hospital::where('region', 'Kilimanjaro')->where('district', 'Himo')->exists()) {
            return;
        }

        foreach (self::RENAMES as $from => $to) {
            Hospital::where('region', 'Kilimanjaro')->where('district', $from)->update(['district' => $to]);
        }

        // Mawenzi RRH is Kilimanjaro's regional referral hospital, in Moshi Municipal.
        Hospital::where('region', 'Kilimanjaro')->where('name', 'Mawenzi RRH')
            ->update(['district' => 'Moshi MC']);
    }
}
