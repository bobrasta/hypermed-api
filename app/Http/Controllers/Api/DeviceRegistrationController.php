<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeviceRegistration;
use App\Models\DeviceRegistrationFile;
use App\Models\DeviceRequirement;
use App\Services\EffectivePermissionResolver;
use App\Services\Tender\TenderDeadlineService;
use App\Services\Tender\TenderDocumentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Section 19.5: TMDA device registrations, self-managed from
 * manufacturer-supplied data. The Annex V checklist is stored as rows so
 * the document can be regenerated whenever the data changes.
 */
class DeviceRegistrationController extends Controller
{
    public function __construct(private TenderDeadlineService $deadlines) {}

    private function can(Request $r, string $key): bool
    {
        return $r->user()->isAdminTier() || app(EffectivePermissionResolver::class)->can($r->user(), $key);
    }

    private function assertView(Request $r): void
    {
        abort_unless($this->can($r, 'screens.tenders') || $this->can($r, 'tenders.manage'), 403, 'You do not have access to device registrations.');
    }

    private function assertManage(Request $r): void
    {
        abort_unless($this->can($r, 'tenders.manage'), 403, 'Only procurement staff can change device registrations.');
    }

    public function index(Request $request)
    {
        $this->assertView($request);
        $q = DeviceRegistration::with('requirements')->orderBy('brand_name');
        if ($request->filled('q')) {
            $s = '%' . $request->q . '%';
            $q->where(fn ($w) => $w->where('brand_name', 'ilike', $s)->orWhere('model', 'ilike', $s)
                ->orWhere('manufacturer', 'ilike', $s)->orWhere('registration_number', 'ilike', $s));
        }

        return response()->json(['data' => [
            'devices' => $q->get()->map(fn ($d) => $this->shape($d, false)),
            'can_manage' => $this->can($request, 'tenders.manage'),
        ]]);
    }

    public function show(Request $request, DeviceRegistration $device)
    {
        $this->assertView($request);
        $device->load(['requirements', 'files']);

        return response()->json(['data' => $this->shape($device, true) + ['can_manage' => $this->can($request, 'tenders.manage')]]);
    }

    public function store(Request $request)
    {
        $this->assertManage($request);
        $device = DB::transaction(function () use ($request) {
            $device = DeviceRegistration::create($this->validated($request, true) + ['created_by' => $request->user()->id]);
            foreach (DeviceRegistration::PRINCIPLES as $i => $p) {
                $device->requirements()->create(['principle_no' => $i + 1, 'principle' => $p]);
            }

            return $device;
        });

        return response()->json(['data' => $this->shape($device->load(['requirements', 'files']), true)], 201);
    }

    public function update(Request $request, DeviceRegistration $device)
    {
        $this->assertManage($request);
        $device->update($this->validated($request, false));

        return response()->json(['data' => $this->shape($device->load(['requirements', 'files']), true)]);
    }

    private function validated(Request $request, bool $creating): array
    {
        return $request->validate([
            'brand_name' => [$creating ? 'required' : 'sometimes', 'string', 'max:200'],
            'common_name' => ['nullable', 'string', 'max:200'],
            'model' => ['nullable', 'string', 'max:200'],
            'manufacturer' => ['nullable', 'string', 'max:200'],
            'risk_class' => ['nullable', Rule::in(['A', 'B', 'C', 'D'])],
            'registration_number' => ['nullable', 'string', 'max:100'],
            'status' => ['sometimes', Rule::in(DeviceRegistration::STATUSES)],
            'submitted_at' => ['nullable', 'date'],
            'registered_at' => ['nullable', 'date'],
            'renewal_due_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
    }

    public function updateRequirement(Request $request, DeviceRegistration $device, int $no)
    {
        $this->assertManage($request);
        $row = DeviceRequirement::where('device_registration_id', $device->id)->where('principle_no', $no)->firstOrFail();
        $row->update($request->validate([
            'applicable' => ['nullable', 'boolean'],
            'method' => ['nullable', 'string', 'max:255'],
            'supporting_document' => ['nullable', 'string', 'max:255'],
        ]));
        activity()->performedOn($device)->causedBy($request->user())->log("Updated checklist principle {$no}");

        return response()->json(['data' => $this->shape($device->load(['requirements', 'files']), true)]);
    }

    public function uploadFile(Request $request, DeviceRegistration $device)
    {
        $this->assertManage($request);
        $data = $request->validate([
            'file' => ['required', 'file', 'max:20480'],
            'principle_nos' => ['nullable', 'array'],
            'principle_nos.*' => ['integer', 'min:1', 'max:' . count(DeviceRegistration::PRINCIPLES)],
            'description' => ['nullable', 'string', 'max:255'],
        ]);
        $device->files()->create([
            'path' => $data['file']->store("device-registrations/{$device->id}", 'public'),
            'original_name' => $data['file']->getClientOriginalName(),
            'principle_nos' => array_values(array_unique(array_map('intval', $data['principle_nos'] ?? []))),
            'description' => $data['description'] ?? null,
            'uploaded_by' => $request->user()->id,
        ]);

        return response()->json(['data' => $this->shape($device->load(['requirements', 'files']), true)]);
    }

    public function downloadFile(Request $request, DeviceRegistration $device, DeviceRegistrationFile $file)
    {
        $this->assertView($request);
        abort_unless($file->device_registration_id === $device->id && Storage::disk('public')->exists($file->path), 404);

        return Storage::disk('public')->download($file->path, $file->original_name);
    }

    public function deleteFile(Request $request, DeviceRegistration $device, DeviceRegistrationFile $file)
    {
        $this->assertManage($request);
        abort_unless($file->device_registration_id === $device->id, 404);
        Storage::disk('public')->delete($file->path);
        $file->delete();

        return response()->json(['data' => $this->shape($device->load(['requirements', 'files']), true)]);
    }

    public function importLetter(Request $request, DeviceRegistration $device, TenderDocumentService $docs)
    {
        $this->assertManage($request);
        [$bytes, $name] = $docs->reasonForImportation($device, $request->validate([
            'office' => ['nullable', 'integer', 'min:0'],
            'purpose' => ['nullable', 'string', 'max:500'],
            'date' => ['nullable', 'date'],
        ]));

        return $this->docx($bytes, $name);
    }

    public function checklistDocument(Request $request, DeviceRegistration $device, TenderDocumentService $docs)
    {
        $this->assertView($request);
        [$bytes, $name] = $docs->checklist($device);

        return $this->docx($bytes, $name);
    }

    private function docx(string $bytes, string $name)
    {
        return response($bytes, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'Content-Disposition' => 'attachment; filename="' . $name . '"',
        ]);
    }

    private function shape(DeviceRegistration $d, bool $detail): array
    {
        $out = $d->only(['id', 'brand_name', 'common_name', 'model', 'manufacturer', 'risk_class', 'registration_number', 'status', 'notes']) + [
            'submitted_at' => $d->submitted_at?->toDateString(),
            'registered_at' => $d->registered_at?->toDateString(),
            'renewal_due_date' => $d->renewal_due_date?->toDateString(),
            'renewal' => $this->deadlines->forDevice($d),
            'allows_import' => $d->allowsImport(),
            'checklist_done' => $d->requirements->filter->isComplete()->count(),
            'checklist_total' => $d->requirements->count(),
        ];
        if ($detail) {
            $out['requirements'] = $d->requirements->map(fn ($r) => $r->only(['principle_no', 'principle', 'applicable', 'method', 'supporting_document']) + ['complete' => $r->isComplete()])->values();
            $out['files'] = $d->files->map(fn ($f) => $f->only(['id', 'original_name', 'principle_nos', 'description', 'created_at']))->values();
        }

        return $out;
    }
}
