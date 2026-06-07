<?php

namespace Database\Seeders;

use App\Models\License;
use Illuminate\Database\Seeder;

class LicenseSeeder extends Seeder
{
    public function run(): void
    {
        License::firstOrCreate(
            ['customer_name' => 'Hypermed Demo'],
            [
                'expires_at' => '2026-07-15 00:00:00',
                'is_active'  => true,
                'notes'      => 'Demo licence seeded automatically.',
            ]
        );
    }
}
