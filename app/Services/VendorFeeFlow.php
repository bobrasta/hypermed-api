<?php

namespace App\Services;

use App\Models\AppNotification;
use App\Models\Receipt;
use App\Models\User;
use App\Models\VendorFee;

/**
 * Who a clearing/vendor fee waits on, and telling them (user, 2026-10-26):
 * the recorder's own manager (Patrick for Tender & Procurement) → Finance
 * (requests payment) → the Director (pays). A manager who is the Director is
 * skipped here — the Director approves last anyway. Mirrors
 * ExpenseService::route().
 */
class VendorFeeFlow
{
    /** Sets the first stage of a newly recorded fee and notifies it. */
    public function start(VendorFee $fee): void
    {
        $managerId = User::whereKey($fee->created_by)->value('manager_id');
        $manager = $managerId && $managerId !== $fee->created_by ? User::where('is_active', true)->find($managerId) : null;
        if ($manager && ! $manager->isDirector()) {
            $fee->update(['status' => 'pending_manager', 'approver_id' => $manager->id]);
        } else {
            $fee->update(['status' => 'pending_receipt', 'approver_id' => null]);
        }
        $this->notifyStage($fee->fresh(['vendor', 'createdBy', 'approver', 'managerApprovedBy']));
    }

    /** Tells whoever the fee now waits on. */
    public function notifyStage(VendorFee $fee): void
    {
        $vars = [
            'vendor_name' => $fee->vendor?->name ?? 'the vendor',
            'billed_amount' => number_format($fee->billed_amount),
            'description' => $fee->description,
            'name' => $fee->createdBy?->name ?? 'A staff member',
            'manager' => $fee->managerApprovedBy?->name ?? 'their manager',
        ];
        match ($fee->status) {
            'pending_manager' => $this->send(collect([$fee->approver_id])->filter(), 'vendor_fee.manager_review', $vars, $fee),
            'pending_receipt' => $this->send($this->finance(), 'vendor_fee.to_pay', $vars, $fee),
            default => null,
        };
    }

    /** Finance verifies receipts — tell them one came in. */
    public function receiptAttached(VendorFee $fee, Receipt $receipt): void
    {
        $this->send($this->finance(['accountant', 'finance', 'finance_manager']), 'vendor_fee.receipt_to_verify', [
            'vendor_name' => $fee->vendor?->name ?? 'the vendor', 'description' => $fee->description,
            'amount' => number_format($receipt->amount),
        ], $fee, except: $receipt->uploaded_by);
    }

    /** The people who request vendor-fee payments (the accountant). */
    private function finance(array $roles = ['accountant']): \Illuminate\Support\Collection
    {
        $ids = User::whereIn('role', $roles)->where('is_active', true)->pluck('id');

        return $ids->isNotEmpty() ? $ids : User::holdingPermission('procurement.initiate_payment')->pluck('id');
    }

    private function send($ids, string $template, array $vars, VendorFee $fee, ?int $except = null): void
    {
        $msg = app(NotificationTemplateService::class)->render($template, $vars);
        foreach ($ids->unique()->reject(fn ($id) => $id === $except) as $id) {
            AppNotification::create(['user_id' => $id, ...$msg, 'entity_type' => 'vendor_fee', 'entity_id' => $fee->id, 'is_read' => false]);
        }
    }
}
