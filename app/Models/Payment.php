<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    protected $fillable = [
        'payment_number', 'invoice_id', 'amount',
        'payment_method', 'reference', 'paid_at', 'notes', 'recorded_by',
    ];

    protected $casts = [
        'paid_at' => 'date',
        'amount'  => 'integer',
    ];

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function recordedBy()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
