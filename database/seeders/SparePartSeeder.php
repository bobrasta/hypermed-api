<?php

namespace Database\Seeders;

use App\Models\SparePart;
use Illuminate\Database\Seeder;

class SparePartSeeder extends Seeder
{
    public function run(): void
    {
        $parts = [
            ['part_number' => 'VEN-FILTER-01', 'name' => 'Ventilator HEPA Filter',      'unit_cost' => 185000,  'stock_qty' => 12, 'reorder_level' => 5,  'supplier' => 'Mindray East Africa', 'models' => ['Mindray SV300', 'Hamilton C6']],
            ['part_number' => 'DIA-CART-01',   'name' => 'Dialysate Bicarbonate Cartridge', 'unit_cost' => 320000, 'stock_qty' => 3, 'reorder_level' => 5, 'supplier' => 'Fresenius Medical Care', 'models' => ['Siemens ADVIA']],
            ['part_number' => 'XRY-TUBE-01',   'name' => 'X-Ray Tube Assembly',          'unit_cost' => 2800000, 'stock_qty' => 1, 'reorder_level' => 1,  'supplier' => 'Siemens Healthineers',  'models' => ['Siemens MOBILETT', 'Philips Bucky']],
            ['part_number' => 'ULT-PROBE-01',  'name' => 'Ultrasound Convex Probe',      'unit_cost' => 1450000, 'stock_qty' => 2, 'reorder_level' => 2,  'supplier' => 'GE Healthcare Africa',  'models' => ['Mindray DC-80', 'GE Vivid E90']],
            ['part_number' => 'ECG-LEADS-01',  'name' => 'ECG 12-Lead Cable Set',        'unit_cost' => 95000,   'stock_qty' => 8, 'reorder_level' => 3,  'supplier' => 'Philips Healthcare',    'models' => ['Philips PageWriter', 'GE MAC 5500']],
            ['part_number' => 'GEN-BATT-01',   'name' => 'UPS Battery Pack 12V 18Ah',    'unit_cost' => 145000,  'stock_qty' => 6, 'reorder_level' => 4,  'supplier' => 'Power Solutions TZ',    'models' => []],
            ['part_number' => 'VEN-CIRCUIT-01','name' => 'Ventilator Breathing Circuit',  'unit_cost' => 48000,   'stock_qty' => 20,'reorder_level' => 10, 'supplier' => 'Mindray East Africa',   'models' => ['Mindray SV300']],
            ['part_number' => 'DFB-PAD-01',    'name' => 'Defibrillator Pads (pair)',     'unit_cost' => 65000,   'stock_qty' => 0, 'reorder_level' => 5,  'supplier' => 'ZOLL Medical',          'models' => ['ZOLL R Series']],
        ];

        foreach ($parts as $p) {
            $models = $p['models'];
            unset($p['models']);
            $part = SparePart::create(array_merge($p, ['currency' => 'TZS', 'description' => null]));
            foreach ($models as $m) {
                $part->compatibleModels()->create(['machine_model' => $m]);
            }
        }
    }
}
