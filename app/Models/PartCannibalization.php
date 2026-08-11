<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PartCannibalization extends Model
{
    protected $fillable = [
        'source_serial_number_id', 'part_used_id', 'removed_by', 'removed_at',
        'status', 'replacement_purchase_order_id', 'replacement_ordered_at',
        'replacement_received_at', 'resolved_at', 'notes',
    ];

    protected $casts = [
        'removed_at'               => 'datetime',
        'replacement_ordered_at'   => 'datetime',
        'replacement_received_at'  => 'datetime',
        'resolved_at'              => 'datetime',
    ];

    public function sourceSerialNumber()
    {
        return $this->belongsTo(SerialNumber::class, 'source_serial_number_id');
    }

    public function partUsed()
    {
        return $this->belongsTo(PartUsed::class);
    }

    public function removedBy()
    {
        return $this->belongsTo(User::class, 'removed_by');
    }

    public function replacementPurchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class, 'replacement_purchase_order_id');
    }
}
