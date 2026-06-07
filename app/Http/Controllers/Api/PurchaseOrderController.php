<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PurchaseOrderController extends Controller
{
    private function nextPoNumber(): string
    {
        $year  = now()->format('Y');
        $count = PurchaseOrder::whereYear('created_at', $year)->count() + 1;
        return 'PO-' . $year . '-' . str_pad($count, 4, '0', STR_PAD_LEFT);
    }

    public function index(Request $request)
    {
        $pos = PurchaseOrder::with(['supplier', 'orderedBy', 'items.inventoryItem'])
            ->when($request->status,      fn ($q, $s) => $q->where('status', $s))
            ->when($request->supplier_id, fn ($q, $id) => $q->where('supplier_id', $id))
            ->latest()
            ->paginate(25);

        return response()->json($pos);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'supplier_id'               => 'required|exists:suppliers,id',
            'purchase_requisition_id'   => 'nullable|exists:purchase_requisitions,id',
            'expected_delivery_date'    => 'nullable|date',
            'currency'                  => 'nullable|string|max:10',
            'shipping_address'          => 'nullable|string',
            'terms'                     => 'nullable|string',
            'notes'                     => 'nullable|string',
            'items'                     => 'required|array|min:1',
            'items.*.inventory_item_id' => 'required|exists:inventory_items,id',
            'items.*.quantity_ordered'  => 'required|integer|min:1',
            'items.*.unit_cost'         => 'required|integer|min:0',
            'items.*.currency'          => 'nullable|string|max:10',
            'items.*.expiry_date'       => 'nullable|date',
            'items.*.batch_number'      => 'nullable|string|max:100',
            'items.*.notes'             => 'nullable|string',
        ]);

        return DB::transaction(function () use ($data, $request) {
            $total = collect($data['items'])->sum(fn ($i) => $i['quantity_ordered'] * $i['unit_cost']);

            $po = PurchaseOrder::create([
                'po_number'               => $this->nextPoNumber(),
                'supplier_id'             => $data['supplier_id'],
                'purchase_requisition_id' => $data['purchase_requisition_id'] ?? null,
                'status'                  => 'draft',
                'ordered_by'              => $request->user()->id,
                'expected_delivery_date'  => $data['expected_delivery_date'] ?? null,
                'currency'                => $data['currency'] ?? 'USD',
                'total_amount'            => $total,
                'shipping_address'        => $data['shipping_address'] ?? null,
                'terms'                   => $data['terms'] ?? null,
                'notes'                   => $data['notes'] ?? null,
            ]);

            foreach ($data['items'] as $item) {
                $po->items()->create($item);
            }

            // Mark requisition as ordered
            if ($po->purchase_requisition_id) {
                PurchaseRequisition::where('id', $po->purchase_requisition_id)
                    ->where('status', 'approved')
                    ->update(['status' => 'ordered']);
            }

            return response()->json(['data' => $po->load(['supplier', 'orderedBy', 'items.inventoryItem'])], 201);
        });
    }

    public function show(PurchaseOrder $purchaseOrder)
    {
        $purchaseOrder->load(['supplier', 'orderedBy', 'requisition', 'items.inventoryItem']);

        return response()->json(['data' => $purchaseOrder]);
    }

    public function update(Request $request, PurchaseOrder $purchaseOrder)
    {
        abort_if($purchaseOrder->status !== 'draft', 422, 'Only draft purchase orders can be edited.');

        $data = $request->validate([
            'supplier_id'            => 'sometimes|exists:suppliers,id',
            'expected_delivery_date' => 'nullable|date',
            'currency'               => 'nullable|string|max:10',
            'shipping_address'       => 'nullable|string',
            'terms'                  => 'nullable|string',
            'notes'                  => 'nullable|string',
        ]);

        $purchaseOrder->update($data);

        return response()->json(['data' => $purchaseOrder->fresh(['supplier', 'items.inventoryItem'])]);
    }

    // Mark PO as sent to supplier
    public function send(PurchaseOrder $purchaseOrder)
    {
        abort_if(!in_array($purchaseOrder->status, ['draft']), 422, 'Only draft orders can be sent.');

        $purchaseOrder->update([
            'status'  => 'sent',
            'sent_at' => now(),
        ]);

        return response()->json(['data' => $purchaseOrder->fresh()]);
    }

    // Goods Received Note — record received quantities and update stock
    public function receive(Request $request, PurchaseOrder $purchaseOrder)
    {
        abort_if(!in_array($purchaseOrder->status, ['sent', 'acknowledged', 'partially_received']), 422, 'Order cannot be received in its current status.');

        $data = $request->validate([
            'items'                             => 'required|array|min:1',
            'items.*.purchase_order_item_id'    => 'required|exists:purchase_order_items,id',
            'items.*.quantity_received'         => 'required|integer|min:1',
            'items.*.batch_number'              => 'nullable|string|max:100',
            'items.*.expiry_date'               => 'nullable|date',
            'notes'                             => 'nullable|string',
        ]);

        return DB::transaction(function () use ($data, $request, $purchaseOrder) {
            $allReceived = true;

            foreach ($data['items'] as $received) {
                $poItem = $purchaseOrder->items()->findOrFail($received['purchase_order_item_id']);

                $newQtyReceived = $poItem->quantity_received + $received['quantity_received'];
                $poItem->update([
                    'quantity_received' => $newQtyReceived,
                    'batch_number'      => $received['batch_number'] ?? $poItem->batch_number,
                    'expiry_date'       => $received['expiry_date'] ?? $poItem->expiry_date,
                ]);

                if ($newQtyReceived < $poItem->quantity_ordered) {
                    $allReceived = false;
                }

                // Record stock movement
                $item = $poItem->inventoryItem()->lockForUpdate()->first();
                $before = $item->stock_qty;
                $after  = $before + $received['quantity_received'];

                StockMovement::create([
                    'inventory_item_id' => $item->id,
                    'type'              => 'receive',
                    'quantity'          => $received['quantity_received'],
                    'quantity_before'   => $before,
                    'quantity_after'    => $after,
                    'unit_cost'         => $poItem->unit_cost,
                    'currency'          => $poItem->currency,
                    'reference_type'    => 'purchase_order',
                    'reference_id'      => $purchaseOrder->id,
                    'batch_number'      => $received['batch_number'] ?? null,
                    'expiry_date'       => $received['expiry_date'] ?? null,
                    'notes'             => $data['notes'] ?? "Received via {$purchaseOrder->po_number}",
                    'performed_by'      => $request->user()->id,
                ]);

                $item->update(['stock_qty' => $after]);
            }

            $purchaseOrder->update([
                'status'               => $allReceived ? 'received' : 'partially_received',
                'actual_delivery_date' => $allReceived ? now()->toDateString() : null,
            ]);

            return response()->json(['data' => $purchaseOrder->load(['items.inventoryItem'])]);
        });
    }

    public function cancel(Request $request, PurchaseOrder $purchaseOrder)
    {
        abort_if(in_array($purchaseOrder->status, ['received']), 422, 'Fully received orders cannot be cancelled.');

        $purchaseOrder->update(['status' => 'cancelled']);

        return response()->json(['data' => $purchaseOrder->fresh()]);
    }
}
