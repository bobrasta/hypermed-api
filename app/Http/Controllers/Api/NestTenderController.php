<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\NestTenderResource;
use App\Jobs\RecomputeNestRelevance;
use App\Jobs\RunNestSync;
use App\Models\NestRelevanceRule;
use App\Models\NestSyncRun;
use App\Models\NestTender;
use App\Models\ProcuringEntity;
use App\Models\Setting;
use App\Models\Tender;
use App\Models\User;
use App\Services\EffectivePermissionResolver;
use App\Services\Nest\NestClient;
use App\Services\Nest\NestDocumentService;
use App\Services\Nest\NestException;
use App\Services\Nest\NestNotifier;
use App\Services\Nest\NestSyncService;
use App\Services\Nest\RelevanceEngine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * NeST tender tracker. Anyone who can see tenders (screens.tenders) can
 * browse; procurement staff (tenders.manage) triage, assign, start bids,
 * edit relevance rules, enter the company NeST login and trigger a check.
 */
class NestTenderController extends Controller
{
    // ------------------------------------------------------------- access

    private function can(Request $r, string $key): bool
    {
        return $r->user()->isAdminTier() || app(EffectivePermissionResolver::class)->can($r->user(), $key);
    }

    private function assertView(Request $r): void
    {
        abort_unless($this->can($r, 'screens.tenders') || $this->can($r, 'tenders.manage'), 403, 'You do not have access to tenders.');
    }

    private function assertManage(Request $r): void
    {
        abort_unless($this->can($r, 'tenders.manage'), 403, 'Only procurement staff can change NeST tenders.');
    }

    // ------------------------------------------------------------ tenders

    public function index(Request $request)
    {
        $this->assertView($request);
        $v = $request->validate([
            'open' => ['nullable', 'boolean'],
            'relevance' => ['nullable', Rule::in(['relevant', 'maybe', 'all'])],
            'tags' => ['nullable', 'array'], 'tags.*' => [Rule::in(NestRelevanceRule::TAGS)],
            'closing_within' => ['nullable', 'integer', 'min:0', 'max:365'],
            'category' => ['nullable', 'string', 'max:10'],
            'entity_type' => ['nullable', 'string', 'max:40'],
            'pipeline_status' => ['nullable', 'array'], 'pipeline_status.*' => [Rule::in(NestTender::PIPELINE)],
            'q' => ['nullable', 'string', 'max:200'],
            'procuring_entity_uuid' => ['nullable', 'uuid'],
            'assigned_to' => ['nullable', Rule::in(['me'])],
            'new_since_visit' => ['nullable', 'boolean'],
            'sort' => ['nullable', Rule::in(['closing', 'newest', 'score'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $q = NestTender::with(['assignee:id,name', 'tender:id,status,tender_number']);

        match ($v['relevance'] ?? 'relevant') {
            'relevant' => $q->relevant(),
            'maybe' => $q->maybe(),
            default => null,
        };
        if ($request->boolean('open')) {
            $q->open();
        }
        if (! empty($v['tags'])) {
            $q->where(function ($w) use ($v) {
                foreach ($v['tags'] as $tag) {
                    $w->orWhereJsonContains('tags', $tag);
                }
            });
        }
        if (isset($v['closing_within'])) {
            $q->closingSoon((int) $v['closing_within']);
        }
        if (! empty($v['category'])) {
            $q->where('procurement_category', strtoupper($v['category']));
        }
        if (! empty($v['entity_type'])) {
            $q->where('entity_type', strtoupper($v['entity_type']));
        }
        if (! empty($v['pipeline_status'])) {
            $q->pipeline($v['pipeline_status']);
        }
        if (! empty($v['procuring_entity_uuid'])) {
            $q->where('procuring_entity_uuid', $v['procuring_entity_uuid']);
        }
        if (($v['assigned_to'] ?? null) === 'me') {
            $q->where('assigned_to', $request->user()->id);
        }
        if ($request->boolean('new_since_visit')) {
            $q->where('first_seen_at', '>', $this->lastVisit($request->user()));
        }
        if (! empty($v['q'])) {
            $s = '%' . $v['q'] . '%';
            $like = DB::getDriverName() === 'pgsql' ? 'ilike' : 'like';
            $q->where(fn ($w) => $w->where('description', $like, $s)->orWhere('procuring_entity_name', $like, $s)
                ->orWhere('reference_number', $like, $s)->orWhere('entity_number', $like, $s));
        }

        match ($v['sort'] ?? 'closing') {
            'newest' => $q->orderByDesc('invitation_at'),
            'score' => $q->orderByDesc('relevance_score')->orderBy('closing_at'),
            // Soonest first, but open tenders before closed ones.
            default => $q->orderByRaw('CASE WHEN closing_at > ? THEN 0 ELSE 1 END', [now()])
                ->orderByRaw('CASE WHEN closing_at > ? THEN closing_at END ASC', [now()])
                ->orderByDesc('closing_at'),
        };
        $q->orderBy('id');

        return NestTenderResource::collection($q->paginate($v['per_page'] ?? 20)->withQueryString())
            ->additional(['can_manage' => $this->can($request, 'tenders.manage')]);
    }

    public function show(Request $request, NestTender $nestTender)
    {
        $this->assertView($request);
        $nestTender->load(['assignee:id,name', 'tender:id,status,tender_number', 'changes']);

        return (new NestTenderResource($nestTender))->additional([
            'can_manage' => $this->can($request, 'tenders.manage'),
            'document' => [
                'available' => NestClient::hasCredentials(),
                'cached' => app(NestDocumentService::class)->cached($nestTender),
            ],
        ]);
    }

    public function update(Request $request, NestTender $nestTender)
    {
        $this->assertManage($request);
        $v = $request->validate([
            'triage_status' => ['sometimes', Rule::in(NestTender::TRIAGE)],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'assigned_to' => ['sometimes', 'nullable', 'integer', Rule::exists('users', 'id')->where('is_active', true)],
        ]);
        if (isset($v['triage_status']) && $nestTender->tender_id) {
            throw ValidationException::withMessages(['triage_status' => 'A bid has started — change the status on the tender itself.']);
        }
        $nestTender->update($v);

        return $this->show($request, $nestTender->fresh());
    }

    /** Create a Section 19 tender from this NeST tender and link them. */
    public function startBid(Request $request, NestTender $nestTender)
    {
        $this->assertManage($request);
        if ($nestTender->tender_id) {
            throw ValidationException::withMessages(['tender' => 'A bid has already been started for this tender.']);
        }

        $tender = DB::transaction(function () use ($request, $nestTender) {
            $name = trim((string) $nestTender->procuring_entity_name) ?: 'Unknown procuring entity';
            $entity = ProcuringEntity::whereRaw('lower(name) = ?', [mb_strtolower($name)])->first()
                ?? ProcuringEntity::create(['name' => $name]);

            $tender = Tender::create([
                'tender_number' => $nestTender->entity_number ?: ($nestTender->reference_number ?: 'NeST-' . $nestTender->entity_id),
                'tender_type' => $nestTender->entity_type === 'FRAMEWORK' ? 'framework' : 'standard',
                'title' => mb_substr($nestTender->description, 0, 255),
                'procuring_entity_id' => $entity->id,
                'status' => 'preparing_bid',
                'bid_submission_deadline' => $nestTender->closing_at?->copy()->setTimezone(config('nest.timezone'))->toDateString(),
                'owner_id' => $nestTender->assigned_to ?? $request->user()->id,
                'created_by' => $request->user()->id,
                'notes' => trim("From NeST {$nestTender->reference_number}." . ($nestTender->notes ? "\n\n{$nestTender->notes}" : '')),
            ]);
            $nestTender->update(['tender_id' => $tender->id, 'triage_status' => 'reviewing',
                'assigned_to' => $nestTender->assigned_to ?? $request->user()->id]);

            return $tender;
        });

        return $this->show($request, $nestTender->fresh())->additional(['tender_id' => $tender->id]);
    }

    public function document(Request $request, NestTender $nestTender)
    {
        $this->assertView($request);
        try {
            $path = app(NestDocumentService::class)->fetch($nestTender, $request->boolean('refresh'));
        } catch (NestException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', $nestTender->reference_number ?: $nestTender->entity_uuid) . '.pdf';

        return Storage::disk('local')->download($path, $name, ['Content-Type' => 'application/pdf']);
    }

    // ---------------------------------------------------------- dashboard

    public function dashboard(Request $request)
    {
        $this->assertView($request);
        $user = $request->user();
        $lastVisit = $this->lastVisit($user);

        $openRelevant = NestTender::relevant()->open();
        $byTag = [];
        foreach (NestRelevanceRule::TAGS as $tag) {
            $byTag[$tag] = (clone $openRelevant)->whereJsonContains('tags', $tag)->count();
        }
        $pipeline = [];
        foreach (NestTender::PIPELINE as $p) {
            $pipeline[$p] = NestTender::relevant()->pipeline([$p])->count();
        }

        $data = [
            'open_relevant' => (clone $openRelevant)->count(),
            'open_maybe' => NestTender::maybe()->open()->count(),
            'closing_3_days' => NestTender::relevant()->closingSoon(3)->count(),
            'closing_7_days' => NestTender::relevant()->closingSoon(7)->count(),
            'by_tag' => $byTag,
            'by_pipeline' => $pipeline,
            'new_since_last_visit' => NestTender::relevant()->where('first_seen_at', '>', $lastVisit)->count(),
            'last_visit_at' => NestTenderResource::eat($lastVisit),
            'my_open' => NestTender::open()->where('assigned_to', $user->id)->count(),
            'top_entities' => NestTender::relevant()
                ->select('procuring_entity_uuid', 'procuring_entity_name', DB::raw('count(*) as relevant_count'))
                ->groupBy('procuring_entity_uuid', 'procuring_entity_name')
                ->orderByDesc('relevant_count')->limit(10)->get(),
            'sync' => $this->syncStatus(),
            'can_manage' => $this->can($request, 'tenders.manage'),
        ];

        if (! $request->boolean('peek')) {
            Setting::set("nest.last_visit.{$user->id}", now()->toIso8601String(), $user->id);
        }

        return response()->json(['data' => $data]);
    }

    private function lastVisit(User $user): \Illuminate\Support\Carbon
    {
        $v = Setting::get("nest.last_visit.{$user->id}");

        return $v ? \Illuminate\Support\Carbon::parse($v) : now()->subDays(7);
    }

    private function syncStatus(): array
    {
        $last = NestSyncRun::latest('id')->first();
        $lastOk = NestSyncRun::where('status', 'ok')->latest('id')->first();

        return [
            'last_run' => $last ? [
                'status' => $last->status, 'trigger' => $last->trigger, 'records' => $last->records,
                'new_relevant' => $last->new_relevant, 'error' => $last->error,
                'started_at' => NestTenderResource::eat($last->started_at), 'finished_at' => NestTenderResource::eat($last->finished_at),
            ] : null,
            'last_ok_at' => NestTenderResource::eat($lastOk?->finished_at),
            'runs_today' => NestSyncService::runsToday(),
            'max_runs_per_day' => (int) config('nest.max_runs_per_day'),
            'total_stored' => NestTender::count(),
        ];
    }

    public function syncNow(Request $request)
    {
        $this->assertManage($request);
        if (NestSyncRun::where('status', 'running')->where('started_at', '>', now()->subMinutes(30))->exists()) {
            return response()->json(['message' => 'A NeST check is already running.'], 409);
        }
        if (! NestSyncService::canRunToday()) {
            return response()->json(['message' => 'NeST has already been checked ' . config('nest.max_runs_per_day') . ' times today. The limit keeps the load on the government server low.'], 429);
        }
        RunNestSync::dispatch($request->user()->id);

        return response()->json(['data' => ['queued' => true, 'sync' => $this->syncStatus()]], 202);
    }

    // -------------------------------------------------- procuring entities

    public function entities(Request $request)
    {
        $this->assertView($request);
        $q = $request->validate(['q' => ['nullable', 'string', 'max:200']])['q'] ?? null;
        $like = DB::getDriverName() === 'pgsql' ? 'ilike' : 'like';

        $rows = NestTender::query()
            ->select('procuring_entity_uuid', 'procuring_entity_name',
                DB::raw('count(*) as total_count'),
                DB::raw('sum(case when is_relevant then 1 else 0 end) as relevant_count'),
                DB::raw('max(invitation_at) as last_tender_at'),
                DB::raw('max(case when is_relevant then invitation_at end) as last_relevant_at'))
            ->whereNotNull('procuring_entity_uuid')
            ->when($q, fn ($w) => $w->where('procuring_entity_name', $like, "%{$q}%"))
            ->groupBy('procuring_entity_uuid', 'procuring_entity_name')
            ->orderByDesc('relevant_count')->orderByDesc('last_tender_at')
            ->limit(500)->get()
            ->map(fn ($r) => [
                'uuid' => $r->procuring_entity_uuid,
                'name' => $r->procuring_entity_name,
                'total_count' => (int) $r->total_count,
                'relevant_count' => (int) $r->relevant_count,
                'last_tender_at' => NestTenderResource::eat($r->last_tender_at ? \Illuminate\Support\Carbon::parse($r->last_tender_at) : null),
                'last_relevant_at' => NestTenderResource::eat($r->last_relevant_at ? \Illuminate\Support\Carbon::parse($r->last_relevant_at) : null),
            ]);

        return response()->json(['data' => $rows]);
    }

    public function entityTenders(Request $request, string $uuid)
    {
        $request->merge(['procuring_entity_uuid' => $uuid, 'relevance' => $request->input('relevance', 'all'), 'sort' => $request->input('sort', 'newest')]);

        return $this->index($request);
    }

    // ---------------------------------------------------- relevance rules

    public function rules(Request $request)
    {
        $this->assertView($request);

        return response()->json(['data' => [
            'rules' => NestRelevanceRule::orderBy('type')->orderBy('tag')->orderBy('term')->get(),
            'threshold' => RelevanceEngine::threshold(),
            'maybe_min' => (int) config('nest.maybe_min'),
            'can_manage' => $this->can($request, 'tenders.manage'),
        ]]);
    }

    public function storeRule(Request $request)
    {
        $this->assertManage($request);
        $rule = NestRelevanceRule::create($this->ruleData($request));
        RecomputeNestRelevance::dispatch();

        return response()->json(['data' => $rule], 201);
    }

    public function updateRule(Request $request, NestRelevanceRule $rule)
    {
        $this->assertManage($request);
        $rule->update($this->ruleData($request, $rule));
        RecomputeNestRelevance::dispatch();

        return response()->json(['data' => $rule->fresh()]);
    }

    public function destroyRule(Request $request, NestRelevanceRule $rule)
    {
        $this->assertManage($request);
        $rule->delete();
        RecomputeNestRelevance::dispatch();

        return response()->json(['data' => ['deleted' => true]]);
    }

    public function recompute(Request $request)
    {
        $this->assertManage($request);
        RecomputeNestRelevance::dispatch();

        return response()->json(['data' => ['queued' => true]], 202);
    }

    private function ruleData(Request $request, ?NestRelevanceRule $rule = null): array
    {
        $req = $rule ? 'sometimes' : 'required';
        $v = $request->validate([
            'type' => [$req, Rule::in(NestRelevanceRule::TYPES)],
            'tag' => ['sometimes', 'nullable', Rule::in(NestRelevanceRule::TAGS)],
            'term' => [$req, 'string', 'min:2', 'max:120'],
            'weight' => [$req, 'integer', 'min:-100', 'max:100'],
            'active' => ['sometimes', 'boolean'],
        ]);
        if (isset($v['term'])) {
            $v['term'] = trim(preg_replace('/\s+/', ' ', $v['term']));
        }
        $type = $v['type'] ?? $rule?->type;
        if ($type !== 'keyword') {
            $v['tag'] = null;
        } elseif (array_key_exists('tag', $v) || ! $rule) {
            if (empty($v['tag'])) {
                throw ValidationException::withMessages(['tag' => 'Pick a tag (Devices, Reagents, Consumables or Service) for a keyword.']);
            }
        }

        $dupe = NestRelevanceRule::where('type', $type)->where('term', $v['term'] ?? $rule?->term)
            ->where(fn ($q) => ($t = array_key_exists('tag', $v) ? $v['tag'] : $rule?->tag) === null ? $q->whereNull('tag') : $q->where('tag', $t))
            ->when($rule, fn ($q) => $q->whereKeyNot($rule->id))->exists();
        if ($dupe) {
            throw ValidationException::withMessages(['term' => 'That rule already exists.']);
        }

        return $v;
    }

    // ----------------------------------------------------------- settings

    public function settings(Request $request)
    {
        $this->assertView($request);

        return response()->json(['data' => $this->settingsPayload($request)]);
    }

    public function updateSettings(Request $request)
    {
        $this->assertView($request);
        $v = $request->validate([
            'notifications' => ['sometimes', 'array'],
            'notifications.new_relevant' => ['sometimes', 'boolean'],
            'notifications.changes' => ['sometimes', 'boolean'],
            'notifications.digest' => ['sometimes', 'boolean'],
            'threshold' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'nest_username' => ['sometimes', 'nullable', 'string', 'max:190'],
            'nest_password' => ['sometimes', 'nullable', 'string', 'max:190'],
            'forget_login' => ['sometimes', 'boolean'],
        ]);

        // Own notification toggles: anyone who can see the tracker.
        if (isset($v['notifications'])) {
            NestNotifier::savePrefs($request->user()->id, $v['notifications']);
        }

        // Shared settings: procurement staff only.
        $shared = array_intersect_key($v, array_flip(['threshold', 'nest_username', 'nest_password', 'forget_login']));
        if ($shared) {
            $this->assertManage($request);
            if (isset($v['threshold']) && $v['threshold'] !== RelevanceEngine::threshold()) {
                Setting::set('nest.relevance_threshold', (string) $v['threshold'], $request->user()->id);
                RecomputeNestRelevance::dispatch();
            }
            if ($request->boolean('forget_login')) {
                NestClient::forgetCredentials($request->user()->id);
            } elseif (filled($v['nest_username'] ?? null)) {
                if (! filled(Setting::get('nest.password')) && ! filled($v['nest_password'] ?? null)) {
                    throw ValidationException::withMessages(['nest_password' => 'Enter the NeST password.']);
                }
                NestClient::storeCredentials(trim($v['nest_username']), $v['nest_password'] ?? null, $request->user()->id);
            }
        }

        return response()->json(['data' => $this->settingsPayload($request)]);
    }

    public function testLogin(Request $request)
    {
        $this->assertManage($request);
        try {
            app(NestClient::class)->testLogin();
        } catch (NestException $e) {
            return response()->json(['message' => $e->getMessage(), 'data' => $this->settingsPayload($request)], 422);
        }

        return response()->json(['data' => $this->settingsPayload($request)]);
    }

    private function settingsPayload(Request $request): array
    {
        return [
            'notifications' => NestNotifier::prefsFor($request->user()->id),
            'threshold' => RelevanceEngine::threshold(),
            'maybe_min' => (int) config('nest.maybe_min'),
            'nest_login' => [
                'username' => $this->can($request, 'tenders.manage') ? Setting::get('nest.username') : null,
                'has_password' => filled(Setting::get('nest.password')),
                'server_ready' => filled(config('nest.auth.client_secret')),
                'last_ok_at' => Setting::get('nest.login_ok_at'),
                'last_error' => Setting::get('nest.login_error'),
            ],
            'sync' => $this->syncStatus(),
            'can_manage' => $this->can($request, 'tenders.manage'),
        ];
    }
}
