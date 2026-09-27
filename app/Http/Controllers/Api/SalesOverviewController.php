<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Machine;
use App\Models\Quotation;
use App\Models\SalesActivity;
use App\Models\SalesLead;
use App\Models\SalesOrder;
use App\Models\SalesTarget;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

// Backs the "Will we land the quarter?" sales dashboard (Sales Dashboard v3).
// Same scoping as DashboardController::buildSales: a sales.view_full_numbers
// holder sees the whole team exactly; a plain rep sees only their own book,
// currency rounded to the nearest 100K.
//
// Definitions, all grounded in real records — nothing inferred from stage:
//   - booked ("closed won") = sales orders confirmed/delivering/delivered,
//     dated by confirmed_at (created_at if never stamped), credited to the
//     order's creator — the same attribution SalesTeamController uses.
//   - commit / best case / pipeline = open leads whose rep-entered
//     expected_close_date falls between today and the period end, split by
//     the rep-entered forecast_category (unset counts as pipeline).
//   - target = sum of sales_targets rows for the months in range.
//   - periods are calendar months / quarters / years.
class SalesOverviewController extends Controller
{
    private const BOOKED_STATUSES = ['confirmed', 'delivering', 'delivered'];

    private ?int $ownerId = null;

    public function index(Request $request)
    {
        $user = $request->user();
        $this->ownerId = $user->hasSalesViewFullNumbers() ? null : $user->id;
        $mask = fn (int $v) => $this->ownerId ? (int) round($v / 100000) * 100000 : $v;

        $now = Carbon::now();
        $periodKey = in_array($request->query('period'), ['month', 'quarter', 'year'], true) ? $request->query('period') : 'quarter';
        [$start, $end, $label] = match ($periodKey) {
            'month'   => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth(), $now->format('M Y')],
            'year'    => [$now->copy()->startOfYear(), $now->copy()->endOfYear(), (string) $now->year],
            default   => [$now->copy()->startOfQuarter(), $now->copy()->endOfQuarter(), 'Q' . $now->quarter . ' ' . $now->year],
        };

        $repIds = $this->ownerId ? collect([$this->ownerId])
            : User::whereIn('role', SalesTargetController::REP_ROLES)->where('is_active', true)->pluck('id');

        // ---- Forecast for the selected period -------------------------------
        $closed = $this->bookedBetween($start, $end);
        $open = $this->openClosingBetween($now->copy()->startOfDay(), $end);
        $byCat = fn (string $cat) => (int) $open->filter(fn ($l) => ($l->forecast_category ?: 'pipeline') === $cat)->sum('deal_value');
        $target = $this->targetBetween($start, $end, $repIds);

        $openAll = SalesLead::whereNotIn('stage', ['won', 'lost'])
            ->when($this->ownerId, fn ($q) => $q->where('assigned_to', $this->ownerId));
        $undated = (clone $openAll)->whereNull('expected_close_date')->count();
        $slipped = (clone $openAll)->whereNotNull('expected_close_date')->where('expected_close_date', '<', $now->toDateString())->count();

        // ---- 12-month trend -------------------------------------------------
        $trendStart = $now->copy()->startOfMonth()->subMonths(11);
        $bookedByMonth = $this->bookedQuery($trendStart, $now->copy()->endOfMonth())
            ->selectRaw("to_char(COALESCE(confirmed_at, created_at), 'YYYY-MM') AS ym, COALESCE(SUM(total_amount), 0) AS total")
            ->groupBy('ym')->pluck('total', 'ym');
        $targetsByMonth = SalesTarget::whereIn('user_id', $repIds)
            ->where(DB::raw('year * 100 + month'), '>=', $trendStart->year * 100 + $trendStart->month)
            ->where(DB::raw('year * 100 + month'), '<=', $now->year * 100 + $now->month)
            ->selectRaw('year, month, SUM(amount) AS total')->groupBy('year', 'month')->get()
            ->mapWithKeys(fn ($r) => [sprintf('%04d-%02d', $r->year, $r->month) => (int) $r->total]);
        $commitRestOfMonth = (int) $this->openClosingBetween($now->copy()->startOfDay(), $now->copy()->endOfMonth())
            ->where('forecast_category', 'commit')->sum('deal_value');

        $months = [];
        for ($i = 0; $i < 12; $i++) {
            $m = $trendStart->copy()->addMonths($i);
            $ym = $m->format('Y-m');
            $isCurrent = $i === 11;
            $months[] = [
                'ym'        => $ym,
                'label'     => $m->format('M'),
                'actual'    => $mask((int) ($bookedByMonth[$ym] ?? 0)),
                'target'    => $mask((int) ($targetsByMonth[$ym] ?? 0)),
                'projected' => $isCurrent ? $mask($commitRestOfMonth) : 0,
                'current'   => $isCurrent,
            ];
        }

        return response()->json(['data' => [
            'scope'  => $this->ownerId ? 'own' : 'team',
            'period' => [
                'key' => $periodKey, 'label' => $label,
                'start' => $start->toDateString(), 'end' => $end->toDateString(),
                'days_left' => (int) max(0, $now->copy()->startOfDay()->diffInDays($end->copy()->startOfDay())),
            ],
            'targets_set' => SalesTarget::whereIn('user_id', $repIds)->where('amount', '>', 0)->exists(),
            'can_set_targets' => $user->hasSalesApprovalAuthority(),
            'months' => $months,
            'forecast' => [
                'closed'    => $mask($closed),
                'commit'    => $mask($byCat('commit')),
                'best_case' => $mask($byCat('best_case')),
                'pipeline'  => $mask($byCat('pipeline')),
                'target'    => $mask($target),
                'open_undated' => $undated,
                'open_slipped' => $slipped,
            ],
            'reps'       => $this->ownerId ? [] : $this->reps($start, $end, $now, $repIds),
            'deals'      => $this->deals($open, $mask),
            'activity'   => $this->activity($now),
            'warranties' => $this->warranties($now),
        ]]);
    }

    private function bookedQuery(Carbon $from, Carbon $to)
    {
        return SalesOrder::whereIn('status', self::BOOKED_STATUSES)
            ->whereRaw('COALESCE(confirmed_at, created_at) BETWEEN ? AND ?', [$from, $to])
            ->when($this->ownerId, fn ($q) => $q->where('created_by', $this->ownerId));
    }

    private function bookedBetween(Carbon $from, Carbon $to): int
    {
        return (int) $this->bookedQuery($from, $to)->sum('total_amount');
    }

    private function openClosingBetween(Carbon $from, Carbon $to): Collection
    {
        return SalesLead::with(['hospital', 'assignee'])
            ->whereNotIn('stage', ['won', 'lost'])
            ->whereBetween('expected_close_date', [$from->toDateString(), $to->toDateString()])
            ->when($this->ownerId, fn ($q) => $q->where('assigned_to', $this->ownerId))
            ->get();
    }

    private function targetBetween(Carbon $from, Carbon $to, Collection $repIds): int
    {
        $total = 0;
        for ($m = $from->copy()->startOfMonth(); $m <= $to; $m->addMonth()) {
            $total += (int) SalesTarget::whereIn('user_id', $repIds)->where('year', $m->year)->where('month', $m->month)->sum('amount');
        }

        return $total;
    }

    private function reps(Carbon $start, Carbon $end, Carbon $now, Collection $repIds): array
    {
        $booked = SalesOrder::whereIn('status', self::BOOKED_STATUSES)
            ->whereRaw('COALESCE(confirmed_at, created_at) BETWEEN ? AND ?', [$start, $end])
            ->whereIn('created_by', $repIds)
            ->selectRaw('created_by, COALESCE(SUM(total_amount), 0) AS total')
            ->groupBy('created_by')->pluck('total', 'created_by');
        $commit = SalesLead::whereNotIn('stage', ['won', 'lost'])
            ->where('forecast_category', 'commit')
            ->whereBetween('expected_close_date', [$now->toDateString(), $end->toDateString()])
            ->whereIn('assigned_to', $repIds)
            ->selectRaw('assigned_to, COALESCE(SUM(deal_value), 0) AS total')
            ->groupBy('assigned_to')->pluck('total', 'assigned_to');
        $targets = SalesTarget::whereIn('user_id', $repIds)
            ->where(DB::raw('year * 100 + month'), '>=', $start->year * 100 + $start->month)
            ->where(DB::raw('year * 100 + month'), '<=', $end->year * 100 + $end->month)
            ->selectRaw('user_id, SUM(amount) AS total')->groupBy('user_id')->pluck('total', 'user_id');

        return User::whereIn('id', $repIds)->orderBy('name')->get()
            ->map(fn (User $u) => [
                'id'       => $u->id,
                'name'     => $u->name,
                'initials' => collect(explode(' ', trim($u->name)))->map(fn ($p) => strtoupper($p[0] ?? ''))->take(2)->implode(''),
                'closed'   => (int) ($booked[$u->id] ?? 0),
                'commit'   => (int) ($commit[$u->id] ?? 0),
                'target'   => (int) ($targets[$u->id] ?? 0),
            ])
            // Someone with no target and nothing booked or committed this
            // period has no row worth showing.
            ->filter(fn ($r) => $r['target'] + $r['closed'] + $r['commit'] > 0)
            ->sortByDesc(fn ($r) => $r['target'] > 0 ? ($r['closed'] + $r['commit']) / $r['target'] : 0)
            ->values()->all();
    }

    private function deals(Collection $open, callable $mask): array
    {
        $rank = ['commit' => 0, 'best_case' => 1, 'pipeline' => 2];

        return $open
            ->sortBy(fn ($l) => [$rank[$l->forecast_category ?: 'pipeline'], $l->expected_close_date])
            ->take(8)
            ->map(fn (SalesLead $l) => [
                'id'                  => $l->id,
                'client'              => $l->hospital?->name ?? $l->hospital_name_raw ?? '—',
                'machine_type'        => $l->machine_type,
                'stage'               => $l->stage,
                'expected_close_date' => $l->expected_close_date?->toDateString(),
                'forecast_category'   => $l->forecast_category ?: 'pipeline',
                'value'               => $mask((int) $l->deal_value),
                'rep'                 => $l->assignee?->name,
            ])->values()->all();
    }

    // Logged calls/visits/demos + quotations sent + orders confirmed ("deal
    // won"), past 7 days; for a rep also what's planned in the next 7 days
    // (future-dated activities and lead follow-ups due).
    private function activity(Carbon $now): array
    {
        $since = $now->copy()->subDays(7);
        $until = $now->copy()->addDays(7);
        $owner = $this->ownerId;

        $items = SalesActivity::with(['createdBy', 'hospital', 'lead.hospital'])
            ->when($owner, fn ($q) => $q->where('created_by', $owner))
            ->whereBetween('occurs_at', [$since, $owner ? $until : $now])
            ->orderByDesc('occurs_at')->limit(30)->get()
            ->map(fn (SalesActivity $a) => [
                'kind'     => $a->type,
                'title'    => $a->subject,
                'client'   => $a->client_name,
                'by'       => $a->createdBy?->name,
                'at'       => $a->occurs_at->toIso8601String(),
                'upcoming' => $a->occurs_at->isFuture(),
            ]);

        $quotes = Quotation::with('createdBy')->whereNotNull('sent_at')->where('sent_at', '>=', $since)
            ->when($owner, fn ($q) => $q->where('created_by', $owner))
            ->latest('sent_at')->limit(10)->get()
            ->map(fn ($q) => [
                'kind'     => 'quote',
                'title'    => "Quotation sent {$q->quotation_number} · TSh " . number_format($this->ownerId ? (int) round($q->total_amount / 100000) * 100000 : $q->total_amount),
                'client'   => $q->client_name,
                'by'       => $q->createdBy?->name,
                'at'       => $q->sent_at->toIso8601String(),
                'upcoming' => false,
            ]);

        $orders = SalesOrder::with('createdBy')->whereIn('status', self::BOOKED_STATUSES)
            ->whereNotNull('confirmed_at')->where('confirmed_at', '>=', $since)
            ->when($owner, fn ($q) => $q->where('created_by', $owner))
            ->latest('confirmed_at')->limit(10)->get()
            ->map(fn ($o) => [
                'kind'     => 'won',
                'title'    => "Order confirmed {$o->order_number} · TSh " . number_format($this->ownerId ? (int) round($o->total_amount / 100000) * 100000 : $o->total_amount),
                'client'   => $o->client_name,
                'by'       => $o->createdBy?->name,
                'at'       => $o->confirmed_at->toIso8601String(),
                'upcoming' => false,
            ]);

        $followUps = ! $owner ? collect() : SalesLead::with('hospital')
            ->where('assigned_to', $owner)->whereNotIn('stage', ['won', 'lost'])
            ->whereBetween('follow_up_date', [$now->toDateString(), $until->toDateString()])
            ->get()
            ->map(fn (SalesLead $l) => [
                'kind'     => 'follow_up',
                'title'    => 'Follow up' . ($l->machine_type ? " — {$l->machine_type}" : ''),
                'client'   => $l->hospital?->name ?? $l->hospital_name_raw,
                'by'       => null,
                'at'       => $l->follow_up_date->copy()->startOfDay()->toIso8601String(),
                'upcoming' => true,
                'date_only' => true,
            ]);

        return $items->concat($quotes)->concat($orders)->concat($followUps)
            ->sortByDesc('at')->values()->take(40)->all();
    }

    // Warranties lapsing in the next 60 days — the moment to sell a service
    // contract. There's no service-contract model yet, so no contract value
    // or renewal status; "open deal" just says whether anyone is already
    // working that hospital. A rep sees machines at hospitals they've sold to
    // or hold a lead for.
    private function warranties(Carbon $now): array
    {
        $hospitalIds = null;
        if ($this->ownerId) {
            $hospitalIds = SalesLead::where('assigned_to', $this->ownerId)->whereNotNull('hospital_id')->pluck('hospital_id')
                ->concat(SalesOrder::where('created_by', $this->ownerId)->whereNotNull('hospital_id')->pluck('hospital_id'))
                ->unique()->values();
        }

        $machines = Machine::with('hospital')
            ->whereBetween('warranty_expiry', [$now->toDateString(), $now->copy()->addDays(60)->toDateString()])
            ->when($hospitalIds !== null, fn ($q) => $q->whereIn('hospital_id', $hospitalIds))
            ->orderBy('warranty_expiry')->get();

        $openDealHospitals = SalesLead::whereNotIn('stage', ['won', 'lost'])
            ->whereIn('hospital_id', $machines->pluck('hospital_id')->unique())
            ->pluck('hospital_id')->unique()->flip();

        return $machines->map(fn (Machine $m) => [
            'machine_id'      => $m->id,
            'hospital_id'     => $m->hospital_id,
            'client'          => $m->hospital?->name ?? '—',
            'model'           => $m->model,
            'type'            => $m->type,
            'serial_no'       => $m->serial_no,
            'warranty_expiry' => Carbon::parse($m->warranty_expiry)->toDateString(),
            'days_left'       => (int) $now->copy()->startOfDay()->diffInDays(Carbon::parse($m->warranty_expiry)),
            'open_deal'       => isset($openDealHospitals[$m->hospital_id]),
        ])->values()->all();
    }
}
