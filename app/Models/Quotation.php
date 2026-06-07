<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Quotation extends Model
{
    protected $fillable = [
        'quotation_number', 'lead_id', 'client_name', 'client_contact', 'client_email',
        'status', 'valid_until', 'currency',
        'subtotal', 'discount_amount', 'tax_amount', 'total_amount',
        'notes', 'terms', 'created_by', 'sent_at', 'accepted_at',
    ];

    protected $casts = [
        'valid_until'  => 'date',
        'sent_at'      => 'datetime',
        'accepted_at'  => 'datetime',
        'subtotal'     => 'integer',
        'discount_amount' => 'integer',
        'tax_amount'   => 'integer',
        'total_amount' => 'integer',
    ];

    public function lead()
    {
        return $this->belongsTo(SalesLead::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items()
    {
        return $this->hasMany(QuotationItem::class);
    }

    public function salesOrder()
    {
        return $this->hasOne(SalesOrder::class);
    }
}
