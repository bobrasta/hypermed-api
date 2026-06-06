<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InventoryItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'sku', 'name', 'description', 'category', 'unit_of_measure',
        'unit_cost', 'currency', 'stock_qty', 'reorder_level',
        'supplier', 'is_active',
    ];

    protected $casts = [
        'unit_cost'     => 'integer',
        'stock_qty'     => 'integer',
        'reorder_level' => 'integer',
        'is_active'     => 'boolean',
    ];

    public function compatibleModels()
    {
        return $this->hasMany(InventoryItemMachineModel::class);
    }

    public function partsUsed()
    {
        return $this->hasMany(PartUsed::class);
    }
}
