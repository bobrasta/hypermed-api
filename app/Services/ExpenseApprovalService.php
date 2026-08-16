<?php

namespace App\Services;

use App\Models\ExpenseCategory;
use App\Models\Setting;

/**
 * Decides whether an expense needs Director sign-off (beyond the CTO's own
 * approval) before it can post to the ledger. Two independent triggers: the
 * category is explicitly flagged as always-escalate (new order payments,
 * machine imports), or the amount exceeds a tunable safety-net threshold.
 */
class ExpenseApprovalService
{
    public const DEFAULT_THRESHOLD = 3_000_000;

    public function evaluate(ExpenseCategory $category, int $grossAmount): array
    {
        $threshold = (int) Setting::get('expense_director_threshold', self::DEFAULT_THRESHOLD);

        $reasons = [];

        if ($category->requires_director_approval) {
            $reasons[] = sprintf("Category '%s' always requires Director approval.", $category->name);
        }

        if ($grossAmount > $threshold) {
            $reasons[] = sprintf(
                'Amount TZS %s exceeds the TZS %s safety-net threshold.',
                number_format($grossAmount), number_format($threshold),
            );
        }

        return [
            'requires_director_approval' => (bool) $reasons,
            'escalation_reason'          => $reasons ? implode('; ', $reasons) : null,
        ];
    }
}
