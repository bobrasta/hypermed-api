<?php

namespace Tests\Feature;

use App\Models\NestRelevanceRule;
use App\Services\Nest\RelevanceEngine;
use Database\Seeders\NestRelevanceRuleSeeder;
use Tests\TestCase;

// The NeST relevance rules, run against the seeded defaults without a
// database: whole-word matching, the exclude veto, the NC service rule and
// the relevant / maybe threshold.
class NestRelevanceEngineTest extends TestCase
{
    private function engine(int $threshold = 10): RelevanceEngine
    {
        $rules = collect(NestRelevanceRuleSeeder::defaults())->map(fn ($r) => new NestRelevanceRule($r + ['active' => true]));

        return new RelevanceEngine($rules, $threshold);
    }

    private function score(string $description, array $extra = []): array
    {
        return $this->engine()->evaluate($extra + [
            'description' => $description,
            'sub_category_name' => 'Goods',
            'sub_category_acronym' => 'GOODS',
            'procurement_category' => 'G',
            'procuring_entity_name' => 'SOME DISTRICT COUNCIL',
        ]);
    }

    public function test_whole_word_matching_and_simple_plurals(): void
    {
        $e = $this->engine();
        $this->assertFalse($e->matches('icu', 'Supply of particular items'));
        $this->assertTrue($e->matches('icu', 'ICU beds'));
        $this->assertTrue($e->matches('monitor', 'Supply of patient Monitors'));
        $this->assertTrue($e->matches('patient monitor', 'patient   monitors'));
        $this->assertFalse($e->matches('road', 'Supply of broadband'));
        $this->assertTrue($e->matches('x-ray', 'Digital X-Ray machine'));
    }

    public function test_device_tender_is_relevant_and_tagged(): void
    {
        $r = $this->score('Procurement of Ophthalmology Medical Equipment_1');
        $this->assertTrue($r['is_relevant']);
        $this->assertSame(['DEVICES'], $r['tags']);
        $this->assertContains('ophthalmology', array_column($r['matched_keywords'], 'term'));
    }

    public function test_exclude_does_not_veto_a_mixed_tender(): void
    {
        $r = $this->score('Supply of medicines and laboratory reagents');
        $this->assertTrue($r['is_relevant']);
        $this->assertSame(['REAGENTS'], $r['tags']);
        $ignored = collect($r['matched_keywords'])->firstWhere('term', 'medicines');
        $this->assertFalse($ignored['counted']);
    }

    public function test_exclude_pushes_down_a_pure_noise_tender(): void
    {
        $r = $this->score('Supply of fuel and lubricants');
        $this->assertFalse($r['is_relevant']);
        $this->assertLessThan(0, $r['relevance_score']);
    }

    public function test_service_needs_device_or_medical_context(): void
    {
        $plain = $this->score('Maintenance of office buildings');
        $this->assertNotContains('SERVICE', $plain['tags']);

        $medical = $this->score('Maintenance and calibration of medical equipment');
        $this->assertContains('SERVICE', $medical['tags']);
        $this->assertTrue($medical['is_relevant']);
    }

    public function test_non_consultancy_only_with_a_service_match(): void
    {
        $nc = ['procurement_category' => 'NC'];
        $this->assertTrue($this->score('Servicing of laboratory analysers', $nc)['is_relevant']);

        $noService = $this->score('Supply of laboratory reagents', $nc);
        $this->assertSame(0, $noService['relevance_score']);
        $this->assertFalse($noService['is_relevant']);

        $works = $this->score('Construction of hospital theatre', ['procurement_category' => 'W']);
        $this->assertSame(0, $works['relevance_score']);
    }

    public function test_health_subcategory_alone_is_only_maybe(): void
    {
        $r = $this->score('Supply of assorted items', [
            'sub_category_acronym' => 'HEALTH_SECTOR_GOODS', 'sub_category_name' => 'Health Sector Goods',
        ]);
        $this->assertSame(5, $r['relevance_score']);
        $this->assertFalse($r['is_relevant']);
    }

    public function test_each_rule_counts_once(): void
    {
        $r = $this->score('Reagents, reagents and more reagents');
        $this->assertSame(10, $r['relevance_score']);
    }
}
