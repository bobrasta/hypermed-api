<?php

namespace App\Services;

use App\Models\Machine;

// Section 4 of hypermed_claude_code_prompt.md: "xray", "x-ray", "X Ray" must
// all resolve to one canonical model string server-side — the UI combobox
// nudges users toward the existing spelling, but this is the actual
// enforcement, since a direct API call bypasses the UI entirely.
class MachineModelNormalizer
{
    // Case, whitespace, hyphens and punctuation are ignored; letters/digits
    // are what's compared. Deliberately NOT fuzzy (no edit-distance/typo
    // matching) — the spec asks only for this normalization, and anything
    // fuzzier risks silently merging genuinely different models.
    public static function normalize(string $model): string
    {
        return strtolower(preg_replace('/[^a-z0-9]/i', '', $model) ?? '');
    }

    // Returns the existing canonical spelling for a normalized match, or
    // null if $model is new. Canonical = the spelling already on record
    // (first one found), so an existing "X-Ray" stays "X-Ray" even if the
    // next person types "xray".
    public static function canonicalFor(string $model): ?string
    {
        $key = self::normalize($model);
        if ($key === '') {
            return null;
        }

        return Machine::query()
            ->select('model')
            ->distinct()
            ->get()
            ->first(fn ($m) => self::normalize($m->model) === $key)
            ?->model;
    }
}
