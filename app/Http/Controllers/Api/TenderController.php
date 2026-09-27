<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BoardResolution;
use App\Models\ProcuringEntity;
use App\Models\Tender;
use App\Models\TenderDocument;
use App\Services\EffectivePermissionResolver;
use App\Services\Tender\CompanyProfile;
use App\Services\Tender\TenderDeadlineService;
use App\Services\Tender\TenderDocumentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/**
 * Section 19: Tenders & Contracts. Procurement staff (tenders.manage) run
 * them; the CTO has read-only access (screens.tenders); the Director and
 * admin are admin-tier. Deadlines come from TenderDeadlineService.
 */
class TenderController extends Controller
{
    public function __construct(private TenderDeadlineService $deadlines) {}

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
        abort_unless($this->can($r, 'tenders.manage'), 403, 'Only procurement staff can change tenders.');
    }

    // ------------------------------------------------------------ tenders

    public function index(Request $request)
    {
        $this->assertView($request);

        $q = Tender::with(['procuringEntity', 'owner:id,name', 'documents'])->latest('id');
        if ($request->filled('status')) {
            $q->whereIn('status', explode(',', $request->status));
        }
        if ($request->filled('q')) {
            $s = '%' . $request->q . '%';
            $q->where(fn ($w) => $w->where('tender_number', 'ilike', $s)->orWhere('contract_number', 'ilike', $s)
                ->orWhere('title', 'ilike', $s)->orWhereHas('procuringEntity', fn ($e) => $e->where('name', 'ilike', $s)));
        }

        $rows = $q->get()->map(fn (Tender $t) => $this->summary($t));

        return response()->json(['data' => [
            'tenders' => $rows->values(),
            'needs_action' => $this->needsAction($rows),
            'can_manage' => $this->can($request, 'tenders.manage'),
        ]]);
    }

    public function show(Request $request, Tender $tender)
    {
        $this->assertView($request);
        $tender->load(['procuringEntity', 'owner:id,name', 'documents', 'boardResolution.tenders:id,board_resolution_id,tender_number']);

        return response()->json(['data' => $this->detail($tender) + ['can_manage' => $this->can($request, 'tenders.manage')]]);
    }

    public function store(Request $request)
    {
        $this->assertManage($request);
        $data = $this->validated($request, true);
        $tender = Tender::create($data + ['created_by' => $request->user()->id, 'owner_id' => $data['owner_id'] ?? $request->user()->id]);

        return response()->json(['data' => $this->detail($tender->fresh(['procuringEntity', 'owner:id,name', 'documents', 'boardResolution']))], 201);
    }

    public function update(Request $request, Tender $tender)
    {
        $this->assertManage($request);
        $tender->update($this->validated($request, false));

        return response()->json(['data' => $this->detail($tender->fresh(['procuringEntity', 'owner:id,name', 'documents', 'boardResolution']))]);
    }

    public function updateStatus(Request $request, Tender $tender)
    {
        $this->assertManage($request);
        $status = $request->validate(['status' => ['required', Rule::in(Tender::STATUSES)]])['status'];

        $need = match ($status) {
            'won', 'awaiting_award' => $tender->reached('bid_submitted') || $tender->status === 'bid_submitted' ? null : 'Submit the bid first.',
            'accepted' => $tender->letter_of_acceptance_date ? null : 'Enter the Letter of Acceptance date first.',
            'performance_security_submitted' => $tender->performance_security_form ? null : 'Record whether the performance security is a declaration or a bank guarantee first.',
            'contract_signed' => $tender->contract_number ? null : 'Enter the contract number first.',
            default => null,
        };
        if ($need) {
            throw ValidationException::withMessages(['status' => $need]);
        }

        $tender->update(['status' => $status]);

        return response()->json(['data' => $this->detail($tender->fresh(['procuringEntity', 'owner:id,name', 'documents', 'boardResolution']))]);
    }

    private function validated(Request $request, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'tender_number' => [$req, 'string', 'max:120'],
            'contract_number' => ['nullable', 'string', 'max:120'],
            'tender_type' => ['sometimes', Rule::in(['standard', 'framework'])],
            'title' => [$req, 'string', 'max:500'],
            'procuring_entity_id' => [$req, 'exists:procuring_entities,id'],
            'estimated_value' => ['nullable', 'integer', 'min:0'],
            'contract_value' => ['nullable', 'integer', 'min:0'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'vat_inclusive' => ['sometimes', 'boolean'],
            'owner_id' => ['nullable', 'exists:users,id'],
            'board_resolution_id' => ['nullable', 'exists:board_resolutions,id'],
            'attorney_name' => ['nullable', 'string', 'max:200'],
            'attorney_address' => ['nullable', 'string', 'max:500'],
            'signatory_name' => ['nullable', 'string', 'max:200'],
            'signatory_position' => ['nullable', 'string', 'max:200'],
            'entity_ref' => ['nullable', 'string', 'max:120'],
            'entity_ref_date' => ['nullable', 'date'],
            'our_ref' => ['nullable', 'string', 'max:120'],
            'bid_submission_deadline' => ['nullable', 'date'],
            'bid_validity_days' => ['nullable', 'integer', 'min:1', 'max:730'],
            'tender_expiry_date' => ['nullable', 'date'],
            'other_tenderer_notified_at' => ['nullable', 'date'],
            'award_notified_at' => ['nullable', 'date'],
            'letter_of_acceptance_date' => ['nullable', 'date'],
            'performance_security_form' => ['nullable', Rule::in(['declaration', 'bank_guarantee'])],
            'contract_signing_deadline' => ['nullable', 'date'],
            'delivery_deadline' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
    }

    // ---------------------------------------------------------- documents

    public function generate(Request $request, Tender $tender, string $type, TenderDocumentService $docs)
    {
        $this->assertManage($request);
        abort_unless(in_array($type, TenderDocument::GENERATED, true), 404);
        $date = $request->validate(['date' => ['nullable', 'date']])['date'] ?? null;

        [$bytes, $filename] = $docs->generate($tender, $type, $request->user(), $date);

        return response($bytes, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    public function uploadExecuted(Request $request, Tender $tender, string $type)
    {
        $this->assertManage($request);
        abort_unless(in_array($type, TenderDocument::TYPES, true), 404);
        $file = $request->validate(['file' => ['required', 'file', 'max:20480', 'mimes:pdf,jpg,jpeg,png,docx']])['file'];

        $path = $file->store("tenders/{$tender->id}/executed", 'public');
        $doc = TenderDocument::firstOrNew(['tender_id' => $tender->id, 'type' => $type]);
        if ($doc->executed_path) {
            Storage::disk('public')->delete($doc->executed_path);
        }
        $doc->fill([
            'executed_path' => $path, 'executed_original_name' => $file->getClientOriginalName(),
            'executed_uploaded_at' => now(), 'executed_uploaded_by' => $request->user()->id,
        ])->save();
        activity()->performedOn($tender)->causedBy($request->user())->log('Uploaded signed ' . TenderDocument::LABELS[$type]);

        return response()->json(['data' => $this->detail($tender->fresh(['procuringEntity', 'owner:id,name', 'documents', 'boardResolution']))]);
    }

    public function download(Request $request, Tender $tender, string $type, string $which)
    {
        $this->assertView($request);
        $doc = TenderDocument::where('tender_id', $tender->id)->where('type', $type)->firstOrFail();
        $path = $which === 'executed' ? $doc->executed_path : $doc->draft_path;
        abort_unless($path && Storage::disk('public')->exists($path), 404, 'File not found.');
        $name = $which === 'executed' ? ($doc->executed_original_name ?: basename($path)) : Str::after(basename($path), '-');

        return Storage::disk('public')->download($path, $name);
    }

    // --------------------------------------------- entities / resolutions

    public function entities(Request $request)
    {
        $this->assertView($request);
        $q = ProcuringEntity::orderBy('name');
        if ($request->filled('q')) {
            $q->where('name', 'ilike', '%' . $request->q . '%');
        }

        return response()->json(['data' => $q->limit(50)->get()]);
    }

    public function storeEntity(Request $request)
    {
        $this->assertManage($request);

        return response()->json(['data' => ProcuringEntity::create($this->entityData($request))], 201);
    }

    public function updateEntity(Request $request, ProcuringEntity $entity)
    {
        $this->assertManage($request);
        $entity->update($this->entityData($request));

        return response()->json(['data' => $entity]);
    }

    private function entityData(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'addressee' => ['nullable', 'string', 'max:200'],
            'address' => ['nullable', 'string', 'max:1000'],
            'short_code' => ['nullable', 'string', 'max:20'],
            'contact' => ['nullable', 'string', 'max:200'],
            'hospital_id' => ['nullable', 'exists:hospitals,id'],
        ]);
    }

    public function resolutions(Request $request)
    {
        $this->assertView($request);

        return response()->json(['data' => BoardResolution::with('tenders:id,board_resolution_id,tender_number,title')
            ->orderByDesc('resolution_date')->get()]);
    }

    public function storeResolution(Request $request)
    {
        $this->assertManage($request);
        $data = $request->validate([
            'number' => ['required', 'string', 'max:50'],
            'resolution_date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);
        $dupe = BoardResolution::where('number', $data['number'])->whereDate('resolution_date', $data['resolution_date'])->first();
        if ($dupe) {
            throw ValidationException::withMessages(['number' => "Resolution No. {$data['number']} of that date is already in the register."]);
        }

        return response()->json(['data' => BoardResolution::create($data + ['created_by' => $request->user()->id])], 201);
    }

    // ----------------------------------------------------- company profile

    public function companyProfile(Request $request)
    {
        $this->assertView($request);

        return response()->json(['data' => CompanyProfile::get()]);
    }

    public function updateCompanyProfile(Request $request)
    {
        $this->assertManage($request);
        $data = $request->validate([
            'legal_name' => ['required', 'string', 'max:200'],
            'name' => ['required', 'string', 'max:200'],
            'short_name' => ['required', 'string', 'max:200'],
            'physical_address' => ['required', 'string', 'max:500'],
            'po_box' => ['required', 'string', 'max:50'],
            'city' => ['required', 'string', 'max:100'],
            'tin' => ['nullable', 'string', 'max:50'],
            'md_name' => ['required', 'string', 'max:200'],
            'md_title' => ['required', 'string', 'max:200'],
            'md_address' => ['nullable', 'string', 'max:500'],
            'md_phone' => ['nullable', 'string', 'max:50'],
            'tmda_offices' => ['array', 'max:20'],
            'tmda_offices.*.name' => ['required', 'string', 'max:100'],
            'tmda_offices.*.address' => ['required', 'string', 'max:1000'],
        ]);

        return response()->json(['data' => CompanyProfile::save($data, $request->user()->id)]);
    }

    // ------------------------------------------------------------- shapes

    private function summary(Tender $t): array
    {
        $deadlines = $this->deadlines->forTender($t);

        return [
            'id' => $t->id,
            'tender_number' => $t->tender_number,
            'contract_number' => $t->contract_number,
            'tender_type' => $t->tender_type,
            'title' => $t->title,
            'procuring_entity' => $t->procuringEntity?->only(['id', 'name', 'addressee', 'address', 'short_code']),
            'estimated_value' => $t->estimated_value,
            'contract_value' => $t->contract_value,
            'currency' => $t->currency,
            'status' => $t->status,
            'step' => $t->step(),
            'owner' => $t->owner?->only(['id', 'name']),
            'next_deadline' => $this->deadlines->next($deadlines),
            'flagged' => $this->deadlines->isFlagged($deadlines),
            'documents_executed' => $t->documents->whereNotNull('executed_path')->count(),
            'documents_total' => count(TenderDocument::TYPES),
        ];
    }

    private function detail(Tender $t): array
    {
        $deadlines = $this->deadlines->forTender($t);
        $docs = $t->documents->keyBy('type');

        return $this->summary($t) + $t->only([
            'vat_inclusive', 'board_resolution_id', 'attorney_name', 'attorney_address', 'signatory_name', 'signatory_position',
            'entity_ref', 'our_ref', 'bid_validity_days', 'performance_security_form', 'notes',
        ]) + [
            'entity_ref_date' => $t->entity_ref_date?->toDateString(),
            'bid_submission_deadline' => $t->bid_submission_deadline?->toDateString(),
            'tender_expiry_date' => $t->tender_expiry_date?->toDateString(),
            'computed_tender_expiry' => $this->deadlines->tenderExpiry($t)?->toDateString(),
            'other_tenderer_notified_at' => $t->other_tenderer_notified_at?->toDateString(),
            'award_notified_at' => $t->award_notified_at?->toDateString(),
            'letter_of_acceptance_date' => $t->letter_of_acceptance_date?->toDateString(),
            'contract_signing_deadline' => $t->contract_signing_deadline?->toDateString(),
            'delivery_deadline' => $t->delivery_deadline?->toDateString(),
            'board_resolution' => $t->boardResolution ? [
                'id' => $t->boardResolution->id,
                'number' => $t->boardResolution->number,
                'resolution_date' => $t->boardResolution->resolution_date->toDateString(),
                'shared_with' => $t->boardResolution->tenders->where('id', '!=', $t->id)->pluck('tender_number')->values(),
            ] : null,
            'deadlines' => $deadlines,
            'documents' => collect(TenderDocument::TYPES)->map(fn ($type) => [
                'type' => $type,
                'label' => TenderDocument::LABELS[$type],
                'generated' => in_array($type, TenderDocument::GENERATED, true),
                'draft_at' => $docs[$type]->draft_generated_at ?? null,
                'executed_at' => $docs[$type]->executed_uploaded_at ?? null,
                'executed_name' => $docs[$type]->executed_original_name ?? null,
                'has_draft' => (bool) ($docs[$type]->draft_path ?? null),
                'has_executed' => (bool) ($docs[$type]->executed_path ?? null),
            ])->values(),
            'linked_shipments' => [], // Section 18 not built yet
            'audit' => Activity::with('causer:id,name')->where('subject_type', Tender::class)->where('subject_id', $t->id)
                ->latest('id')->limit(50)->get()->map(fn ($a) => [
                    'description' => $a->description === 'updated' ? $this->describeChange($a) : ($a->description === 'created' ? 'Tender created' : $a->description),
                    'who' => $a->causer?->name,
                    'at' => $a->created_at,
                ]),
        ];
    }

    private function describeChange(Activity $a): string
    {
        $new = $a->properties['attributes'] ?? [];
        $old = $a->properties['old'] ?? [];
        $parts = [];
        foreach ($new as $k => $v) {
            $label = Str::of($k)->replace('_', ' ')->ucfirst();
            $from = $old[$k] ?? null;
            $parts[] = $k === 'status' ? "Status: " . Str::headline((string) $from) . ' → ' . Str::headline((string) $v)
                : "{$label}: " . (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}(T|$)/', $v) ? \Illuminate\Support\Carbon::parse($v)->format('j M Y')
                    : (is_bool($v) ? ($v ? 'yes' : 'no') : (is_scalar($v) ? Str::limit((string) $v, 40) : 'changed')));
        }

        return implode('; ', $parts) ?: 'Updated';
    }

    private function needsAction($rows): array
    {
        $rank = ['overdue' => 0, 'due_today' => 1, 'soon' => 2];

        return $rows->filter(fn ($r) => $r['flagged'] || ($r['next_deadline'] && isset($rank[$r['next_deadline']['state']])))
            ->sortBy(fn ($r) => [$r['flagged'] ? 0 : 1, $rank[$r['next_deadline']['state'] ?? 'soon'] ?? 3, $r['next_deadline']['due'] ?? '9999'])
            ->values()->all();
    }
}
