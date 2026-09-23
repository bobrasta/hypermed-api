<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Machine;
use App\Models\Quotation;
use App\Models\SalesLead;
use App\Models\SalesOrder;
use App\Models\ServiceTicket;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

// Backs "My Performance" (every role — see /performance/mine) and "Team
// Performance" (sales managers only — /performance/team). Deliberately
// composed from sections that are present only when the caller has real
// activity behind them (a technician with zero installs ever doesn't get
// an empty Field section) rather than every role seeing every block.
// No quota/target concept exists anywhere in the schema — deliberately
// left out rather than fabricated; the revenue chart has no target line
// and the leaderboard has no quota column, unlike the original design
// mock which assumed one.
class PerformanceController extends Controller
{
    public function mine(Request $request)
    {
        $user = $request->user();
        $startOfMonth = Carbon::now()->startOfMonth();

        $tasks = Task::where('assigned_to', $user->id)
            ->selectRaw("
                COUNT(*) FILTER (WHERE status = 'completed') AS completed,
                COUNT(*) FILTER (WHERE status IN ('assigned', 'in_progress')) AS pending,
                COUNT(*) FILTER (WHERE status = 'overdue') AS overdue
            ")->first();

        $hasSalesActivity = SalesLead::where('assigned_to', $user->id)->exists()
            || Quotation::where('created_by', $user->id)->exists();
        $hasFieldActivity = Machine::where('installed_by', $user->id)->exists()
            || ServiceTicket::where('assigned_to', $user->id)->exists();

        return response()->json(['data' => [
            'period' => Carbon::now()->format('F Y'),
            'tasks' => [
                'completed' => (int) $tasks->completed,
                'pending'   => (int) $tasks->pending,
                'overdue'   => (int) $tasks->overdue,
            ],
            'sales' => $hasSalesActivity ? $this->mySales($user, $startOfMonth) : null,
            'field' => $hasFieldActivity ? $this->myField($user, $startOfMonth) : null,
        ]]);
    }

    private function mySales(User $user, Carbon $startOfMonth): array
    {
        $leads = SalesLead::where('assigned_to', $user->id)
            ->whereNotIn('stage', ['won', 'lost'])->get(['stage', 'deal_value']);
        $stageOrder = ['lead', 'qualified', 'demo_scheduled', 'proposal_sent', 'negotiation'];
        $pipelineValue = (int) $leads->sum('deal_value');
        $byStage = collect($stageOrder)->map(function ($stage) use ($leads, $pipelineValue) {
            $inStage = $leads->where('stage', $stage);
            $value = (int) $inStage->sum('deal_value');
            return [
                'stage' => $stage, 'count' => $inStage->count(), 'value' => $value,
                'pct' => $pipelineValue > 0 ? round($value / $pipelineValue * 100) : 0,
            ];
        })->values();

        $sentThisMonth = Quotation::where('created_by', $user->id)
            ->where('sent_at', '>=', $startOfMonth)->count();
        $acceptedThisMonth = Quotation::where('created_by', $user->id)
            ->where('accepted_at', '>=', $startOfMonth)->count();

        $months = collect(range(5, 0))->map(function ($i) use ($user) {
            $start = Carbon::now()->subMonthsNoOverflow($i)->startOfMonth();
            $end = (clone $start)->endOfMonth();
            return [
                'name' => $start->format('M'),
                'sent' => Quotation::where('created_by', $user->id)->whereBetween('sent_at', [$start, $end])->count(),
                'accepted' => Quotation::where('created_by', $user->id)->whereBetween('accepted_at', [$start, $end])->count(),
            ];
        });
        $totalSent6m = $months->sum('sent');
        $totalAccepted6m = $months->sum('accepted');

        $commissionMtd = (int) SalesOrder::where('commission_agent_id', $user->id)
            ->where('confirmed_at', '>=', $startOfMonth)
            ->sum('commission_amount');

        // Career totals for the profile page's header stat strip — real
        // aggregates, same reasoning as myField()'s all-time additions.
        $wonLeads = SalesLead::where('assigned_to', $user->id)->where('stage', 'won');

        return [
            'pipeline_value' => $pipelineValue,
            'open_leads' => $leads->count(),
            'leads_by_stage' => $byStage,
            'quotations_sent_this_month' => $sentThisMonth,
            'quotations_accepted_this_month' => $acceptedThisMonth,
            'monthly_chart' => $months,
            'accept_rate' => $totalSent6m > 0 ? round($totalAccepted6m / $totalSent6m * 100) : 0,
            'commission_mtd' => $commissionMtd,
            'deals_won_all_time' => (clone $wonLeads)->count(),
            'accounts_served' => (clone $wonLeads)->whereNotNull('hospital_id')->distinct()->count('hospital_id'),
        ];
    }

    private function myField(User $user, Carbon $startOfMonth): array
    {
        // All-time counterparts to the monthly figures below — used for the
        // profile page's header stat strip, which shows career totals
        // rather than this month's activity. Real aggregates, not
        // fabricated: no first-time-fix concept exists in the schema (no
        // repeat-visit/reopened flag on ServiceTicket), so that number from
        // the original design mock is deliberately left out rather than
        // invented.
        $hospitalsServed = Machine::where('installed_by', $user->id)->whereNotNull('hospital_id')
            ->distinct()->pluck('hospital_id')
            ->merge(ServiceTicket::where('assigned_to', $user->id)->whereNotNull('hospital_id')
                ->distinct()->pluck('hospital_id'))
            ->unique()->count();

        return [
            'machines_installed_this_month' => Machine::where('installed_by', $user->id)
                ->where('installed_at', '>=', $startOfMonth)->count(),
            'tickets_resolved_this_month' => ServiceTicket::where('assigned_to', $user->id)
                ->where('status', 'resolved')->where('resolved_at', '>=', $startOfMonth)->count(),
            'tickets_open' => ServiceTicket::where('assigned_to', $user->id)
                ->whereIn('status', ['open', 'in_progress'])->count(),
            'machines_installed_all_time' => Machine::where('installed_by', $user->id)->count(),
            'tickets_resolved_all_time' => ServiceTicket::where('assigned_to', $user->id)
                ->where('status', 'resolved')->count(),
            'hospitals_served' => $hospitalsServed,
        ];
    }

    public function team(Request $request)
    {
        $manager = $request->user();
        abort_if(! $manager->hasSalesCreateSubordinateAuthority(), 403,
            'Access Denied: you do not have permission to view team performance.');

        $team = User::where('manager_id', $manager->id)->where('is_active', true)->get();
        $teamIds = $team->pluck('id');
        $startOfMonth = Carbon::now()->startOfMonth();

        $revenueMtd = (int) Invoice::join('sales_orders', 'sales_orders.id', '=', 'invoices.sales_order_id')
            ->whereIn('sales_orders.created_by', $teamIds)
            ->where('invoices.issue_date', '>=', $startOfMonth->toDateString())
            ->whereIn('invoices.status', ['paid', 'partial'])
            ->sum('invoices.amount_paid');

        $teamPipeline = (int) SalesLead::whereIn('assigned_to', $teamIds)
            ->whereNotIn('stage', ['won', 'lost'])->sum('deal_value');

        $awaitingApproval = Quotation::whereIn('created_by', $teamIds)
            ->where('approval_status', 'pending')->count();

        $commissionOwed = (int) SalesOrder::whereIn('commission_agent_id', $teamIds)->sum('commission_amount');

        // Same bucketing as FinanceReportController::arAging(), scoped to
        // this team's invoices only rather than the whole company's AR.
        $overdueInvoices = Invoice::join('sales_orders', 'sales_orders.id', '=', 'invoices.sales_order_id')
            ->whereIn('sales_orders.created_by', $teamIds)
            ->whereIn('invoices.status', ['pending', 'partial', 'overdue', 'sent'])
            ->where('invoices.due_date', '<', Carbon::today())
            ->selectRaw('invoices.total - invoices.amount_paid AS balance')
            ->get();
        $receivableOverdue = (int) $overdueInvoices->sum(fn ($i) => max(0, $i->balance));

        $months = collect(range(8, 0))->map(function ($i) use ($teamIds) {
            $start = Carbon::now()->subMonthsNoOverflow($i)->startOfMonth();
            $end = (clone $start)->endOfMonth();
            $actual = (int) Invoice::join('sales_orders', 'sales_orders.id', '=', 'invoices.sales_order_id')
                ->whereIn('sales_orders.created_by', $teamIds)
                ->whereBetween('invoices.issue_date', [$start->toDateString(), $end->toDateString()])
                ->whereIn('invoices.status', ['paid', 'partial'])
                ->sum('invoices.amount_paid');
            return ['name' => $start->format('M'), 'actual' => $actual];
        });
        $ytdActual = (int) Invoice::join('sales_orders', 'sales_orders.id', '=', 'invoices.sales_order_id')
            ->whereIn('sales_orders.created_by', $teamIds)
            ->where('invoices.issue_date', '>=', Carbon::now()->startOfYear()->toDateString())
            ->whereIn('invoices.status', ['paid', 'partial'])
            ->sum('invoices.amount_paid');

        $byHospital = Invoice::join('sales_orders', 'sales_orders.id', '=', 'invoices.sales_order_id')
            ->join('hospitals', 'hospitals.id', '=', 'invoices.hospital_id')
            ->whereIn('sales_orders.created_by', $teamIds)
            ->whereIn('invoices.status', ['paid', 'partial'])
            ->where('invoices.issue_date', '>=', $startOfMonth->toDateString())
            ->selectRaw('hospitals.name AS name, SUM(invoices.amount_paid) AS value')
            ->groupBy('hospitals.name')
            ->orderByDesc('value')
            ->limit(5)
            ->get();
        $hospitalMax = $byHospital->max('value') ?: 1;

        $revenueByRep = SalesOrder::whereIn('created_by', $teamIds)
            ->join('invoices', 'invoices.sales_order_id', '=', 'sales_orders.id')
            ->whereIn('invoices.status', ['paid', 'partial'])
            ->where('invoices.issue_date', '>=', $startOfMonth->toDateString())
            ->selectRaw('sales_orders.created_by AS rep_id, SUM(invoices.amount_paid) AS total')
            ->groupBy('sales_orders.created_by')->pluck('total', 'rep_id');
        $wonByRep = SalesLead::whereIn('assigned_to', $teamIds)->where('stage', 'won')
            ->where('updated_at', '>=', $startOfMonth)
            ->selectRaw('assigned_to, COUNT(*) AS count')->groupBy('assigned_to')->pluck('count', 'assigned_to');
        $commissionByRep = SalesOrder::whereIn('commission_agent_id', $teamIds)
            ->where('confirmed_at', '>=', $startOfMonth)
            ->selectRaw('commission_agent_id, SUM(commission_amount) AS total')
            ->groupBy('commission_agent_id')->pluck('total', 'commission_agent_id');

        $leaderboard = $team->map(fn (User $r) => [
            'id' => $r->id, 'name' => $r->name, 'zone' => $r->zone,
            'initials' => collect(explode(' ', trim($r->name)))->map(fn ($p) => strtoupper($p[0] ?? ''))->implode(''),
            'won' => (int) ($wonByRep[$r->id] ?? 0),
            'revenue' => (int) ($revenueByRep[$r->id] ?? 0),
            'commission_owed' => (int) ($commissionByRep[$r->id] ?? 0),
        ])->sortByDesc('revenue')->values();

        return response()->json(['data' => [
            'period' => Carbon::now()->format('F Y'),
            'reps_count' => $team->count(),
            'kpis' => [
                'revenue_mtd' => $revenueMtd,
                'team_pipeline' => $teamPipeline,
                'awaiting_my_approval' => $awaitingApproval,
                'commission_owed' => $commissionOwed,
                'receivable_overdue' => $receivableOverdue,
            ],
            'revenue_by_month' => $months,
            'ytd_actual' => $ytdActual,
            'revenue_by_hospital' => $byHospital->map(fn ($h) => [
                'name' => $h->name, 'value' => (int) $h->value,
                'pct' => round($h->value / $hospitalMax * 100),
            ]),
            'leaderboard' => $leaderboard,
        ]]);
    }
}
