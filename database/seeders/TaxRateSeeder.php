<?php

namespace Database\Seeders;

use App\Models\TaxRate;
use Illuminate\Database\Seeder;

class TaxRateSeeder extends Seeder
{
    public function run(): void
    {
        TaxRate::firstOrCreate(
            ['name' => 'VAT (18%)'],
            ['rate' => 18, 'is_default' => true, 'is_active' => true],
        );
        TaxRate::firstOrCreate(
            ['name' => 'Zero-rated (0%)'],
            ['rate' => 0, 'is_default' => false, 'is_active' => true],
        );
    }
}
