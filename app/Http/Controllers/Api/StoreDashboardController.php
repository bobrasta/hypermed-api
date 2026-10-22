<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\InventoryItem;
use App\Models\Machine;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\StockMovement;
use App\Models\StockOutRequest;
use App\Services\EffectivePermissionResolver;
use Illuminate\Http\Request;

/**
 * The storekeeper's dashboard (main Dashboard, storekeeper role — see the
 * app's UnifiedDashboardScreen / the web DashboardController): stock at a
 * glance, what is waiting on the store (POs to receive, stock-out requests,
 * requisitions, machines in stock), recent movements and where the stock
 * value sits. One call, live data (every action on the store changes it).
 */
class StoreDashboardController extends Controller
{
    public function index(Request $request, EffectivePermissionResolver $permissions)
    {
        abort_unless($permissions->can($request->user(), 'screens.inventory'), 403, 'You do not have access to the store dashboard.');

        $active = InventoryItem::where('is_active', true);
        // Low = at or under a reorder level that has been set; items with no
        // reorder level count as out of stock only when they reach 0.
        $low = (clone $active)->where('reorder_level', '>', 0)->whereColumn('stock_qty', '<=', 'reorder_level');

        $toReceive = PurchaseOrder::whereIn('status', ['sent', 'acknowledged', 'partially_received']);

        return response()->json(['data' => [
            'kpis' => [
                'stock_value'     => (int) (clone $active)->selectRaw('COALESCE(SUM(stock_qty::numeric * unit_cost), 0) AS v')->value('v'),
                'items'           => (clone $active)->count(),
                'in_stock'        => (clone $active)->where('stock_qty', '>', 0)->count(),
                'out_of_stock'    => (clone $active)->where('stock_qty', '<=', 0)->count(),
                'low_stock'       => (clone $low)->count(),
                'units'           => (int) (clone $active)->where('stock_qty', '>', 0)->sum('stock_qty'),
            ],
            'waiting' => [
                'pos_to_receive'      => (clone $toReceive)->count(),
                'stock_out_pending'   => StockOutRequest::where('status', 'pending')->count(),
                'requisitions_open'   => PurchaseRequisition::whereIn('status', ['submitted', 'approved'])->count(),
                'machines_in_stock'   => Machine::where('lifecycle_stage', 'in_stock')->count(),
                'machines_allocated'  => Machine::where('lifecycle_stage', 'allocated')->count(),
            ],
            'low_stock' => (clone $low)->orderByRaw('stock_qty::numeric / NULLIF(reorder_level, 0)')->limit(10)
                ->get(['id', 'name', 'sku', 'stock_qty', 'reorder_level', 'unit_of_measure'])
                ->map(fn ($i) => ['id' => $i->id, 'name' => $i->name, 'sku' => $i->sku, 'qty' => (int) $i->stock_qty,
                    'reorder_level' => (int) $i->reorder_level, 'uom' => $i->unit_of_measure]),
            'pos_to_receive' => (clone $toReceive)->with('supplier:id,name')->withCount('items')
                ->orderByRaw('expected_delivery_date IS NULL, expected_delivery_date')->limit(6)->get()
                ->map(fn ($po) => ['id' => $po->id, 'po_number' => $po->po_number, 'supplier' => $po->supplier?->name,
                    'status' => $po->status, 'expected' => $po->expected_delivery_date?->toDateString(), 'lines' => $po->items_count]),
            'stock_out_pending' => StockOutRequest::where('status', 'pending')
                ->with(['inventoryItem:id,name', 'requester:id,name', 'location:id,name'])->oldest()->limit(6)->get()
                ->map(fn ($s) => ['id' => $s->id, 'item' => $s->inventoryItem?->name, 'qty' => (int) $s->quantity, 'type' => $s->type,
                    'requested_by' => $s->requester?->name, 'location' => $s->location?->name, 'requested_at' => $s->created_at?->toIso8601String()]),
            'machines_in_stock' => Machine::where('lifecycle_stage', 'in_stock')->latest('id')->limit(5)
                ->get(['id', 'serial_no', 'model', 'type'])
                ->map(fn ($m) => ['id' => $m->id, 'serial_no' => $m->serial_no, 'model' => $m->model, 'type' => $m->type]),
            'recent_movements' => StockMovement::with(['inventoryItem:id,name', 'location:id,name', 'performedBy:id,name'])
                ->latest('id')->limit(10)->get()
                ->map(fn ($m) => ['id' => $m->id, 'type' => $m->type, 'item' => $m->inventoryItem?->name, 'qty' => (int) $m->quantity,
                    'location' => $m->location?->name, 'by' => $m->performedBy?->name, 'notes' => $m->notes,
                    'at' => $m->created_at?->toIso8601String()]),
            // Biggest stock values first — also where a wrong count shows up.
            'top_value' => (clone $active)->where('stock_qty', '>', 0)
                ->orderByRaw('stock_qty::numeric * unit_cost DESC')->limit(8)
                ->get(['id', 'name', 'stock_qty', 'unit_cost'])
                ->map(fn ($i) => ['id' => $i->id, 'name' => $i->name, 'qty' => (int) $i->stock_qty, 'unit_cost' => (int) $i->unit_cost,
                    'value' => (int) $i->stock_qty * (int) $i->unit_cost]),
            'by_category' => Category::query()
                ->join('inventory_items', fn ($j) => $j->on('inventory_items.category_id', '=', 'categories.id')->where('inventory_items.is_active', true))
                ->groupBy('categories.id', 'categories.name')
                ->selectRaw('categories.name AS name, COUNT(*) AS items, COALESCE(SUM(inventory_items.stock_qty::numeric * inventory_items.unit_cost), 0) AS value')
                ->orderByDesc('value')->limit(8)->get()
                ->map(fn ($c) => ['name' => $c->name, 'items' => (int) $c->items, 'value' => (int) $c->value]),
        ]]);
    }
}
