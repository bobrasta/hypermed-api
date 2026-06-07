<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryItem;
use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StockMovementController extends Controller
{
    public function index(Request $request)
    {
        $movements = StockMovement::with(['inventoryItem', 'performedBy'])
            ->when($request->inventory_item_id, fn ($q, $id) => $q->where('inventory_item_id', $id))
            ->when($request->type, fn ($q, $t) => $q->where('type', $t))
            ->latest()
            ->paginate(50);

        return response()->json($movements);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'inventory_item_id' => 'required|exists:inventory_items,id',
            'type'              => 'required|in:receive,issue,transfer,write_off,adjustment,return',
            'quantity'          => 'required|integer|not_in:0',
            'unit_cost'         => 'nullable|integer|min:0',
            'currency'          => 'nullable|string|max:10',
            'reference_type'    => 'nullable|string|max:50',
            'reference_id'      => 'nullable|integer',
            'location'          => 'nullable|string|max:100',
            'batch_number'      => 'nullable|string|max:100',
            'expiry_date'       => 'nullable|date',
            'notes'             => 'nullable|string',
        ]);

        return DB::transaction(function () use ($data, $request) {
            $item = InventoryItem::lockForUpdate()->findOrFail($data['inventory_item_id']);

            // Outbound movements are always negative
            $qty = in_array($data['type'], ['issue', 'write_off'])
                ? -abs($data['quantity'])
                : abs($data['quantity']);

            // Adjustment can be positive or negative as supplied
            if ($data['type'] === 'adjustment') {
                $qty = $data['quantity'];
            }

            $before = $item->stock_qty;
            $after  = $before + $qty;

            abort_if($after < 0, 422, 'Insufficient stock for this movement.');

            $movement = StockMovement::create(array_merge($data, [
                'quantity'        => $qty,
                'quantity_before' => $before,
                'quantity_after'  => $after,
                'performed_by'    => $request->user()->id,
            ]));

            $item->update(['stock_qty' => $after]);

            return response()->json(['data' => $movement->load(['inventoryItem', 'performedBy'])], 201);
        });
    }
}
