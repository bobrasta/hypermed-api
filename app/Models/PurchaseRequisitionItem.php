<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseRequisitionItem extends Model
{
    protected $fillable = [
        'purchase_requisition_id', 'inventory_item_id',
        'quantity_requested', 'quantity_approved', 'estimated_unit_cost', 'currency', 'notes',
    ];

    protected $casts = [
        'quantity_requested'  => 'integer',
        'quantity_approved'   => 'integer',
        'estimated_unit_cost' => 'integer',
    ];

    public function requisition()
    {
        return $this->belongsTo(PurchaseRequisition::class, 'purchase_requisition_id');
    }

    public function inventoryItem()
    {
        return $this->belongsTo(InventoryItem::class);
    }
}
