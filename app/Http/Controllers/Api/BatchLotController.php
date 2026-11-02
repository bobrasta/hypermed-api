<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\BatchLotResource;
use App\Models\BatchLot;
use App\Models\InventoryItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BatchLotController extends Controller
{
    /**
     * Every unexpired lot that still has stock, across the catalog, for the
     * sale / invoice / quotation product search. Left = qty_remaining when the
     * lot was recorded, less what invoices (not drafts, proformas or cancelled)
     * have sold from it since. Same item + lot + expiry recorded twice
     * (Clickhuduma does) shows once.
     */
    public function open()
    {
        $rows = DB::select(<<<'SQL'
            with sold as (
              select l.inventory_item_id, upper(trim(l.lot_number)) lot, i.created_at, l.quantity
              from invoice_line_items l join invoices i on i.id = l.invoice_id
              where l.inventory_item_id is not null and coalesce(trim(l.lot_number), '') <> ''
                and i.status not in ('draft', 'proforma', 'cancelled')
            ), lots as (
              select b.inventory_item_id, b.lot_number, b.expiry_date,
                b.qty_remaining - coalesce((select sum(s.quantity) from sold s
                  where s.inventory_item_id = b.inventory_item_id and s.lot = upper(trim(b.lot_number))
                    and s.created_at > b.created_at), 0) qty_left
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

    public function index(Request $request, InventoryItem $inventoryItem)
    {
        $lots = $inventoryItem->batchLots()
            ->with('supplier')
            ->orderByDesc('received_at')
            ->orderByDesc('created_at')
            ->get();

        return BatchLotResource::collection($lots);
    }

    public function store(Request $request, InventoryItem $inventoryItem)
    {
        $data = $request->validate([
            'batch_number'      => ['required', 'string', 'max:100'],
            'lot_number'        => ['nullable', 'string', 'max:100'],
            'expiry_date'       => ['nullable', 'date'],
            'manufactured_date' => ['nullable', 'date'],
            'qty_received'      => ['required', 'integer', 'min:1'],
            'qty_remaining'     => ['nullable', 'integer', 'min:0'],
            'supplier_id'       => ['nullable', 'exists:suppliers,id'],
            'unit_cost'         => ['nullable', 'integer', 'min:0'],
            'currency'          => ['nullable', 'string', 'max:10'],
            'received_at'       => ['nullable', 'date'],
            'notes'             => ['nullable', 'string'],
        ]);

        $data['inventory_item_id'] = $inventoryItem->id;
        $data['qty_remaining'] ??= $data['qty_received'];

        $lot = BatchLot::create($data);
        $lot->load('supplier');

        return (new BatchLotResource($lot))->response()->setStatusCode(201);
    }

    public function update(Request $request, InventoryItem $inventoryItem, BatchLot $batchLot)
    {
        abort_if($batchLot->inventory_item_id !== $inventoryItem->id, 404);

        $data = $request->validate([
            'batch_number'      => ['sometimes', 'string', 'max:100'],
            'lot_number'        => ['nullable', 'string', 'max:100'],
            'expiry_date'       => ['nullable', 'date'],
            'manufactured_date' => ['nullable', 'date'],
            'qty_remaining'     => ['nullable', 'integer', 'min:0'],
            'supplier_id'       => ['nullable', 'exists:suppliers,id'],
            'unit_cost'         => ['nullable', 'integer', 'min:0'],
            'currency'          => ['nullable', 'string', 'max:10'],
            'received_at'       => ['nullable', 'date'],
            'notes'             => ['nullable', 'string'],
        ]);

        $batchLot->update($data);
        $batchLot->load('supplier');

        return new BatchLotResource($batchLot);
    }

    public function destroy(InventoryItem $inventoryItem, BatchLot $batchLot)
    {
        abort_if($batchLot->inventory_item_id !== $inventoryItem->id, 404);
        $batchLot->delete();
        return response()->noContent();
    }
}
