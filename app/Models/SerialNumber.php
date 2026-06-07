<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SerialNumber extends Model
{
    protected $fillable = [
        'inventory_item_id', 'serial_number', 'status',
        'location_id', 'assigned_to_machine_id',
        'purchase_date', 'warranty_expires_at', 'notes',
    ];

    protected $casts = [
        'purchase_date'      => 'date',
        'warranty_expires_at'=> 'date',
    ];

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function assignedMachine(): BelongsTo
    {
        return $this->belongsTo(Machine::class, 'assigned_to_machine_id');
    }

    public function isWarrantyExpired(): bool
    {
        return $this->warranty_expires_at && $this->warranty_expires_at->isPast();
    }
}
