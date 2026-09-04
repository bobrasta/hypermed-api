<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\Hospital;
use App\Models\LeaveRequest;
use App\Models\Machine;
use App\Models\PerDiemRequest;
use App\Models\PurchaseOrder;
use App\Models\Quotation;
use App\Models\StockOutRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Admin/Director-only "Command Centre" — a fixed bespoke layout (map +
 * cross-department exception feed + technician roster + compact
 * per-department mini-panels), not the permission-list-driven section
 * approach UnifiedDashboardController uses for every other role. Reuses
 * that controller's builders wherever the data overlaps rather than
 * re-querying.
 */
class AdminOverviewController extends Controller
{
    private const PENDING_PO_STATUSES = [
        'pending_sales_manager', 'pending_director_review',
        'pending_payment_initiation', 'pending_director_final',
    ];

    private const PENDING_PER_DIEM_STATUSES = [
        'pending_team_lead', 'pending_cto', 'pending_payment', 'pending_director',
    ];

    public function __construct(
        private DashboardController $dashboard,
        private FinanceReportController $financeReports,
        private HrReportController $hrReports,
    ) {
    }

    public function index(Request $request)
    {
        abort_if(! $request->user()->hasDirectorAuthority(), 403, 'Access Denied: admin/director only.');

        $fleet = $this->dashboard->buildFleetSection();
        $service = $this->dashboard->buildServiceSection();
        $sales = $this->dashboard->buildSales();
        $inventory = $this->dashboard->buildInventory();
        $trend = $this->financeReports->buildMonthlyTrend();
        $hr = $this->hrReports->buildHeadcountBreakdown();

        $technicians = $this->technicianRoster();
        $enRouteCount = collect($technicians)->where('state', 'en_route')->count();
        $netProfitThisMonth = (int) (collect($trend)->last()['net_profit'] ?? 0);

        return response()->json(['data' => [
            'greeting' => [
                'name'              => $request->user()->name,
                'date'              => now()->format('d M Y'),
                'machines_down'     => $fleet['down'],
                'open_tickets'      => $service['open_tickets'],
                'technicians_out'   => $enRouteCount,
            ],
            'kpis' => [
                'fleet_uptime_pct'  => $fleet['total_machines'] > 0
                    ? round($fleet['operational'] / $fleet['total_machines'] * 100, 1) : 0,
                'machines_down'     => $fleet['down'],
                'open_tickets'      => $service['open_tickets'],
                'overdue_tickets'   => $service['overdue_tickets'],
                'cash_position'     => $netProfitThisMonth,
                'pipeline_value'    => $sales['kpi']['pipeline_value'] ?? 0,
            ],
            'fleet' => [
                'legend' => [
                    'operational'   => $fleet['operational'],
                    'needs_service' => $fleet['needs_service'],
                    'down'          => $fleet['down'],
                    'technician_en_route' => $enRouteCount,
                ],
                'zones' => $this->zoneBreakdown(),
            ],
            'attention'    => $this->attentionFeed($inventory),
            'technicians'  => $technicians,
            'sales'        => $sales,
            'finance'      => ['months' => $trend],
            'inventory'    => $inventory,
            'people'       => $this->peopleSummary($hr),
        ]]);
    }

    private function zoneBreakdown(): array
    {
        return Hospital::whereNotNull('zone')
            ->selectRaw('zone, COUNT(*) AS count')
            ->groupBy('zone')
            ->orderByDesc('count')
            ->limit(6)
            ->get()
            ->map(fn ($z) => ['name' => ucwords(str_replace('_', ' ', $z->zone)), 'count' => (int) $z->count])
            ->values()
            ->toArray();
    }

    private function technicianRoster(): array
    {
        $today = now()->toDateString();
        $onLeaveUserIds = LeaveRequest::where('status', 'approved')
            ->whereDate('start_date', '<=', $today)
            ->whereDate('end_date', '>=', $today)
            ->pluck('user_id');

        return User::where('role', 'technician')
            ->with('currentTask')
            ->get()
            ->map(function (User $u) use ($onLeaveUserIds) {
                $state = $onLeaveUserIds->contains($u->id)
                    ? 'on_leave'
                    : (in_array($u->avail_status, ['On task', 'Assigned'], true) ? 'en_route' : 'available');

                return [
                    'name'  => $u->name,
                    'state' => $state,
                    'where' => $state === 'on_leave' ? 'On leave' : ($u->currentTask?->title ?? 'Workshop'),
                ];
            })
            ->values()
            ->toArray();
    }

    // Newest/most-severe first, capped at 6 — deliberately a rollup for
    // low-stock/approvals (one summary row each), not one row per request,
    // matching the reference design's own "N more in the approvals queue"
    // footer pattern rather than trying to enumerate every pending item
    // from every module here.
    private function attentionFeed(array $inventory): array
    {
        $items = [];

        foreach (Machine::where('status', 'down')->with('hospital')->latest('updated_at')->limit(4)->get() as $m) {
            $items[] = [
                'title' => "{$m->model} offline — {$m->hospital?->name}",
                'meta'  => $m->hospital?->region ?? '—',
                'age'   => $m->updated_at?->diffForHumans(null, true),
                'severity' => 'critical',
            ];
        }

        $staleQuotation = Quotation::where('status', 'sent')->oldest('updated_at')->first();
        if ($staleQuotation) {
            $items[] = [
                'title' => "Quotation awaiting client — {$staleQuotation->client_name}",
                'meta'  => "{$staleQuotation->quotation_number} · TSh {$staleQuotation->total_amount}",
                'age'   => $staleQuotation->updated_at?->diffForHumans(null, true),
                'severity' => 'warning',
            ];
        }

        if (($inventory['low_stock_count'] ?? 0) > 0) {
            $items[] = [
                'title' => "{$inventory['low_stock_count']} items below reorder level",
                'meta'  => 'Inventory · ' . ($inventory['open_purchase_orders'] ?? 0) . ' purchase orders raised',
                'age'   => null,
                'severity' => 'warning',
            ];
        }

        $pendingApprovals = StockOutRequest::where('status', 'pending')->count()
            + PerDiemRequest::whereIn('status', self::PENDING_PER_DIEM_STATUSES)->count()
            + Expense::whereIn('status', ['pending_cto', 'pending_director'])->count()
            + PurchaseOrder::whereIn('status', self::PENDING_PO_STATUSES)->count()
            + LeaveRequest::where('status', 'pending')->count();

        if ($pendingApprovals > 0) {
            $items[] = [
                'title' => "{$pendingApprovals} request(s) pending your approval",
                'meta'  => 'Across stock-out, per-diem, expenses, purchase orders, leave',
                'age'   => null,
                'severity' => 'info',
            ];
        }

        return array_slice($items, 0, 6);
    }

    private function peopleSummary(array $hr): array
    {
        $today = now()->toDateString();

        return [
            'active_staff' => $hr['total'] ?? 0,
            'on_leave_today' => LeaveRequest::where('status', 'approved')
                ->whereDate('start_date', '<=', $today)
                ->whereDate('end_date', '>=', $today)
                ->count(),
            'pending_approvals' => LeaveRequest::where('status', 'pending')->count(),
        ];
    }
}
