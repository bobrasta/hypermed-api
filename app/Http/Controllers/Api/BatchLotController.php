<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\BatchLotResource;
use App\Models\BatchLot;
use App\Models\InventoryItem;
use App\Models\Location;
use App\Services\StockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BatchLotController extends Controller
{
    /**
     * What's left of lot `b`: its counted quantity less what invoices (not
     * drafts, proformas or cancelled) sold from it since it was counted
     * (qty_counted_at, or when the lot was recorded).
     */
    private const LEFT_SQL = <<<'SQL'
        b.qty_remaining - coalesce((
          select sum(l.quantity) from invoice_line_items l join invoices i on i.id = l.invoice_id
          where l.inventory_item_id = b.inventory_item_id
            and upper(trim(l.lot_number)) = upper(trim(b.lot_number))
            and i.status not in ('draft', 'proforma', 'cancelled')
            and i.created_at > coalesce(b.qty_counted_at, b.created_at)
        ), 0)
        SQL;

    /**
     * Every unexpired lot that still has stock, across the catalog, for the
     * sale / invoice / quotation product search. Same item + lot + expiry
     * recorded twice (Clickhuduma did) shows once.
     */
    public function open()
    {
        $left = self::LEFT_SQL;
        $rows = DB::select(<<<SQL
            with lots as (
              select b.inventory_item_id, b.lot_number, b.expiry_date, {$left} qty_left
              from batch_lots b
              where coalesce(trim(b.lot_number), '') <> '' and (b.expiry_date is null or b.expiry_date >= current_date)
            )
            select inventory_item_id, lot_number, expiry_date, sum(qty_left)::int qty_left
            from lots group by 1, 2, 3 having sum(qty_left) > 0
            order by inventory_item_id, expiry_date nulls last, lot_number
            SQL);

        return response()->json(['data' => array_map(fn ($r) => [
            'inventory_item_id' => (int) $r->inventory_item_id,
            'lot_number'        => $r->lot_number,
            'expiry_date'       => $r->expiry_date ? substr((string) $r->expiry_date, 0, 10) : null,
            'qty_left'          => (int) $r->qty_left,
        ], $rows)]);
    }

    /** The item's lots, earliest expiry first, each with what's left of it. */
    public function index(InventoryItem $inventoryItem)
    {
        $lots = BatchLot::query()
            ->from('batch_lots as b')
            ->where('b.inventory_item_id', $inventoryItem->id)
            ->select('b.*')
            ->selectRaw('greatest(' . self::LEFT_SQL . ', 0)::int as qty_left')
            ->with('supplier')
            ->orderByRaw('b.expiry_date asc nulls last')
            ->orderBy('b.lot_number')
            ->get();

        return BatchLotResource::collection($lots)->additional(['meta' => [
            'total_left' => (int) $lots->sum('qty_left'),
            'stock_qty'  => (int) $inventoryItem->stock_qty,
        ]]);
    }

    /** A new lot: its quantity goes into stock at the item's store. */
    public function store(Request $request, InventoryItem $inventoryItem, StockService $stock)
    {
        abort_if(! $request->user()->hasStockAdjustAuthority(), 403, 'You are not authorised to change stock.');

        $data = $request->validate([
            'lot_number'        => ['required', 'string', 'max:100'],
            'expiry_date'       => ['nullable', 'date'],
            'manufactured_date' => ['nullable', 'date'],
            'quantity'          => ['required', 'integer', 'min:1'],
            'supplier_id'       => ['nullable', 'exists:suppliers,id'],
            'unit_cost'         => ['nullable', 'integer', 'min:0'],
            'currency'          => ['nullable', 'string', 'max:10'],
            'received_at'       => ['nullable', 'date'],
            'notes'             => ['nullable', 'string'],
        ]);
        $lotNo = trim($data['lot_number']);
        abort_if($this->lotTaken($inventoryItem, $lotNo), 422, "Lot {$lotNo} is already recorded for this item — edit it instead.");

        $qty = (int) $data['quantity'];
        unset($data['quantity']);

        $lot = DB::transaction(function () use ($data, $lotNo, $qty, $inventoryItem, $stock) {
            $lot = BatchLot::create([
                ...$data,
                'inventory_item_id' => $inventoryItem->id,
                'batch_number'      => $lotNo,
                'lot_number'        => $lotNo,
                'qty_received'      => $qty,
                'qty_remaining'     => $qty,
                'qty_counted_at'    => now(),
                'received_at'       => $data['received_at'] ?? now()->toDateString(),
            ]);
            $stock->adjustBy($inventoryItem, $this->storeFor($inventoryItem), $qty, "Lot {$lotNo} added");
            return $lot;
        });

        return (new BatchLotResource($this->withLeft($lot)))->response()->setStatusCode(201);
    }

    /**
     * Correct a lot. A quantity sent is what is on the shelf now; stock moves
     * by the difference from what was left.
     */
    public function update(Request $request, InventoryItem $inventoryItem, BatchLot $batchLot, StockService $stock)
    {
        abort_if($batchLot->inventory_item_id !== $inventoryItem->id, 404);
        abort_if(! $request->user()->hasStockAdjustAuthority(), 403, 'You are not authorised to change stock.');

        $data = $request->validate([
            'lot_number'        => ['sometimes', 'string', 'max:100'],
            'expiry_date'       => ['nullable', 'date'],
            'manufactured_date' => ['nullable', 'date'],
            'quantity'          => ['sometimes', 'integer', 'min:0'],
            'supplier_id'       => ['nullable', 'exists:suppliers,id'],
            'unit_cost'         => ['nullable', 'integer', 'min:0'],
            'currency'          => ['nullable', 'string', 'max:10'],
            'received_at'       => ['nullable', 'date'],
            'notes'             => ['nullable', 'string'],
        ]);

        if (isset($data['lot_number'])) {
            $lotNo = trim($data['lot_number']);
            abort_if($this->lotTaken($inventoryItem, $lotNo, $batchLot->id), 422, "Lot {$lotNo} is already recorded for this item.");
            $data['lot_number'] = $data['batch_number'] = $lotNo;
        }

        DB::transaction(function () use ($data, $batchLot, $inventoryItem, $stock) {
            $before = $this->leftOf($batchLot);
            $qty = null;
            if (array_key_exists('quantity', $data)) {
                $qty = (int) $data['quantity'];
                $data['qty_remaining']  = $qty;
                $data['qty_received']   = max((int) $batchLot->qty_received, $qty);
                $data['qty_counted_at'] = now();
                unset($data['quantity']);
            }
            $batchLot->update($data);
            if ($qty !== null && $qty !== $before) {
                $this->moveStock($stock, $inventoryItem, $qty - $before, "Lot {$batchLot->lot_number}: {$before} → {$qty}");
            }
        });

        return new BatchLotResource($this->withLeft($batchLot->fresh()));
    }

    /** Remove a lot recorded by mistake; what was left of it comes off stock. */
    public function destroy(Request $request, InventoryItem $inventoryItem, BatchLot $batchLot, StockService $stock)
    {
        abort_if($batchLot->inventory_item_id !== $inventoryItem->id, 404);
        abort_if(! $request->user()->hasStockAdjustAuthority(), 403, 'You are not authorised to change stock.');

        DB::transaction(function () use ($batchLot, $inventoryItem, $stock) {
            $left = $this->leftOf($batchLot);
            $batchLot->delete();
            if ($left > 0) {
                $this->moveStock($stock, $inventoryItem, -$left, "Lot {$batchLot->lot_number} removed");
            }
        });

        return response()->noContent();
    }

    private function lotTaken(InventoryItem $item, string $lotNo, ?int $exceptId = null): bool
    {
        return BatchLot::where('inventory_item_id', $item->id)
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
            ->whereRaw('upper(trim(lot_number)) = ?', [mb_strtoupper($lotNo)])
            ->exists();
    }

    private function leftOf(BatchLot $lot): int
    {
        $v = DB::selectOne('select greatest(' . self::LEFT_SQL . ', 0)::int v from batch_lots b where b.id = ?', [$lot->id]);
        return (int) ($v->v ?? 0);
    }

    private function withLeft(BatchLot $lot): BatchLot
    {
        $lot->setAttribute('qty_left', $this->leftOf($lot));
        return $lot->load('supplier');
    }

    /** Never takes the store below zero (item stock and lots can disagree). */
    private function moveStock(StockService $stock, InventoryItem $item, int $delta, string $reason): void
    {
        $location = $this->storeFor($item);
        if ($delta < 0) {
            $onHand = (int) $item->stockLevels()->where('location_id', $location->id)->value('quantity_on_hand');
            $delta = -min(-$delta, max($onHand, 0));
        }
        if ($delta !== 0) {
            $stock->adjustBy($item, $location, $delta, $reason);
        }
    }

    /** The store the item's stock sits in (most stock), else the first active store. */
    private function storeFor(InventoryItem $item): Location
    {
        $id = $item->stockLevels()->orderByDesc('quantity_on_hand')->value('location_id');
        return ($id ? Location::find($id) : null)
            ?? Location::where('is_active', true)->orderBy('id')->firstOrFail();
    }
}
