<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesOrderItem extends Model
{
    protected $fillable = [
        'sales_order_id', 'inventory_item_id', 'description', 'unit_of_measure',
        'quantity_ordered', 'quantity_delivered', 'quantity_invoiced', 'unit_price', 'discount', 'total_price',
    ];

    protected $casts = [
        'quantity_ordered'   => 'integer',
        'quantity_delivered' => 'integer',
        'quantity_invoiced'  => 'integer',
        'unit_price'         => 'integer',
        'discount'           => 'integer',
        'total_price'        => 'integer',
    ];

    /** This line's discount on [$qty] units, spread evenly over the ordered quantity. */
    public function discountFor(int $qty, int $alreadyInvoiced = 0): int
    {
        if (! $this->discount || $this->quantity_ordered <= 0) {
            return 0;
        }
        // Cumulative rounding, so every unit's share adds up to the whole
        // discount over a run of partial invoices.
        $upTo = fn (int $q) => (int) round($this->discount * min($q, $this->quantity_ordered) / $this->quantity_ordered);

        return $upTo($alreadyInvoiced + $qty) - $upTo($alreadyInvoiced);
    }

    public function inventoryItem()
    {
        return $this->belongsTo(InventoryItem::class);
    }
}
