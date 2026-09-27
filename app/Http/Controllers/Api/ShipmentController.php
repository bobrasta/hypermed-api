<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApprovalLog;
use App\Models\Department;
use App\Models\Machine;
use App\Models\PurchaseOrder;
use App\Models\Shipment;
use App\Models\ShipmentDocument;
use App\Models\ShipmentEvent;
use App\Models\Supplier;
use App\Models\Tender;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorFee;
use App\Services\DocumentNumberService;
use App\Services\EffectivePermissionResolver;
use App\Services\Shipment\ShipmentFlow;
use App\Services\Shipment\ShipmentNotifier;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Section 18: import/export shipments. Tender/Procurement staff
 * (shipments.manage — procurement_manager, logistics) run them; CTO, MD and
 * the Sales Manager read them (screens.shipments); a department's manager
 * reads the shipments tagged to their department. The clearing payment is a
 * Section 16 vendor fee and is approved there, never here.
 */
class ShipmentController extends Controller
{
    public function __construct(private ShipmentFlow $flow, private ShipmentNotifier $notifier) {}

    // ------------------------------------------------------------- access

    private function can(Request $r, string $key): bool
    {
        return $r->user()->isAdminTier() || app(EffectivePermissionResolver::class)->can($r->user(), $key);
    }

    private function canManage(Request $r): bool
    {
        return $this->can($r, 'shipments.manage');
    }

    /** True when the caller sees every shipment; false when only their departments'. */
    private function seesAll(Request $r): bool
    {
        return $this->canManage($r) || $this->can($r, 'screens.shipments');
    }

    private function managedDepartmentIds(Request $r): array
    {
        return Department::where('manager_id', $r->user()->id)->pluck('id')->all();
    }

    private function assertView(Request $r, ?Shipment $s = null): void
    {
        if ($this->seesAll($r)) {
            return;
        }
        $depts = $this->managedDepartmentIds($r);
        abort_if($depts === [], 403, 'You do not have access to shipments.');
        abort_if($s && ! in_array($s->department_id, $depts, true), 403, 'This shipment is not tagged to your department.');
    }

    private function assertManage(Request $r): void
    {
        abort_unless($this->canManage($r), 403, 'Only procurement staff can change shipments.');
    }

    // ------------------------------------------------------------ shipments

    public function index(Request $request)
    {
        $this->assertView($request);

        $q = Shipment::with(['supplier:id,name', 'purchaseOrder:id,po_number', 'department:id,name', 'documents:id,shipment_id,type', 'vendorFee.receipts'])
            ->latest('updated_at');
        if (! $this->seesAll($request)) {
            $q->whereIn('department_id', $this->managedDepartmentIds($request));
        }
        foreach (['direction', 'department_id'] as $f) {
            if ($request->filled($f)) {
                $q->where($f, $request->input($f));
            }
        }
        if ($request->filled('q')) {
            $s = '%' . $request->q . '%';
            $q->where(fn ($w) => $w->where('reference', 'ilike', $s)->orWhere('description', 'ilike', $s)
                ->orWhereHas('supplier', fn ($x) => $x->where('name', 'ilike', $s))
                ->orWhereHas('purchaseOrder', fn ($x) => $x->where('po_number', 'ilike', $s)));
        }

        $rows = $q->get()->map(fn (Shipment $s) => $this->summary($s));

        return response()->json(['data' => [
            'shipments' => $rows->values(),
            'counts' => [
                'active' => $rows->where('flag', '!=', 'done')->count(),
                'needs_action' => $rows->whereIn('flag', ['action', 'blocked'])->count(),
                'awaiting_permit' => $rows->filter(fn ($r) => $r['direction'] === 'import' && $r['step'] >= 3 && $r['step'] <= 5 && $r['flag'] !== 'done')->count(),
                'done' => $rows->where('flag', 'done')->count(),
            ],
            'can_manage' => $this->canManage($request),
            'labels' => ['import' => $this->flow->settings()['import_labels'], 'export' => $this->flow->settings()['export_labels']],
        ]]);
    }

    public function show(Request $request, Shipment $shipment)
    {
        $this->assertView($request, $shipment);

        return response()->json(['data' => $this->detail($request, $shipment)]);
    }

    public function store(Request $request, DocumentNumberService $numbers)
    {
        $this->assertManage($request);
        $data = $this->validated($request, true);
        $files = $this->validatedFiles($request, $data['direction']);

        if ($this->flow->settings()['docs_required_at_creation']) {
            $missing = array_diff(ShipmentFlow::requiredDocTypes($data['direction'], $data['freight_mode']), array_keys($files));
            if ($missing) {
                throw ValidationException::withMessages(['documents' => 'All shipping documents are required at creation: '
                    . implode(', ', array_map(fn ($t) => ShipmentFlow::DOC_LABELS[$t], $missing)) . '.']);
            }
        }

        $shipment = DB::transaction(function () use ($request, $data, $files, $numbers) {
            $shipment = Shipment::create($data + [
                'reference' => $numbers->next('shipment'),
                'step' => 1,
                'created_by' => $request->user()->id,
            ]);
            foreach ($files as $type => $file) {
                $this->storeDocument($shipment, $type, $file, $request->user()->id);
            }
            ShipmentEvent::create([
                'shipment_id' => $shipment->id, 'from_step' => null, 'to_step' => 1,
                'note' => $data['location_notes'] ?? null, 'user_id' => $request->user()->id, 'created_at' => now(),
            ]);

            return $shipment;
        });

        $shipment->load('department');
        $this->notifier->created($shipment, $request->user());

        return response()->json(['data' => $this->detail($request, $shipment)], 201);
    }

    public function update(Request $request, Shipment $shipment)
    {
        $this->assertManage($request);
        $data = $this->validated($request, false);

        // A shipment can't be edited into a state its current step forbids
        // (e.g. clearing the control number of a shipment already assessed).
        $shipment->fill($data);
        if ($why = $this->flow->blockReason($shipment->load('documents'), $shipment->step)) {
            throw ValidationException::withMessages(['shipment' => $why]);
        }
        $shipment->save();

        return response()->json(['data' => $this->detail($request, $shipment)]);
    }

    public function updateStatus(Request $request, Shipment $shipment)
    {
        $this->assertManage($request);
        $last = $this->flow->lastStep($shipment);
        $data = $request->validate([
            'step' => ['required', 'integer', 'min:1', "max:{$last}"],
            'note' => ['nullable', 'string', 'max:1000'],
            'reason' => ['nullable', 'string', 'max:1000'],
            // Details the target step needs can be supplied in the same call.
            'tmda_application_ref' => ['nullable', 'string', 'max:120'],
            'tmda_applied_at' => ['nullable', 'date'],
            'tmda_issued_at' => ['nullable', 'date'],
            'control_number' => ['nullable', 'string', 'max:120'],
        ]);
        $target = (int) $data['step'];
        $from = $shipment->step;

        if ($target === $from) {
            throw ValidationException::withMessages(['step' => 'The shipment is already at that status.']);
        }
        // 18.2: going backward is a correction and needs a logged reason.
        if ($target < $from && mb_strlen(trim((string) ($data['reason'] ?? ''))) < 10) {
            throw ValidationException::withMessages(['reason' => 'Going back a status needs a reason (at least 10 characters).']);
        }

        $details = array_filter(array_intersect_key($data, array_flip(['tmda_application_ref', 'tmda_applied_at', 'tmda_issued_at', 'control_number'])));
        if (isset($details['tmda_application_ref']) && ! isset($details['tmda_applied_at']) && ! $shipment->tmda_applied_at) {
            $details['tmda_applied_at'] = now()->toDateString();
        }
        $shipment->fill($details + ['step' => $target]);
        $shipment->load(['documents', 'vendorFee.receipts', 'vendorFee.deliveryJob']);

        if ($target > $from && ($why = $this->flow->blockReason($shipment, $target))) {
            throw ValidationException::withMessages(['step' => $why]);
        }

        DB::transaction(function () use ($shipment, $from, $target, $data, $request) {
            $shipment->save();
            ShipmentEvent::create([
                'shipment_id' => $shipment->id, 'from_step' => $from, 'to_step' => $target,
                'note' => $data['note'] ?? null,
                'reason' => $target < $from ? trim($data['reason']) : null,
                'user_id' => $request->user()->id, 'created_at' => now(),
            ]);
        });

        $shipment->load('department');
        $this->notifier->statusChanged($shipment, $request->user(), $data['note'] ?? ($target < $from ? 'Corrected: ' . trim($data['reason']) : null));

        return response()->json(['data' => $this->detail($request, $shipment)]);
    }

    // ------------------------------------------------------------ documents

    public function uploadDocument(Request $request, Shipment $shipment)
    {
        $this->assertManage($request);
        $data = $request->validate([
            'type' => ['required', Rule::in(ShipmentFlow::allowedDocTypes($shipment->direction))],
            'file' => ['required', 'file', 'max:20480', 'mimes:pdf,jpg,jpeg,png,doc,docx,xls,xlsx'],
        ]);
        $this->storeDocument($shipment, $data['type'], $request->file('file'), $request->user()->id);
        activity()->performedOn($shipment)->causedBy($request->user())
            ->withProperties(['document' => $data['type']])->log('document uploaded');

        return response()->json(['data' => $this->detail($request, $shipment)]);
    }

    public function downloadDocument(Request $request, Shipment $shipment, string $type)
    {
        $this->assertView($request, $shipment);
        $doc = $shipment->documents()->where('type', $type)->first();
        abort_unless($doc && Storage::disk('public')->exists($doc->path), 404, 'File not found.');

        return Storage::disk('public')->download($doc->path, $doc->original_name);
    }

    private function storeDocument(Shipment $shipment, string $type, UploadedFile $file, int $userId): void
    {
        $old = $shipment->documents()->where('type', $type)->first();
        $path = $file->store("shipments/{$shipment->id}", 'public');
        ShipmentDocument::updateOrCreate(
            ['shipment_id' => $shipment->id, 'type' => $type],
            ['path' => $path, 'original_name' => $file->getClientOriginalName(), 'uploaded_by' => $userId],
        );
        if ($old && $old->path !== $path) {
            Storage::disk('public')->delete($old->path);
        }
    }

    // ---------------------------------------------- clearing fee, machines

    /**
     * Links the clearing agent's Section 16 vendor fee: either an existing
     * unlinked fee, or a new one created exactly as VendorFeeController
     * would (pending_receipt). Payment then follows Section 16's own chain.
     */
    public function linkClearingFee(Request $request, Shipment $shipment)
    {
        $this->assertManage($request);
        abort_if($shipment->direction !== 'import', 422, 'Only import shipments have a clearing fee.');
        abort_if($shipment->vendorFee && $shipment->vendorFee->status === 'paid', 422, 'The clearing fee is already paid.');

        $data = $request->validate([
            'vendor_fee_id' => ['nullable', 'exists:vendor_fees,id'],
            'vendor_id' => ['required_without:vendor_fee_id', 'nullable', 'exists:vendors,id'],
            'billed_amount' => ['required_without:vendor_fee_id', 'nullable', 'integer', 'min:1'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        if (! empty($data['vendor_fee_id'])) {
            $fee = VendorFee::findOrFail($data['vendor_fee_id']);
            abort_if(Shipment::where('vendor_fee_id', $fee->id)->where('id', '!=', $shipment->id)->exists(), 422, 'That vendor fee is already linked to another shipment.');
            abort_if($fee->status === 'rejected', 422, 'That vendor fee was rejected.');
        } else {
            $fee = VendorFee::create([
                'vendor_id' => $data['vendor_id'],
                'description' => ($data['description'] ?? null) ?: "Clearing — {$shipment->reference}",
                'billed_amount' => $data['billed_amount'],
                'currency' => 'TZS',
                'status' => 'pending_receipt',
                'created_by' => $request->user()->id,
            ]);
            ApprovalLog::record($fee, 'created', $request->user());
        }

        $shipment->update(['vendor_fee_id' => $fee->id]);

        return response()->json(['data' => $this->detail($request, $shipment)]);
    }

    public function syncMachines(Request $request, Shipment $shipment)
    {
        $this->assertManage($request);
        $data = $request->validate(['machine_ids' => ['present', 'array'], 'machine_ids.*' => ['integer', 'exists:machines,id']]);
        $shipment->machines()->sync($data['machine_ids']);
        activity()->performedOn($shipment)->causedBy($request->user())
            ->withProperties(['machine_ids' => $data['machine_ids']])->log('machines updated');

        return response()->json(['data' => $this->detail($request, $shipment)]);
    }

    // ------------------------------------------------ options + settings

    /** Everything the new/edit forms pick from, in one call. */
    public function options(Request $request)
    {
        $this->assertManage($request);
        $linked = Shipment::whereNotNull('vendor_fee_id')->pluck('vendor_fee_id');

        return response()->json(['data' => [
            'suppliers' => Supplier::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'purchase_orders' => PurchaseOrder::with('supplier:id,name')->latest('id')->limit(200)->get(['id', 'po_number', 'supplier_id', 'status', 'total_amount'])
                ->map(fn ($po) => ['id' => $po->id, 'po_number' => $po->po_number, 'supplier_id' => $po->supplier_id,
                    'supplier_name' => $po->supplier?->name, 'status' => $po->status, 'total' => (int) $po->total_amount]),
            'departments' => $this->departmentRows(),
            'tenders' => Tender::latest('id')->limit(200)->get(['id', 'tender_number', 'title']),
            // Clearing agents first — the fee here is almost always theirs.
            'vendors' => Vendor::orderByRaw("type = 'clearing' desc")->orderBy('name')->get(['id', 'name', 'type']),
            'open_vendor_fees' => VendorFee::with('vendor:id,name')->whereNotIn('id', $linked)->where('status', '!=', 'rejected')
                ->latest('id')->limit(100)->get()
                ->map(fn ($f) => ['id' => $f->id, 'vendor_name' => $f->vendor?->name, 'description' => $f->description,
                    'billed_amount' => (int) $f->billed_amount, 'status' => $f->status]),
            'doc_types' => ShipmentFlow::DOC_LABELS,
        ]]);
    }

    public function settings(Request $request)
    {
        $this->assertView($request);

        return response()->json(['data' => $this->settingsPayload($request)]);
    }

    public function updateSettings(Request $request)
    {
        abort_unless($request->user()->isAdminTier(), 403, 'Only an administrator can change shipment settings.');
        $data = $request->validate([
            'permit_gate' => ['required', 'boolean'],
            'docs_required_at_creation' => ['required', 'boolean'],
            'import_labels' => ['required', 'array', 'size:' . count(ShipmentFlow::IMPORT_LABELS)],
            'import_labels.*' => ['required', 'string', 'max:60'],
            'export_labels' => ['required', 'array', 'size:' . count(ShipmentFlow::EXPORT_LABELS)],
            'export_labels.*' => ['required', 'string', 'max:60'],
            'confirmed_assumptions' => ['present', 'array'],
            'confirmed_assumptions.*' => ['integer', 'min:0', 'max:' . (count(ShipmentFlow::ASSUMPTIONS) - 1)],
        ]);
        $this->flow->saveSettings($data, $request->user()->id);

        return response()->json(['data' => $this->settingsPayload($request)]);
    }

    private function settingsPayload(Request $request): array
    {
        return $this->flow->settings() + [
            'assumptions' => ShipmentFlow::ASSUMPTIONS,
            'departments' => $this->departmentRows(),
            'leadership_roles' => ShipmentNotifier::LEADERSHIP_ROLES,
            'fallback_roles' => ShipmentNotifier::FALLBACK_ROLES,
            'can_edit' => $request->user()->isAdminTier(),
        ];
    }

    public function storeDepartment(Request $request)
    {
        abort_unless($request->user()->isAdminTier(), 403, 'Only an administrator can manage departments.');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', 'unique:departments,name'],
            'manager_id' => ['nullable', 'exists:users,id'],
        ]);
        Department::create($data);

        return response()->json(['data' => $this->departmentRows()], 201);
    }

    public function updateDepartment(Request $request, Department $department)
    {
        abort_unless($request->user()->isAdminTier(), 403, 'Only an administrator can manage departments.');
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120', Rule::unique('departments', 'name')->ignore($department->id)],
            'manager_id' => ['nullable', 'exists:users,id'],
        ]);
        $department->update($data);

        return response()->json(['data' => $this->departmentRows()]);
    }

    private function departmentRows()
    {
        return Department::with('manager:id,name')->orderBy('name')->get()
            ->map(fn ($d) => ['id' => $d->id, 'name' => $d->name, 'manager_id' => $d->manager_id, 'manager_name' => $d->manager?->name]);
    }

    // ------------------------------------------------------------ helpers

    private function validated(Request $request, bool $creating): array
    {
        $data = $request->validate([
            'direction' => [$creating ? 'required' : 'prohibited', Rule::in(['import', 'export'])],
            'freight_mode' => [$creating ? 'required' : 'sometimes', Rule::in(['air', 'sea', 'road'])],
            'description' => [$creating ? 'required' : 'sometimes', 'string', 'max:500'],
            'supplier_id' => ['nullable', 'exists:suppliers,id'],
            'purchase_order_id' => ['nullable', 'exists:purchase_orders,id'],
            'outbound_reason' => ['nullable', 'string', 'max:255'],
            'department_id' => ['nullable', 'exists:departments,id'],
            'tender_id' => ['nullable', 'exists:tenders,id'],
            'expected_arrival' => ['nullable', 'date'],
            'port' => ['nullable', 'string', 'max:120'],
            'location_notes' => ['nullable', 'string', 'max:2000'],
            'tmda_application_ref' => ['nullable', 'string', 'max:120'],
            'tmda_applied_at' => ['nullable', 'date'],
            'tmda_issued_at' => ['nullable', 'date'],
            'control_number' => ['nullable', 'string', 'max:120'],
        ]);

        // A PO's own supplier wins, so the two can't disagree.
        if (! empty($data['purchase_order_id']) && empty($data['supplier_id'])) {
            $data['supplier_id'] = PurchaseOrder::whereKey($data['purchase_order_id'])->value('supplier_id');
        }

        return $data;
    }

    /** documents[<type>] files sent with the create form. */
    private function validatedFiles(Request $request, string $direction): array
    {
        $request->validate([
            'documents' => ['nullable', 'array'],
            'documents.*' => ['file', 'max:20480', 'mimes:pdf,jpg,jpeg,png,doc,docx,xls,xlsx'],
        ]);
        $files = array_filter((array) $request->file('documents', []), fn ($f) => $f instanceof UploadedFile);
        $bad = array_diff(array_keys($files), ShipmentFlow::allowedDocTypes($direction));
        if ($bad) {
            throw ValidationException::withMessages(['documents' => 'Unknown document type: ' . implode(', ', $bad) . '.']);
        }

        return $files;
    }

    private function feePayload(?VendorFee $fee): ?array
    {
        if (! $fee) {
            return null;
        }
        $receipts = $fee->receipts;

        return [
            'id' => $fee->id,
            'vendor_name' => $fee->vendor?->name,
            'description' => $fee->description,
            'billed' => (int) $fee->billed_amount,
            'receipts_total' => (int) $receipts->sum('amount'),
            'receipt_uploaded' => $receipts->isNotEmpty(),
            'finance_verified' => $receipts->isNotEmpty() && $receipts->whereNull('verified_at')->isEmpty(),
            'status' => $fee->status,
            'paid' => $fee->status === 'paid',
            'block_reason' => in_array($fee->status, ['paid', 'rejected'], true) ? null : $fee->paymentBlockReason(),
        ];
    }

    private function summary(Shipment $s): array
    {
        $flag = $this->flow->flag($s);
        $required = ShipmentFlow::requiredDocTypes($s->direction, $s->freight_mode);

        return [
            'id' => $s->id,
            'reference' => $s->reference,
            'direction' => $s->direction,
            'freight_mode' => $s->freight_mode,
            'description' => $s->description,
            'supplier_name' => $s->supplier?->name,
            'po_number' => $s->purchaseOrder?->po_number,
            'outbound_reason' => $s->outbound_reason,
            'department_id' => $s->department_id,
            'department_name' => $s->department?->name,
            'step' => $s->step,
            'last_step' => $this->flow->lastStep($s),
            'status_label' => $this->flow->label($s),
            'flag' => $flag['flag'],
            'flag_reason' => $flag['reason'],
            'docs_uploaded' => count(array_intersect($required, $s->documents->pluck('type')->all())),
            'docs_required' => count($required),
            'expected_arrival' => $s->expected_arrival?->toDateString(),
            'location_notes' => $s->location_notes,
            'fee_status' => $s->vendorFee?->status,
            'updated_at' => $s->updated_at?->toIso8601String(),
        ];
    }

    private function detail(Request $request, Shipment $s): array
    {
        $s->load(['supplier:id,name', 'purchaseOrder:id,po_number,status,total_amount', 'department.manager:id,name', 'tender:id,tender_number,title',
            'documents.uploader:id,name', 'events.user:id,name', 'vendorFee.receipts', 'vendorFee.vendor:id,name',
            'vendorFee.deliveryJob', 'machines:id,serial_no,model,type', 'creator:id,name']);

        $labels = $this->flow->labels($s);
        $required = ShipmentFlow::requiredDocTypes($s->direction, $s->freight_mode);
        $docs = $s->documents->keyBy('type');
        $canManage = $this->canManage($request);

        return $this->summary($s) + [
            'labels' => $labels,
            'port' => $s->port,
            'tender' => $s->tender ? ['id' => $s->tender->id, 'tender_number' => $s->tender->tender_number, 'title' => $s->tender->title] : null,
            'supplier_id' => $s->supplier_id,
            'purchase_order_id' => $s->purchase_order_id,
            'purchase_order' => $s->purchaseOrder ? ['id' => $s->purchaseOrder->id, 'po_number' => $s->purchaseOrder->po_number,
                'status' => $s->purchaseOrder->status, 'total' => (int) $s->purchaseOrder->total_amount] : null,
            'department_manager' => $s->department?->manager?->name,
            'tmda' => $s->direction === 'import' ? [
                'application_ref' => $s->tmda_application_ref,
                'applied_at' => $s->tmda_applied_at?->toDateString(),
                'issued_at' => $s->tmda_issued_at?->toDateString(),
                'permit_uploaded' => $docs->has('tmda_permit'),
            ] : null,
            'control_number' => $s->control_number,
            'documents' => collect(ShipmentFlow::allowedDocTypes($s->direction))
                ->filter(fn ($t) => in_array($t, $required, true) || $docs->has($t) || in_array($t, ['tmda_permit', 'recipient_receipt'], true))
                ->map(fn ($t) => [
                    'type' => $t,
                    'label' => ShipmentFlow::DOC_LABELS[$t],
                    'required' => in_array($t, $required, true),
                    'file_name' => $docs->get($t)?->original_name,
                    'uploaded_by' => $docs->get($t)?->uploader?->name,
                    'uploaded_at' => $docs->get($t)?->updated_at?->toIso8601String(),
                ])->values(),
            'clearing_fee' => $this->feePayload($s->vendorFee),
            'machines' => $s->machines->map(fn (Machine $m) => ['id' => $m->id, 'serial_no' => $m->serial_no, 'model' => $m->model, 'type' => $m->type])->values(),
            'events' => $s->events->map(fn (ShipmentEvent $e) => [
                'from_step' => $e->from_step,
                'to_step' => $e->to_step,
                'label' => $labels[$e->to_step - 1] ?? "Step {$e->to_step}",
                'note' => $e->note,
                'reason' => $e->reason,
                'backward' => $e->from_step !== null && $e->to_step < $e->from_step,
                'by' => $e->user?->name,
                'at' => $e->created_at?->toIso8601String(),
            ])->values(),
            'next_block_reason' => $s->step < $this->flow->lastStep($s) ? $this->flow->blockReason($s, $s->step + 1) : null,
            'created_by_name' => $s->creator?->name,
            'created_at' => $s->created_at?->toIso8601String(),
            'can_manage' => $canManage,
            // Read-only viewers with Section 16 approval authority act on the
            // fee from the Vendors screen; this just tells the UI to link there.
            'can_approve_fee' => $request->user()->hasDirectorAuthority(),
            'recipients' => User::whereIn('id', $this->notifier->recipients($s))->orderBy('name')->get(['id', 'name', 'role'])
                ->map(fn ($u) => ['name' => $u->name, 'role' => $u->role])->values(),
        ];
    }
}
