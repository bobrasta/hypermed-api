<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use App\Models\ApprovalLog;
use App\Models\Location;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\User;
use App\Services\StockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PurchaseOrderController extends Controller
{
    // Every stage-approver relation, so the Flutter side can show real names
    // ("Approved by Jane") at each step without a second round-trip. Shared
    // by index/show/store and every stage-transition response below.
    private const RELATIONS = [
        'supplier', 'location', 'orderedBy', 'items.inventoryItem',
        'salesApprovedBy', 'directorReviewedBy', 'paymentInitiatedBy',
        'directorApprovedBy', 'rejectedBy',
    ];

    private function nextPoNumber(): string
    {
        $year  = now()->format('Y');
        $count = PurchaseOrder::whereYear('created_at', $year)->count() + 1;
        return 'PO-' . $year . '-' . str_pad($count, 4, '0', STR_PAD_LEFT);
    }

    public function index(Request $request)
    {
        $pos = PurchaseOrder::with(self::RELATIONS)
            ->when($request->status,      fn ($q, $s) => $q->where('status', $s))
            ->when($request->supplier_id, fn ($q, $id) => $q->where('supplier_id', $id))
            ->when($request->location_id, fn ($q, $id) => $q->where('location_id', $id))
            ->latest()
            ->paginate(25);

        return response()->json([
            'data' => $pos->items(),
            'meta' => [
                'current_page' => $pos->currentPage(),
                'last_page'    => $pos->lastPage(),
                'total'        => $pos->total(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'supplier_id'               => 'required|exists:suppliers,id',
            'location_id'               => 'required|exists:locations,id',
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
                'location_id'             => $data['location_id'],
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

            return response()->json(['data' => $po->load(self::RELATIONS)], 201);
        });
    }

    public function show(PurchaseOrder $purchaseOrder)
    {
        $purchaseOrder->load([...self::RELATIONS, 'requisition']);

        return response()->json(['data' => $purchaseOrder]);
    }

    public function update(Request $request, PurchaseOrder $purchaseOrder)
    {
        abort_if($purchaseOrder->status !== 'draft', 422, 'Only draft purchase orders can be edited.');

        $data = $request->validate([
            'supplier_id'            => 'sometimes|exists:suppliers,id',
            'location_id'            => 'sometimes|exists:locations,id',
            'expected_delivery_date' => 'nullable|date',
            'currency'               => 'nullable|string|max:10',
            'shipping_address'       => 'nullable|string',
            'terms'                  => 'nullable|string',
            'notes'                  => 'nullable|string',
        ]);

        $purchaseOrder->update($data);

        return response()->json(['data' => $purchaseOrder->fresh(['supplier', 'items.inventoryItem'])]);
    }

    // ── Approval / payment chain ────────────────────────────────────────────
    // draft -> pending_sales_manager -> pending_director_review ->
    // pending_payment_initiation -> pending_director_final -> approved.
    // Covers both reorder-driven and new-product-driven POs (see the
    // requisition's `origin` tag) — same chain either way.

    public function submitForApproval(Request $request, PurchaseOrder $purchaseOrder)
    {
        abort_if(! $request->user()->hasProcurementCreateAuthority(), 403, 'You are not authorised to submit purchase orders.');
        abort_if($purchaseOrder->status !== 'draft', 422, 'Only draft orders can be submitted for approval.');

        $purchaseOrder->update(['status' => 'pending_sales_manager']);

        $this->notifyStage($purchaseOrder, 'sales_manager', 'po_submitted', 'Purchase Order Submitted',
            "{$purchaseOrder->po_number} was submitted and needs sales review.");

        return response()->json(['data' => $purchaseOrder->fresh(self::RELATIONS)]);
    }

    public function approveSalesManager(Request $request, PurchaseOrder $purchaseOrder)
    {
        abort_if(! $request->user()->hasProcurementSalesStageAuthority(), 403, 'You are not authorised to approve this purchase order.');
        abort_if($purchaseOrder->status !== 'pending_sales_manager', 422, 'Only orders awaiting sales review can be approved at this stage.');

        $purchaseOrder->update([
            'status'            => 'pending_director_review',
            'sales_approved_by' => $request->user()->id,
            'sales_approved_at' => now(),
        ]);
        ApprovalLog::record($purchaseOrder, 'sales_approved', $request->user());

        $this->notifyStage($purchaseOrder, 'director', 'po_sales_approved', 'Purchase Order — Director Review',
            "{$purchaseOrder->po_number} passed sales review and needs director review.");
        // Visibility only — cto isn't a gate on this chain, just kept informed.
        $this->notifyStage($purchaseOrder, 'cto', 'po_sales_approved', 'Purchase Order — Director Review',
            "{$purchaseOrder->po_number} passed sales review and is now with the director.");

        return response()->json(['data' => $purchaseOrder->fresh(self::RELATIONS)]);
    }

    public function rejectSalesManager(Request $request, PurchaseOrder $purchaseOrder)
    {
        abort_if(! $request->user()->hasProcurementSalesStageAuthority(), 403, 'You are not authorised to review this purchase order.');
        abort_if($purchaseOrder->status !== 'pending_sales_manager', 422, 'Only orders awaiting sales review can be rejected at this stage.');

        $this->reject($request, $purchaseOrder);

        return response()->json(['data' => $purchaseOrder->fresh(self::RELATIONS)]);
    }

    public function approveDirectorReview(Request $request, PurchaseOrder $purchaseOrder)
    {
        abort_if(! $request->user()->hasDirectorAuthority(), 403, 'Only the director can approve this purchase order.');
        abort_if($purchaseOrder->status !== 'pending_director_review', 422, 'Only orders awaiting director review can be approved at this stage.');

        $purchaseOrder->update([
            'status'                => 'pending_payment_initiation',
            'director_reviewed_by'  => $request->user()->id,
            'director_reviewed_at'  => now(),
        ]);
        ApprovalLog::record($purchaseOrder, 'director_reviewed', $request->user());

        $this->notifyStage($purchaseOrder, 'accountant', 'po_director_reviewed', 'Purchase Order — Payment Needed',
            "{$purchaseOrder->po_number} was approved by the director and needs payment initiated.");

        return response()->json(['data' => $purchaseOrder->fresh(self::RELATIONS)]);
    }

    public function rejectDirectorReview(Request $request, PurchaseOrder $purchaseOrder)
    {
        abort_if(! $request->user()->hasDirectorAuthority(), 403, 'Only the director can reject this purchase order.');
        abort_if($purchaseOrder->status !== 'pending_director_review', 422, 'Only orders awaiting director review can be rejected at this stage.');

        $this->reject($request, $purchaseOrder);

        return response()->json(['data' => $purchaseOrder->fresh(self::RELATIONS)]);
    }

    public function initiatePayment(Request $request, PurchaseOrder $purchaseOrder)
    {
        abort_if(! $request->user()->hasAccountantAuthority(), 403, 'You are not authorised to initiate payment on this purchase order.');
        abort_if($purchaseOrder->status !== 'pending_payment_initiation', 422, 'Only orders awaiting payment initiation can be actioned at this stage.');

        $data = $request->validate([
            'amount_paid' => 'nullable|integer|min:0',
        ]);

        $purchaseOrder->update([
            'status'               => 'pending_director_final',
            'payment_initiated_by' => $request->user()->id,
            'payment_initiated_at' => now(),
            'payment_status'       => 'partial', // existing enum has no 'initiated' — 'partial' covers "payment underway"; approveDirectorFinal() moves it to 'paid'
            'amount_paid'          => $data['amount_paid'] ?? $purchaseOrder->total_amount,
        ]);
        ApprovalLog::record($purchaseOrder, 'payment_initiated', $request->user());

        $this->notifyStage($purchaseOrder, 'director', 'po_payment_initiated', 'Purchase Order — Final Approval',
            "Payment was initiated for {$purchaseOrder->po_number} — needs final director approval.");

        return response()->json(['data' => $purchaseOrder->fresh(self::RELATIONS)]);
    }

    public function approveDirectorFinal(Request $request, PurchaseOrder $purchaseOrder)
    {
        abort_if(! $request->user()->hasDirectorAuthority(), 403, 'Only the director can give final approval on this purchase order.');
        abort_if($purchaseOrder->status !== 'pending_director_final', 422, 'Only orders awaiting final director approval can be actioned at this stage.');
        abort_if($purchaseOrder->payment_initiated_by === $request->user()->id, 403, 'The same person cannot both initiate payment and give final approval on it.');

        $purchaseOrder->update([
            'status'                => 'approved',
            'director_approved_by'  => $request->user()->id,
            'director_approved_at'  => now(),
            'payment_status'        => 'paid',
        ]);
        ApprovalLog::record($purchaseOrder, 'approved', $request->user());

        $this->notifyStage($purchaseOrder, 'ordered_by', 'po_approved', 'Purchase Order Approved',
            "{$purchaseOrder->po_number} is fully approved and ready to send to the supplier.");

        return response()->json(['data' => $purchaseOrder->fresh(self::RELATIONS)]);
    }

    public function rejectDirectorFinal(Request $request, PurchaseOrder $purchaseOrder)
    {
        abort_if(! $request->user()->hasDirectorAuthority(), 403, 'Only the director can reject this purchase order.');
        abort_if($purchaseOrder->status !== 'pending_director_final', 422, 'Only orders awaiting final director approval can be rejected at this stage.');

        $this->reject($request, $purchaseOrder);

        return response()->json(['data' => $purchaseOrder->fresh(self::RELATIONS)]);
    }

    /** Shared rejection path — any stage, any authorised reviewer for that stage. */
    private function reject(Request $request, PurchaseOrder $purchaseOrder): void
    {
        $data = $request->validate(['rejection_reason' => 'nullable|string']);

        $purchaseOrder->update([
            'status'            => 'rejected',
            'rejected_by'       => $request->user()->id,
            'rejected_at'       => now(),
            'rejection_reason'  => $data['rejection_reason'] ?? null,
        ]);

        AppNotification::create([
            'user_id'     => $purchaseOrder->ordered_by,
            'type'        => 'po_rejected',
            'title'       => 'Purchase Order Rejected',
            'body'        => "{$purchaseOrder->po_number} was rejected."
                . ($purchaseOrder->rejection_reason ? " Reason: {$purchaseOrder->rejection_reason}" : ''),
            'entity_type' => 'purchase_order',
            'entity_id'   => $purchaseOrder->id,
            'is_read'     => false,
        ]);
    }

    /**
     * Fans a notification out to everyone who can act on (or, for 'cto',
     * just observe) the next stage. $target is either a role name
     * ('sales_manager', 'cto') or 'director'/'accountant' (which resolve to
     * their real authority tiers rather than a single role string, since
     * both can be held by more than one role) or 'ordered_by' (the PO's
     * original creator specifically, not a role at all).
     */
    private function notifyStage(PurchaseOrder $po, string $target, string $type, string $title, string $body): void
    {
        $recipientIds = match ($target) {
            'sales_manager' => User::where('role', 'sales_manager')->pluck('id'),
            'accountant'    => User::where('role', 'accountant')->pluck('id'),
            'director'      => User::whereIn('role', User::ADMIN_TIER)->pluck('id'),
            'cto'           => User::whereIn('role', User::CTO_TIER)->pluck('id'),
            'ordered_by'    => collect([$po->ordered_by])->filter(),
            default         => collect(),
        };

        $recipientIds->each(fn ($id) => AppNotification::create([
            'user_id'     => $id,
            'type'        => $type,
            'title'       => $title,
            'body'        => $body,
            'entity_type' => 'purchase_order',
            'entity_id'   => $po->id,
            'is_read'     => false,
        ]));
    }

    // Mark PO as sent to supplier
    public function send(PurchaseOrder $purchaseOrder)
    {
        abort_if($purchaseOrder->status !== 'approved', 422, 'Only fully-approved orders can be sent.');

        $purchaseOrder->update([
            'status'  => 'sent',
            'sent_at' => now(),
        ]);

        return response()->json(['data' => $purchaseOrder->fresh(self::RELATIONS)]);
    }

    // Goods Received Note — record received quantities and update stock
    public function receive(Request $request, PurchaseOrder $purchaseOrder, StockService $stockService)
    {
        abort_if(! $request->user()->hasLogisticsReceiveAuthority(), 403, 'You are not authorised to receive purchase orders.');
        abort_if(!in_array($purchaseOrder->status, ['sent', 'acknowledged', 'partially_received']), 422, 'Order cannot be received in its current status.');
        abort_if(!$purchaseOrder->location_id, 422, 'This purchase order has no destination location set.');

        $data = $request->validate([
            'items'                             => 'required|array|min:1',
            'items.*.purchase_order_item_id'    => 'required|exists:purchase_order_items,id',
            'items.*.quantity_received'         => 'required|integer|min:1',
            'items.*.batch_number'              => 'nullable|string|max:100',
            'items.*.expiry_date'               => 'nullable|date',
            'notes'                             => 'nullable|string',
        ]);

        return DB::transaction(function () use ($data, $purchaseOrder, $stockService) {
            $allReceived = true;
            $location = $purchaseOrder->location;

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

                $movement = $stockService->add(
                    $poItem->inventoryItem,
                    $location,
                    $received['quantity_received'],
                    $poItem->unit_cost,
                    $data['notes'] ?? "Received via {$purchaseOrder->po_number}",
                    $purchaseOrder,
                );

                if (isset($received['batch_number']) || isset($received['expiry_date'])) {
                    $movement->update([
                        'batch_number' => $received['batch_number'] ?? null,
                        'expiry_date'  => $received['expiry_date'] ?? null,
                    ]);
                }
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

        return response()->json(['data' => $purchaseOrder->fresh(self::RELATIONS)]);
    }
}
