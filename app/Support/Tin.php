<?php

namespace App\Support;

use App\Models\Hospital;

// Tanzania Revenue Authority taxpayer identification number: 9 digits,
// written 123-456-789. Accepts it with or without dashes/spaces.
class Tin
{
    public const RULE = ['regex:/^\s*\d{3}[\s-]?\d{3}[\s-]?\d{3}\s*$/'];

    public const MESSAGE = 'The client TIN must be 9 digits (e.g. 123-456-789).';

    public static function normalize(?string $tin): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $tin);

        return strlen($digits) === 9
            ? substr($digits, 0, 3) . '-' . substr($digits, 3, 3) . '-' . substr($digits, 6, 3)
            : null;
    }

    // Remember a client's TIN the first time a document carries it, so the
    // next quotation/invoice for that client pre-fills it.
    public static function rememberOn(?Hospital $hospital, ?string $tin): void
    {
        if ($hospital && $tin && ! $hospital->tin) {
            $hospital->update(['tin' => $tin]);
        }
    }
}
