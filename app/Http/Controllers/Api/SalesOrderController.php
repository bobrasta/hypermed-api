<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\InvoiceResource;
use App\Http\Resources\SalesOrderResource;
use App\Models\Invoice;
use App\Models\SalesOrder;
use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SalesOrderController extends Controller
{
    public function index(Request $request)
    {
        $orders = SalesOrder::with(['createdBy', 'quotation'])
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->search, fn ($q, $s) => $q->where(function ($q) use ($s) {
                $q->where('client_name', 'like', "%$s%")
                  ->orWhere('order_number', 'like', "%$s%");
            }))
            ->latest()
            ->paginate(25);

        return SalesOrderResource::collection($orders);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'client_name'               => 'required|string|max:200',
            'client_contact'            => 'nullable|string|max:200',
            'currency'                  => 'nullable|string|max:10',
            'discount_amount'           => 'nullable|integer|min:0',
            'tax_amount'                => 'nullable|integer|min:0',
            'expected_delivery_date'    => 'nullable|date',
            'notes'                     => 'nullable|string',
            'items'                     => 'required|array|min:1',
            'items.*.inventory_item_id' => 'nullable|exists:inventory_items,id',
            'items.*.description'       => 'required|string|max:300',
            'items.*.unit_of_measure'   => 'nullable|string|max:30',
            'items.*.quantity_ordered'  => 'required|integer|min:1',
            'items.*.unit_price'        => 'required|integer|min:0',
        ]);

        return DB::transaction(function () use ($data, $request) {
            $subtotal = collect($data['items'])->sum(fn ($i) => $i['quantity_ordered'] * $i['unit_price']);
            $discountAmount = $data['discount_amount'] ?? 0;
            $taxAmount      = $data['tax_amount'] ?? 0;

            $year  = now()->format('Y');
            $count = SalesOrder::whereYear('created_at', $year)->count() + 1;

            $order = SalesOrder::create([
                'order_number'           => 'SO-' . $year . '-' . str_pad($count, 4, '0', STR_PAD_LEFT),
                'client_name'            => $data['client_name'],
                'client_contact'         => $data['client_contact'] ?? null,
                'status'                 => 'pending',
                'currency'               => $data['currency'] ?? 'TZS',
                'subtotal'               => $subtotal,
                'discount_amount'        => $discountAmount,
                'tax_amount'             => $taxAmount,
                'total_amount'           => $subtotal - $discountAmount + $taxAmount,
                'notes'                  => $data['notes'] ?? null,
                'expected_delivery_date' => $data['expected_delivery_date'] ?? null,
                'created_by'             => $request->user()->id,
            ]);

            foreach ($data['items'] as $item) {
                $order->items()->create([
                    'inventory_item_id'  => $item['inventory_item_id'] ?? null,
                    'description'        => $item['description'],
                    'unit_of_measure'    => $item['unit_of_measure'] ?? 'pcs',
                    'quantity_ordered'   => $item['quantity_ordered'],
                    'quantity_delivered' => 0,
                    'unit_price'         => $item['unit_price'],
                    'total_price'        => $item['quantity_ordered'] * $item['unit_price'],
                ]);
            }

            return (new SalesOrderResource($order->load(['createdBy', 'items.inventoryItem'])))
                ->response()->setStatusCode(201);
        });
    }

    public function show(SalesOrder $salesOrder)
    {
        $salesOrder->load(['createdBy', 'confirmedBy', 'items.inventoryItem', 'quotation']);
        return new SalesOrderResource($salesOrder);
    }

    // Confirm the order (internal confirmation)
    public function confirm(Request $request, SalesOrder $salesOrder)
    {
        abort_if($salesOrder->status !== 'pending', 422, 'Only pending orders can be confirmed.');

        $salesOrder->update([
            'status'       => 'confirmed',
            'confirmed_by' => $request->user()->id,
            'confirmed_at' => now(),
        ]);

        return new SalesOrderResource($salesOrder->fresh(['createdBy', 'confirmedBy', 'items']));
    }

    // Deliver items — per-item quantities, auto-issues stock movements
    public function deliver(Request $request, SalesOrder $salesOrder)
    {
        abort_if(!in_array($salesOrder->status, ['confirmed', 'delivering']), 422, 'Order must be confirmed before delivery.');

        $data = $request->validate([
            'items'                           => 'required|array|min:1',
            'items.*.sales_order_item_id'     => 'required|exists:sales_order_items,id',
            'items.*.quantity_delivered'      => 'required|integer|min:1',
            'notes'                           => 'nullable|string',
        ]);

        return DB::transaction(function () use ($data, $request, $salesOrder) {
            $allDelivered = true;

            foreach ($data['items'] as $delivered) {
                $soItem = $salesOrder->items()->findOrFail($delivered['sales_order_item_id']);

                $newQtyDelivered = $soItem->quantity_delivered + $delivered['quantity_delivered'];
                $soItem->update(['quantity_delivered' => $newQtyDelivered]);

                if ($newQtyDelivered < $soItem->quantity_ordered) {
                    $allDelivered = false;
                }

                // Issue stock movement for inventory-linked items
                if ($soItem->inventory_item_id) {
                    $item   = $soItem->inventoryItem()->lockForUpdate()->first();
                    $before = $item->stock_qty;
                    $after  = max(0, $before - $delivered['quantity_delivered']);

                    StockMovement::create([
                        'inventory_item_id' => $item->id,
                        'type'              => 'issue',
                        'quantity'          => $delivered['quantity_delivered'],
                        'quantity_before'   => $before,
                        'quantity_after'    => $after,
                        'unit_cost'         => $soItem->unit_price,
                        'currency'          => $salesOrder->currency,
                        'reference_type'    => 'sales_order',
                        'reference_id'      => $salesOrder->id,
                        'notes'             => $data['notes'] ?? "Issued via {$salesOrder->order_number}",
                        'performed_by'      => $request->user()->id,
                    ]);

                    $item->update(['stock_qty' => $after]);
                }
            }

            $salesOrder->update([
                'status'       => $allDelivered ? 'delivered' : 'delivering',
                'delivered_at' => $allDelivered ? now() : null,
            ]);

            return new SalesOrderResource($salesOrder->load(['createdBy', 'confirmedBy', 'items.inventoryItem']));
        });
    }

    public function cancel(SalesOrder $salesOrder)
    {
        abort_if(in_array($salesOrder->status, ['delivered']), 422, 'Delivered orders cannot be cancelled.');
        $salesOrder->update(['status' => 'cancelled']);
        return new SalesOrderResource($salesOrder->fresh(['createdBy', 'items']));
    }

    // Create an invoice from a delivered Sales Order
    public function createInvoice(Request $request, SalesOrder $salesOrder)
    {
        abort_if($salesOrder->status !== 'delivered', 422, 'Only delivered orders can be invoiced.');

        if (Invoice::where('sales_order_id', $salesOrder->id)->exists()) {
            return response()->json(['message' => 'An invoice already exists for this Sales Order.'], 422);
        }

        $invoice = DB::transaction(function () use ($salesOrder, $request) {
            $year = date('Y');
            $last = Invoice::where('invoice_number', 'like', "INV-$year-%")
                           ->orderByDesc('id')->value('invoice_number');
            $seq  = $last ? ((int) substr($last, -4) + 1) : 1;
            $invNumber = 'INV-' . $year . '-' . str_pad($seq, 4, '0', STR_PAD_LEFT);

            $inv = Invoice::create([
                'invoice_number'  => $invNumber,
                'sales_order_id'  => $salesOrder->id,
                'client_name'     => $salesOrder->client_name,
                'client_contact'  => $salesOrder->client_contact,
                'issue_date'      => now()->toDateString(),
                'due_date'        => now()->addDays(30)->toDateString(),
                'subtotal'        => $salesOrder->subtotal,
                'tax_rate'        => 0,
                'tax_amount'      => $salesOrder->tax_amount,
                'total'           => $salesOrder->total_amount,
                'amount_paid'     => 0,
                'status'          => 'pending',
                'currency'        => $salesOrder->currency,
                'notes'           => $salesOrder->notes,
            ]);

            foreach ($salesOrder->items as $soItem) {
                $inv->lineItems()->create([
                    'description' => $soItem->description,
                    'quantity'    => $soItem->quantity_ordered,
                    'unit_price'  => $soItem->unit_price,
                    'total'       => $soItem->total_price,
                ]);
            }

            return $inv;
        });

        return response()->json([
            'data' => new InvoiceResource($invoice->load(['lineItems', 'salesOrder', 'payments'])),
        ], 201);
    }
}
