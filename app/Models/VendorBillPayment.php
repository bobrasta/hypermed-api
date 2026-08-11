<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VendorBillPayment extends Model
{
    protected $fillable = [
        'payment_number', 'vendor_bill_id', 'amount',
        'payment_method', 'reference', 'paid_at', 'notes', 'recorded_by',
    ];

    protected $casts = [
        'paid_at' => 'date',
        'amount'  => 'integer',
    ];

    public function vendorBill()
    {
        return $this->belongsTo(VendorBill::class);
    }

    public function recordedBy()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
