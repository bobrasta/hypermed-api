<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    protected $fillable = [
        'name', 'short_code', 'type', 'contact_name', 'contact_email',
        'contact_phone', 'website', 'address', 'city', 'country',
        'currency', 'payment_terms', 'lead_time_days', 'rating', 'notes', 'is_active',
    ];

    protected $casts = [
        'lead_time_days' => 'integer',
        'rating'         => 'integer',
        'is_active'      => 'boolean',
    ];

    public function items()
    {
        return $this->belongsToMany(InventoryItem::class, 'supplier_items')
                    ->withPivot(['unit_price', 'currency', 'supplier_sku', 'lead_time_days', 'minimum_order_qty', 'is_preferred', 'notes'])
                    ->withTimestamps();
    }

    public function supplierItems()
    {
        return $this->hasMany(SupplierItem::class);
    }

    public function purchaseOrders()
    {
        return $this->hasMany(PurchaseOrder::class);
    }
}
