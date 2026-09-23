<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VendorFee extends Model
{
    protected $fillable = [
        'vendor_id', 'delivery_job_id', 'description', 'billed_amount', 'currency',
        'status', 'created_by',
        'submitted_by', 'submitted_at',
        'paid_by', 'paid_at', 'payment_reference',
        'rejected_by', 'rejected_at', 'rejection_reason',
    ];

    protected $casts = [
        'billed_amount' => 'integer',
        'submitted_at' => 'datetime',
        'paid_at' => 'datetime',
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
}
