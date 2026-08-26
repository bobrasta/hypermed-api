<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesOrder extends Model
{
    protected $fillable = [
        'order_number', 'quotation_id', 'location_id', 'hospital_id', 'client_name', 'client_contact',
        'status', 'currency',
        'subtotal', 'discount_amount', 'tax_amount', 'total_amount',
        'notes', 'expected_delivery_date',
        'created_by', 'confirmed_by', 'confirmed_at', 'delivered_at', 'delivered_by',
        'approval_status', 'approval_reason', 'approved_by', 'approved_at', 'rejection_reason',
        'commission_agent_id', 'commission_percent', 'commission_amount',
    ];

    protected $casts = [
        'expected_delivery_date' => 'date',
        'confirmed_at'           => 'datetime',
        'delivered_at'           => 'datetime',
        'approved_at'            => 'datetime',
        'subtotal'               => 'integer',
        'discount_amount'        => 'integer',
        'tax_amount'             => 'integer',
        'total_amount'           => 'integer',
        'commission_percent'     => 'float',
        'commission_amount'      => 'integer',
    ];

    public function quotation()
    {
        return $this->belongsTo(Quotation::class);
    }

    public function location()
    {
        return $this->belongsTo(Location::class);
    }

    public function hospital()
    {
        return $this->belongsTo(Hospital::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function confirmedBy()
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function deliveredBy()
    {
        return $this->belongsTo(User::class, 'delivered_by');
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function commissionAgent()
    {
        return $this->belongsTo(User::class, 'commission_agent_id');
    }

    public function items()
    {
        return $this->hasMany(SalesOrderItem::class);
    }
}
