<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PurchaseRequisition;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PurchaseRequisitionController extends Controller
{
    private function nextPrNumber(): string
    {
        $year  = now()->format('Y');
        $count = PurchaseRequisition::whereYear('created_at', $year)->count() + 1;
        return 'PR-' . $year . '-' . str_pad($count, 4, '0', STR_PAD_LEFT);
    }

    public function index(Request $request)
    {
        $prs = PurchaseRequisition::with(['requestedBy', 'approvedBy', 'items.inventoryItem'])
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->latest()
            ->paginate(25);

        return response()->json($prs);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'required_by'   => 'nullable|date',
            'department'    => 'nullable|string|max:100',
            'justification' => 'nullable|string',
            'items'         => 'required|array|min:1',
            'items.*.inventory_item_id'   => 'required|exists:inventory_items,id',
            'items.*.quantity_requested'  => 'required|integer|min:1',
            'items.*.estimated_unit_cost' => 'nullable|integer|min:0',
            'items.*.currency'            => 'nullable|string|max:10',
            'items.*.notes'               => 'nullable|string',
        ]);

        return DB::transaction(function () use ($data, $request) {
            $pr = PurchaseRequisition::create([
                'pr_number'    => $this->nextPrNumber(),
                'status'       => 'draft',
                'requested_by' => $request->user()->id,
                'required_by'  => $data['required_by'] ?? null,
                'department'   => $data['department'] ?? null,
                'justification'=> $data['justification'] ?? null,
            ]);

            foreach ($data['items'] as $item) {
                $pr->items()->create($item);
            }

            return response()->json(['data' => $pr->load(['requestedBy', 'items.inventoryItem'])], 201);
        });
    }

    public function show(PurchaseRequisition $purchaseRequisition)
    {
        $purchaseRequisition->load(['requestedBy', 'approvedBy', 'items.inventoryItem', 'purchaseOrders.supplier']);

        return response()->json(['data' => $purchaseRequisition]);
    }

    public function update(Request $request, PurchaseRequisition $purchaseRequisition)
    {
        abort_if($purchaseRequisition->status !== 'draft', 422, 'Only draft requisitions can be edited.');

        $data = $request->validate([
            'required_by'   => 'nullable|date',
            'department'    => 'nullable|string|max:100',
            'justification' => 'nullable|string',
            'items'         => 'sometimes|array|min:1',
            'items.*.id'                  => 'nullable|exists:purchase_requisition_items,id',
            'items.*.inventory_item_id'   => 'required_with:items|exists:inventory_items,id',
            'items.*.quantity_requested'  => 'required_with:items|integer|min:1',
            'items.*.estimated_unit_cost' => 'nullable|integer|min:0',
            'items.*.currency'            => 'nullable|string|max:10',
            'items.*.notes'               => 'nullable|string',
        ]);

        $purchaseRequisition->update(collect($data)->except('items')->toArray());

        if (isset($data['items'])) {
            $purchaseRequisition->items()->delete();
            foreach ($data['items'] as $item) {
                $purchaseRequisition->items()->create(collect($item)->except('id')->toArray());
            }
        }

        return response()->json(['data' => $purchaseRequisition->load(['requestedBy', 'items.inventoryItem'])]);
    }

    public function submit(PurchaseRequisition $purchaseRequisition)
    {
        abort_if($purchaseRequisition->status !== 'draft', 422, 'Only draft requisitions can be submitted.');

        $purchaseRequisition->update([
            'status'       => 'submitted',
            'submitted_at' => now(),
        ]);

        return response()->json(['data' => $purchaseRequisition->fresh()]);
    }

    public function approve(Request $request, PurchaseRequisition $purchaseRequisition)
    {
        abort_if($purchaseRequisition->status !== 'submitted', 422, 'Only submitted requisitions can be approved.');

        $data = $request->validate([
            'items'                        => 'sometimes|array',
            'items.*.id'                   => 'required|exists:purchase_requisition_items,id',
            'items.*.quantity_approved'    => 'required|integer|min:0',
        ]);

        $purchaseRequisition->update([
            'status'      => 'approved',
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
        ]);

        if (isset($data['items'])) {
            foreach ($data['items'] as $item) {
                $purchaseRequisition->items()
                    ->where('id', $item['id'])
                    ->update(['quantity_approved' => $item['quantity_approved']]);
            }
        }

        return response()->json(['data' => $purchaseRequisition->load(['approvedBy', 'items.inventoryItem'])]);
    }

    public function reject(Request $request, PurchaseRequisition $purchaseRequisition)
    {
        abort_if($purchaseRequisition->status !== 'submitted', 422, 'Only submitted requisitions can be rejected.');

        $data = $request->validate([
            'rejection_reason' => 'required|string',
        ]);

        $purchaseRequisition->update([
            'status'           => 'rejected',
            'rejection_reason' => $data['rejection_reason'],
        ]);

        return response()->json(['data' => $purchaseRequisition->fresh()]);
    }
}
