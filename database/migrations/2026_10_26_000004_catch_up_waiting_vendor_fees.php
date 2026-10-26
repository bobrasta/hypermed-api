<?php

use App\Models\VendorFee;
use App\Services\VendorFeeFlow;
use Illuminate\Database\Migrations\Migration;

/**
 * Catch-up for work submitted while the flow was broken (user, 2026-10-26):
 * fees left "awaiting Finance" with nobody told are routed into the new
 * chain — to the recorder's manager (Patrick) first, or Finance — with the
 * notifications they never got; receipts attached but never verified tell
 * Finance to verify them. Fees already with the Director were notified then.
 * Runs once (migration) — no repeat notifications.
 */
return new class extends Migration
{
    public function up(): void
    {
        $flow = app(VendorFeeFlow::class);
        foreach (VendorFee::where('status', 'pending_receipt')->orderBy('id')->get() as $fee) {
            $flow->start($fee);
        }
        $waiting = VendorFee::with(['vendor', 'receipts'])->where('status', '!=', 'rejected')
            ->whereHas('receipts', fn ($q) => $q->whereNull('verified_at'))->get();
        foreach ($waiting as $fee) {
            $flow->receiptAttached($fee, $fee->receipts->whereNull('verified_at')->sortByDesc('id')->first());
        }
    }

    public function down(): void
    {
        // Notifications already sent stay sent.
    }
};
