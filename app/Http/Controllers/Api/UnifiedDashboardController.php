<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\EffectivePermissionResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * One request, one screen — replaces the old one-size-fits-all "Dashboard"
 * (which showed fleet/ticket/revenue KPIs to literally every role) with an
 * ordered list of department sections, each included only if the viewer
 * holds the same screens.* permission that already gates that department
 * everywhere else in the app. Section presence in the response IS the
 * authorization — Flutter renders whatever comes back with no gating logic
 * of its own. Every builder below reuses the exact aggregation logic the
 * satellite dashboards already use (extracted into public-but-unrouted
 * methods on their owning controllers) rather than re-querying, so this
 * endpoint and the existing dashboard/*, finance-reports/*, revenue/*, and
 * hr-reports/* routes stay in lockstep with zero duplicated business logic.
 */
class UnifiedDashboardController extends Controller
{
    public function __construct(
        private DashboardController $dashboard,
        private FinanceReportController $financeReports,
        private RevenueController $revenue,
        private HrReportController $hrReports,
        private EffectivePermissionResolver $permissions,
    ) {
    }

    public function index(Request $request)
    {
        $user = $request->user();

        $sectionTable = [
            ['key' => 'fleet',     'permission' => 'screens.machines',     'title' => 'Machines & Fleet',   'type' => 'kpi_grid', 'builder' => fn () => $this->dashboard->buildFleetSection()],
            ['key' => 'service',   'permission' => 'screens.service',      'title' => 'Service & Tickets',  'type' => 'mixed',    'builder' => fn () => $this->dashboard->buildServiceSection()],
            ['key' => 'inventory', 'permission' => 'screens.inventory',    'title' => 'Inventory',          'type' => 'mixed',    'builder' => fn () => $this->dashboard->buildInventory()],
            ['key' => 'sales',     'permission' => 'screens.sales',        'title' => 'Sales',              'type' => 'mixed',    'builder' => fn () => $this->dashboard->buildSales()],
            // Wrapped under 'months' — buildRevenueSummary()/buildMonthlyTrend()
            // return a plain array (matching their own routes' response
            // shape, untouched), but every OTHER section here is an object;
            // wrapping keeps `data` uniformly an object across all sections
            // so the client never has to type-switch on key just to parse it.
            ['key' => 'revenue',   'permission' => 'screens.revenue',      'title' => 'Revenue',            'type' => 'chart',    'builder' => fn () => ['months' => $this->revenue->buildRevenueSummary()]],
            ['key' => 'finance',   'permission' => 'screens.finance',      'title' => 'Finance',            'type' => 'chart',    'builder' => fn () => ['months' => $this->financeReports->buildMonthlyTrend()]],
            ['key' => 'hr',        'permission' => 'screens.hr_dashboard', 'title' => 'HR',                 'type' => 'kpi_grid', 'builder' => fn () => $this->hrReports->buildHeadcountBreakdown()],
        ];

        $sections = [];

        foreach ($sectionTable as $section) {
            if (! $this->permissions->can($user, $section['permission'])) {
                continue;
            }

            try {
                $sections[] = [
                    'key'   => $section['key'],
                    'title' => $section['title'],
                    'type'  => $section['type'],
                    'data'  => $section['builder'](),
                ];
            } catch (\Throwable $e) {
                // One broken department shouldn't blank the whole dashboard.
                Log::error("UnifiedDashboardController: section '{$section['key']}' failed", [
                    'user_id' => $user->id,
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        return response()->json(['data' => ['sections' => $sections]]);
    }
}
