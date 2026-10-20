<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A payment to a supplier: initiated by Finance, approved by the Director
 * (never the same person), and only then posted — from the bank/cash account
 * it left, at its own exchange rate. Matched to invoices/opening balances
 * through CreditorAllocation; what isn't matched is an advance.
 */
class SupplierPayment extends Model
{
    use LogsActivity;

    protected $fillable = [
        'payment_number', 'supplier_id', 'vendor_id', 'purchase_order_id', 'vendor_bill_id', 'currency', 'amount',
        'exchange_rate', 'amount_tzs', 'paid_from_account_id', 'payment_date', 'reference', 'notes', 'status',
        'fx_difference_tzs', 'initiated_by', 'initiated_at', 'approved_by', 'approved_at',
        'rejected_by', 'rejected_at', 'rejection_reason',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'exchange_rate' => 'decimal:4',
        'amount_tzs' => 'integer',
        'fx_difference_tzs' => 'integer',
        'payment_date' => 'date',
        'initiated_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly($this->fillable)->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public function supplier() { return $this->belongsTo(Supplier::class); }
    public function vendor() { return $this->belongsTo(Vendor::class); }

    public function creditorType(): string { return $this->supplier_id ? 'supplier' : 'vendor'; }
    public function creditorId(): int { return (int) ($this->supplier_id ?: $this->vendor_id); }
    public function creditorName(): ?string { return $this->supplier_id ? $this->supplier?->name : $this->vendor?->name; }
    public function purchaseOrder() { return $this->belongsTo(PurchaseOrder::class); }
    public function vendorBill() { return $this->belongsTo(VendorBill::class); }
    public function paidFrom() { return $this->belongsTo(ChartOfAccount::class, 'paid_from_account_id'); }
    public function initiatedBy() { return $this->belongsTo(User::class, 'initiated_by'); }
    public function approvedBy() { return $this->belongsTo(User::class, 'approved_by'); }
    public function rejectedBy() { return $this->belongsTo(User::class, 'rejected_by'); }
    public function allocations() { return $this->hasMany(CreditorAllocation::class); }
}
