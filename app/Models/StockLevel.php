<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockLevel extends Model
{
    protected $fillable = [
        'inventory_item_id', 'location_id', 'quantity_on_hand', 'quantity_reserved',
    ];

    protected $casts = [
        'quantity_on_hand'   => 'integer',
        'quantity_reserved'  => 'integer',
    ];

    public function inventoryItem()
    {
        return $this->belongsTo(InventoryItem::class);
    }

    public function location()
    {
        return $this->belongsTo(Location::class);
    }

    public function getQuantityAvailableAttribute(): int
    {
        return $this->quantity_on_hand - $this->quantity_reserved;
    }
}
