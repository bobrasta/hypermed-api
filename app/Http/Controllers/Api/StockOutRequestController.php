<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\StockOutRequestResource;
use App\Models\AppNotification;
use App\Models\InventoryItem;
use App\Models\Location;
use App\Models\StockOutRequest;
use App\Models\User;
use App\Services\StockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StockOutRequestController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $query = StockOutRequest::with(['inventoryItem', 'location', 'serviceTicket', 'requester', 'reviewer']);

        if (! $user->hasCtoApprovalAuthority()) {
            $query->where('requested_by', $user->id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        return StockOutRequestResource::collection($query->latest()->get());
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'inventory_item_id' => ['required', 'exists:inventory_items,id'],
            'location_id'       => ['required', 'exists:locations,id'],
            'service_ticket_id' => ['nullable', 'exists:service_tickets,id'],
            'type'              => ['required', 'in:issue,write_off'],
            'quantity'          => ['required', 'integer', 'min:1'],
            'reason'            => ['required', 'string'],
        ]);

        $data['requested_by'] = $request->user()->id;
        $data['status'] = 'pending';

        $stockOutRequest = StockOutRequest::create($data);

        $this->notifyCto($stockOutRequest);

        return response()->json(['data' => new StockOutRequestResource(
            $stockOutRequest->load(['inventoryItem', 'location', 'serviceTicket', 'requester'])
        )], 201);
    }

    public function approve(Request $request, StockOutRequest $stockOutRequest, StockService $stockService)
    {
        abort_if(! $request->user()->hasCtoApprovalAuthority(), 403, 'You are not authorised to approve stock-out requests.');
        abort_if($stockOutRequest->status !== 'pending', 422, 'Only pending requests can be approved.');

        DB::transaction(function () use ($request, $stockOutRequest, $stockService) {
            $item = InventoryItem::findOrFail($stockOutRequest->inventory_item_id);
            $location = Location::findOrFail($stockOutRequest->location_id);

            $movement = $stockService->deduct(
                $item, $location, $stockOutRequest->quantity, $stockOutRequest->reason,
                reference: $stockOutRequest, type: $stockOutRequest->type,
            );

            $stockOutRequest->update([
                'status'            => 'approved',
                'reviewed_by'       => $request->user()->id,
                'reviewed_at'       => now(),
                'stock_movement_id' => $movement->id,
            ]);
        });

        $this->notifyRequester($stockOutRequest, approved: true);

        return response()->json(['data' => new StockOutRequestResource(
            $stockOutRequest->load(['inventoryItem', 'location', 'serviceTicket', 'requester', 'reviewer'])
        )]);
    }

    public function reject(Request $request, StockOutRequest $stockOutRequest)
    {
        abort_if(! $request->user()->hasCtoApprovalAuthority(), 403, 'You are not authorised to review stock-out requests.');
        abort_if($stockOutRequest->status !== 'pending', 422, 'Only pending requests can be rejected.');

        $data = $request->validate(['rejection_reason' => ['nullable', 'string']]);

        $stockOutRequest->update([
            'status'           => 'rejected',
            'reviewed_by'      => $request->user()->id,
            'reviewed_at'      => now(),
            'rejection_reason' => $data['rejection_reason'] ?? null,
        ]);

        $this->notifyRequester($stockOutRequest, approved: false);

        return response()->json(['data' => new StockOutRequestResource(
            $stockOutRequest->load(['inventoryItem', 'location', 'serviceTicket', 'requester', 'reviewer'])
        )]);
    }

    public function cancel(Request $request, StockOutRequest $stockOutRequest)
    {
        $user = $request->user();
        abort_if($stockOutRequest->requested_by !== $user->id && ! $user->hasCtoApprovalAuthority(), 403, 'Not authorised.');
        abort_if($stockOutRequest->status !== 'pending', 422, 'Only pending requests can be cancelled.');

        $stockOutRequest->update(['status' => 'cancelled']);

        return response()->json(['data' => new StockOutRequestResource(
            $stockOutRequest->load(['inventoryItem', 'location', 'serviceTicket', 'requester'])
        )]);
    }

    private function notifyCto(StockOutRequest $stockOutRequest): void
    {
        $name = $stockOutRequest->requester?->name ?? 'A staff member';
        $item = $stockOutRequest->inventoryItem?->name ?? 'an item';

        User::whereIn('role', User::CTO_TIER)
            ->pluck('id')
            ->each(fn ($id) => AppNotification::create([
                'user_id'     => $id,
                'type'        => 'stock_out_requested',
                'title'       => 'Stock-Out Request Submitted',
                'body'        => "{$name} requested to {$stockOutRequest->type} {$stockOutRequest->quantity} x {$item}.",
                'entity_type' => 'stock_out_request',
                'entity_id'   => $stockOutRequest->id,
                'is_read'     => false,
            ]));
    }

    private function notifyRequester(StockOutRequest $stockOutRequest, bool $approved): void
    {
        $item = $stockOutRequest->inventoryItem?->name ?? 'the item';

        AppNotification::create([
            'user_id'     => $stockOutRequest->requested_by,
            'type'        => $approved ? 'stock_out_approved' : 'stock_out_rejected',
            'title'       => $approved ? 'Stock-Out Approved' : 'Stock-Out Rejected',
            'body'        => $approved
                ? "Your request to {$stockOutRequest->type} {$stockOutRequest->quantity} x {$item} was approved."
                : "Your stock-out request for {$item} was rejected." . ($stockOutRequest->rejection_reason ? " Reason: {$stockOutRequest->rejection_reason}" : ''),
            'entity_type' => 'stock_out_request',
            'entity_id'   => $stockOutRequest->id,
            'is_read'     => false,
        ]);
    }
}
