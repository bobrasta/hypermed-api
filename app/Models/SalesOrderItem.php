<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesOrderItem extends Model
{
    protected $fillable = [
        'sales_order_id', 'inventory_item_id', 'description', 'unit_of_measure',
        'quantity_ordered', 'quantity_delivered', 'unit_price', 'total_price',
    ];

    protected $casts = [
        'quantity_ordered'   => 'integer',
        'quantity_delivered' => 'integer',
        'unit_price'         => 'integer',
        'total_price'        => 'integer',
    ];

    public function inventoryItem()
    {
        return $this->belongsTo(InventoryItem::class);
    }
}
