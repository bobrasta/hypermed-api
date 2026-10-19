<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Default NeST relevance rules for a medical-device and laboratory-reagent
 * supplier. Inserted only when missing, so edits made from the app survive a
 * re-run. Run by the create_nest_tender_tables migration.
 */
class NestRelevanceRuleSeeder extends Seeder
{
    public const DEVICES = [
        'medical equipment', 'medical device', 'medical devices', 'hospital equipment', 'equipment',
        'spare parts', 'ultrasound', 'x-ray', 'xray', 'digital radiography', 'imaging', 'radiology',
        'ct scan', 'mri', 'mammography', 'patient monitor', 'monitors', 'defibrillator', 'ecg',
        'infusion pump', 'syringe pump', 'suction machine', 'oxygen concentrator', 'oxygen plant',
        'ventilator', 'anaesthesia machine', 'anesthesia machine', 'autoclave', 'sterilizer',
        'steriliser', 'incubator', 'phototherapy', 'delivery bed', 'hospital beds', 'operating table',
        'theatre', 'examination couch', 'dental', 'ophthalmology', 'ophthalmic', 'endoscopy',
        'dialysis', 'surgical instruments', 'laboratory equipment', 'analyzer', 'analyser',
        'microscope', 'centrifuge', 'biosafety cabinet', 'blood bank refrigerator',
        'maintenance of medical equipment',
    ];

    public const REAGENTS = [
        'reagents', 'laboratory reagents', 'lab reagents', 'test kits', 'rapid test',
        'rapid diagnostic', 'rdt', 'controls', 'calibrators', 'culture media', 'stains',
        'haematology reagents', 'hematology reagents', 'chemistry reagents', 'elisa', 'pcr',
        'laboratory supplies', 'laboratory consumables', 'blood bags', 'vacutainer',
        'specimen containers',
    ];

    public const CONSUMABLES = [
        'medical supplies', 'medical consumables', 'medical sundries', 'gloves', 'syringes',
        'cannula', 'catheter', 'sutures', 'dressings',
    ];

    public const SERVICE = ['maintenance', 'servicing', 'calibration', 'repair'];

    public const ENTITY_HINTS = [
        'HOSPITAL', 'MEDICAL STORES', 'LABORATORY', 'HEALTH', 'INSTITUTE', 'DISPENSARY',
        'CLINIC', 'NBTS', 'BLOOD TRANSFUSION',
    ];

    public const EXCLUDES = [
        'medicines', 'drugs', 'pharmaceuticals', 'stationery', 'printing', 'food', 'catering',
        'uniforms', 'fuel', 'lubricants', 'building materials', 'construction', 'road',
        'cleaning services', 'security services', 'fire extinguishers', 'vehicles',
        'office furniture',
    ];

    /** @return list<array{type: string, tag: ?string, term: string, weight: int}> */
    public static function defaults(): array
    {
        $rows = [];
        $add = function (string $type, ?string $tag, array $terms, int $weight) use (&$rows) {
            foreach ($terms as $term) {
                $rows[] = ['type' => $type, 'tag' => $tag, 'term' => $term, 'weight' => $weight];
            }
        };
        $add('keyword', 'DEVICES', self::DEVICES, 10);
        $add('keyword', 'REAGENTS', self::REAGENTS, 10);
        $add('keyword', 'CONSUMABLES', self::CONSUMABLES, 6);
        $add('keyword', 'SERVICE', self::SERVICE, 6);
        $add('health_subcategory', null, ['HEALTH_SECTOR'], 5);
        $add('entity_hint', null, self::ENTITY_HINTS, 2);
        $add('exclude', null, self::EXCLUDES, -20);

        return $rows;
    }

    public function run(): void
    {
        $now = now();
        foreach (self::defaults() as $r) {
            $exists = DB::table('nest_relevance_rules')
                ->where('type', $r['type'])->where('term', $r['term'])
                ->where(fn ($q) => $r['tag'] === null ? $q->whereNull('tag') : $q->where('tag', $r['tag']))
                ->exists();
            if (! $exists) {
                DB::table('nest_relevance_rules')->insert($r + ['active' => true, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
    }
}
