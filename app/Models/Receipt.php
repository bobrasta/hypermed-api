<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Receipt extends Model
{
    protected $fillable = [
        'vendor_fee_id', 'receipt_type', 'receipt_number', 'issuer_name', 'issuer_tin',
        'receipt_date', 'amount',
        'file_original_name', 'file_stored_name', 'file_mime', 'file_size',
        'uploaded_by', 'verified_by', 'verified_at',
    ];

    protected $casts = [
        'receipt_date' => 'date',
        'amount' => 'integer',
        'verified_at' => 'datetime',
    ];

    public function vendorFee()
    {
        return $this->belongsTo(VendorFee::class);
    }

    public function uploadedBy()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function verifiedBy()
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function isVerified(): bool
    {
        return ! is_null($this->verified_at);
    }
}
