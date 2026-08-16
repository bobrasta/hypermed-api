<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockOutRequest extends Model
{
    protected $fillable = [
        'inventory_item_id', 'location_id', 'type', 'quantity', 'reason',
        'requested_by', 'status', 'reviewed_by', 'reviewed_at',
        'rejection_reason', 'stock_movement_id',
    ];

    protected $casts = [
        'reviewed_at' => 'datetime',
    ];

    public function inventoryItem()
    {
        return $this->belongsTo(InventoryItem::class);
    }

    public function location()
    {
        return $this->belongsTo(Location::class);
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function stockMovement()
    {
        return $this->belongsTo(StockMovement::class);
    }
}
