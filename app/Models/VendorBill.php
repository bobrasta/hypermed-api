<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class VendorBill extends Model
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly($this->fillable)
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected $fillable = [
        'bill_number', 'supplier_reference', 'exchange_rate', 'total_tzs', 'supplier_id', 'purchase_order_id', 'category_id', 'issue_date', 'due_date',
        'subtotal', 'tax_rate', 'tax_amount', 'total', 'amount_paid', 'status', 'currency',
        'notes', 'created_by', 'approved_by', 'approved_at',
    ];

    protected $casts = [
        'issue_date'  => 'date',
        'due_date'    => 'date',
        'subtotal'    => 'decimal:2',
        'tax_rate'    => 'float',
        'tax_amount'  => 'decimal:2',
        'total'       => 'decimal:2',
        'amount_paid' => 'decimal:2',
        'exchange_rate' => 'decimal:4',
        'total_tzs'   => 'integer',
        'approved_at' => 'datetime',
    ];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function category()
    {
        return $this->belongsTo(ExpenseCategory::class, 'category_id');
    }

    public function lineItems()
    {
        return $this->hasMany(VendorBillLineItem::class);
    }

    public function payments()
    {
        return $this->hasMany(VendorBillPayment::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function allocations()
    {
        return $this->hasMany(CreditorAllocation::class);
    }

    public function supplierPayments()
    {
        return $this->hasMany(SupplierPayment::class);
    }

    /** In the invoice currency. */
    public function getBalanceDueAttribute(): float
    {
        return round((float) $this->total - (float) $this->amount_paid, 2);
    }
}
