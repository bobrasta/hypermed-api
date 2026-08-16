<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryItem;
use App\Models\Location;
use App\Models\StockMovement;
use App\Services\StockService;
use Illuminate\Http\Request;

class StockMovementController extends Controller
{
    public function index(Request $request)
    {
        $movements = StockMovement::with(['inventoryItem', 'location', 'locationFrom', 'locationTo', 'performedBy'])
            ->when($request->inventory_item_id, fn ($q, $id) => $q->where('inventory_item_id', $id))
            ->when($request->location_id, fn ($q, $id) => $q->where('location_id', $id))
            ->when($request->type, fn ($q, $t) => $q->where('type', $t))
            ->latest()
            ->paginate(50);

        return response()->json($movements);
    }

    public function store(Request $request, StockService $stockService)
    {
        $data = $request->validate([
            'inventory_item_id' => 'required|exists:inventory_items,id',
            'location_id'       => 'required|exists:locations,id',
            'to_location_id'    => 'required_if:type,transfer|nullable|exists:locations,id',
            // 'issue'/'write_off' (stock leaving the store) go through
            // StockOutRequestController's approval flow instead — only
            // CTO/Director approving a request may create those movements.
            'type'              => 'required|in:receive,transfer,adjustment,return',
            'quantity'          => 'required|integer|not_in:0',
            'unit_cost'         => 'nullable|integer|min:0',
            'reference_type'    => 'nullable|string|max:50',
            'reference_id'      => 'nullable|integer',
            'batch_number'      => 'nullable|string|max:100',
            'expiry_date'       => 'nullable|date',
            'notes'             => 'nullable|string',
        ]);

        $item = InventoryItem::findOrFail($data['inventory_item_id']);
        $location = Location::findOrFail($data['location_id']);
        $reason = $data['notes'] ?? ucfirst(str_replace('_', ' ', $data['type']));

        $movement = match ($data['type']) {
            'receive' => $stockService->add(
                $item, $location, abs($data['quantity']),
                $data['unit_cost'] ?? $item->unit_cost, $reason, type: 'receive',
            ),
            'return' => $stockService->add(
                $item, $location, abs($data['quantity']),
                $data['unit_cost'] ?? $item->unit_cost, $reason, type: 'return',
            ),
            'transfer' => $stockService->transfer(
                $item, $location, Location::findOrFail($data['to_location_id']), abs($data['quantity']), $reason,
            ),
            'adjustment' => $stockService->adjustBy(
                $item, $location, $data['quantity'], $reason,
            ),
        };

        if (isset($data['batch_number']) || isset($data['expiry_date'])) {
            $movement->update([
                'batch_number' => $data['batch_number'] ?? null,
                'expiry_date'  => $data['expiry_date'] ?? null,
            ]);
        }

        return response()->json(['data' => $movement->fresh()->load(['inventoryItem', 'location', 'locationFrom', 'locationTo', 'performedBy'])], 201);
    }
}
