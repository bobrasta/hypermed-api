<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\InventoryItemResource;
use App\Models\InventoryItem;
use App\Models\Location;
use App\Services\StockService;
use Illuminate\Http\Request;

class InventoryController extends Controller
{
    public function index(Request $request)
    {
        $query = InventoryItem::with(['compatibleModels', 'category', 'stockLevels.location'])
            ->where('is_active', true);

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }
        if ($request->boolean('low_stock')) {
            $query->whereColumn('stock_qty', '<=', 'reorder_level');
        }
        if ($request->filled('creates_machine_record')) {
            $query->where('creates_machine_record', $request->boolean('creates_machine_record'));
        }
        if ($request->filled('supplier')) {
            $query->where('supplier', $request->supplier);
        }
        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'ilike', '%' . $request->search . '%')
                  ->orWhere('sku', 'ilike', '%' . $request->search . '%');
            });
        }

        // Default stays small for anything hitting this without an explicit per_page
        // (e.g. a future paginated table), but every current caller — Inventory Items,
        // POS catalog/scan, Add Part, Quotations/Sales Orders line-item pickers,
        // Requisitions, Stock Movements — loads the FULL catalog once client-side and
        // paginates/filters locally, so cap high rather than silently truncating a
        // 300+ item real catalog to 20.
        $perPage = min($request->integer('per_page', 20), 1000);

        return InventoryItemResource::collection($query->orderBy('name')->paginate($perPage));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'sku'                 => ['required', 'string', 'unique:inventory_items'],
            'name'                => ['required', 'string'],
            'description'         => ['nullable', 'string'],
            'category_id'         => ['nullable', 'exists:categories,id'],
            'unit_of_measure'     => ['nullable', 'in:piece,box,litre,set,kg,roll'],
            'unit_cost'           => ['required', 'integer', 'min:0'],
            'currency'            => ['nullable', 'string', 'max:10'],
            'stock_qty'           => ['required', 'integer', 'min:0'],
            'reorder_level'       => ['required', 'integer', 'min:0'],
            'supplier'            => ['nullable', 'string'],
            'creates_machine_record' => ['nullable', 'boolean'],
            'warranty_months'     => ['nullable', 'integer', 'min:0'],
            'compatible_models'   => ['nullable', 'array'],
            'compatible_models.*' => ['string'],
        ]);

        $models = $data['compatible_models'] ?? [];
        unset($data['compatible_models']);

        $item = InventoryItem::create($data);

        foreach ($models as $model) {
            $item->compatibleModels()->create(['machine_model' => $model]);
        }

        return response()->json(['data' => new InventoryItemResource($item->load(['compatibleModels', 'category']))], 201);
    }

    // Minimal-friction creation for a part discovered mid-service that isn't
    // catalogued yet — auto-fills everything store() otherwise requires and
    // flags the item for inventory admin to properly fill in later.
    public function quickCreate(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string'],
        ]);

        $item = InventoryItem::create([
            'sku'           => 'QR-' . strtoupper(uniqid()),
            'name'          => $data['name'],
            'category_id'   => null,
            'unit_cost'     => 0,
            'currency'      => 'TZS',
            'stock_qty'     => 0,
            'reorder_level' => 0,
            'needs_review'  => true,
        ]);

        return response()->json(['data' => new InventoryItemResource($item->fresh())], 201);
    }

    // Route param is {inventory} — variable name must match
    public function show(InventoryItem $inventory)
    {
        $inventory->load(['compatibleModels', 'category', 'stockLevels.location']);

        return response()->json(['data' => new InventoryItemResource($inventory)]);
    }

    public function update(Request $request, InventoryItem $inventory)
    {
        $data = $request->validate([
            'sku'             => ['sometimes', 'string', 'unique:inventory_items,sku,' . $inventory->id],
            'name'            => ['sometimes', 'string'],
            'description'     => ['nullable', 'string'],
            'category_id'     => ['nullable', 'exists:categories,id'],
            'unit_of_measure' => ['nullable', 'in:piece,box,litre,set,kg,roll'],
            'unit_cost'       => ['sometimes', 'integer', 'min:0'],
            'stock_qty'       => ['sometimes', 'integer', 'min:0'],
            'reorder_level'   => ['sometimes', 'integer', 'min:0'],
            'supplier'        => ['nullable', 'string'],
            'is_active'       => ['sometimes', 'boolean'],
            'creates_machine_record' => ['sometimes', 'boolean'],
            'warranty_months' => ['nullable', 'integer', 'min:0'],
            'needs_review'    => ['sometimes', 'boolean'],
        ]);

        $inventory->update($data);

        return response()->json(['data' => new InventoryItemResource($inventory->load(['compatibleModels', 'category']))]);
    }

    public function destroy(InventoryItem $inventory)
    {
        $inventory->update(['is_active' => false]);

        return response()->json(['message' => 'Item deactivated.']);
    }

    public function adjust(Request $request, InventoryItem $inventoryItem, StockService $stockService)
    {
        $data = $request->validate([
            'location_id'  => ['required', 'exists:locations,id'],
            'new_quantity' => ['required', 'integer', 'min:0'],
            'reason'       => ['nullable', 'string'],
        ]);

        $location = Location::findOrFail($data['location_id']);

        $stockService->adjust($inventoryItem, $location, $data['new_quantity'], $data['reason'] ?? 'Manual adjustment');

        return response()->json(['data' => new InventoryItemResource(
            $inventoryItem->fresh()->load(['compatibleModels', 'category', 'stockLevels.location'])
        )]);
    }
}
