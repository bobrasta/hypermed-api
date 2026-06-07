<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use Illuminate\Http\Request;

class SupplierController extends Controller
{
    public function index(Request $request)
    {
        $suppliers = Supplier::query()
            ->when($request->search, fn ($q, $s) => $q->where('name', 'ilike', "%$s%"))
            ->when($request->type,   fn ($q, $t) => $q->where('type', $t))
            ->when($request->active !== null, fn ($q) => $q->where('is_active', $request->boolean('active')))
            ->withCount('items')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $suppliers]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'          => 'required|string|max:255',
            'short_code'    => 'nullable|string|max:20|unique:suppliers',
            'type'          => 'required|in:manufacturer,distributor,importer,local_vendor',
            'contact_name'  => 'nullable|string|max:255',
            'contact_email' => 'nullable|email',
            'contact_phone' => 'nullable|string|max:30',
            'website'       => 'nullable|url',
            'address'       => 'nullable|string',
            'city'          => 'nullable|string|max:100',
            'country'       => 'nullable|string|max:100',
            'currency'      => 'nullable|string|max:10',
            'payment_terms' => 'nullable|in:prepaid,net_15,net_30,net_60,net_90',
            'lead_time_days'=> 'nullable|integer|min:0',
            'rating'        => 'nullable|integer|min:1|max:5',
            'notes'         => 'nullable|string',
            'is_active'     => 'boolean',
        ]);

        $supplier = Supplier::create($data);

        return response()->json(['data' => $supplier], 201);
    }

    public function show(Supplier $supplier)
    {
        $supplier->load(['items' => fn ($q) => $q->with('compatibleModels')]);

        return response()->json(['data' => $supplier]);
    }

    public function update(Request $request, Supplier $supplier)
    {
        $data = $request->validate([
            'name'          => 'sometimes|required|string|max:255',
            'short_code'    => 'nullable|string|max:20|unique:suppliers,short_code,' . $supplier->id,
            'type'          => 'sometimes|in:manufacturer,distributor,importer,local_vendor',
            'contact_name'  => 'nullable|string|max:255',
            'contact_email' => 'nullable|email',
            'contact_phone' => 'nullable|string|max:30',
            'website'       => 'nullable|url',
            'address'       => 'nullable|string',
            'city'          => 'nullable|string|max:100',
            'country'       => 'nullable|string|max:100',
            'currency'      => 'nullable|string|max:10',
            'payment_terms' => 'nullable|in:prepaid,net_15,net_30,net_60,net_90',
            'lead_time_days'=> 'nullable|integer|min:0',
            'rating'        => 'nullable|integer|min:1|max:5',
            'notes'         => 'nullable|string',
            'is_active'     => 'boolean',
        ]);

        $supplier->update($data);

        return response()->json(['data' => $supplier]);
    }

    public function destroy(Supplier $supplier)
    {
        $supplier->update(['is_active' => false]);

        return response()->json(null, 204);
    }

    // Link an inventory item to a supplier with pricing
    public function addItem(Request $request, Supplier $supplier)
    {
        $data = $request->validate([
            'inventory_item_id'  => 'required|exists:inventory_items,id',
            'unit_price'         => 'required|integer|min:0',
            'currency'           => 'nullable|string|max:10',
            'supplier_sku'       => 'nullable|string|max:100',
            'lead_time_days'     => 'nullable|integer|min:0',
            'minimum_order_qty'  => 'nullable|integer|min:1',
            'is_preferred'       => 'boolean',
            'notes'              => 'nullable|string',
        ]);

        $supplier->items()->syncWithoutDetaching([
            $data['inventory_item_id'] => collect($data)->except('inventory_item_id')->toArray(),
        ]);

        return response()->json(['data' => $supplier->load('items')]);
    }

    public function removeItem(Supplier $supplier, int $inventoryItemId)
    {
        $supplier->items()->detach($inventoryItemId);

        return response()->json(null, 204);
    }
}
