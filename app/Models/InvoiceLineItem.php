<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvoiceLineItem extends Model
{
    protected $fillable = ['invoice_id', 'description', 'quantity', 'unit_price', 'discount', 'total'];

    protected $casts = [
        'quantity' => 'float',
        'unit_price' => 'integer',
        'discount' => 'integer',
        'total' => 'integer',
    ];

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }
}
