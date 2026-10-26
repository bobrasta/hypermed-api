<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseReturnItem extends Model
{
    protected $fillable = ['purchase_return_id', 'purchase_order_item_id', 'inventory_item_id', 'quantity', 'unit_cost', 'total'];

    protected $casts = ['quantity' => 'integer', 'unit_cost' => 'integer', 'total' => 'integer'];

    public function purchaseReturn() { return $this->belongsTo(PurchaseReturn::class); }
    public function purchaseOrderItem() { return $this->belongsTo(PurchaseOrderItem::class); }
    public function inventoryItem() { return $this->belongsTo(InventoryItem::class); }
}
