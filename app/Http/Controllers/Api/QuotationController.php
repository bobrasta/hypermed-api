<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\QuotationResource;
use App\Http\Resources\SalesOrderResource;
use App\Models\Quotation;
use App\Models\SalesOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class QuotationController extends Controller
{
    private function nextQtNumber(): string
    {
        $year  = now()->format('Y');
        $count = Quotation::whereYear('created_at', $year)->count() + 1;
        return 'QT-' . $year . '-' . str_pad($count, 4, '0', STR_PAD_LEFT);
    }

    private function calcTotals(array $items, int $discountAmount = 0, int $taxAmount = 0): array
    {
        $subtotal = collect($items)->sum(function ($i) {
            $lineTotal = $i['quantity'] * $i['unit_price'];
            $disc      = isset($i['discount_percent']) ? $lineTotal * ($i['discount_percent'] / 100) : 0;
            return (int) round($lineTotal - $disc);
        });

        return [
            'subtotal'        => $subtotal,
            'discount_amount' => $discountAmount,
            'tax_amount'      => $taxAmount,
            'total_amount'    => $subtotal - $discountAmount + $taxAmount,
        ];
    }

    public function index(Request $request)
    {
        $quotations = Quotation::with(['createdBy'])
            ->when($request->status,      fn ($q, $s) => $q->where('status', $s))
            ->when($request->search,      fn ($q, $s) => $q->where(function ($q) use ($s) {
                $q->where('client_name', 'like', "%$s%")
                  ->orWhere('quotation_number', 'like', "%$s%");
            }))
            ->latest()
            ->paginate(25);

        return QuotationResource::collection($quotations);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'lead_id'                   => 'nullable|exists:sales_leads,id',
            'client_name'               => 'required|string|max:200',
            'client_contact'            => 'nullable|string|max:200',
            'client_email'              => 'nullable|email|max:200',
            'valid_until'               => 'nullable|date',
            'currency'                  => 'nullable|string|max:10',
            'discount_amount'           => 'nullable|integer|min:0',
            'tax_amount'                => 'nullable|integer|min:0',
            'notes'                     => 'nullable|string',
            'terms'                     => 'nullable|string',
            'items'                     => 'required|array|min:1',
            'items.*.inventory_item_id' => 'nullable|exists:inventory_items,id',
            'items.*.description'       => 'required|string|max:300',
            'items.*.unit_of_measure'   => 'nullable|string|max:30',
            'items.*.quantity'          => 'required|integer|min:1',
            'items.*.unit_price'        => 'required|integer|min:0',
            'items.*.discount_percent'  => 'nullable|numeric|min:0|max:100',
        ]);

        return DB::transaction(function () use ($data, $request) {
            $totals = $this->calcTotals(
                $data['items'],
                $data['discount_amount'] ?? 0,
                $data['tax_amount'] ?? 0,
            );

            $quotation = Quotation::create([
                'quotation_number' => $this->nextQtNumber(),
                'lead_id'          => $data['lead_id'] ?? null,
                'client_name'      => $data['client_name'],
                'client_contact'   => $data['client_contact'] ?? null,
                'client_email'     => $data['client_email'] ?? null,
                'status'           => 'draft',
                'valid_until'      => $data['valid_until'] ?? null,
                'currency'         => $data['currency'] ?? 'TZS',
                'notes'            => $data['notes'] ?? null,
                'terms'            => $data['terms'] ?? null,
                'created_by'       => $request->user()->id,
                ...$totals,
            ]);

            foreach ($data['items'] as $item) {
                $lineTotal = (int) round(
                    $item['quantity'] * $item['unit_price'] *
                    (1 - (($item['discount_percent'] ?? 0) / 100))
                );
                $quotation->items()->create([
                    'inventory_item_id' => $item['inventory_item_id'] ?? null,
                    'description'       => $item['description'],
                    'unit_of_measure'   => $item['unit_of_measure'] ?? 'pcs',
                    'quantity'          => $item['quantity'],
                    'unit_price'        => $item['unit_price'],
                    'discount_percent'  => $item['discount_percent'] ?? 0,
                    'total_price'       => $lineTotal,
                ]);
            }

            return (new QuotationResource($quotation->load(['createdBy', 'items.inventoryItem'])))
                ->response()->setStatusCode(201);
        });
    }

    public function show(Quotation $quotation)
    {
        $quotation->load(['createdBy', 'items.inventoryItem', 'lead']);
        return new QuotationResource($quotation);
    }

    public function update(Request $request, Quotation $quotation)
    {
        abort_if(!in_array($quotation->status, ['draft']), 422, 'Only draft quotations can be edited.');

        $data = $request->validate([
            'client_name'    => 'sometimes|string|max:200',
            'client_contact' => 'nullable|string|max:200',
            'client_email'   => 'nullable|email|max:200',
            'valid_until'    => 'nullable|date',
            'currency'       => 'nullable|string|max:10',
            'discount_amount'=> 'nullable|integer|min:0',
            'tax_amount'     => 'nullable|integer|min:0',
            'notes'          => 'nullable|string',
            'terms'          => 'nullable|string',
        ]);

        $quotation->update($data);

        return new QuotationResource($quotation->fresh(['createdBy', 'items']));
    }

    public function destroy(Quotation $quotation)
    {
        abort_if(!in_array($quotation->status, ['draft', 'rejected']), 422, 'Only draft or rejected quotations can be deleted.');
        $quotation->delete();
        return response()->noContent();
    }

    // Mark as sent to client
    public function send(Quotation $quotation)
    {
        abort_if($quotation->status !== 'draft', 422, 'Only draft quotations can be sent.');
        $quotation->update(['status' => 'sent', 'sent_at' => now()]);
        return new QuotationResource($quotation->fresh(['createdBy', 'items']));
    }

    // Client accepted the quotation
    public function accept(Quotation $quotation)
    {
        abort_if($quotation->status !== 'sent', 422, 'Only sent quotations can be accepted.');
        $quotation->update(['status' => 'accepted', 'accepted_at' => now()]);
        return new QuotationResource($quotation->fresh(['createdBy', 'items']));
    }

    // Client rejected the quotation
    public function reject(Quotation $quotation)
    {
        abort_if(!in_array($quotation->status, ['sent', 'accepted']), 422, 'Quotation cannot be rejected in its current status.');
        $quotation->update(['status' => 'rejected']);
        return new QuotationResource($quotation->fresh(['createdBy', 'items']));
    }

    // Convert accepted quotation to Sales Order
    public function convert(Request $request, Quotation $quotation)
    {
        abort_if($quotation->status !== 'accepted', 422, 'Only accepted quotations can be converted to a sales order.');

        $data = $request->validate([
            'expected_delivery_date' => 'nullable|date',
            'notes'                  => 'nullable|string',
        ]);

        return DB::transaction(function () use ($quotation, $data, $request) {
            $quotation->load('items');

            $year  = now()->format('Y');
            $count = SalesOrder::whereYear('created_at', $year)->count() + 1;
            $orderNumber = 'SO-' . $year . '-' . str_pad($count, 4, '0', STR_PAD_LEFT);

            $order = SalesOrder::create([
                'order_number'           => $orderNumber,
                'quotation_id'           => $quotation->id,
                'client_name'            => $quotation->client_name,
                'client_contact'         => $quotation->client_contact,
                'status'                 => 'pending',
                'currency'               => $quotation->currency,
                'subtotal'               => $quotation->subtotal,
                'discount_amount'        => $quotation->discount_amount,
                'tax_amount'             => $quotation->tax_amount,
                'total_amount'           => $quotation->total_amount,
                'notes'                  => $data['notes'] ?? $quotation->notes,
                'expected_delivery_date' => $data['expected_delivery_date'] ?? null,
                'created_by'             => $request->user()->id,
            ]);

            foreach ($quotation->items as $qi) {
                $order->items()->create([
                    'inventory_item_id' => $qi->inventory_item_id,
                    'description'       => $qi->description,
                    'unit_of_measure'   => $qi->unit_of_measure,
                    'quantity_ordered'  => $qi->quantity,
                    'quantity_delivered'=> 0,
                    'unit_price'        => $qi->unit_price,
                    'total_price'       => $qi->total_price,
                ]);
            }

            $quotation->update(['status' => 'converted']);

            return (new SalesOrderResource($order->load(['createdBy', 'items.inventoryItem', 'quotation'])))
                ->response()->setStatusCode(201);
        });
    }
}
