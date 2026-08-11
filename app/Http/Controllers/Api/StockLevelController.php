<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\StockLevelResource;
use App\Models\StockLevel;
use Illuminate\Http\Request;

class StockLevelController extends Controller
{
    public function index(Request $request)
    {
        $query = StockLevel::with(['inventoryItem', 'location'])
            ->whereHas('inventoryItem', fn ($q) => $q->where('is_active', true));

        if ($request->filled('warehouse_id')) {
            $query->where('location_id', $request->warehouse_id);
        }

        if ($request->boolean('low_stock')) {
            $query->whereHas('inventoryItem', fn ($q) =>
                $q->whereColumn('stock_levels.quantity_on_hand', '<=', 'inventory_items.reorder_level')
            );
        }

        return StockLevelResource::collection(
            $query->orderBy('quantity_on_hand')->paginate(50)
        );
    }
}
