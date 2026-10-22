<?php

namespace App\Services\Nest;

use App\Models\NestRelevanceRule;
use App\Models\Setting;
use Illuminate\Support\Collection;

/**
 * Decides whether a NeST tender is one a medical-device / laboratory-reagent
 * supplier can bid on.
 *
 *  - Terms match whole words, case-insensitively, against the description +
 *    sub-category name ("particular" never matches "icu"); a trailing s/es is
 *    allowed so "monitor" also catches "monitors".
 *  - Score = sum of the weights of the rules that matched, each rule once.
 *  - Generic keywords carry a context ("equipment", "reagents", "x-ray"…) and
 *    only count for a health tender: 'text' needs a health word in the
 *    description or a health sub-category; 'health' also accepts a health
 *    buyer (hospital, MSD, …). Without it they flag ICT, mining, railway or
 *    water-lab tenders.
 *  - SERVICE terms (maintenance, repair, …) only count with a counted
 *    DEVICES term or a health tender.
 *  - Excludes (medicines, fuel, …) subtract — unless the tender also has a
 *    counted DEVICES or REAGENTS match, so mixed tenders are never hidden.
 *  - Only eligible categories (G, NC) score at all; NC only with a counted
 *    SERVICE match.
 *  - Relevant at score >= threshold; "maybe" from maybe_min up to it.
 */
class RelevanceEngine
{
    /** @var Collection<int, NestRelevanceRule>|null */
    private ?Collection $rules = null;

    private array $patterns = [];

    /** @param Collection<int, NestRelevanceRule>|null $rules active rules; loaded from the DB when null */
    public function __construct(?Collection $rules = null, private ?int $threshold = null)
    {
        $this->rules = $rules;
    }

    public static function threshold(): int
    {
        return (int) Setting::get('nest.relevance_threshold', config('nest.relevance_threshold'));
    }

    /**
     * @param  array{description?: ?string, sub_category_name?: ?string, sub_category_acronym?: ?string,
     *               procurement_category?: ?string, procuring_entity_name?: ?string}  $t
     * @return array{relevance_score: int, is_relevant: bool, tags: list<string>, matched_keywords: list<array>}
     */
    public function evaluate(array $t): array
    {
        $rules = $this->rules ??= NestRelevanceRule::where('active', true)->get();
        $threshold = $this->threshold ??= self::threshold();

        $text = trim(($t['description'] ?? '') . ' ' . ($t['sub_category_name'] ?? ''));
        $acronym = strtoupper((string) ($t['sub_category_acronym'] ?? ''));
        $entity = (string) ($t['procuring_entity_name'] ?? '');
        $category = strtoupper((string) ($t['procurement_category'] ?? ''));

        $hits = [];
        foreach ($rules as $r) {
            $matched = match ($r->type) {
                'keyword', 'exclude' => $this->matches($r->term, $text),
                'health_subcategory' => $acronym !== '' && str_contains($acronym, strtoupper($r->term)),
                'entity_hint' => $this->matches($r->term, $entity),
                default => false,
            };
            if ($matched) {
                $hits[] = $r;
            }
        }

        $healthText = collect($hits)->contains(fn ($r) => $r->type === 'health_subcategory')
            || collect(config('nest.health_text_terms'))->contains(fn ($w) => $this->matches($w, $text));
        $healthBuyer = collect(config('nest.health_entity_terms'))->contains(fn ($w) => $this->matches($w, $entity));
        $inContext = fn ($r) => match ($r->context) {
            'text' => $healthText,
            'health' => $healthText || $healthBuyer,
            default => true,
        };

        $tagged = fn (string $tag) => collect($hits)->contains(fn ($r) => $r->type === 'keyword' && $r->tag === $tag && $inContext($r));
        $hasDevices = $tagged('DEVICES');
        $hasReagents = $tagged('REAGENTS');
        $serviceContext = $hasDevices || $healthText || $healthBuyer;

        $score = 0;
        $tags = [];
        $matched = [];
        foreach ($hits as $r) {
            $counts = true;
            $note = null;
            if ($r->type === 'keyword' && ! $inContext($r)) {
                $counts = false;
                $note = $r->context === 'text'
                    ? 'not counted: no medical/health wording in the tender'
                    : 'not counted: not a health tender or health buyer';
            } elseif ($r->type === 'keyword' && $r->tag === 'SERVICE' && ! $serviceContext) {
                $counts = false;
                $note = 'service term without a device or health context';
            }
            if ($r->type === 'exclude' && ($hasDevices || $hasReagents)) {
                $counts = false;
                $note = 'ignored: tender also has devices or reagents';
            }
            if ($counts) {
                $score += $r->weight;
                if ($r->tag) {
                    $tags[$r->tag] = true;
                }
            }
            $matched[] = array_filter([
                'term' => $r->term, 'type' => $r->type, 'tag' => $r->tag, 'weight' => $r->weight, 'context' => $r->context,
                'counted' => $counts, 'note' => $note,
            ], fn ($v) => $v !== null);
        }

        $hasService = isset($tags['SERVICE']);
        $eligible = in_array($category, config('nest.eligible_categories'), true)
            && ($category !== 'NC' || $hasService);
        if (! $eligible) {
            $score = 0;
            $tags = [];
        }

        $order = array_flip(NestRelevanceRule::TAGS);
        $tagList = array_keys($tags);
        usort($tagList, fn ($a, $b) => ($order[$a] ?? 99) <=> ($order[$b] ?? 99));

        return [
            'relevance_score' => $score,
            'is_relevant' => $score >= $threshold,
            'tags' => $tagList,
            'matched_keywords' => $matched,
        ];
    }

    public function matches(string $term, string $text): bool
    {
        if ($text === '' || trim($term) === '') {
            return false;
        }
        $pattern = $this->patterns[$term] ??= $this->pattern($term);

        return (bool) preg_match($pattern, $text);
    }

    private function pattern(string $term): string
    {
        // Words separated by any run of spaces/hyphens, whole-word bounded,
        // optional plural suffix.
        $words = preg_split('/[\s]+/u', trim($term));
        $body = implode('[\s\-]+', array_map(fn ($w) => preg_quote($w, '/'), $words));

        return '/(?<![\p{L}\p{N}])' . $body . '(?:s|es)?(?![\p{L}\p{N}])/iu';
    }
}
