<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Default NeST relevance rules for a medical-device and laboratory-reagent
 * supplier. Inserted only when missing, so edits made from the app survive a
 * re-run. Run by the create_nest_tender_tables migration.
 */
class NestRelevanceRuleSeeder extends Seeder
{
    // term => context: null always counts; 'text' needs health wording in the
    // tender itself (bare "equipment" at a hospital is often ICT); 'health'
    // also accepts a health buyer. See RelevanceEngine.
    public const DEVICES = [
        'medical equipment' => null, 'medical device' => null, 'medical devices' => null,
        'hospital equipment' => null, 'biomedical equipment' => null, 'diagnostic equipment' => null,
        'equipment' => 'text', 'spare parts' => 'text', 'monitors' => 'text',
        'ultrasound' => null, 'x-ray' => 'health', 'xray' => 'health', 'digital radiography' => null,
        'imaging' => 'health', 'radiology' => 'health', 'ct scan' => null, 'mri' => null, 'mammography' => null,
        'patient monitor' => null, 'defibrillator' => null, 'ecg' => null, 'infusion pump' => null,
        'syringe pump' => null, 'suction machine' => null, 'oxygen concentrator' => null, 'oxygen plant' => 'health',
        'ventilator' => null, 'anaesthesia machine' => null, 'anesthesia machine' => null,
        'autoclave' => 'health', 'sterilizer' => 'health', 'steriliser' => 'health', 'incubator' => 'health',
        'phototherapy' => null, 'delivery bed' => null, 'hospital beds' => null, 'operating table' => null,
        'examination couch' => null, 'endoscopy' => null, 'dialysis' => null, 'surgical instruments' => null,
        'laboratory equipment' => 'health', 'analyzer' => 'health', 'analyser' => 'health',
        'microscope' => 'health', 'centrifuge' => 'health', 'biosafety cabinet' => null,
        'blood bank refrigerator' => null, 'maintenance of medical equipment' => null,
    ];

    public const REAGENTS = [
        'reagents' => 'health', 'laboratory reagents' => 'health', 'lab reagents' => 'health',
        'test kits' => 'health', 'rapid test' => null, 'rapid diagnostic' => null, 'rdt' => null,
        'controls' => 'text', 'calibrators' => 'health', 'culture media' => 'health', 'stains' => 'health',
        'haematology reagents' => null, 'hematology reagents' => null, 'chemistry reagents' => 'health',
        'elisa' => null, 'pcr' => 'health', 'laboratory supplies' => 'health', 'laboratory consumables' => 'health',
        'blood bags' => null, 'vacutainer' => null, 'specimen containers' => null,
        'diagnostics' => null, 'diagnostic items' => null, 'diagnostic supplies' => null, 'diagnostic kits' => null,
    ];

    public const CONSUMABLES = [
        'medical supplies' => null, 'medical consumables' => null, 'medical sundries' => null,
        'hospital supplies' => null, 'surgical supplies' => null, 'dental supplies' => null,
        'gloves' => 'health', 'syringes' => null, 'cannula' => null, 'catheter' => null, 'sutures' => null,
        'dressings' => 'health',
    ];

    public const SERVICE = ['maintenance', 'servicing', 'calibration', 'repair'];

    public const ENTITY_HINTS = [
        'HOSPITAL', 'MEDICAL STORES', 'LABORATORY', 'HEALTH', 'INSTITUTE', 'DISPENSARY',
        'CLINIC', 'NBTS', 'BLOOD TRANSFUSION',
    ];

    public const EXCLUDES = [
        // Singular: a term also matches its plural ("medicine" → "medicines").
        'medicine', 'drug', 'pharmaceutical', 'stationery', 'printing', 'food', 'catering',
        'uniforms', 'fuel', 'lubricants', 'building materials', 'construction', 'road',
        'cleaning services', 'security services', 'fire extinguishers', 'vehicles',
        'office furniture', 'carpentry', 'masonry', 'cleaning materials',
    ];

    // Exclude terms renamed on 2026-10-22 (the plural missed "Supply of Medicine").
    public const RENAMED = ['medicines' => 'medicine', 'drugs' => 'drug', 'pharmaceuticals' => 'pharmaceutical'];

    // Seeded on 2026-10-19 but they name a specialty, not a product, so
    // "ophthalmology medicines" scored as devices. Switched off by the
    // 2026_10_22 migration (left in place, so they can be switched back on).
    public const RETIRED = ['ophthalmology', 'ophthalmic', 'dental', 'theatre'];

    /** @return list<array{type: string, tag: ?string, term: string, weight: int, context: ?string}> */
    public static function defaults(): array
    {
        $rows = [];
        $add = function (string $type, ?string $tag, array $terms, int $weight) use (&$rows) {
            foreach ($terms as $term => $context) {
                if (is_int($term)) {
                    [$term, $context] = [$context, null];
                }
                $rows[] = ['type' => $type, 'tag' => $tag, 'term' => $term, 'weight' => $weight, 'context' => $context];
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
        // The 2026_10_19 migration runs this before the context column exists.
        $hasContext = Schema::hasColumn('nest_relevance_rules', 'context');
        foreach (self::defaults() as $r) {
            $exists = DB::table('nest_relevance_rules')
                ->where('type', $r['type'])->where('term', $r['term'])
                ->where(fn ($q) => $r['tag'] === null ? $q->whereNull('tag') : $q->where('tag', $r['tag']))
                ->exists();
            if (! $hasContext) {
                unset($r['context']);
            }
            if (! $exists) {
                DB::table('nest_relevance_rules')->insert($r + ['active' => true, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
    }
}
