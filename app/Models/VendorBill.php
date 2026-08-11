<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VendorBill extends Model
{
    protected $fillable = [
        'bill_number', 'supplier_id', 'purchase_order_id', 'category_id', 'issue_date', 'due_date',
        'subtotal', 'tax_rate', 'tax_amount', 'total', 'amount_paid', 'status', 'currency',
        'notes', 'created_by',
    ];

    protected $casts = [
        'issue_date'  => 'date',
        'due_date'    => 'date',
        'subtotal'    => 'integer',
        'tax_rate'    => 'float',
        'tax_amount'  => 'integer',
        'total'       => 'integer',
        'amount_paid' => 'integer',
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

    public function getBalanceDueAttribute(): int
    {
        return $this->total - $this->amount_paid;
    }
}
