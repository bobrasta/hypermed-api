<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryItemImage extends Model
{
    protected $fillable = [
        'inventory_item_id', 'file_path', 'original_name', 'file_size', 'is_primary', 'sort_order',
    ];

    protected $casts = [
        'file_size'  => 'integer',
        'is_primary' => 'boolean',
        'sort_order' => 'integer',
    ];

    protected $appends = ['url'];

    public function getUrlAttribute(): string
    {
        return asset('storage/' . $this->file_path);
    }

    public function inventoryItem()
    {
        return $this->belongsTo(InventoryItem::class);
    }
}
