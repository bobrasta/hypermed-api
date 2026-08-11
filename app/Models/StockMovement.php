<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockMovement extends Model
{
    protected $fillable = [
        'inventory_item_id', 'type', 'quantity', 'quantity_before', 'quantity_after',
        'unit_cost', 'currency', 'reference_type', 'reference_id',
        'location_id', 'location_from_id', 'location_to_id',
        'batch_number', 'expiry_date', 'notes', 'performed_by',
    ];

    protected $casts = [
        'quantity'        => 'integer',
        'quantity_before' => 'integer',
        'quantity_after'  => 'integer',
        'unit_cost'       => 'integer',
        'expiry_date'     => 'date',
    ];

    public function inventoryItem()
    {
        return $this->belongsTo(InventoryItem::class);
    }

    public function location()
    {
        return $this->belongsTo(Location::class);
    }

    public function locationFrom()
    {
        return $this->belongsTo(Location::class, 'location_from_id');
    }

    public function locationTo()
    {
        return $this->belongsTo(Location::class, 'location_to_id');
    }

    public function performedBy()
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
