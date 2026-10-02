<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QuotationItem extends Model
{
    protected $fillable = [
        'quotation_id', 'inventory_item_id', 'description', 'unit_of_measure',
        'quantity', 'unit_price', 'discount_percent', 'discount_amount', 'total_price',
    ];

    protected $casts = [
        'quantity'         => 'integer',
        'unit_price'       => 'integer',
        'discount_percent' => 'float',
        'discount_amount'  => 'integer',
        'total_price'      => 'integer',
    ];

    public function inventoryItem()
    {
        return $this->belongsTo(InventoryItem::class);
    }
}
