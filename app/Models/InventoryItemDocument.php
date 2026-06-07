<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryItemDocument extends Model
{
    protected $fillable = [
        'inventory_item_id', 'name', 'file_path', 'original_name',
        'mime_type', 'file_size', 'document_type',
    ];

    protected $casts = [
        'file_size' => 'integer',
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
