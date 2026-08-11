<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            'Radiology & Imaging',
            'Dental Healthcare',
            'Laboratory Equipments',
            'Refrigerators and Freezers',
            'General Hospital Furniture',
            'Pediatric and Neonatal',
            'Critical Care and Patient Monitors',
            'Central Sterile Supply Department',
            'Audiology and Balance',
            'Oxygen Generation and Delivery System',
            'Neurology',
            'Ear/Nose/Throat',
            'Tools',
            'Spare Parts',
        ];

        foreach ($categories as $name) {
            Category::firstOrCreate(
                ['slug' => Str::slug($name)],
                ['name' => $name, 'is_active' => true]
            );
        }
    }
}
