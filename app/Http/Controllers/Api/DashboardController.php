<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\HospitalResource;
use App\Http\Resources\ServiceTicketResource;
use App\Models\Hospital;
use App\Models\Invoice;
use App\Models\ServiceTicket;
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

        $topHospitals = Hospital::select('id', 'name', 'short_code', 'machines_operational', 'machine_count', 'revenue_monthly')
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
}
