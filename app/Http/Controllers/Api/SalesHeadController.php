<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Delegation;
use App\Models\Hospital;
use App\Models\Invoice;
use App\Models\LeaveRequest;
use App\Models\Meeting;
use App\Models\Quotation;
use App\Models\SalesActivity;
use App\Models\SalesLead;
use App\Models\SalesOrder;
use App\Models\Setting;
use App\Models\Tender;
use App\Models\TenderDocument;
use App\Models\User;
use App\Services\EffectivePermissionResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/**
 * The Head of Sales workspace (2026-11-11). Every figure comes from records
 * the system already keeps — leads, quotations, orders, invoices, tenders,
 * meetings, sales activities, contacts, users. Nothing here is a second copy
 * of that data; the only things stored by this controller are the company
 * quarter target (settings) and the reps' discount ceilings (users).
 *
 * Gate: screens.sales_head (granted to the Head of Sales as a per-user
 * override) or admin tier.
 */
class SalesHeadController extends Controller
{
    /** A deal this big, or anything with a ministry, counts as strategic. */
    private const STRATEGIC_VALUE = 5_000_000;
    private const BOOKED = ['confirmed', 'delivering', 'delivered'];
    private const LIVE_TENDER = ['identified', 'preparing_bid', 'bid_submitted', 'awaiting_award'];
    private const WON_TENDER = ['won', 'accepted', 'performance_security_submitted', 'contract_signed', 'fulfilling', 'delivered', 'closed'];

    private const STAGES = [
        'lead' => ['Lead', 'mute'],
        'qualified' => ['Qualified', 'info'],
        'demo_scheduled' => ['Demo booked', 'violet'],
        'proposal_sent' => ['Proposal sent', 'warn'],
        'negotiation' => ['Negotiation', 'cyan'],
        'won' => ['Won', 'ok'],
        'lost' => ['Lost', 'bad'],
    ];

    private function gate(Request $request): User
    {
        $user = $request->user();
        $r = app(EffectivePermissionResolver::class);
        abort_unless($r->can($user, 'screens.sales_head') || $r->can($user, 'authority.admin_tier'), 403, 'Only the Head of Sales can open this.');

        return $user;
    }

    /** Everyone selling: the same set the targets and overview use, minus the head. */
    private function team(User $head): Collection
    {
        return User::whereIn('role', SalesTargetController::REP_ROLES)
            ->where('is_active', true)->where('id', '!=', $head->id)
            ->orderBy('name')->get();
    }

    private static function tsh(int|float $v): string
    {
        $n = (int) round($v);
        $a = abs($n);
        $s = $n < 0 ? '−' : '';
        if ($a >= 1_000_000_000) return $s.'TSh '.number_format($a / 1e9, 2).'B';
        if ($a >= 1_000_000) return $s.'TSh '.number_format($a / 1e6, 1).'M';
        if ($a >= 1_000) return $s.'TSh '.number_format($a / 1e3, 0).'K';

        return $s.'TSh '.$a;
    }

    private static function who(?User $u, User $head): string
    {
        if (! $u) return 'Unassigned';

        return $u->id === $head->id ? 'You' : $u->name;
    }

    private static function stage(string $s): array
    {
        return self::STAGES[$s] ?? [ucfirst(str_replace('_', ' ', $s)), 'mute'];
    }

    private static function account(SalesLead $l): string
    {
        return $l->hospital?->name ?? $l->hospital_name_raw ?? '—';
    }

    private static function daysAgo(?Carbon $d): ?int
    {
        return $d ? (int) $d->copy()->startOfDay()->diffInDays(now()->startOfDay()) : null;
    }

    private function companyTargetFor(int $year, int $quarter): int
    {
        return (int) Setting::get("sales_head.company_target.{$year}.q{$quarter}", 0);
    }

    // ── Dashboard: strategic deals ──────────────────────────────────────────

    public function strategicDeals(Request $request)
    {
        $head = $this->gate($request);
        $today = now()->startOfDay();

        $deals = SalesLead::with(['hospital', 'contact', 'assignee'])
            ->whereNotIn('stage', ['won', 'lost'])
            ->where(fn ($q) => $q->where('deal_value', '>=', self::STRATEGIC_VALUE)
                ->orWhereHas('hospital', fn ($h) => $h->where('name', 'ilike', '%ministry%')))
            ->orderByDesc('deal_value')->limit(15)->get()
            ->map(function (SalesLead $l) use ($head, $today) {
                [$stage, $tone] = self::stage($l->stage);
                $contact = $l->contact
                    ? trim($l->contact->first_name.' '.$l->contact->last_name).($l->contact->job_title ? ' · '.$l->contact->job_title : '')
                    : ($l->contact_name_raw ?: 'No contact recorded');
                $follow = $l->follow_up_date ? Carbon::parse($l->follow_up_date) : null;
                [$next, $nextTone] = match (true) {
                    $follow && $follow->lt($today) => ['Follow-up was due '.$follow->format('j M'), 'warn'],
                    $follow !== null => ['Follow up '.$follow->format('j M'), 'mute'],
                    $l->expected_close_date !== null && Carbon::parse($l->expected_close_date)->lt($today) => ['Slipped · was due to close '.Carbon::parse($l->expected_close_date)->format('j M'), 'warn'],
                    $l->expected_close_date !== null => ['Expected to close '.Carbon::parse($l->expected_close_date)->format('j M'), 'mute'],
                    default => ['No next step set', 'warn'],
                };

                return [
                    'lead_id' => $l->id,
                    'account' => self::account($l),
                    'deal' => $l->machine_type ?: 'Deal',
                    'stage' => $stage, 'stage_tone' => $tone,
                    'who_decides' => $contact,
                    'next_move' => $next, 'next_tone' => $nextTone,
                    'owner' => self::who($l->assignee, $head),
                    'value' => (int) $l->deal_value,
                ];
            })->values();

        return response()->json(['data' => [
            'deals' => $deals,
            // Same periods as the sales overview; the target is set per quarter.
            'company_target' => match ($request->query('period')) {
                'month' => (int) round($this->companyTargetFor(now()->year, now()->quarter) / 3),
                'quarter' => $this->companyTargetFor(now()->year, now()->quarter),
                default => collect(range(1, 4))->sum(fn ($q) => $this->companyTargetFor(now()->year, $q)),
            },
        ]]);
    }

    // ── Calendar ────────────────────────────────────────────────────────────

    public function calendar(Request $request)
    {
        $head = $this->gate($request);
        $start = Carbon::parse($request->query('week_start', now()->startOfWeek()->toDateString()))->startOfDay();
        $end = $start->copy()->addDays(7);
        $teamIds = $this->team($head)->pluck('id')->push($head->id);
        $events = collect();
        $stakes = collect();

        // Meetings the head chairs, records, created or attends.
        Meeting::with('attendees')
            ->whereBetween('starts_at', [$start, $end])
            ->where(fn ($q) => $q->where('chaired_by', $head->id)->orWhere('created_by', $head->id)->orWhere('recorded_by', $head->id)
                ->orWhereHas('attendees', fn ($a) => $a->where('user_id', $head->id)))
            ->get()->each(function (Meeting $m) use ($events) {
                $guests = $m->attendees->whereNull('user_id');
                $events->push([
                    'starts_at' => $m->starts_at->toIso8601String(),
                    'ends_at' => ($m->ends_at ?? $m->starts_at->copy()->addHour())->toIso8601String(),
                    'title' => $m->title,
                    'where' => $m->location ?: ($m->online_link ? 'Online' : ($guests->first()?->guest_organisation ?? '')),
                    'kind' => $guests->isNotEmpty() ? 'client' : 'team',
                ]);
            });

        // Client visits, meetings and demos the team logged for this week.
        SalesActivity::with(['createdBy', 'hospital', 'lead.hospital'])
            ->whereIn('created_by', $teamIds)->whereIn('type', ['visit', 'meeting', 'demo'])
            ->whereBetween('occurs_at', [$start, $end])->get()
            ->each(function (SalesActivity $a) use ($events, $head) {
                $events->push([
                    'starts_at' => $a->occurs_at->toIso8601String(),
                    'ends_at' => $a->occurs_at->copy()->addHour()->toIso8601String(),
                    'title' => $a->subject ?: ucfirst($a->type).' · '.($a->client_name ?? 'client'),
                    'where' => trim(($a->client_name ?? '').' · '.self::who($a->createdBy, $head), ' ·'),
                    'kind' => 'client',
                ]);
            });

        // Demos booked on leads.
        SalesLead::with(['hospital', 'assignee'])->whereNotIn('stage', ['won', 'lost'])
            ->whereBetween('demo_date', [$start, $end])->get()
            ->each(function (SalesLead $l) use ($events, $head) {
                $at = Carbon::parse($l->demo_date)->setTime(10, 0);
                $events->push([
                    'starts_at' => $at->toIso8601String(), 'ends_at' => $at->copy()->addHour()->toIso8601String(),
                    'title' => 'Demo · '.($l->machine_type ?: 'deal'),
                    'where' => self::account($l).' · '.self::who($l->assignee, $head),
                    'kind' => 'client',
                ]);
            });

        // Tender bid deadlines and contract signings (dates only — shown late afternoon).
        Tender::with('procuringEntity')->whereNotIn('status', Tender::TERMINAL)
            ->where(fn ($q) => $q->whereBetween('bid_submission_deadline', [$start, $end])->orWhereBetween('contract_signing_deadline', [$start, $end]))
            ->get()->each(function (Tender $t) use ($events, $stakes, $start, $end) {
                $value = (int) ($t->contract_value ?: $t->estimated_value);
                foreach ([['bid_submission_deadline', 'Bid deadline', 'tender', 'warn'], ['contract_signing_deadline', 'Contract signing', 'signing', 'ok']] as [$col, $label, $kind, $tone]) {
                    $d = $t->{$col};
                    if (! $d || $d->lt($start) || $d->gte($end)) continue;
                    $at = $d->copy()->setTime(15, 0);
                    $events->push([
                        'starts_at' => $at->toIso8601String(), 'ends_at' => $at->copy()->addHour()->toIso8601String(),
                        'title' => $label.' · '.$t->title, 'where' => $t->procuringEntity?->name ?? ($t->tender_number ?? ''), 'kind' => $kind,
                    ]);
                    $stakes->push(['title' => $t->title, 'value' => self::tsh($value), 'raw' => $value,
                        'when' => $label.' '.$d->format('D j M'), 'note' => $t->procuringEntity?->name ?? '', 'tone' => $tone]);
                }
            });

        // Open deals expected to close this week.
        SalesLead::with(['hospital', 'assignee'])->whereNotIn('stage', ['won', 'lost'])
            ->whereBetween('expected_close_date', [$start, $end])->orderByDesc('deal_value')->limit(6)->get()
            ->each(function (SalesLead $l) use ($stakes, $head) {
                $stakes->push(['title' => self::account($l).' · '.($l->machine_type ?: 'deal'), 'value' => self::tsh((int) $l->deal_value), 'raw' => (int) $l->deal_value,
                    'when' => 'Expected to close '.Carbon::parse($l->expected_close_date)->format('D j M'),
                    'note' => self::who($l->assignee, $head).' · '.self::stage($l->stage)[0], 'tone' => 'cyan']);
            });

        return response()->json(['data' => [
            'week_start' => $start->toIso8601String(),
            'events' => $events->sortBy('starts_at')->values(),
            'stakes' => $stakes->sortByDesc('raw')->take(5)->map(fn ($s) => collect($s)->except('raw'))->values(),
        ]]);
    }

    // ── Key accounts ────────────────────────────────────────────────────────

    public function keyAccounts(Request $request)
    {
        $head = $this->gate($request);
        $since = now()->subMonths(12)->startOfDay();

        $revenue = Invoice::whereNotNull('hospital_id')->where('issue_date', '>=', $since)
            ->whereNotIn('status', ['cancelled', 'void'])
            ->selectRaw('hospital_id, SUM(total) AS total')->groupBy('hospital_id')->pluck('total', 'hospital_id');
        $openLeads = SalesLead::with('assignee')->whereNotIn('stage', ['won', 'lost'])->whereNotNull('hospital_id')->get()->groupBy('hospital_id');

        $ids = $revenue->sortDesc()->keys()->take(15)
            ->merge($openLeads->keys())->unique()->values();
        $hospitals = Hospital::whereIn('id', $ids)->get()->keyBy('id');
        $machines = DB::table('machines')->whereIn('hospital_id', $ids)->selectRaw('hospital_id, COUNT(*) AS n')->groupBy('hospital_id')->pluck('n', 'hospital_id');
        $contacts = Contact::whereIn('hospital_id', $ids)->orderByDesc('last_contacted_at')->get()->groupBy('hospital_id');
        $lastActivity = SalesActivity::whereIn('hospital_id', $ids)->selectRaw('hospital_id, MAX(occurs_at) AS at')->groupBy('hospital_id')->pluck('at', 'hospital_id');
        $lastInvoiceBy = Invoice::with('creator')->whereIn('hospital_id', $ids)->orderByDesc('issue_date')->get()->unique('hospital_id')->keyBy('hospital_id');

        $rows = $ids->map(function ($id) use ($hospitals, $revenue, $openLeads, $machines, $contacts, $lastActivity, $lastInvoiceBy, $head) {
            $h = $hospitals[$id] ?? null;
            if (! $h) return null;
            $leads = $openLeads[$id] ?? collect();
            $people = $contacts[$id] ?? collect();
            $last = collect([$people->max('last_contacted_at'), $lastActivity[$id] ?? null])->filter()->map(fn ($d) => Carbon::parse($d))->max();
            $owner = $leads->sortByDesc('deal_value')->first()?->assignee ?? $lastInvoiceBy[$id]?->creator;

            return [
                'id' => $h->id,
                'name' => $h->name,
                'type' => collect([$h->type ? ucwords(str_replace('_', ' ', $h->type)) : null, $h->region])->filter()->implode(' · '),
                'revenue_12m' => (int) ($revenue[$id] ?? 0),
                'open_deals' => $leads->count(),
                'machines' => (int) ($machines[$id] ?? 0),
                'last_contact_days' => self::daysAgo($last),
                'owner' => self::who($owner, $head),
                'note' => (string) ($h->notes ?? ''),
                'people' => $people->take(5)->map(fn (Contact $c) => [
                    'label' => trim($c->first_name.' '.$c->last_name),
                    'value' => $c->job_title ?? '',
                    'note' => $c->last_contacted_at ? 'Contacted '.Carbon::parse($c->last_contacted_at)->format('j M') : 'Not contacted yet',
                    'tone' => $c->last_contacted_at && Carbon::parse($c->last_contacted_at)->gt(now()->subDays(30)) ? 'ok' : 'mute',
                ])->values(),
                'deals' => $leads->sortByDesc('deal_value')->take(5)->map(fn (SalesLead $l) => [
                    'label' => $l->machine_type ?: 'Deal',
                    'value' => self::tsh((int) $l->deal_value),
                    'note' => self::stage($l->stage)[0], 'tone' => self::stage($l->stage)[1],
                ])->values(),
            ];
        })->filter()->sortByDesc('revenue_12m')->values();

        return response()->json(['data' => ['accounts' => $rows]]);
    }

    // ── Tenders & contracts ─────────────────────────────────────────────────

    public function tenders(Request $request)
    {
        $head = $this->gate($request);

        $live = Tender::with(['owner', 'procuringEntity', 'documents'])->whereIn('status', self::LIVE_TENDER)
            ->orderByRaw('bid_submission_deadline IS NULL, bid_submission_deadline')->get()
            ->map(function (Tender $t) use ($head) {
                // Before the award only the bid documents apply.
                $needed = ['power_of_attorney', 'bid_securing_declaration'];
                $docs = collect($needed)->map(function ($type) use ($t) {
                    $d = $t->documents->firstWhere('type', $type);
                    $state = $d?->executed_path ? 'Signed copy uploaded' : ($d?->draft_path ? 'Draft generated' : 'Not started');

                    return ['label' => TenderDocument::LABELS[$type] ?? $type, 'value' => $state, 'tone' => $d?->executed_path ? 'ok' : 'warn'];
                });

                return [
                    'tender_id' => $t->id,
                    'name' => $t->title,
                    'ref' => collect([$t->tender_number, $t->procuringEntity?->name, $t->currency !== 'TZS' ? $t->currency : null])->filter()->implode(' · '),
                    'closes_at' => $t->bid_submission_deadline?->copy()->setTime(16, 0)->toIso8601String(),
                    'status' => ucfirst(str_replace('_', ' ', $t->status)),
                    'status_tone' => match ($t->status) { 'preparing_bid' => 'warn', 'bid_submitted', 'awaiting_award' => 'cyan', default => 'mute' },
                    'docs_done' => $docs->where('tone', 'ok')->count(),
                    'docs_total' => $docs->count(),
                    'owner' => self::who($t->owner, $head),
                    'value' => (int) ($t->estimated_value ?? 0),
                    'docs' => $docs->values(),
                ];
            });

        $year = now()->year;
        $decided = Tender::whereYear('created_at', $year)->whereIn('status', [...self::WON_TENDER, 'lost'])->get();
        $won = $decided->whereIn('status', self::WON_TENDER)->count();

        $contracts = Tender::whereIn('status', ['won', 'accepted', 'performance_security_submitted', 'contract_signed', 'fulfilling'])
            ->orderByRaw('delivery_deadline IS NULL, delivery_deadline')->limit(8)->get()
            ->map(function (Tender $t) {
                $due = $t->delivery_deadline ?? $t->contract_signing_deadline;
                $late = $due && $due->lt(now()->startOfDay());

                return [
                    'title' => $t->title,
                    'note' => ucfirst(str_replace('_', ' ', $t->status)),
                    'value' => self::tsh((int) ($t->contract_value ?: $t->estimated_value)),
                    'when' => $due ? ($t->delivery_deadline ? 'deliver by ' : 'sign by ').$due->format('j M') : 'no date set',
                    'tone' => $late ? 'warn' : 'mute',
                ];
            });

        return response()->json(['data' => [
            'summary' => $decided->isEmpty() ? 'No tender results yet this year' : "Won {$won} of {$decided->count()} decided this year",
            'tenders' => $live->values(),
            'contracts' => $contracts->values(),
        ]]);
    }

    // ── Relationships ───────────────────────────────────────────────────────

    public function relationships(Request $request)
    {
        $head = $this->gate($request);
        $open = SalesLead::with('assignee')->whereNotIn('stage', ['won', 'lost'])->get();
        $byContact = $open->whereNotNull('contact_id')->groupBy('contact_id');
        $byHospital = $open->whereNotNull('hospital_id')->groupBy('hospital_id');

        $contacts = Contact::with(['hospital', 'interactions' => fn ($q) => $q->latest()->limit(5)])
            ->where(fn ($q) => $q->whereIn('id', $byContact->keys())->orWhereIn('hospital_id', $byHospital->keys())
                ->orWhere('last_contacted_at', '>=', now()->subMonths(6)))
            ->get()
            ->map(function (Contact $c) use ($byContact, $byHospital, $head) {
                $leads = ($byContact[$c->id] ?? collect())->merge($byHospital[$c->hospital_id] ?? collect())->unique('id');
                $last = collect([$c->last_contacted_at, $c->interactions->max('created_at')])->filter()->map(fn ($d) => Carbon::parse($d))->max();
                $days = self::daysAgo($last);
                $owner = $leads->sortByDesc('deal_value')->first()?->assignee;

                return [
                    'id' => $c->id,
                    'name' => trim($c->first_name.' '.$c->last_name),
                    'role' => collect([$c->job_title, $c->hospital?->name])->filter()->implode(' · '),
                    'last_met' => $days === null ? 'never' : ($days === 0 ? 'today' : ($days === 1 ? 'yesterday' : "{$days} days ago")),
                    'last_met_days' => $days,
                    'deals' => $leads->pluck('machine_type')->filter()->unique()->take(3)->implode(', '),
                    'owner' => $owner ? self::who($owner, $head) : '—',
                    'about' => '',
                    'meetings' => $c->interactions->map(fn ($i) => [
                        'what' => ucfirst(str_replace('_', ' ', $i->type ?? 'contact')).($i->summary ? ' · '.$i->summary : ''),
                        'when' => Carbon::parse($i->created_at)->format('j M'),
                        'note' => collect([$i->outcome, $i->next_action ? 'Next: '.$i->next_action : null])->filter()->implode(' · '),
                        'planned' => $i->next_action_date && Carbon::parse($i->next_action_date)->gte(now()->startOfDay()),
                    ])->values(),
                    'value' => (int) $leads->sum('deal_value'),
                ];
            })
            ->sortByDesc('value')->take(60)->values();

        return response()->json(['data' => ['contacts' => $contacts]]);
    }

    // ── My team: this week's activity ───────────────────────────────────────

    public function teamActivity(Request $request)
    {
        $head = $this->gate($request);
        $team = $this->team($head);
        $mon = now()->startOfWeek();
        $sat = $mon->copy()->addDays(5);

        $acts = SalesActivity::whereIn('created_by', $team->pluck('id'))->whereBetween('occurs_at', [$mon, $sat])->get();
        $quotes = Quotation::whereIn('created_by', $team->pluck('id'))->whereBetween('created_at', [$mon, $sat])->get(['created_by', 'created_at']);

        $activity = $team->map(function (User $u) use ($acts, $quotes, $mon) {
            $days = [];
            for ($d = 0; $d < 5; $d++) {
                $day = $mon->copy()->addDays($d)->toDateString();
                $mine = $acts->where('created_by', $u->id)->filter(fn ($a) => $a->occurs_at->toDateString() === $day);
                $days[] = [
                    $mine->whereIn('type', ['visit', 'meeting', 'demo'])->count(),
                    $mine->whereIn('type', ['call', 'whatsapp', 'email'])->count(),
                    $quotes->where('created_by', $u->id)->filter(fn ($q) => $q->created_at->toDateString() === $day)->count(),
                ];
            }

            return ['name' => $u->name, 'days' => $days];
        });

        // Facts worth a look, per person — all computed, nothing typed in.
        $openQuotes = Quotation::whereIn('created_by', $team->pluck('id'))->whereIn('status', ['draft', 'sent'])->where('subtotal', '>', 0)->get();
        $flags = $team->map(function (User $u) use ($openQuotes, $acts) {
            $mine = $openQuotes->where('created_by', $u->id);
            $avg = $mine->isEmpty() ? null : $mine->avg(fn ($q) => $q->discount_amount / max(1, $q->subtotal) * 100);
            $limit = $u->max_discount_percent !== null ? (float) $u->max_discount_percent : null;
            if ($avg !== null && $limit !== null && $avg > $limit) {
                return ['name' => $u->name, 'label' => sprintf('Open quotes average %.1f%% off against a %.0f%% limit.', $avg, $limit), 'tone' => 'warn'];
            }
            if ($acts->where('created_by', $u->id)->isEmpty()) {
                return ['name' => $u->name, 'label' => 'No calls or visits logged this week.', 'tone' => 'info'];
            }

            return null;
        })->filter()->values();

        $latest = SalesActivity::with(['createdBy', 'hospital', 'lead.hospital'])->whereIn('created_by', $team->pluck('id'))
            ->latest('occurs_at')->limit(8)->get()
            ->map(fn (SalesActivity $a) => [
                'title' => $a->createdBy?->name ?? '—',
                'when' => $a->occurs_at->format('j M'),
                'note' => collect([ucfirst($a->type), $a->subject, $a->client_name])->filter()->implode(' · '),
            ]);

        return response()->json(['data' => [
            'day_labels' => ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'],
            'activity' => $activity->values(),
            'notes' => $latest->values(),
            'flags' => $flags,
        ]]);
    }

    // ── Company target (per quarter) ────────────────────────────────────────

    public function companyTarget(Request $request)
    {
        $this->gate($request);
        $year = (int) $request->query('year', now()->year);
        $quarter = max(1, min(4, (int) $request->query('quarter', now()->quarter)));

        return response()->json(['data' => ['year' => $year, 'quarter' => $quarter, 'amount' => $this->companyTargetFor($year, $quarter)]]);
    }

    public function setCompanyTarget(Request $request)
    {
        $head = $this->gate($request);
        $v = $request->validate([
            'year' => ['required', 'integer', 'between:2020,2100'],
            'quarter' => ['required', 'integer', 'between:1,4'],
            'amount' => ['required', 'integer', 'min:0'],
        ]);
        Setting::set("sales_head.company_target.{$v['year']}.q{$v['quarter']}", (string) $v['amount'], $head->id);

        return response()->json(['data' => $v]);
    }

    // ── Commission (this quarter) ───────────────────────────────────────────

    public function commission(Request $request)
    {
        $head = $this->gate($request);
        $start = now()->startOfQuarter();
        $end = now()->endOfQuarter();
        $people = $this->team($head)->push($head);

        $orders = SalesOrder::with(['invoices'])->whereIn('status', self::BOOKED)
            ->whereBetween(DB::raw('COALESCE(confirmed_at, created_at)'), [$start, $end])
            ->get();

        $rows = $people->map(function (User $u) use ($orders, $head) {
            $closed = $orders->where('created_by', $u->id);
            $earning = $orders->where('commission_agent_id', $u->id);
            $earned = 0;
            $waiting = 0;
            foreach ($earning as $o) {
                $total = max(1, (int) $o->invoices->sum('total'));
                $paidShare = $o->invoices->isEmpty() ? 0 : min(1, $o->invoices->sum('amount_paid') / $total);
                $earned += (int) round($o->commission_amount * $paidShare);
                $waiting += (int) round($o->commission_amount * (1 - $paidShare));
            }
            $paid = (int) $closed->sum(fn ($o) => $o->invoices->sum('amount_paid'));
            $rate = $u->commission_percent !== null ? rtrim(rtrim(number_format((float) $u->commission_percent, 1), '0'), '.').'%' : '—';
            $parts = preg_split('/\s+/', trim($u->name)) ?: [''];

            return [
                'name' => $u->id === $head->id ? $u->name.' (you)' : $u->name,
                'initials' => strtoupper(substr($parts[0], 0, 1).(count($parts) > 1 ? substr(end($parts), 0, 1) : '')),
                'plan' => $u->commission_percent !== null ? "{$rate} of each order they earn on" : 'No commission rate set',
                'closed' => (int) $closed->sum('total_amount'),
                'paid_by_client' => $paid,
                'rate' => $rate,
                'rate_tone' => $u->commission_percent === null ? 'dim' : 'text',
                'earned' => $earned,
                'waiting' => $waiting,
                'status' => $earned > 0 ? 'Earned' : ($waiting > 0 ? 'Waiting' : '—'),
                'status_tone' => $earned > 0 ? 'ok' : ($waiting > 0 ? 'warn' : 'mute'),
            ];
        })->filter(fn ($r) => $r['closed'] > 0 || $r['earned'] > 0 || $r['waiting'] > 0 || $r['rate'] !== '—')->values();

        $held = $orders->whereNotNull('commission_agent_id')->where('commission_amount', '>', 0)
            ->flatMap(fn ($o) => $o->invoices->map(fn ($i) => [$o, $i]))
            ->filter(fn ($p) => $p[1]->amount_paid < $p[1]->total)
            ->map(function ($p) use ($people) {
                [$o, $i] = $p;
                $agent = $people->firstWhere('id', $o->commission_agent_id)?->name ?? '—';
                $due = $i->due_date ? Carbon::parse($i->due_date) : null;
                $late = $due && $due->lt(now()->startOfDay()) ? (int) $due->diffInDays(now()->startOfDay()).' days overdue' : ($due ? 'due '.$due->format('j M') : 'no due date');
                $share = $o->commission_amount * (1 - $i->amount_paid / max(1, $i->total)) * ($i->total / max(1, $o->invoices->sum('total')));

                return ['title' => $i->invoice_number.' · '.($i->client_name ?? $o->client_name), 'note' => "{$agent} · {$late}", 'value' => self::tsh($share), 'raw' => $share];
            })->sortByDesc('raw')->take(8)->map(fn ($h) => collect($h)->except('raw'))->values();

        return response()->json(['data' => [
            'period' => 'Q'.now()->quarter.' '.now()->year,
            'payout_note' => 'Commission is set per order (the commission agent and %). It counts as earned as the client pays the order’s invoices.',
            'rows' => $rows,
            'tiers' => $rows->map(fn ($r) => ['label' => $r['name'], 'value' => $r['rate']])->values(),
            'held' => $held,
        ]]);
    }

    // ── Team access (read-only) ─────────────────────────────────────────────

    private const ACCESS_AREAS = [
        ['Raise sales', 'Quotations, orders, invoices', 'sales.create', 'Can', 'Cannot'],
        ['Issue quotations', 'Send a quotation to the client', 'sales.issue_quotation', 'Can', 'Cannot'],
        ['Edit sales', 'Change quotations and orders', 'sales.edit', 'Can', 'Cannot'],
        ['Approve discounts', 'Quotes over someone’s limit', 'sales.approve_order', 'Approves', 'Asks'],
        ['Exact figures', 'Revenue to the shilling', 'sales.view_full_numbers', 'Exact', 'Rounded'],
        ['Add team members', 'Create sales users under them', 'sales.create_subordinate_user', 'Can', 'Cannot'],
    ];

    /** Sidebar entries a sales person can get, in the app's order. */
    private const NAV = [
        ['Operations', 'Dashboard', 'dashboard'], ['Operations', 'Approvals', 'approvals'], ['Operations', 'Machines', 'machines'],
        ['Operations', 'Hospitals', 'hospitals'], ['Operations', 'Service', 'service'], ['Operations', 'Inventory', 'inventory'],
        ['Operations', 'Staff', 'staff'], ['Operations', 'My Leave', 'my_leave'],
        ['Business', 'Purchases', 'purchases'], ['Business', 'Sales', 'sales'], ['Business', 'Quotations', 'quotations'],
        ['Business', 'Customers', 'customers'], ['Business', 'Revenue', 'revenue'], ['Business', 'Finance', 'finance'],
        ['Business', 'Tenders', 'tenders'], ['Business', 'Shipments', 'shipments'],
        ['System', 'Reports', 'reports'], ['System', 'Settings', 'settings'],
    ];

    public function access(Request $request)
    {
        $head = $this->gate($request);
        $resolver = app(EffectivePermissionResolver::class);
        $roles = Role::whereIn('name', SalesTargetController::REP_ROLES)->with('permissions')->get()->keyBy('name');
        $team = $this->team($head);
        $limits = fn (string $role) => $team->where('role', $role)->pluck('max_discount_percent')->filter(fn ($v) => $v !== null)->map(fn ($v) => (float) $v);

        $roleNames = ['sales_manager' => 'Sales manager', 'sales' => 'Sales rep'];
        $cols = collect($roleNames)->filter(fn ($_, $r) => $roles->has($r));
        $has = fn (string $role, string $perm) => (bool) $roles[$role]?->permissions->contains('name', $perm);

        $rows = collect(self::ACCESS_AREAS)->map(fn ($a) => [
            'area' => $a[0], 'note' => $a[1],
            'cells' => [
                ['level' => $resolver->can($head, $a[2]) ? 'full' : 'hide', 'label' => $resolver->can($head, $a[2]) ? $a[3] : $a[4]],
                ...$cols->keys()->map(fn ($r) => ['level' => $has($r, $a[2]) ? 'full' : 'hide', 'label' => $has($r, $a[2]) ? $a[3] : $a[4]])->all(),
            ],
        ])->push([
            'area' => 'Discount without approval', 'note' => 'Each person’s ceiling, set in Settings',
            'cells' => [
                ['level' => 'full', 'label' => 'Any amount'],
                ...$cols->keys()->map(function ($r) use ($limits) {
                    $l = $limits($r);
                    if ($l->isEmpty()) return ['level' => 'hide', 'label' => 'None set'];
                    $lo = $l->min(); $hi = $l->max();

                    return ['level' => 'view', 'label' => $lo === $hi ? 'Up to '.rtrim(rtrim(number_format($hi, 1), '0'), '.').'%' : sprintf('%s–%s%%', rtrim(rtrim(number_format($lo, 1), '0'), '.'), rtrim(rtrim(number_format($hi, 1), '0'), '.'))];
                })->all(),
            ],
        ]);

        $sidebars = $cols->mapWithKeys(fn ($label, $r) => [$label => collect(self::NAV)->map(fn ($n) => [
            'group' => $n[0], 'label' => $n[1], 'visible' => $has($r, 'screens.'.$n[2]), 'sub' => false,
        ])->values()]);

        return response()->json(['data' => [
            'roles' => ['You', ...$cols->values()->all()],
            'role_counts' => ['1', ...$cols->keys()->map(fn ($r) => (string) $team->where('role', $r)->count())->all()],
            'rows' => $rows->values(),
            'sidebars' => $sidebars,
            'footnote' => 'Read from each role’s permissions. A per-person override in Settings › Team & Roles can differ from the role.',
        ]]);
    }

    // ── Reports (this year) ─────────────────────────────────────────────────

    public function reports(Request $request)
    {
        $head = $this->gate($request);
        $from = now()->startOfYear();
        $users = User::whereIn('id', $this->team($head)->pluck('id')->push($head->id))->get()->keyBy('id');
        $name = fn ($id) => $users[$id]?->name ?? 'Someone else';

        // 1. Discounts given away.
        $quotes = Quotation::where('created_at', '>=', $from)->where('subtotal', '>', 0)->get(['created_by', 'subtotal', 'discount_amount']);
        $given = (int) $quotes->sum('discount_amount');
        $over = $quotes->filter(fn ($q) => ($l = $users[$q->created_by]?->max_discount_percent) !== null && $q->discount_amount / $q->subtotal * 100 > (float) $l)->count();
        $byRep = $quotes->groupBy('created_by')->map(fn ($g, $id) => ['label' => $name($id), 'value' => (int) $g->sum('discount_amount')])->sortByDesc('value')->take(8);
        $topShare = $given > 0 && $byRep->isNotEmpty() ? $byRep->first()['value'] / $given : 0;

        // 2. Wins and losses.
        $closed = SalesLead::whereIn('stage', ['won', 'lost'])->where('updated_at', '>=', $from)->get(['stage', 'assigned_to']);
        $won = $closed->where('stage', 'won')->count();
        $lost = $closed->where('stage', 'lost')->count();
        $winByRep = $closed->groupBy('assigned_to')->map(fn ($g, $id) => [
            'label' => $id ? $name($id) : 'Unassigned', 'won' => $g->where('stage', 'won')->count(), 'n' => $g->count(),
        ])->sortByDesc('n')->take(8);

        // 3. Tender results.
        $tenders = Tender::where('created_at', '>=', $from)->get(['status', 'estimated_value', 'contract_value']);
        $tWon = $tenders->whereIn('status', self::WON_TENDER);
        $tByStatus = $tenders->groupBy('status')->map(fn ($g, $s) => ['label' => ucfirst(str_replace('_', ' ', $s)), 'value' => $g->count(), 'tone' => in_array($s, self::WON_TENDER) ? 'ok' : ($s === 'lost' ? 'bad' : 'mute')])->sortByDesc('value');

        // 4. Where deals come from.
        $leads = SalesLead::where('created_at', '>=', $from)->get(['source', 'deal_value']);
        $sourceLabel = ['referral' => 'Referral', 'tender' => 'Tender', 'inbound_call' => 'Client called us', 'walk_in' => 'Walk-in', 'other' => 'Other'];
        $bySource = $leads->groupBy(fn ($l) => $l->source ?: 'unknown')->map(fn ($g, $s) => ['label' => $sourceLabel[$s] ?? 'Not recorded', 'value' => (int) $g->sum('deal_value'), 'n' => $g->count()])->sortByDesc('value');
        $referral = $bySource['referral'] ?? ['value' => 0, 'n' => 0];
        $leadTotal = max(1, (int) $leads->sum('deal_value'));

        $year = (string) now()->year;
        $reports = [
            [
                'key' => 'discounts', 'name' => 'Discounts given away', 'description' => 'Discount on quotations, by person', 'schedule' => $year,
                'summary' => self::tsh($given).' given in discounts this year · '.($quotes->sum('subtotal') > 0 ? number_format($given / $quotes->sum('subtotal') * 100, 1) : '0').'% of quote value',
                'kpis' => [
                    ['label' => 'Given away', 'value' => self::tsh($given), 'tone' => 'warn'],
                    ['label' => 'Quotations', 'value' => (string) $quotes->count()],
                    ['label' => 'Over the limit', 'value' => "{$over}", 'tone' => $over > 0 ? 'warn' : 'text'],
                ],
                'chart_label' => 'Discount by person',
                'rows' => $byRep->map(fn ($r) => ['label' => $r['label'], 'value' => $r['value'], 'display' => self::tsh($r['value']), 'tone' => 'info'])->values(),
                'takeaway' => $topShare >= 0.4 ? sprintf('%s gave %d%% of all discount this year.', $byRep->first()['label'], round($topShare * 100)) : '',
            ],
            [
                'key' => 'win_loss', 'name' => 'Wins and losses', 'description' => 'Deals closed won or lost, by person', 'schedule' => $year,
                'summary' => "{$won} won · {$lost} lost this year".($won + $lost > 0 ? ' · '.round($won / ($won + $lost) * 100).'% win rate' : ''),
                'kpis' => [
                    ['label' => 'Won', 'value' => (string) $won, 'tone' => 'ok'],
                    ['label' => 'Lost', 'value' => (string) $lost],
                    ['label' => 'Win rate', 'value' => $won + $lost > 0 ? round($won / ($won + $lost) * 100).'%' : '—'],
                ],
                'chart_label' => 'Win rate by person · %',
                'rows' => $winByRep->map(fn ($r) => ['label' => $r['label'], 'value' => $r['n'] ? round($r['won'] / $r['n'] * 100) : 0,
                    'display' => "{$r['won']} of {$r['n']}", 'tone' => 'ok'])->values(),
                'takeaway' => '',
            ],
            [
                'key' => 'tenders', 'name' => 'Tender results', 'description' => 'Tenders started this year, by where they ended up', 'schedule' => $year,
                'summary' => "{$tenders->count()} tenders this year · {$tWon->count()} won",
                'kpis' => [
                    ['label' => 'Won', 'value' => $tWon->count().' / '.$tenders->count(), 'tone' => 'ok'],
                    ['label' => 'Lost', 'value' => (string) $tenders->where('status', 'lost')->count()],
                    ['label' => 'Value won', 'value' => self::tsh((int) $tWon->sum(fn ($t) => $t->contract_value ?: $t->estimated_value)), 'tone' => 'ok'],
                ],
                'chart_label' => 'Tenders by status',
                'rows' => $tByStatus->map(fn ($r) => ['label' => $r['label'], 'value' => $r['value'], 'display' => (string) $r['value'], 'tone' => $r['tone']])->values(),
                'takeaway' => '',
            ],
            [
                'key' => 'sources', 'name' => 'Where deals come from', 'description' => 'New leads this year, by source', 'schedule' => $year,
                'summary' => "{$leads->count()} new leads · ".self::tsh((int) $leads->sum('deal_value')),
                'kpis' => [
                    ['label' => 'Referrals', 'value' => (string) $referral['n'], 'tone' => 'violet'],
                    ['label' => 'Referral value', 'value' => self::tsh($referral['value'])],
                    ['label' => 'Share', 'value' => round($referral['value'] / $leadTotal * 100).'%', 'tone' => 'violet'],
                ],
                'chart_label' => 'Lead value by source',
                'rows' => $bySource->map(fn ($r) => ['label' => $r['label'], 'value' => $r['value'], 'display' => self::tsh($r['value']).' · '.$r['n'], 'tone' => 'violet'])->values(),
                'takeaway' => '',
            ],
        ];

        return response()->json(['data' => ['reports' => $reports]]);
    }

    // ── My Leave: what happens while away ───────────────────────────────────

    public function leaveCover(Request $request)
    {
        $head = $this->gate($request);
        $leave = LeaveRequest::where('user_id', $head->id)->whereIn('status', ['pending', 'approved'])
            ->where('end_date', '>=', now()->toDateString())->orderBy('start_date')->first();

        // Standing in for someone else while they're away is the other half of cover.
        $cover = Delegation::with('delegator')->where('delegate_id', $head->id)->whereNull('revoked_at')
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()))->get()
            ->map(fn (Delegation $d) => [
                'title' => 'You stand in for '.($d->delegator?->name ?? '—'),
                'note' => $d->standby_for_id ? 'Only while they are away' : collect([$d->starts_at?->format('j M'), $d->ends_at?->format('j M')])->filter()->implode(' – '),
                'value' => 'You', 'tone' => 'violet',
            ])->values();

        $during = collect();
        if ($leave) {
            $from = Carbon::parse($leave->start_date)->startOfDay();
            $to = Carbon::parse($leave->end_date)->endOfDay();
            Tender::whereNotIn('status', Tender::TERMINAL)->whereBetween('bid_submission_deadline', [$from, $to])->get()
                ->each(fn ($t) => $during->push(['at' => $t->bid_submission_deadline, 'when' => $t->bid_submission_deadline->format('j M'), 'title' => 'Bid deadline · '.$t->title, 'note' => 'Decide the bid before you go', 'tone' => 'warn']));
            SalesLead::with('hospital')->whereNotIn('stage', ['won', 'lost'])->whereBetween('expected_close_date', [$from, $to])->orderByDesc('deal_value')->limit(6)->get()
                ->each(fn ($l) => $during->push(['at' => Carbon::parse($l->expected_close_date), 'when' => Carbon::parse($l->expected_close_date)->format('j M'),
                    'title' => self::account($l).' · '.($l->machine_type ?: 'deal'), 'note' => 'Expected to close · '.self::tsh((int) $l->deal_value), 'tone' => 'mute']));
            Meeting::whereBetween('starts_at', [$from, $to])->where(fn ($q) => $q->where('chaired_by', $head->id)->orWhereHas('attendees', fn ($a) => $a->where('user_id', $head->id)))->get()
                ->each(fn ($m) => $during->push(['at' => $m->starts_at, 'when' => $m->starts_at->format('j M'), 'title' => $m->title, 'note' => 'Meeting you’re in — move it or send someone', 'tone' => 'warn']));
        }

        return response()->json(['data' => [
            'cover' => $cover,
            'during' => $during->sortBy('at')->map(fn ($x) => collect($x)->except('at'))->values(),
        ]]);
    }

    // ── Settings: each person's discount ceiling ────────────────────────────

    public function rules(Request $request)
    {
        $head = $this->gate($request);
        $team = $this->team($head);

        return response()->json(['data' => [
            'roles' => ['Discount without approval'],
            'limits' => $team->map(fn (User $u) => [
                'key' => 'discount_pct:'.$u->id,
                'label' => $u->name,
                'note' => collect([ucwords(str_replace('_', ' ', $u->role)), $u->zone])->filter()->implode(' · '),
                'unit' => '%', 'step' => 1,
                // -1 = no ceiling (ApprovalService skips the check when it's null).
                'values' => [$u->max_discount_percent === null ? -1 : (int) round((float) $u->max_discount_percent)],
            ])->values(),
            'routing' => [
                ['title' => 'Within their limit', 'note' => 'The quotation goes ahead. No approval needed'],
                ['title' => 'Over their limit', 'note' => 'It waits for you in Decisions before the client can accept it'],
                ['title' => 'No limit set', 'note' => 'Their quotations never need approval'],
            ],
            'changes' => $this->limitChanges($team),
            'notifications' => [],
        ]]);
    }

    /** Recent ceiling changes, from the activity log the User model already writes. */
    private function limitChanges(Collection $team): array
    {
        return DB::table('activity_log')
            ->where('subject_type', User::class)->whereIn('subject_id', $team->pluck('id'))
            ->where('properties', 'like', '%max_discount_percent%')
            ->orderByDesc('created_at')->limit(15)->get()
            ->map(function ($row) use ($team) {
                $p = json_decode($row->properties ?? '{}', true) ?: [];
                if (! array_key_exists('max_discount_percent', $p['attributes'] ?? [])) return null;
                $fmt = fn ($v) => $v === null ? 'no limit' : rtrim(rtrim(number_format((float) $v, 1), '0'), '.').'%';
                $old = $p['old']['max_discount_percent'] ?? null;
                $new = $p['attributes']['max_discount_percent'];
                if ($old === $new) return null;
                $by = $row->causer_id ? (User::find($row->causer_id)?->name ?? '—') : 'System';

                return [
                    'title' => ($team->firstWhere('id', $row->subject_id)?->name ?? '—').': '.$fmt($old).' → '.$fmt($new),
                    'when' => Carbon::parse($row->created_at)->format('j M'),
                    'note' => $by,
                ];
            })->filter()->take(8)->values()->all();
    }

    public function saveRules(Request $request)
    {
        $head = $this->gate($request);
        $v = $request->validate([
            'limits' => ['required', 'array'],
            'limits.*.key' => ['required', 'string'],
            'limits.*.values' => ['required', 'array', 'min:1'],
            'limits.*.values.0' => ['required', 'numeric', 'between:-1,100'],
        ]);
        $teamIds = $this->team($head)->pluck('id');
        foreach ($v['limits'] as $l) {
            if (! preg_match('/^discount_pct:(\d+)$/', $l['key'], $m) || ! $teamIds->contains((int) $m[1])) continue;
            // Through the model so the change lands in the activity log (User logs max_discount_percent).
            $u = User::find((int) $m[1]);
            $new = $l['values'][0] < 0 ? null : (float) $l['values'][0];
            if ($u && $u->max_discount_percent !== $new) {
                $u->update(['max_discount_percent' => $new]);
            }
        }

        return $this->rules($request);
    }
}
