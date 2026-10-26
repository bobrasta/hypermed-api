<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\FiltersByPeriod;
use App\Http\Controllers\Controller;
use App\Models\PurchaseOrder;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnItem;
use App\Services\Creditors\CreditorService;
use App\Services\DocumentNumberService;
use App\Services\DocumentPdfService;
use App\Services\StockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Purchase returns (Clickhuduma's "List Purchase Return"): goods sent back
 * to the supplier out of a received purchase. Takes the stock out of the
 * store it was received into and, when the order's supplier invoice is on
 * the books, credits the supplier's account (CreditorService).
 */
class PurchaseReturnController extends Controller
{
    use FiltersByPeriod;

    private const RELATIONS = ['supplier:id,name,currency', 'location:id,name', 'purchaseOrder:id,po_number,currency,total_amount', 'createdBy:id,name', 'items.inventoryItem:id,name,sku'];

    private function canRecord(Request $request): bool
    {
        return $request->user()->hasPurchaseCreateAuthority() || $request->user()->hasLogisticsReceiveAuthority();
    }

    public function index(Request $request)
    {
        abort_if(! $request->user()->hasPurchaseOrderReadAuthority(), 403, 'Not authorised.');

        $returns = PurchaseReturn::with(self::RELATIONS)
            ->when($request->supplier_id, fn ($q, $id) => $q->where('supplier_id', $id))
            ->when($request->purchase_order_id, fn ($q, $id) => $q->where('purchase_order_id', $id))
            ->tap(fn ($q) => $this->applyPeriod($q, $request, 'return_date'))
            ->latest('return_date')->latest('id')
            ->paginate($this->perPage($request, 25));

        return response()->json([
            'data' => $returns->items(),
            'meta' => [
                'current_page' => $returns->currentPage(),
                'last_page'    => $returns->lastPage(),
                'total'        => $returns->total(),
            ],
        ]);
    }

    public function show(Request $request, PurchaseReturn $purchaseReturn)
    {
        abort_if(! $request->user()->hasPurchaseOrderReadAuthority(), 403, 'Not authorised.');

        return response()->json(['data' => $purchaseReturn->load(self::RELATIONS)]);
    }

    public function store(Request $request, DocumentNumberService $numbers, StockService $stock, CreditorService $creditors)
    {
        abort_if(! $this->canRecord($request), 403, 'You are not authorised to record purchase returns.');

        $data = $request->validate([
            'purchase_order_id'               => 'required|exists:purchase_orders,id',
            'return_date'                     => 'nullable|date|before_or_equal:today',
            'reason'                          => 'nullable|string|max:2000',
            'items'                           => 'required|array|min:1',
            'items.*.purchase_order_item_id'  => 'required|integer|distinct',
            'items.*.quantity'                => 'required|integer|min:1',
        ]);

        $po = PurchaseOrder::with(['items.inventoryItem', 'location', 'supplier'])->findOrFail($data['purchase_order_id']);
        abort_if(! in_array($po->status, ['partially_received', 'received'], true), 422, 'Only goods already received can be returned.');
        abort_if(! $po->location, 422, 'This purchase has no store to take the goods out of.');

        $return = DB::transaction(function () use ($data, $po, $request, $numbers, $stock) {
            $return = PurchaseReturn::create([
                'return_number'     => $numbers->next('purchase_return'),
                'purchase_order_id' => $po->id,
                'supplier_id'       => $po->supplier_id,
                'location_id'       => $po->location_id,
                'return_date'       => $data['return_date'] ?? now()->toDateString(),
                'currency'          => strtoupper((string) $po->currency ?: 'TZS'),
                'reason'            => $data['reason'] ?? null,
                'created_by'        => $request->user()->id,
            ]);

            $total = 0;
            foreach ($data['items'] as $line) {
                $poItem = $po->items->firstWhere('id', $line['purchase_order_item_id']);
                abort_if(! $poItem, 422, 'A returned line is not on this purchase.');
                // Lock the order line so two returns can't both take the last units.
                $poItem = $poItem->newQuery()->lockForUpdate()->find($poItem->id);
                $already = (int) PurchaseReturnItem::where('purchase_order_item_id', $poItem->id)->sum('quantity');
                $left = $poItem->quantity_received - $already;
                $name = $po->items->firstWhere('id', $poItem->id)->inventoryItem?->name ?? 'an item';
                abort_if($line['quantity'] > $left, 422, "Only {$left} of {$name} can still be returned (received {$poItem->quantity_received}, returned {$already}).");

                $lineTotal = $line['quantity'] * (int) $poItem->unit_cost;
                $return->items()->create([
                    'purchase_order_item_id' => $poItem->id,
                    'inventory_item_id'      => $poItem->inventory_item_id,
                    'quantity'               => $line['quantity'],
                    'unit_cost'              => $poItem->unit_cost,
                    'total'                  => $lineTotal,
                ]);
                $total += $lineTotal;

                $stock->deduct(
                    $poItem->inventoryItem,
                    $po->location,
                    $line['quantity'],
                    "Returned to {$po->supplier->name} ({$return->return_number}, {$po->po_number})",
                    $return,
                    type: 'supplier_return',
                );
            }
            $return->update(['total_amount' => $total]);

            return $return;
        });

        $creditors->postPurchaseReturn($return->fresh(['purchaseOrder.supplier']));
        $creditors->refreshPurchaseOrder($po->fresh());

        return response()->json(['data' => $return->fresh(self::RELATIONS)], 201);
    }

    public function pdf(Request $request, PurchaseReturn $purchaseReturn, DocumentPdfService $pdf)
    {
        abort_if(! $request->user()->hasPurchaseOrderReadAuthority(), 403, 'Not authorised.');

        return $pdf->purchaseReturnPdf($purchaseReturn);
    }
}
