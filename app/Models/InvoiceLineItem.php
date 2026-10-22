<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvoiceLineItem extends Model
{
    protected $fillable = ['invoice_id', 'inventory_item_id', 'description', 'quantity', 'unit_of_measure', 'lot_number',
        'expiry_date', 'unit_price', 'discount', 'total'];

    protected $casts = [
        'quantity' => 'float',
        'unit_price' => 'integer',
        'discount' => 'integer',
        'total' => 'integer',
        'expiry_date' => 'date',
    ];

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function inventoryItem()
    {
        return $this->belongsTo(InventoryItem::class);
    }
}
