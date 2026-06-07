<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SerialNumberResource;
use App\Models\InventoryItem;
use App\Models\SerialNumber;
use Illuminate\Http\Request;

class SerialNumberController extends Controller
{
    public function index(Request $request, InventoryItem $inventoryItem)
    {
        $query = $inventoryItem->serialNumbers()->with(['location', 'assignedMachine']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        return SerialNumberResource::collection($query->orderBy('serial_number')->get());
    }

    public function store(Request $request, InventoryItem $inventoryItem)
    {
        $data = $request->validate([
            'serial_number'          => ['required', 'string', 'max:255', 'unique:serial_numbers'],
            'status'                 => ['nullable', 'in:available,assigned,in_service,damaged,disposed'],
            'location_id'            => ['nullable', 'exists:locations,id'],
            'assigned_to_machine_id' => ['nullable', 'exists:machines,id'],
            'purchase_date'          => ['nullable', 'date'],
            'warranty_expires_at'    => ['nullable', 'date'],
            'notes'                  => ['nullable', 'string'],
        ]);

        $data['inventory_item_id'] = $inventoryItem->id;

        $serial = SerialNumber::create($data);
        $serial->load(['location', 'assignedMachine']);

        return (new SerialNumberResource($serial))->response()->setStatusCode(201);
    }

    public function update(Request $request, InventoryItem $inventoryItem, SerialNumber $serialNumber)
    {
        abort_if($serialNumber->inventory_item_id !== $inventoryItem->id, 404);

        $data = $request->validate([
            'serial_number'          => ['sometimes', 'string', 'max:255', 'unique:serial_numbers,serial_number,' . $serialNumber->id],
            'status'                 => ['nullable', 'in:available,assigned,in_service,damaged,disposed'],
            'location_id'            => ['nullable', 'exists:locations,id'],
            'assigned_to_machine_id' => ['nullable', 'exists:machines,id'],
            'purchase_date'          => ['nullable', 'date'],
            'warranty_expires_at'    => ['nullable', 'date'],
            'notes'                  => ['nullable', 'string'],
        ]);

        $serialNumber->update($data);
        $serialNumber->load(['location', 'assignedMachine']);

        return new SerialNumberResource($serialNumber);
    }

    public function destroy(InventoryItem $inventoryItem, SerialNumber $serialNumber)
    {
        abort_if($serialNumber->inventory_item_id !== $inventoryItem->id, 404);
        $serialNumber->delete();
        return response()->noContent();
    }
}
