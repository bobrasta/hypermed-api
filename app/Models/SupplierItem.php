<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupplierItem extends Model
{
    protected $fillable = [
        'supplier_id', 'inventory_item_id', 'unit_price', 'currency',
        'supplier_sku', 'lead_time_days', 'minimum_order_qty', 'is_preferred', 'notes',
    ];

    protected $casts = [
        'unit_price'        => 'integer',
        'lead_time_days'    => 'integer',
        'minimum_order_qty' => 'integer',
        'is_preferred'      => 'boolean',
    ];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function inventoryItem()
    {
        return $this->belongsTo(InventoryItem::class);
    }
}
