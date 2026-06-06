<?php

namespace Database\Seeders;

use App\Models\InventoryItem;
use Illuminate\Database\Seeder;

class InventoryItemSeeder extends Seeder
{
    public function run(): void
    {
        $items = [
            // ── MACHINE PARTS (migrated from spare_parts, seeded fresh here) ──
            ['sku' => 'VEN-FILTER-01',  'name' => 'Ventilator HEPA Filter',          'category' => 'machine_part', 'unit_of_measure' => 'piece', 'unit_cost' => 185000,  'stock_qty' => 12, 'reorder_level' => 5,  'supplier' => 'Mindray East Africa',   'compatible_models' => ['Mindray SV300', 'Hamilton C6']],
            ['sku' => 'DIA-CART-01',    'name' => 'Dialysate Bicarbonate Cartridge', 'category' => 'machine_part', 'unit_of_measure' => 'piece', 'unit_cost' => 320000,  'stock_qty' => 3,  'reorder_level' => 5,  'supplier' => 'Fresenius Medical Care', 'compatible_models' => ['Siemens ADVIA']],
            ['sku' => 'XRY-TUBE-01',    'name' => 'X-Ray Tube Assembly',             'category' => 'machine_part', 'unit_of_measure' => 'piece', 'unit_cost' => 2800000, 'stock_qty' => 1,  'reorder_level' => 1,  'supplier' => 'Siemens Healthineers',   'compatible_models' => ['Siemens MOBILETT', 'Philips Bucky']],
            ['sku' => 'ULT-PROBE-01',   'name' => 'Ultrasound Convex Probe',         'category' => 'machine_part', 'unit_of_measure' => 'piece', 'unit_cost' => 1450000, 'stock_qty' => 2,  'reorder_level' => 2,  'supplier' => 'GE Healthcare Africa',   'compatible_models' => ['Mindray DC-80', 'GE Vivid E90']],
            ['sku' => 'ECG-LEADS-01',   'name' => 'ECG 12-Lead Cable Set',           'category' => 'machine_part', 'unit_of_measure' => 'set',   'unit_cost' => 95000,   'stock_qty' => 8,  'reorder_level' => 3,  'supplier' => 'Philips Healthcare',     'compatible_models' => ['Philips PageWriter', 'GE MAC 5500']],
            ['sku' => 'GEN-BATT-01',    'name' => 'UPS Battery Pack 12V 18Ah',       'category' => 'machine_part', 'unit_of_measure' => 'piece', 'unit_cost' => 145000,  'stock_qty' => 6,  'reorder_level' => 4,  'supplier' => 'Power Solutions TZ',     'compatible_models' => []],
            ['sku' => 'VEN-CIRCUIT-01', 'name' => 'Ventilator Breathing Circuit',    'category' => 'machine_part', 'unit_of_measure' => 'piece', 'unit_cost' => 48000,   'stock_qty' => 20, 'reorder_level' => 10, 'supplier' => 'Mindray East Africa',    'compatible_models' => ['Mindray SV300']],
            ['sku' => 'DFB-PAD-01',     'name' => 'Defibrillator Pads (pair)',       'category' => 'machine_part', 'unit_of_measure' => 'set',   'unit_cost' => 65000,   'stock_qty' => 0,  'reorder_level' => 5,  'supplier' => 'ZOLL Medical',           'compatible_models' => ['ZOLL R Series']],

            // ── CONSUMABLES ──
            ['sku' => 'CON-GLOVES-L',   'name' => 'Surgical Gloves Size L (box 100)','category' => 'consumable',   'unit_of_measure' => 'box',   'unit_cost' => 18000,   'stock_qty' => 50, 'reorder_level' => 20, 'supplier' => 'Meditech Supplies TZ',   'compatible_models' => []],
            ['sku' => 'CON-GLOVES-M',   'name' => 'Surgical Gloves Size M (box 100)','category' => 'consumable',   'unit_of_measure' => 'box',   'unit_cost' => 18000,   'stock_qty' => 40, 'reorder_level' => 20, 'supplier' => 'Meditech Supplies TZ',   'compatible_models' => []],
            ['sku' => 'CON-UGEL-250',   'name' => 'Ultrasound Gel 250ml',            'category' => 'consumable',   'unit_of_measure' => 'piece', 'unit_cost' => 12000,   'stock_qty' => 30, 'reorder_level' => 10, 'supplier' => 'Parker Laboratories',    'compatible_models' => []],
            ['sku' => 'CON-UGEL-5L',    'name' => 'Ultrasound Gel 5 Litre',          'category' => 'consumable',   'unit_of_measure' => 'piece', 'unit_cost' => 85000,   'stock_qty' => 8,  'reorder_level' => 3,  'supplier' => 'Parker Laboratories',    'compatible_models' => []],
            ['sku' => 'CON-ECGPAPER-01','name' => 'ECG Thermal Paper Roll 50mm',     'category' => 'consumable',   'unit_of_measure' => 'roll',  'unit_cost' => 8500,    'stock_qty' => 60, 'reorder_level' => 20, 'supplier' => 'Meditech Supplies TZ',   'compatible_models' => []],
            ['sku' => 'CON-SYRINGE-10', 'name' => 'Disposable Syringe 10ml (box 100)','category' => 'consumable',  'unit_of_measure' => 'box',   'unit_cost' => 22000,   'stock_qty' => 25, 'reorder_level' => 10, 'supplier' => 'Meditech Supplies TZ',   'compatible_models' => []],
            ['sku' => 'CON-MASK-N95',   'name' => 'N95 Respirator Mask (box 20)',    'category' => 'consumable',   'unit_of_measure' => 'box',   'unit_cost' => 35000,   'stock_qty' => 15, 'reorder_level' => 5,  'supplier' => 'Meditech Supplies TZ',   'compatible_models' => []],

            // ── ACCESSORIES ──
            ['sku' => 'ACC-TRAY-SS',    'name' => 'Stainless Steel Instrument Tray', 'category' => 'accessory',    'unit_of_measure' => 'piece', 'unit_cost' => 45000,   'stock_qty' => 20, 'reorder_level' => 5,  'supplier' => 'Surgical Instruments EA','compatible_models' => []],
            ['sku' => 'ACC-TRAY-KID',   'name' => 'Kidney Dish Stainless Steel',     'category' => 'accessory',    'unit_of_measure' => 'piece', 'unit_cost' => 22000,   'stock_qty' => 30, 'reorder_level' => 10, 'supplier' => 'Surgical Instruments EA','compatible_models' => []],
            ['sku' => 'ACC-BPSTAND-01', 'name' => 'IV Drip Stand (adjustable)',      'category' => 'accessory',    'unit_of_measure' => 'piece', 'unit_cost' => 95000,   'stock_qty' => 12, 'reorder_level' => 4,  'supplier' => 'Meditech Supplies TZ',   'compatible_models' => []],
            ['sku' => 'ACC-OXYMTR-01',  'name' => 'Fingertip Pulse Oximeter',        'category' => 'accessory',    'unit_of_measure' => 'piece', 'unit_cost' => 38000,   'stock_qty' => 10, 'reorder_level' => 3,  'supplier' => 'Contec Medical',         'compatible_models' => []],
            ['sku' => 'ACC-BPCUFF-01',  'name' => 'BP Cuff Adult (standard)',        'category' => 'accessory',    'unit_of_measure' => 'piece', 'unit_cost' => 28000,   'stock_qty' => 8,  'reorder_level' => 3,  'supplier' => 'Omron Healthcare',       'compatible_models' => []],

            // ── EQUIPMENT (larger items sold standalone) ──
            ['sku' => 'EQP-BED-HOSP',   'name' => 'Hospital Bed (manual, 2-crank)',  'category' => 'equipment',    'unit_of_measure' => 'piece', 'unit_cost' => 1850000, 'stock_qty' => 5,  'reorder_level' => 2,  'supplier' => 'Linet Group Africa',     'compatible_models' => []],
            ['sku' => 'EQP-BED-ICU',    'name' => 'ICU Electric Bed (4-section)',    'category' => 'equipment',    'unit_of_measure' => 'piece', 'unit_cost' => 5200000, 'stock_qty' => 2,  'reorder_level' => 1,  'supplier' => 'Linet Group Africa',     'compatible_models' => []],
            ['sku' => 'EQP-WHEEL-01',   'name' => 'Wheelchair Standard Foldable',   'category' => 'equipment',    'unit_of_measure' => 'piece', 'unit_cost' => 420000,  'stock_qty' => 8,  'reorder_level' => 3,  'supplier' => 'Drive Medical Africa',   'compatible_models' => []],
            ['sku' => 'EQP-STRETC-01',  'name' => 'Patient Stretcher Trolley',      'category' => 'equipment',    'unit_of_measure' => 'piece', 'unit_cost' => 1200000, 'stock_qty' => 3,  'reorder_level' => 1,  'supplier' => 'Drive Medical Africa',   'compatible_models' => []],
            ['sku' => 'EQP-SCALE-01',   'name' => 'Medical Weight Scale (digital)', 'category' => 'equipment',    'unit_of_measure' => 'piece', 'unit_cost' => 185000,  'stock_qty' => 6,  'reorder_level' => 2,  'supplier' => 'Seca Medical',           'compatible_models' => []],
        ];

        foreach ($items as $item) {
            $models = $item['compatible_models'];
            unset($item['compatible_models']);

            // Skip if already exists (machine_parts were migrated from spare_parts)
            $inv = InventoryItem::firstOrCreate(
                ['sku' => $item['sku']],
                array_merge($item, ['description' => null, 'currency' => 'TZS', 'is_active' => true])
            );

            if ($inv->wasRecentlyCreated) {
                foreach ($models as $m) {
                    $inv->compatibleModels()->create(['machine_model' => $m]);
                }
            }
        }
    }
}
