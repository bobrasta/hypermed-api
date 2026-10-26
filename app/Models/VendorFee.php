<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VendorFee extends Model
{
    protected $fillable = [
        'vendor_id', 'delivery_job_id', 'description', 'billed_amount', 'currency',
        'status', 'receipt_after_payment', 'created_by',
        'approver_id', 'manager_approved_by', 'manager_approved_at',
        'submitted_by', 'submitted_at',
        'paid_by', 'paid_at', 'payment_reference', 'paid_from_account_id',
        'rejected_by', 'rejected_at', 'rejection_reason',
    ];

    protected $casts = [
        'billed_amount' => 'integer',
        'receipt_after_payment' => 'boolean',
        'submitted_at' => 'datetime',
        'paid_at' => 'datetime',
        'manager_approved_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

    public function deliveryJob()
    {
        return $this->belongsTo(DeliveryJob::class);
    }

    public function receipts()
    {
        return $this->hasMany(Receipt::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** The recorder's manager, who approves first (pending_manager). */
    public function approver()
    {
        return $this->belongsTo(User::class, 'approver_id');
    }

    public function managerApprovedBy()
    {
        return $this->belongsTo(User::class, 'manager_approved_by');
    }

    public function submittedBy()
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function paidBy()
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    public function rejectedBy()
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    /**
     * Section 16.2's single payment rule, in one place so both
     * submitForPayment() and the Director's final approve() re-check it
     * independently rather than trusting the "ready_for_payment" status
     * flag alone (a receipt could theoretically be altered in between).
     * Returns a plain-text reason when not payable, or null when it is —
     * matches 16.4's "reason shown in plain text" UI requirement directly.
     */
    public function paymentBlockReason(): ?string
    {
        // A shipment's clearing fee is paid first; its receipt follows (2026-10-26).
        return $this->receipt_after_payment ? null : $this->receiptGap();
    }

    /** Paid clearing fee whose receipt hasn't fully come in yet: what's missing, or null. */
    public function receiptFollowUp(): ?string
    {
        if (! $this->receipt_after_payment || $this->status !== 'paid') {
            return null;
        }
        $gap = $this->receiptGap();

        return $gap === 'No receipt attached.' ? 'Receipt to follow — attach the clearing agent\'s receipt.' : $gap;
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'paid' => $this->receiptFollowUp() ? 'Paid · receipt to follow' : 'Paid',
            'rejected' => 'Rejected',
            'pending_manager' => 'Awaiting ' . ($this->approver?->name ?? 'manager') . '\'s approval',
            'ready_for_payment' => 'Awaiting Director approval',
            default => $this->receipt_after_payment ? 'Awaiting Finance (payment request)' : 'Awaiting receipt',
        };
    }

    /** Receipts against the billed amount: what's missing, or null when they cover it. */
    private function receiptGap(): ?string
    {
        $receipts = $this->relationLoaded('receipts') ? $this->receipts : $this->receipts()->get();

        if ($receipts->isEmpty()) {
            return 'No receipt attached.';
        }

        $unverified = $receipts->whereNull('verified_at')->count();
        if ($unverified > 0) {
            return $unverified === 1
                ? '1 receipt awaiting verification.'
                : "{$unverified} receipts awaiting verification.";
        }

        $sum = (int) $receipts->sum('amount');
        $gap = $this->billed_amount - $sum;

        if ($gap > 0) {
            return 'Missing receipt for TZS ' . number_format($gap) . '.';
        }
        if ($gap < 0) {
            return 'Receipts exceed the billed amount by TZS ' . number_format(abs($gap)) . ' — correct before submitting.';
        }

        if ($this->delivery_job_id && ! $this->deliveryJob?->hasDeliveryNote()) {
            return 'Signed delivery note not yet attached.';
        }

        return null;
    }

    public function isPayable(): bool
    {
        return $this->paymentBlockReason() === null;
    }

    public function paidFrom()
    {
        return $this->belongsTo(ChartOfAccount::class, 'paid_from_account_id');
    }
}
