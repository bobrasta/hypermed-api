<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\InventoryItemResource;
use App\Models\InventoryItem;
use Illuminate\Http\Request;

class InventoryController extends Controller
{
    public function index(Request $request)
    {
        $query = InventoryItem::with('compatibleModels')
            ->where('is_active', true);

        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }
        if ($request->boolean('low_stock')) {
            $query->whereColumn('stock_qty', '<=', 'reorder_level');
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

        return InventoryItemResource::collection($query->orderBy('category')->orderBy('name')->paginate(20));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'sku'                 => ['required', 'string', 'unique:inventory_items'],
            'name'                => ['required', 'string'],
            'description'         => ['nullable', 'string'],
            'category'            => ['required', 'in:machine_part,consumable,accessory,equipment,other'],
            'unit_of_measure'     => ['nullable', 'in:piece,box,litre,set,kg,roll'],
            'unit_cost'           => ['required', 'integer', 'min:0'],
            'currency'            => ['nullable', 'string', 'max:10'],
            'stock_qty'           => ['required', 'integer', 'min:0'],
            'reorder_level'       => ['required', 'integer', 'min:0'],
            'supplier'            => ['nullable', 'string'],
            'compatible_models'   => ['nullable', 'array'],
            'compatible_models.*' => ['string'],
        ]);

        $models = $data['compatible_models'] ?? [];
        unset($data['compatible_models']);

        $item = InventoryItem::create($data);

        foreach ($models as $model) {
            $item->compatibleModels()->create(['machine_model' => $model]);
        }

        return response()->json(['data' => new InventoryItemResource($item->load('compatibleModels'))], 201);
    }

    // Route param is {inventory} — variable name must match
    public function show(InventoryItem $inventory)
    {
        $inventory->load('compatibleModels');

        return response()->json(['data' => new InventoryItemResource($inventory)]);
    }

    public function update(Request $request, InventoryItem $inventory)
    {
        $data = $request->validate([
            'sku'             => ['sometimes', 'string', 'unique:inventory_items,sku,' . $inventory->id],
            'name'            => ['sometimes', 'string'],
            'description'     => ['nullable', 'string'],
            'category'        => ['sometimes', 'in:machine_part,consumable,accessory,equipment,other'],
            'unit_of_measure' => ['nullable', 'in:piece,box,litre,set,kg,roll'],
            'unit_cost'       => ['sometimes', 'integer', 'min:0'],
            'stock_qty'       => ['sometimes', 'integer', 'min:0'],
            'reorder_level'   => ['sometimes', 'integer', 'min:0'],
            'supplier'        => ['nullable', 'string'],
            'is_active'       => ['sometimes', 'boolean'],
        ]);

        $inventory->update($data);

        return response()->json(['data' => new InventoryItemResource($inventory->load('compatibleModels'))]);
    }

    public function destroy(InventoryItem $inventory)
    {
        $inventory->update(['is_active' => false]);

        return response()->json(['message' => 'Item deactivated.']);
    }

    public function adjust(Request $request, InventoryItem $inventoryItem)
    {
        $data = $request->validate([
            'adjustment' => ['required', 'integer'],
            'reason'     => ['nullable', 'string'],
        ]);

        $inventoryItem->increment('stock_qty', $data['adjustment']);

        return response()->json(['data' => new InventoryItemResource($inventoryItem->fresh()->load('compatibleModels'))]);
    }
}
