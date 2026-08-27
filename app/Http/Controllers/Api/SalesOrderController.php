<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\InvoiceResource;
use App\Http\Resources\SalesOrderResource;
use App\Models\AppNotification;
use App\Models\Invoice;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\ApprovalService;
use App\Services\CreditCheckService;
use App\Services\FinancePostingService;
use App\Services\MachineRegistrationService;
use App\Services\StockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SalesOrderController extends Controller
{
    public function index(Request $request)
    {
        $orders = SalesOrder::with(['createdBy', 'deliveredBy', 'quotation', 'location', 'hospital', 'commissionAgent'])
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->search, fn ($q, $s) => $q->where(function ($q) use ($s) {
                $q->where('client_name', 'like', "%$s%")
                  ->orWhere('order_number', 'like', "%$s%");
            }))
            ->latest()
            ->paginate(25);

        return SalesOrderResource::collection($orders);
    }

    public function store(Request $request, ApprovalService $approval)
    {
        $data = $request->validate([
            'client_name'               => 'required|string|max:200',
            'client_contact'            => 'nullable|string|max:200',
            'hospital_id'               => 'nullable|exists:hospitals,id',
            'location_id'               => 'required|exists:locations,id',
            'currency'                  => 'nullable|string|max:10',
            'discount_amount'           => 'nullable|integer|min:0',
            'tax_amount'                => 'nullable|integer|min:0',
            'expected_delivery_date'    => 'nullable|date',
            'commission_agent_id'       => 'nullable|exists:users,id',
            'notes'                     => 'nullable|string',
            'items'                     => 'required|array|min:1',
            'items.*.inventory_item_id' => 'nullable|exists:inventory_items,id',
            'items.*.description'       => 'required|string|max:300',
            'items.*.unit_of_measure'   => 'nullable|string|max:30',
            'items.*.quantity_ordered'  => 'required|integer|min:1',
            'items.*.unit_price'        => 'required|integer|min:0',
        ]);

        return DB::transaction(function () use ($data, $request, $approval) {
            $subtotal = collect($data['items'])->sum(fn ($i) => $i['quantity_ordered'] * $i['unit_price']);
            $discountAmount = $data['discount_amount'] ?? 0;
            $taxAmount      = $data['tax_amount'] ?? 0;
            $totalAmount    = $subtotal - $discountAmount + $taxAmount;

            $approvalFields = $approval->evaluate($request->user(), $subtotal, $discountAmount, $totalAmount);

            $year  = now()->format('Y');
            $count = SalesOrder::whereYear('created_at', $year)->count() + 1;

            $order = SalesOrder::create([
                'order_number'           => 'SO-' . $year . '-' . str_pad($count, 4, '0', STR_PAD_LEFT),
                'client_name'            => $data['client_name'],
                'client_contact'         => $data['client_contact'] ?? null,
                'hospital_id'            => $data['hospital_id'] ?? null,
                'location_id'            => $data['location_id'],
                'status'                 => 'pending',
                'currency'               => $data['currency'] ?? 'TZS',
                'subtotal'               => $subtotal,
                'discount_amount'        => $discountAmount,
                'tax_amount'             => $taxAmount,
                'total_amount'           => $totalAmount,
                'notes'                  => $data['notes'] ?? null,
                'expected_delivery_date' => $data['expected_delivery_date'] ?? null,
                'created_by'             => $request->user()->id,
                'commission_agent_id'    => $data['commission_agent_id'] ?? $request->user()->id,
                ...$approvalFields,
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
        $salesOrder->load(['createdBy', 'confirmedBy', 'deliveredBy', 'items.inventoryItem', 'quotation', 'location', 'hospital', 'commissionAgent']);
        return new SalesOrderResource($salesOrder);
    }

    // Confirm the order (internal confirmation)
    public function confirm(Request $request, SalesOrder $salesOrder)
    {
        abort_if($salesOrder->status !== 'pending', 422, 'Only pending orders can be confirmed.');
        abort_if($salesOrder->approval_status === 'pending', 422,
            'This order needs manager approval before it can be confirmed: ' . $salesOrder->approval_reason);
        abort_if($salesOrder->approval_status === 'rejected', 422,
            'This order was rejected and cannot be confirmed: ' . $salesOrder->rejection_reason);

        // Lock the commission rate + amount now, from whatever the agent's rate
        // is at this exact moment. Deliberately never recalculated afterwards —
        // if the agent's commission_percent changes later, this order is unaffected.
        $agent = $salesOrder->commissionAgent ?? $salesOrder->createdBy;
        $commissionPercent = $agent?->commission_percent;
        $commissionAmount  = $commissionPercent !== null
            ? (int) round($salesOrder->total_amount * $commissionPercent / 100)
            : null;

        $salesOrder->update([
            'status'              => 'confirmed',
            'confirmed_by'        => $request->user()->id,
            'confirmed_at'        => now(),
            'commission_percent'  => $commissionPercent,
            'commission_amount'   => $commissionAmount,
        ]);

        $this->notifyStorekeepers($salesOrder);

        return new SalesOrderResource($salesOrder->fresh(['createdBy', 'confirmedBy', 'commissionAgent', 'items', 'location', 'hospital']));
    }

    // Confirmed = real and ready to pick — storekeepers had no signal that a
    // new order needed pulling before this; deliver() is when they record
    // what actually shipped, this is what tells them to start.
    private function notifyStorekeepers(SalesOrder $salesOrder): void
    {
        User::where('role', 'storekeeper')
            ->pluck('id')
            ->each(fn ($id) => AppNotification::create([
                'user_id'     => $id,
                'type'        => 'stock_pull_required',
                'title'       => 'Order Ready to Pack',
                'body'        => "{$salesOrder->order_number} for {$salesOrder->client_name} is confirmed — ready stock for delivery.",
                'entity_type' => 'sales_order',
                'entity_id'   => $salesOrder->id,
                'is_read'     => false,
            ]));
    }

    // Manager approves a discount/value that exceeded the creator's limits
    public function approve(Request $request, SalesOrder $salesOrder)
    {
        abort_if(! $request->user()->hasSalesApprovalAuthority(), 403, 'You are not authorised to approve sales orders.');
        abort_if($salesOrder->approval_status !== 'pending', 422, 'This order is not pending approval.');

        $salesOrder->update([
            'approval_status' => 'approved',
            'approved_by'     => $request->user()->id,
            'approved_at'     => now(),
        ]);

        return new SalesOrderResource($salesOrder->fresh(['createdBy', 'approvedBy', 'items']));
    }

    // Manager rejects — the order cannot be confirmed until re-submitted differently
    public function rejectApproval(Request $request, SalesOrder $salesOrder)
    {
        abort_if(! $request->user()->hasSalesApprovalAuthority(), 403, 'You are not authorised to reject sales orders.');
        abort_if($salesOrder->approval_status !== 'pending', 422, 'This order is not pending approval.');

        $data = $request->validate(['rejection_reason' => 'nullable|string']);

        $salesOrder->update([
            'approval_status'  => 'rejected',
            'approved_by'      => $request->user()->id,
            'approved_at'      => now(),
            'rejection_reason' => $data['rejection_reason'] ?? null,
        ]);

        return new SalesOrderResource($salesOrder->fresh(['createdBy', 'approvedBy', 'items']));
    }

    // Deliver items — per-item quantities, auto-issues stock movements
    public function deliver(Request $request, SalesOrder $salesOrder, StockService $stockService, MachineRegistrationService $machineRegistration)
    {
        abort_if(! $request->user()->hasLogisticsDeliverAuthority(), 403, 'You are not authorised to deliver sales orders.');
        abort_if(!in_array($salesOrder->status, ['confirmed', 'delivering']), 422, 'Order must be confirmed before delivery.');
        abort_if(!$salesOrder->location_id, 422, 'This sales order has no source location set.');

        $data = $request->validate([
            'items'                           => 'required|array|min:1',
            'items.*.sales_order_item_id'     => 'required|exists:sales_order_items,id',
            'items.*.quantity_delivered'      => 'required|integer|min:1',
            'notes'                           => 'nullable|string',
        ]);

        return DB::transaction(function () use ($data, $salesOrder, $stockService, $machineRegistration, $request) {
            $allDelivered = true;
            $location = $salesOrder->location;
            $machinesCreated = [];

            foreach ($data['items'] as $delivered) {
                $soItem = $salesOrder->items()->findOrFail($delivered['sales_order_item_id']);
                $qty = $delivered['quantity_delivered'];

                $priorQtyDelivered = $soItem->quantity_delivered;
                $newQtyDelivered   = $priorQtyDelivered + $qty;
                $soItem->update(['quantity_delivered' => $newQtyDelivered]);

                if ($newQtyDelivered < $soItem->quantity_ordered) {
                    $allDelivered = false;
                }

                // Issue stock movement for inventory-linked items
                if ($soItem->inventory_item_id) {
                    $stockService->deduct(
                        $soItem->inventoryItem,
                        $location,
                        $qty,
                        $data['notes'] ?? "Issued via {$salesOrder->order_number}",
                        $salesOrder,
                    );
                }

                if ($salesOrder->hospital_id && $soItem->inventoryItem) {
                    $machinesCreated = array_merge($machinesCreated, $machineRegistration->registerForDelivery(
                        $soItem->inventoryItem, $salesOrder->hospital_id, $salesOrder->order_number,
                        $priorQtyDelivered, $qty, $salesOrder->id,
                    ));
                }
            }

            $salesOrder->update([
                'status'       => $allDelivered ? 'delivered' : 'delivering',
                'delivered_at' => $allDelivered ? now() : null,
                'delivered_by' => $allDelivered ? $request->user()->id : null,
            ]);

            return response()->json([
                'data' => new SalesOrderResource($salesOrder->load(['createdBy', 'confirmedBy', 'deliveredBy', 'items.inventoryItem', 'location', 'hospital'])),
                'machines_created' => collect($machinesCreated)->map(fn ($m) => [
                    'id' => $m->id, 'serial_no' => $m->serial_no, 'model' => $m->model,
                ]),
            ]);
        });
    }

    public function cancel(SalesOrder $salesOrder)
    {
        abort_if(in_array($salesOrder->status, ['delivered']), 422, 'Delivered orders cannot be cancelled.');
        $salesOrder->update(['status' => 'cancelled']);
        return new SalesOrderResource($salesOrder->fresh(['createdBy', 'items']));
    }

    // Create an invoice covering whatever has been delivered but not yet invoiced.
    // Can be called more than once on the same Sales Order as partial deliveries
    // come in — each call only bills the newly-delivered, not-yet-invoiced quantity.
    public function createInvoice(Request $request, SalesOrder $salesOrder, CreditCheckService $creditCheck, FinancePostingService $financePosting)
    {
        abort_if(!in_array($salesOrder->status, ['delivering', 'delivered']), 422,
            'Order must have at least a partial delivery before it can be invoiced.');

        $salesOrder->load('items');
        $billableItems = $salesOrder->items->filter(
            fn ($item) => $item->quantity_delivered > $item->quantity_invoiced
        );

        abort_if($billableItems->isEmpty(), 422, 'Nothing new to invoice — all delivered quantity has already been invoiced.');

        $lineSubtotal = $billableItems->sum(fn ($item) =>
            ($item->quantity_delivered - $item->quantity_invoiced) * $item->unit_price
        );

        // Prorate the order-level discount/tax by how much of the order's value
        // this particular invoice covers, so partial invoices split them fairly.
        $proportion  = $salesOrder->subtotal > 0 ? $lineSubtotal / $salesOrder->subtotal : 1;
        $discount    = (int) round($salesOrder->discount_amount * $proportion);
        $taxAmount   = (int) round($salesOrder->tax_amount * $proportion);
        $invoiceTotal = $lineSubtotal - $discount + $taxAmount;

        $creditCheck->assertWithinLimit($salesOrder->hospital, $invoiceTotal);

        $invoice = DB::transaction(function () use ($salesOrder, $billableItems, $lineSubtotal, $taxAmount, $invoiceTotal, $financePosting) {
            $year = date('Y');
            $last = Invoice::where('invoice_number', 'like', "INV-$year-%")
                           ->orderByDesc('id')->value('invoice_number');
            $seq  = $last ? ((int) substr($last, -4) + 1) : 1;
            $invNumber = 'INV-' . $year . '-' . str_pad($seq, 4, '0', STR_PAD_LEFT);

            $inv = Invoice::create([
                'invoice_number'  => $invNumber,
                'sales_order_id'  => $salesOrder->id,
                'hospital_id'     => $salesOrder->hospital_id,
                'client_name'     => $salesOrder->client_name,
                'client_contact'  => $salesOrder->client_contact,
                'issue_date'      => now()->toDateString(),
                'due_date'        => now()->addDays(30)->toDateString(),
                'subtotal'        => $lineSubtotal,
                'tax_rate'        => 0,
                'tax_amount'      => $taxAmount,
                'total'           => $invoiceTotal,
                'amount_paid'     => 0,
                'status'          => 'pending',
                'currency'        => $salesOrder->currency,
                'notes'           => $salesOrder->notes,
            ]);

            foreach ($billableItems as $soItem) {
                $qty = $soItem->quantity_delivered - $soItem->quantity_invoiced;

                $inv->lineItems()->create([
                    'description' => $soItem->description,
                    'quantity'    => $qty,
                    'unit_price'  => $soItem->unit_price,
                    'total'       => $qty * $soItem->unit_price,
                ]);

                $soItem->update(['quantity_invoiced' => $soItem->quantity_invoiced + $qty]);
            }

            $financePosting->postInvoiceIssued($inv);

            return $inv;
        });

        return response()->json([
            'data' => new InvoiceResource($invoice->load(['lineItems', 'salesOrder', 'payments'])),
        ], 201);
    }
}
