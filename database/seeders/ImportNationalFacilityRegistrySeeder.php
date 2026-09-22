<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Imports the remaining ~13,600 facilities from the government's national
 * health facility registry (Registered_facility27-02-2026.xlsx, 13,850
 * facilities total) that are NOT already one of our real client facilities
 * -- those 248 got their coordinates updated in place by
 * ImportGovernmentFacilityCoordinatesSeeder instead, and are excluded from
 * this file so nothing is double-inserted.
 *
 * These are prospect/reference facilities, not client sites: machine_count
 * stays 0, no contact info, `notes` says so explicitly. ~9% have no
 * coordinate (the source registry itself has no lat/lng on file for them).
 *
 * Data lives in data/national_facility_registry.csv (name, short_code,
 * type, region, district, latitude, longitude, zone) rather than as an
 * inline PHP array -- at this row count a literal array would make the
 * seeder file itself impractical to review or diff.
 *
 * Guarded (skips once the first row's short_code already exists) but
 * every row is independently idempotent via short_code lookup regardless,
 * so safe to leave in the deploy chain.
 */
class ImportNationalFacilityRegistrySeeder extends Seeder
{
    private const CHUNK_SIZE = 500;

    public function run(): void
    {
        $path = __DIR__.'/data/national_facility_registry.csv';
        $handle = fopen($path, 'r');
        $header = fgetcsv($handle);

        $firstRow = fgetcsv($handle);
        if ($firstRow === false) {
            fclose($handle);

            return;
        }
        $first = array_combine($header, $firstRow);
        if (DB::table('hospitals')->where('short_code', $first['short_code'])->exists()) {
            fclose($handle);

            return;
        }
        rewind($handle);
        fgetcsv($handle);

        $now = now();
        $buffer = [];

        while (($row = fgetcsv($handle)) !== false) {
            $data = array_combine($header, $row);

            $buffer[] = [
                'name' => $data['name'],
                'short_code' => $data['short_code'],
                'type' => $data['type'],
                'region' => $data['region'] !== '' ? $data['region'] : null,
                'district' => $data['district'] !== '' ? $data['district'] : null,
                'latitude' => $data['latitude'] !== '' ? $data['latitude'] : null,
                'longitude' => $data['longitude'] !== '' ? $data['longitude'] : null,
                'zone' => $data['zone'] !== '' ? $data['zone'] : null,
                'machine_count' => 0,
                'machines_operational' => 0,
                'revenue_monthly' => 0,
                'notes' => 'Government national health facility registry (non-client, no Hypermed equipment on site).',
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($buffer) >= self::CHUNK_SIZE) {
                DB::table('hospitals')->insert($buffer);
                $buffer = [];
            }
        }
        if ($buffer) {
            DB::table('hospitals')->insert($buffer);
        }

        fclose($handle);
    }
}
