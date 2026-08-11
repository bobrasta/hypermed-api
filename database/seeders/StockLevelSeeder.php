<?php

namespace Database\Seeders;

use App\Models\InventoryItem;
use App\Models\Location;
use App\Models\StockLevel;
use Illuminate\Database\Seeder;

/**
 * One-time backfill: puts each item's existing flat stock_qty total into
 * stock_levels at the primary warehouse, so the per-location table matches
 * what was already on inventory_items before stock_levels existed.
 */
class StockLevelSeeder extends Seeder
{
    public function run(): void
    {
        $mainLocation = Location::where('code', 'DSM-MAIN')->first();

        if (! $mainLocation) {
            return;
        }

        InventoryItem::where('stock_qty', '>', 0)->each(function (InventoryItem $item) use ($mainLocation) {
            StockLevel::firstOrCreate(
                ['inventory_item_id' => $item->id, 'location_id' => $mainLocation->id],
                ['quantity_on_hand' => $item->stock_qty, 'quantity_reserved' => 0]
            );
        });
    }
}
