<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PartCannibalizationResource;
use App\Models\PartCannibalization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PartCannibalizationController extends Controller
{
    public function index(Request $request)
    {
        $query = PartCannibalization::with([
            'sourceSerialNumber.inventoryItem',
            'partUsed.inventoryItem',
            'partUsed.ticket.machine',
            'removedBy',
            'replacementPurchaseOrder',
        ]);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('source_serial_number_id')) {
            $query->where('source_serial_number_id', $request->source_serial_number_id);
        }

        return PartCannibalizationResource::collection($query->latest('removed_at')->get());
    }

    // Mark that a replacement part has been ordered for the flagged unit —
    // doesn't clear has_missing_parts yet, the unit is still physically
    // incomplete until the replacement is actually installed (resolve()).
    public function orderReplacement(Request $request, PartCannibalization $partCannibalization)
    {
        abort_if($partCannibalization->status !== 'open', 422, 'Only open cannibalizations can move to replacement-ordered.');

        $data = $request->validate([
            'purchase_order_id' => ['nullable', 'exists:purchase_orders,id'],
        ]);

        $partCannibalization->update([
            'status'                        => 'replacement_ordered',
            'replacement_purchase_order_id' => $data['purchase_order_id'] ?? null,
            'replacement_ordered_at'        => now(),
        ]);

        return response()->json(['data' => new PartCannibalizationResource(
            $partCannibalization->load(['sourceSerialNumber.inventoryItem', 'partUsed.inventoryItem', 'partUsed.ticket.machine', 'removedBy', 'replacementPurchaseOrder'])
        )]);
    }

    // Replacement physically installed back into the source unit — clears
    // has_missing_parts on the serial number, but only if no OTHER open
    // cannibalization is still outstanding against the same unit (a unit can
    // have more than one part taken over time).
    public function resolve(Request $request, PartCannibalization $partCannibalization)
    {
        abort_if($partCannibalization->status === 'resolved', 422, 'Already resolved.');

        DB::transaction(function () use ($partCannibalization) {
            $partCannibalization->update([
                'status'                   => 'resolved',
                'replacement_received_at'  => now(),
                'resolved_at'              => now(),
            ]);

            $stillOpen = PartCannibalization::where('source_serial_number_id', $partCannibalization->source_serial_number_id)
                ->where('id', '!=', $partCannibalization->id)
                ->whereIn('status', ['open', 'replacement_ordered'])
                ->exists();

            if (! $stillOpen) {
                $partCannibalization->sourceSerialNumber?->update(['has_missing_parts' => false]);
            }
        });

        return response()->json(['data' => new PartCannibalizationResource(
            $partCannibalization->load(['sourceSerialNumber.inventoryItem', 'partUsed.inventoryItem', 'partUsed.ticket.machine', 'removedBy', 'replacementPurchaseOrder'])
        )]);
    }
}
