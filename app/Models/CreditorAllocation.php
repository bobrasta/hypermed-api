<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Matches a debit (a payment, or an opening advance) to a credit (an
 * invoice, or an opening balance owed), in the supplier's currency.
 * kind 'payment': the payment settled something already owed (its FX
 * difference posted with the payment); kind 'advance': an invoice used up
 * money paid before it; kind 'return': goods sent back settled an invoice.
 */
class CreditorAllocation extends Model
{
    protected $fillable = ['supplier_payment_id', 'opening_balance_id', 'vendor_bill_id', 'opening_owed_id', 'purchase_return_id', 'amount', 'kind'];

    protected $casts = ['amount' => 'decimal:2'];

    public function payment() { return $this->belongsTo(SupplierPayment::class, 'supplier_payment_id'); }
    public function openingAdvance() { return $this->belongsTo(CreditorOpeningBalance::class, 'opening_balance_id'); }
    public function bill() { return $this->belongsTo(VendorBill::class, 'vendor_bill_id'); }
    public function openingOwed() { return $this->belongsTo(CreditorOpeningBalance::class, 'opening_owed_id'); }
}
