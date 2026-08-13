<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\HospitalResource;
use App\Http\Resources\ServiceTicketResource;
use App\Http\Resources\QuotationResource;
use App\Http\Resources\SalesOrderResource;
use App\Models\Category;
use App\Models\Hospital;
use App\Models\InventoryItem;
use App\Models\Invoice;
use App\Models\PurchaseOrder;
use App\Models\Quotation;
use App\Models\SalesLead;
use App\Models\SalesOrder;
use App\Models\ServiceTicket;
use App\Models\StockMovement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index()
    {
        $kpi = Cache::remember('dashboard:kpi', 60, function () {
            // Two queries instead of five — PostgreSQL FILTER aggregation
            $machines = DB::selectOne("
                SELECT COUNT(*)                                         AS total,
                       COUNT(*) FILTER (WHERE status = 'operational')  AS operational
                FROM machines
            ");

            $tickets = DB::selectOne("
                SELECT COUNT(*) FILTER (WHERE status IN ('open', 'in_progress')) AS open_count,
                       COUNT(*) FILTER (WHERE status = 'overdue')                AS overdue_count
                FROM service_tickets
            ");

            $revenueThisMonth = Invoice::where('issue_date', '>=', Carbon::now()->startOfMonth()->toDateString())
                ->whereIn('status', ['paid', 'partial'])
                ->sum('amount_paid');

            return [
                'total_machines'     => (int) $machines->total,
                'operational'        => (int) $machines->operational,
                'open_tickets'       => (int) $tickets->open_count,
                'overdue_tickets'    => (int) $tickets->overdue_count,
                'revenue_this_month' => (int) $revenueThisMonth,
            ];
        });

        $recentTickets = ServiceTicket::with(['machine', 'hospital', 'assignee'])
            ->latest()
            ->limit(5)
            ->get();

        $topHospitals = Hospital::select('id', 'name', 'short_code', 'machines_operational', 'machine_count', 'revenue_monthly', 'latitude', 'longitude', 'region', 'zone')
            ->orderByDesc('revenue_monthly')
            ->limit(5)
            ->get();

        return response()->json([
            'data' => [
                'kpi'            => $kpi,
                'recent_tickets' => ServiceTicketResource::collection($recentTickets),
                'top_hospitals'  => HospitalResource::collection($topHospitals),
            ],
        ]);
    }

    public function inventory()
    {
        $totalStockValue = (int) InventoryItem::where('is_active', true)
            ->selectRaw('COALESCE(SUM(stock_qty * unit_cost), 0) AS value')
            ->value('value');

        $totalItems = InventoryItem::where('is_active', true)->count();

        $lowStockQuery = InventoryItem::where('is_active', true)
            ->whereColumn('stock_qty', '<=', 'reorder_level');

        $openPurchaseOrders = PurchaseOrder::whereNotIn('status', ['received', 'cancelled'])->count();

        $lowStockItems = (clone $lowStockQuery)
            ->orderBy('stock_qty')
            ->limit(8)
            ->get(['name', 'sku', 'reorder_level'])
            ->map(fn ($item) => [
                'name'          => $item->name,
                'sku'           => $item->sku,
                'reorder_point' => $item->reorder_level,
            ]);

        $recentMovements = StockMovement::with(['inventoryItem:id,name', 'location:id,name'])
            ->latest()
            ->limit(8)
            ->get()
            ->map(fn ($m) => [
                'type'       => $m->type,
                'item_id'    => $m->inventory_item_id,
                'item'       => ['name' => $m->inventoryItem?->name],
                'reason'     => $m->reason,
                'location'   => ['name' => $m->location?->name],
                'created_at' => $m->created_at,
            ]);

        $stockValueByCategory = Category::query()
            ->leftJoin('inventory_items', function ($join) {
                $join->on('inventory_items.category_id', '=', 'categories.id')
                    ->where('inventory_items.is_active', true);
            })
            ->groupBy('categories.id', 'categories.name')
            ->selectRaw('categories.name AS category_name, COALESCE(SUM(inventory_items.stock_qty * inventory_items.unit_cost), 0) AS value')
            ->havingRaw('COALESCE(SUM(inventory_items.stock_qty * inventory_items.unit_cost), 0) > 0')
            ->orderByDesc('value')
            ->get();

        return response()->json([
            'data' => [
                'total_stock_value'       => $totalStockValue,
                'total_items'             => $totalItems,
                'low_stock_count'         => $lowStockQuery->count(),
                'open_purchase_orders'    => $openPurchaseOrders,
                'low_stock_items'         => $lowStockItems,
                'recent_movements'        => $recentMovements,
                'stock_value_by_category' => $stockValueByCategory,
            ],
        ]);
    }

    public function sales()
    {
        $startOfMonth = Carbon::now()->startOfMonth();

        $pipeline = SalesLead::whereNotIn('stage', ['won', 'lost'])
            ->selectRaw('stage, COUNT(*) AS count, COALESCE(SUM(deal_value), 0) AS value')
            ->groupBy('stage')
            ->get();

        $wonThisMonth = SalesLead::where('stage', 'won')
            ->where('updated_at', '>=', $startOfMonth)
            ->selectRaw('COUNT(*) AS count, COALESCE(SUM(deal_value), 0) AS value')
            ->first();

        $lostThisMonth = SalesLead::where('stage', 'lost')
            ->where('updated_at', '>=', $startOfMonth)
            ->count();

        $closedThisMonth = (int) $wonThisMonth->count + $lostThisMonth;
        $winRate = $closedThisMonth > 0 ? round((int) $wonThisMonth->count / $closedThisMonth * 100, 1) : 0;

        $revenueThisMonth = (int) Invoice::whereNotNull('sales_order_id')
            ->where('issue_date', '>=', $startOfMonth->toDateString())
            ->whereIn('status', ['paid', 'partial'])
            ->sum('amount_paid');

        $topReps = SalesOrder::query()
            ->whereNotNull('commission_agent_id')
            ->whereIn('status', ['confirmed', 'delivering', 'delivered'])
            ->join('users', 'users.id', '=', 'sales_orders.commission_agent_id')
            ->groupBy('sales_orders.commission_agent_id', 'users.name')
            ->selectRaw('sales_orders.commission_agent_id AS rep_id, users.name AS rep_name, COUNT(*) AS deal_count, COALESCE(SUM(sales_orders.total_amount), 0) AS total_value')
            ->orderByDesc('total_value')
            ->limit(5)
            ->get();

        $kpi = [
            'pipeline_value'                => (int) $pipeline->sum('value'),
            'open_leads'                     => (int) $pipeline->sum('count'),
            'won_this_month'                 => (int) $wonThisMonth->count,
            'won_value_this_month'           => (int) $wonThisMonth->value,
            'win_rate_this_month'            => $winRate,
            'quotations_pending_approval'    => Quotation::where('approval_status', 'pending')->count(),
            'quotations_awaiting_response'   => Quotation::where('status', 'sent')->count(),
            'sales_orders_this_month'        => SalesOrder::whereYear('created_at', now()->year)->whereMonth('created_at', now()->month)->count(),
            'revenue_this_month'             => $revenueThisMonth,
        ];

        $recentQuotations = Quotation::with(['createdBy'])->latest()->limit(5)->get();
        $recentOrders     = SalesOrder::with(['createdBy', 'hospital'])->latest()->limit(5)->get();

        return response()->json([
            'data' => [
                'kpi'                => $kpi,
                'pipeline_by_stage'  => $pipeline,
                'top_reps'           => $topReps,
                'recent_quotations'  => QuotationResource::collection($recentQuotations),
                'recent_orders'      => SalesOrderResource::collection($recentOrders),
            ],
        ]);
    }
}
