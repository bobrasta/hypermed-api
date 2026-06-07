<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BatchLot extends Model
{
    protected $fillable = [
        'inventory_item_id', 'batch_number', 'lot_number',
        'expiry_date', 'manufactured_date',
        'qty_received', 'qty_remaining',
        'supplier_id', 'unit_cost', 'currency',
        'received_at', 'notes',
    ];

    protected $casts = [
        'expiry_date'      => 'date',
        'manufactured_date'=> 'date',
        'received_at'      => 'date',
        'qty_received'     => 'integer',
        'qty_remaining'    => 'integer',
        'unit_cost'        => 'integer',
    ];

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function isExpired(): bool
    {
        return $this->expiry_date && $this->expiry_date->isPast();
    }

    public function isExpiringSoon(): bool
    {
        return $this->expiry_date
            && !$this->isExpired()
            && $this->expiry_date->isBefore(now()->addDays(90));
    }
}
