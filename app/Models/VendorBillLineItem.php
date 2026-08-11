<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VendorBillLineItem extends Model
{
    protected $fillable = ['vendor_bill_id', 'description', 'quantity', 'unit_price', 'total'];

    protected $casts = [
        'quantity'   => 'float',
        'unit_price' => 'integer',
        'total'      => 'integer',
    ];

    public function vendorBill()
    {
        return $this->belongsTo(VendorBill::class);
    }
}
