<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseOrderItem extends Model
{
    protected $fillable = [
        'purchase_order_id', 'inventory_item_id',
        'quantity_ordered', 'quantity_received', 'unit_cost', 'currency',
        'expiry_date', 'batch_number', 'notes',
    ];

    protected $casts = [
        'quantity_ordered'  => 'integer',
        'quantity_received' => 'integer',
        'unit_cost'         => 'integer',
        'total_cost'        => 'integer',
        'expiry_date'       => 'date',
    ];

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function inventoryItem()
    {
        return $this->belongsTo(InventoryItem::class);
    }
}
