<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryItem;
use App\Models\LeaveRequest;
use App\Models\Machine;
use App\Models\PerDiemRequest;
use App\Models\ServiceTicket;
use App\Models\StockOutRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * CTO dashboard — technical-ops snapshot (fleet, SLA, field team, spares).
 * Approvals are deliberately NOT here: the app reads them from the existing
 * per-diem / expense / stock-out endpoints so each has one source of truth.
 */
class CtoOverviewController extends Controller
{
    // No SLA field exists on tickets; the design's "48 h target" is applied
    // to every open ticket from created_at. At risk = under 8 h left.
    private const SLA_HOURS = 48;
    private const AT_RISK_HOURS = 8;
    private const CALENDAR_DAYS = 14;

    // Per diem already past the CTO step — drawn as a confirmed trip.
    private const TRIP_APPROVED = ['pending_director', 'pending_payment', 'paid'];

    public function __construct(private DashboardController $dashboard)
    {
    }

    public function index(Request $request)
    {
        $user = $request->user();
        abort_if(! $user->hasCtoApprovalAuthority() && ! $user->hasDirectorAuthority(), 403, 'Access Denied: CTO only.');

        $fleet = $this->dashboard->buildFleetSection();
        $inventory = $this->dashboard->buildInventory();
        $now = now();

        $down = $this->downMachines($now);
        $tickets = $this->openTickets($now);
        $team = $this->team($now);

        return response()->json(['data' => [
            'greeting' => ['name' => explode(' ', trim($user->name))[0]],
            'kpis' => [
                'fleet_uptime_pct'     => $fleet['total_machines'] > 0
                    ? round($fleet['operational'] / $fleet['total_machines'] * 100, 1) : 0,
                'machines_operational' => $fleet['operational'],
                'machines_total'       => $fleet['total_machines'],
                'machines_down'        => $fleet['down'],
                'machines_down_today'  => $down->filter(fn ($m) => $m['since']->isSameDay($now))->count(),
                'hospitals_affected'   => $down->pluck('hospital_id')->filter()->unique()->count(),
                'open_tickets'         => $tickets->count(),
                'sla_breached'         => $tickets->where('sla_hours_left', '<', 0)->count(),
                'sla_at_risk'          => $tickets->filter(fn ($t) => $t['sla_hours_left'] >= 0 && $t['sla_hours_left'] < self::AT_RISK_HOURS)->count(),
                'technicians_total'    => count($team['team']),
                'technicians_out'      => collect($team['team'])->where('state', 'en_route')->count(),
                'technicians_on_leave' => collect($team['team'])->where('state', 'on_leave')->count(),
                'low_stock_count'      => $inventory['low_stock_count'] ?? 0,
                'open_purchase_orders' => $inventory['open_purchase_orders'] ?? 0,
            ],
            'fleet_legend' => [
                'operational'         => $fleet['operational'],
                'needs_service'       => $fleet['needs_service'],
                'down'                => $fleet['down'],
                'technician_en_route' => collect($team['team'])->where('state', 'en_route')->count(),
            ],
            'down_longest' => $down->take(6)->map(fn ($m) => collect($m)->except(['since', 'hospital_id'])->all())->values(),
            'sla_tickets'  => $tickets->take(10)->values(),
            'calendar'     => $team,
            'spares'       => $this->spares(),
        ]]);
    }

    /**
     * Installed + down, longest first. "Down since" is the last activity-log
     * entry that set status=down (the log only goes back to 2026-09-16), else
     * the machine's updated_at.
     */
    private function downMachines(Carbon $now): Collection
    {
        $machines = Machine::with('hospital:id,name,region')
            ->where('lifecycle_stage', 'installed')
            ->where('status', 'down')
            ->get(['id', 'model', 'type', 'hospital_id', 'updated_at']);
        if ($machines->isEmpty()) {
            return collect();
        }

        $since = DB::table('activity_log')
            ->where('subject_type', Machine::class)
            ->whereIn('subject_id', $machines->pluck('id'))
            ->whereRaw("properties->'attributes'->>'status' = 'down'")
            ->groupBy('subject_id')
            ->selectRaw('subject_id, MAX(created_at) AS at')
            ->pluck('at', 'subject_id');

        $assignees = ServiceTicket::with('assignee:id,name')
            ->whereIn('machine_id', $machines->pluck('id'))
            ->where('status', '!=', 'resolved')
            ->whereNotNull('assigned_to')
            ->latest()
            ->get(['machine_id', 'assigned_to'])
            ->unique('machine_id')
            ->keyBy('machine_id');

        return $machines->map(function (Machine $m) use ($since, $assignees, $now) {
            $at = isset($since[$m->id]) ? Carbon::parse($since[$m->id]) : ($m->updated_at ?? $now);
            $who = $assignees->get($m->id)?->assignee?->name;

            return [
                'machine_id'  => $m->id,
                'name'        => $m->model ?: ($m->type ?: 'Machine'),
                'hospital'    => $m->hospital?->name ?? '—',
                'note'        => collect([$m->hospital?->region, $who ?? 'unassigned'])->filter()->implode(' · '),
                'down_hours'  => (int) max(0, $at->diffInHours($now)),
                'since'       => $at,
                'hospital_id' => $m->hospital_id,
            ];
        })->sortByDesc('down_hours')->values();
    }

    /** Open tickets, most urgent (least SLA time left) first. */
    private function openTickets(Carbon $now): Collection
    {
        return ServiceTicket::with(['hospital:id,name', 'machine:id,model,type', 'assignee:id,name'])
            ->whereIn('status', ['open', 'in_progress', 'overdue'])
            ->get()
            ->map(function (ServiceTicket $t) use ($now) {
                $deadline = $t->created_at->copy()->addHours(self::SLA_HOURS);
                $title = $t->machine?->model
                    ? $t->machine->model . ' · ' . str($t->type ?? 'service')->replace('_', ' ')
                    : str($t->description ?? 'Service ticket')->limit(40)->toString();

                return [
                    'id'               => $t->id,
                    'ticket_number'    => $t->ticket_number,
                    'title'            => $title,
                    'hospital'         => $t->hospital?->name ?? '—',
                    'assignee_name'    => $t->assignee?->name,
                    'assignee_note'    => $t->assignee && $t->stage ? str($t->stage)->replace('_', ' ')->toString() : null,
                    'sla_target_hours' => self::SLA_HOURS,
                    'sla_hours_left'   => (int) floor($now->diffInHours($deadline, false)),
                ];
            })
            ->sortBy('sla_hours_left')
            ->values();
    }

    /** Technicians + their trips/leave over two weeks from this Monday. */
    private function team(Carbon $now): array
    {
        $start = $now->copy()->startOfWeek(Carbon::MONDAY)->startOfDay();
        $end = $start->copy()->addDays(self::CALENDAR_DAYS - 1);
        $today = $now->toDateString();

        $techs = User::where('role', 'technician')->where('is_active', true)
            ->with('currentTask')->orderBy('name')->get();
        $ids = $techs->pluck('id');

        $trips = PerDiemRequest::with('lines:id,per_diem_request_id,region,district,site_name')
            ->whereIn('user_id', $ids)
            ->whereIn('status', [...self::TRIP_APPROVED, 'pending_cto'])
            ->whereDate('start_date', '<=', $end)
            ->whereDate('end_date', '>=', $start)
            ->orderBy('start_date')
            ->get()
            ->groupBy('user_id');

        $leave = LeaveRequest::whereIn('user_id', $ids)
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $end)
            ->whereDate('end_date', '>=', $start)
            ->get()
            ->groupBy('user_id');

        $team = $techs->map(function (User $u) use ($trips, $leave, $today) {
            $myTrips = $trips[$u->id] ?? collect();
            $myLeave = $leave[$u->id] ?? collect();

            $onLeave = $myLeave->first(fn ($l) => $l->start_date->toDateString() <= $today && $l->end_date->toDateString() >= $today);
            $current = $myTrips->first(fn ($p) => in_array($p->status, self::TRIP_APPROVED, true)
                && $p->start_date->toDateString() <= $today && $p->end_date->toDateString() >= $today);
            $busy = in_array($u->avail_status, ['On task', 'Assigned'], true);

            $state = $onLeave ? 'on_leave' : (($current || $busy) ? 'en_route' : 'base');
            $where = match (true) {
                (bool) $onLeave => 'On leave',
                (bool) $current => $this->tripPlaces($current) ?: $current->destination,
                $busy           => $u->currentTask?->title ?? 'On task',
                default         => 'Workshop',
            };
            $note = '';
            if ($current) {
                $day = $current->start_date->diffInDays(Carbon::parse($today)) + 1;
                $total = $current->start_date->diffInDays($current->end_date) + 1;
                $note = sprintf('Trip PD-%04d · day %d of %d · %s', $current->id, $day, $total,
                    $current->status === 'paid' ? 'per diem paid' : 'per diem not yet paid');
            } elseif ($onLeave) {
                $note = 'Back ' . $onLeave->end_date->copy()->addDay()->format('d M');
            }

            $bars = $myTrips->map(fn ($p) => [
                'per_diem_request_id' => $p->id,
                'label'      => sprintf('PD-%04d · %s', $p->id, $this->tripPlaces($p) ?: $p->destination),
                'start_date' => $p->start_date->toDateString(),
                'end_date'   => $p->end_date->toDateString(),
                'kind'       => $p->status === 'pending_cto' ? 'pending_cto' : 'approved',
            ])->concat($myLeave->map(fn ($l) => [
                'per_diem_request_id' => null,
                'label'      => 'Leave',
                'start_date' => $l->start_date->toDateString(),
                'end_date'   => $l->end_date->toDateString(),
                'kind'       => 'leave',
            ]))->values();

            return [
                'user_id'   => $u->id,
                'name'      => $u->name,
                'state'     => $state,
                'where'     => $where,
                'trip_note' => $note,
                'bars'      => $bars,
            ];
        })->sortBy(fn ($m) => ['en_route' => 0, 'base' => 1, 'on_leave' => 2][$m['state']])->values();

        return ['start_date' => $start->toDateString(), 'days' => self::CALENDAR_DAYS, 'team' => $team];
    }

    private function tripPlaces(PerDiemRequest $p): string
    {
        return $p->lines->map(fn ($l) => $l->site_name ?: ($l->district ?: $l->region))
            ->filter()->unique()->take(3)->implode(', ');
    }

    /**
     * Items at/below reorder level; ones a pending stock request for an open
     * ticket is waiting on come first, then emptiest first.
     */
    private function spares(): array
    {
        $low = InventoryItem::where('is_active', true)
            ->whereColumn('stock_qty', '<=', 'reorder_level')
            ->orderBy('stock_qty')
            ->limit(40)
            ->get(['id', 'name', 'stock_qty', 'reorder_level']);
        if ($low->isEmpty()) {
            return [];
        }

        $blocking = StockOutRequest::with('serviceTicket:id,ticket_number,status')
            ->whereIn('inventory_item_id', $low->pluck('id'))
            ->where('status', 'pending')
            ->whereNotNull('service_ticket_id')
            ->get()
            ->filter(fn ($r) => $r->serviceTicket && $r->serviceTicket->status !== 'resolved')
            ->groupBy('inventory_item_id');

        return $low->map(function (InventoryItem $i) use ($blocking) {
            $reqs = $blocking[$i->id] ?? collect();
            $qty = (int) $i->stock_qty;

            return [
                'item_id'  => $i->id,
                'name'     => $i->name,
                'qty'      => $qty,
                'note'     => $reqs->isNotEmpty()
                    ? 'Blocks ' . $reqs->pluck('serviceTicket.ticket_number')->unique()->implode(', ') . ' · request pending you'
                    : "Reorder level {$i->reorder_level}",
                'severity' => $qty <= 0 ? 'critical' : 'warning',
                '_rank'    => $reqs->isNotEmpty() ? 0 : 1,
            ];
        })->sortBy([['_rank', 'asc'], ['qty', 'asc']])
            ->take(8)
            ->map(fn ($s) => collect($s)->except('_rank')->all())
            ->values()
            ->all();
    }
}
