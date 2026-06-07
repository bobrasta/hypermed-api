<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\BatchLotResource;
use App\Models\BatchLot;
use App\Models\InventoryItem;
use Illuminate\Http\Request;

class BatchLotController extends Controller
{
    public function index(Request $request, InventoryItem $inventoryItem)
    {
        $lots = $inventoryItem->batchLots()
            ->with('supplier')
            ->orderByDesc('received_at')
            ->orderByDesc('created_at')
            ->get();

        return BatchLotResource::collection($lots);
    }

    public function store(Request $request, InventoryItem $inventoryItem)
    {
        $data = $request->validate([
            'batch_number'      => ['required', 'string', 'max:100'],
            'lot_number'        => ['nullable', 'string', 'max:100'],
            'expiry_date'       => ['nullable', 'date'],
            'manufactured_date' => ['nullable', 'date'],
            'qty_received'      => ['required', 'integer', 'min:1'],
            'qty_remaining'     => ['nullable', 'integer', 'min:0'],
            'supplier_id'       => ['nullable', 'exists:suppliers,id'],
            'unit_cost'         => ['nullable', 'integer', 'min:0'],
            'currency'          => ['nullable', 'string', 'max:10'],
            'received_at'       => ['nullable', 'date'],
            'notes'             => ['nullable', 'string'],
        ]);

        $data['inventory_item_id'] = $inventoryItem->id;
        $data['qty_remaining'] ??= $data['qty_received'];

        $lot = BatchLot::create($data);
        $lot->load('supplier');

        return (new BatchLotResource($lot))->response()->setStatusCode(201);
    }

    public function update(Request $request, InventoryItem $inventoryItem, BatchLot $batchLot)
    {
        abort_if($batchLot->inventory_item_id !== $inventoryItem->id, 404);

        $data = $request->validate([
            'batch_number'      => ['sometimes', 'string', 'max:100'],
            'lot_number'        => ['nullable', 'string', 'max:100'],
            'expiry_date'       => ['nullable', 'date'],
            'manufactured_date' => ['nullable', 'date'],
            'qty_remaining'     => ['nullable', 'integer', 'min:0'],
            'supplier_id'       => ['nullable', 'exists:suppliers,id'],
            'unit_cost'         => ['nullable', 'integer', 'min:0'],
            'currency'          => ['nullable', 'string', 'max:10'],
            'received_at'       => ['nullable', 'date'],
            'notes'             => ['nullable', 'string'],
        ]);

        $batchLot->update($data);
        $batchLot->load('supplier');

        return new BatchLotResource($batchLot);
    }

    public function destroy(InventoryItem $inventoryItem, BatchLot $batchLot)
    {
        abort_if($batchLot->inventory_item_id !== $inventoryItem->id, 404);
        $batchLot->delete();
        return response()->noContent();
    }
}
