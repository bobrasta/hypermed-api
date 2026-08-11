<?php

namespace Database\Seeders;

use App\Models\Location;
use Illuminate\Database\Seeder;

class LocationSeeder extends Seeder
{
    public function run(): void
    {
        $locations = [
            ['name' => 'Dar es Salaam Main Warehouse', 'code' => 'DSM-MAIN', 'type' => 'warehouse'],
            ['name' => 'Mwanza Regional Store',         'code' => 'MWZ-STORE', 'type' => 'warehouse'],
            ['name' => 'Arusha Branch Store',            'code' => 'ARS-STORE', 'type' => 'warehouse'],
        ];

        foreach ($locations as $location) {
            Location::firstOrCreate(
                ['code' => $location['code']],
                array_merge($location, ['is_active' => true])
            );
        }
    }
}
