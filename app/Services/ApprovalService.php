<?php

namespace App\Services;

use App\Models\User;

/**
 * Decides whether a quotation or sales order needs manager sign-off before it
 * can be sent to a client / confirmed internally. Two independent triggers:
 * the creator discounting beyond their personal cap, or the order's total
 * value exceeding the company-wide approval threshold. Server-side only —
 * unlike the legacy reference system, there is no client-side-only version
 * of this check.
 */
class ApprovalService
{
    public function evaluate(User $creator, int $subtotal, int $discountAmount, int $totalAmount): array
    {
        $discountPercent = $subtotal > 0 ? ($discountAmount / $subtotal) * 100 : 0;
        $threshold = (int) config('services.sales_approval_threshold', 5_000_000);

        $reasons = [];

        if ($creator->max_discount_percent !== null && $discountPercent > (float) $creator->max_discount_percent) {
            $reasons[] = sprintf(
                'Discount of %.1f%% exceeds %s\'s limit of %.1f%%',
                $discountPercent, $creator->name, (float) $creator->max_discount_percent,
            );
        }

        if ($totalAmount > $threshold) {
            $reasons[] = sprintf(
                'Total of TZS %s exceeds the TZS %s approval threshold',
                number_format($totalAmount), number_format($threshold),
            );
        }

        return [
            'approval_status' => $reasons ? 'pending' : 'not_required',
            'approval_reason' => $reasons ? implode('; ', $reasons) : null,
        ];
    }
}
