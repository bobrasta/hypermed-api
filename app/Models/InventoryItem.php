<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InventoryItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'sku', 'barcode', 'barcode_type', 'name', 'description',
        'category_id', 'unit_of_measure',
        'manufacturer', 'model_number', 'country_of_origin',
        'has_ce', 'has_fda', 'has_tbs',
        'weight_kg', 'dimensions', 'voltage', 'specifications',
        'shelf_life_days', 'preferred_supplier_id',
        'unit_cost', 'cost_currency', 'stock_qty', 'reorder_level', 'reorder_notified_at',
        'supplier', 'is_active', 'creates_machine_record', 'warranty_months',
        'needs_review',
    ];

    protected $casts = [
        'unit_cost'      => 'integer',
        'stock_qty'      => 'integer',
        'reorder_level'  => 'integer',
        'reorder_notified_at' => 'datetime',
        'shelf_life_days'=> 'integer',
        'is_active'      => 'boolean',
        'has_ce'         => 'boolean',
        'has_fda'        => 'boolean',
        'has_tbs'        => 'boolean',
        'specifications' => 'array',
        'creates_machine_record' => 'boolean',
        'warranty_months' => 'integer',
        'needs_review'   => 'boolean',
    ];

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function stockLevels()
    {
        return $this->hasMany(StockLevel::class);
    }

    public function compatibleModels()
    {
        return $this->hasMany(InventoryItemMachineModel::class);
    }

    public function images()
    {
        return $this->hasMany(InventoryItemImage::class)->orderBy('sort_order');
    }

    public function primaryImage()
    {
        return $this->hasOne(InventoryItemImage::class)->where('is_primary', true);
    }

    public function documents()
    {
        return $this->hasMany(InventoryItemDocument::class);
    }

    public function suppliers()
    {
        return $this->belongsToMany(Supplier::class, 'supplier_items')
                    ->withPivot(['unit_price', 'currency', 'supplier_sku', 'lead_time_days', 'minimum_order_qty', 'is_preferred', 'notes'])
                    ->withTimestamps();
    }

    public function preferredSupplier()
    {
        return $this->belongsTo(Supplier::class, 'preferred_supplier_id');
    }

    public function stockMovements()
    {
        return $this->hasMany(StockMovement::class)->latest();
    }

    public function partsUsed()
    {
        return $this->hasMany(PartUsed::class);
    }

    public function batchLots()
    {
        return $this->hasMany(BatchLot::class)->orderByDesc('received_at');
    }

    public function serialNumbers()
    {
        return $this->hasMany(SerialNumber::class)->orderBy('serial_number');
    }
}
