<?php

namespace App\Services;

use App\Models\Hospital;
use App\Models\Invoice;

/**
 * Hard-blocks finalizing an invoice that would push a hospital's outstanding
 * balance past its credit limit. Skipped entirely when the invoice has no
 * linked hospital, or the hospital has no credit_limit set (null = unlimited).
 */
class CreditCheckService
{
    // Shared with the live-headroom display on the Quotation/SalesOrder
    // build screens (HospitalController@show / index) — one source of
    // truth for "what does this hospital currently owe", same reasoning
    // as consolidating clickhuduma's duplicate due-balance query today.
    public function getOutstandingBalance(Hospital $hospital): int
    {
        return Invoice::where('hospital_id', $hospital->id)
            ->whereNotIn('status', ['paid', 'cancelled', 'waived'])
            ->get()
            ->sum(fn (Invoice $inv) => $inv->total - $inv->amount_paid);
    }

    public function assertWithinLimit(?Hospital $hospital, int $newInvoiceAmount): void
    {
        if (! $hospital || $hospital->credit_limit === null) {
            return;
        }

        $outstandingBalance = $this->getOutstandingBalance($hospital);
        $projectedBalance = $outstandingBalance + $newInvoiceAmount;

        abort_if($projectedBalance > $hospital->credit_limit, 422, sprintf(
            "This would exceed %s's credit limit of TZS %s (current outstanding: TZS %s).",
            $hospital->name,
            number_format($hospital->credit_limit),
            number_format($outstandingBalance),
        ));
    }
}
