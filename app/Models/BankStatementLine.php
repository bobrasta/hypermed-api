<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BankStatementLine extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'reconciliation_id', 'txn_date', 'description', 'debit', 'credit',
        'matched_payment_id', 'matched_expense_id', 'matched_vendor_bill_payment_id',
    ];

    protected $casts = [
        'txn_date'   => 'date',
        'debit'      => 'integer',
        'credit'     => 'integer',
        'created_at' => 'datetime',
    ];

    public function reconciliation()
    {
        return $this->belongsTo(BankReconciliation::class, 'reconciliation_id');
    }

    public function matchedPayment()
    {
        return $this->belongsTo(Payment::class, 'matched_payment_id');
    }

    public function matchedExpense()
    {
        return $this->belongsTo(Expense::class, 'matched_expense_id');
    }

    public function matchedVendorBillPayment()
    {
        return $this->belongsTo(VendorBillPayment::class, 'matched_vendor_bill_payment_id');
    }

    public function getIsMatchedAttribute(): bool
    {
        return $this->matched_payment_id !== null
            || $this->matched_expense_id !== null
            || $this->matched_vendor_bill_payment_id !== null;
    }
}
