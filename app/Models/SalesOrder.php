<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesOrder extends Model
{
    protected $fillable = [
        'order_number', 'quotation_id', 'client_name', 'client_contact',
        'status', 'currency',
        'subtotal', 'discount_amount', 'tax_amount', 'total_amount',
        'notes', 'expected_delivery_date',
        'created_by', 'confirmed_by', 'confirmed_at', 'delivered_at',
    ];

    protected $casts = [
        'expected_delivery_date' => 'date',
        'confirmed_at'           => 'datetime',
        'delivered_at'           => 'datetime',
        'subtotal'               => 'integer',
        'discount_amount'        => 'integer',
        'tax_amount'             => 'integer',
        'total_amount'           => 'integer',
    ];

    public function quotation()
    {
        return $this->belongsTo(Quotation::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function confirmedBy()
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function items()
    {
        return $this->hasMany(SalesOrderItem::class);
    }
}
