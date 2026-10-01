<?php

namespace App\Support;

// TERMS & CONDITIONS printed on invoices and quotations. The company
// defaults (config/company.php) are the starting point; a document can
// override any of them on its form, and a blank text falls back to the
// default for that heading.
class DocumentTerms
{
    public const RULES = [
        'term_items'         => ['sometimes', 'nullable', 'array', 'max:6'],
        'term_items.*.label' => ['required', 'string', 'max:100'],
        'term_items.*.text'  => ['nullable', 'string', 'max:1000'],
    ];

    public static function defaults(): array
    {
        return config('company.default_terms', []);
    }

    /** What the form sent, kept only where it differs from the default (null = all defaults). */
    public static function normalize(?array $items): ?array
    {
        $defaults = collect(self::defaults())->pluck('text', 'label');
        $kept = collect($items ?? [])
            ->map(fn ($t) => ['label' => trim($t['label']), 'text' => trim((string) ($t['text'] ?? ''))])
            ->filter(fn ($t) => $t['text'] !== '' && $t['text'] !== $defaults->get($t['label']))
            ->values()->all();

        return $kept ?: null;
    }

    /** The terms to print: each default heading, with this document's text where it has one. */
    public static function resolve(?array $items, ?string $legacyText = null): array
    {
        if (! $items && $legacyText) {
            return [['label' => 'Terms', 'text' => $legacyText]];
        }
        $own = collect($items ?? [])->pluck('text', 'label');

        return collect(self::defaults())
            ->map(fn ($t) => ['label' => $t['label'], 'text' => $own->get($t['label']) ?: $t['text']])
            ->all();
    }
}
