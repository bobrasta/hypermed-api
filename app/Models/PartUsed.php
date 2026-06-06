<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PartUsed extends Model
{
    protected $table = 'parts_used';

    protected $fillable = ['ticket_id', 'inventory_item_id', 'qty', 'unit_cost'];

    protected $casts = [
        'qty'       => 'integer',
        'unit_cost' => 'integer',
    ];

    public function ticket()
    {
        return $this->belongsTo(ServiceTicket::class, 'ticket_id');
    }

    public function inventoryItem()
    {
        return $this->belongsTo(InventoryItem::class);
    }
}
