<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryItemMachineModel extends Model
{
    protected $fillable = ['inventory_item_id', 'machine_model'];

    public function inventoryItem()
    {
        return $this->belongsTo(InventoryItem::class);
    }
}
