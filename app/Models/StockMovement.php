<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockMovement extends Model
{
    protected $fillable = [
        'inventory_item_id', 'type', 'quantity', 'quantity_before', 'quantity_after',
        'unit_cost', 'currency', 'reference_type', 'reference_id',
        'location', 'batch_number', 'expiry_date', 'notes', 'performed_by',
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

    public function performedBy()
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
