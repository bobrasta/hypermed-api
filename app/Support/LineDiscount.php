<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Per-line TSh discounts on sales and quotations (user, 2026-10-02): a
 * line's total is qty × unit price, less any percentage (quotations only,
 * older lines) and less the TSh discount.
 */
class LineDiscount
{
    public static function gross(array $line): int
    {
        return (int) round($line['quantity'] * $line['unit_price']);
    }

    public static function net(array $line, string $amountKey = 'discount'): int
    {
        $afterPercent = (int) round(self::gross($line) * (1 - (($line['discount_percent'] ?? 0) / 100)));

        return $afterPercent - (int) ($line[$amountKey] ?? 0);
    }

    /** Each line's discount must leave something to pay. */
    public static function assertValid(array $lines, string $field, string $amountKey = 'discount'): void
    {
        foreach (array_values($lines) as $i => $line) {
            if (self::net($line, $amountKey) < 0) {
                throw ValidationException::withMessages(["{$field}.{$i}.{$amountKey}" =>
                    'Line ' . ($i + 1) . ': the discount is more than the line amount.']);
            }
        }
    }

    /**
     * A direct sale has no approval step, so a discount over the seller's
     * personal cap (users.max_discount_percent) is refused — it has to go
     * through a quotation, which routes it for manager approval.
     */
    public static function assertWithinCap(User $user, array $lines, string $amountKey = 'discount'): void
    {
        if ($user->max_discount_percent === null) {
            return;
        }
        $gross = array_sum(array_map(fn ($l) => self::gross($l), $lines));
        $net = array_sum(array_map(fn ($l) => self::net($l, $amountKey), $lines));
        $percent = $gross > 0 ? ($gross - $net) / $gross * 100 : 0;
        if ($percent > (float) $user->max_discount_percent + 1e-9) {
            throw ValidationException::withMessages(['line_items' => sprintf(
                'A discount of %.1f%% is over your limit of %.1f%%. Save it as a quotation to get it approved.',
                $percent, (float) $user->max_discount_percent)]);
        }
    }
}
