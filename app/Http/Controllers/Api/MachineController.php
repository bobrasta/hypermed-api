<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MachineResource;
use App\Models\Hospital;
use App\Models\Machine;
use App\Services\MachineModelNormalizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class MachineController extends Controller
{
    public function index(Request $request)
    {
        // See InventoryController::index() — same reasoning: callers load a
        // big batch once and reveal/filter locally, don't silently truncate.
        $perPage = min($request->integer('per_page', 20), 1000);
        $page    = $request->integer('page', 1);
        $filters = $request->only(['status', 'hospital_id', 'type', 'model', 'zone', 'replacement_recommended']);

        // Same TTL-cache pattern as DashboardController/HospitalController —
        // was a big chunk of the 2-3s load time on the Machines screen.
        $cacheKey = 'machines:index:' . md5(json_encode($filters) . ":{$perPage}:{$page}");
        $machines = Cache::remember($cacheKey, 60, function () use ($request, $perPage) {
            $query = Machine::with('hospital');

            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }
            if ($request->filled('hospital_id')) {
                $query->where('hospital_id', $request->hospital_id);
            }
            if ($request->filled('type')) {
                $query->where('type', $request->type);
            }
            if ($request->filled('model')) {
                $query->where('model', $request->model);
            }
            if ($request->filled('zone')) {
                $zone = $request->zone;
                $query->whereHas('hospital', fn ($q) => $q->where('zone', $zone));
            }
            if ($request->boolean('replacement_recommended')) {
                // Section 12's list badge/filter — a single aggregate query
                // (not one cost lookup per row) against every machine with a
                // purchase cost on record.
                $query->whereIn('id', $this->replacementRecommendedIds());
            }

            return $query->paginate($perPage);
        });

        return MachineResource::collection($machines);
    }

    // Machine IDs whose recorded service cost exceeds the configured
    // replacement threshold (Section 12). Delegates to
    // MachineCostService::exceedsThreshold() per candidate machine (not a
    // hand-rolled flat aggregate) so this always agrees with the Service
    // Costs tab's own number — Section 6's per-ticket cost-splitting across
    // several machines can't be expressed as one direct machine_id join,
    // and drifting the two calculations apart is worse than the N+1: the
    // candidate set here is already narrowed to "has a purchase cost on
    // record", which stays small in practice.
    private function replacementRecommendedIds(): array
    {
        $costs = app(\App\Services\MachineCostService::class);

        return Machine::whereNotNull('purchase_cost_tsh')->where('purchase_cost_tsh', '>', 0)
            ->get()
            ->filter(fn ($machine) => $costs->exceedsThreshold($machine))
            ->pluck('id')
            ->all();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'serial_no'        => ['required', 'string', 'unique:machines'],
            'model'            => ['required', 'string'],
            'type'             => ['required', 'string'],
            'hospital_id'      => ['required', 'exists:hospitals,id'],
            'ward'             => ['nullable', 'string'],
            'install_date'     => ['nullable', 'date'],
            'warranty_expiry'  => ['nullable', 'date'],
            // pending_installation is allowed here too — the only other
            // path that ever sets it is MachineRegistrationService
            // (Sales Order delivery), which writes straight to the model
            // and never goes through this validation at all. A machine
            // that didn't arrive via a tracked delivery (a swap, a
            // directly-sourced unit) needs a way in too — see the
            // "Register new machine" flow on the Create Service Ticket
            // form. pending_signoff is deliberately NOT allowed here: it
            // must only ever be reached by actually resolving an
            // installation ticket (ServiceTicketController::resolve()),
            // never set directly at creation.
            'status'           => ['required', 'in:operational,needs_service,down,warranty,idle,pending_installation'],
            'revenue_per_month' => ['nullable', 'integer', 'min:0'],
            // This endpoint always targets a real hospital directly (unlike
            // Receive Machine below) — Section 13's "admin fallback
            // registration" path, so it goes straight to Installed.
            'purchase_cost'          => ['nullable', 'integer', 'min:0'],
            'purchase_cost_currency' => ['nullable', 'string', 'size:3'],
            'purchase_cost_fx_rate'  => ['nullable', 'numeric', 'min:0'],
        ]);

        // Duplicate prevention server-side, not just in the combobox — a
        // direct API call or a stale client still normalizes onto whatever
        // spelling is already on record.
        if ($canonical = MachineModelNormalizer::canonicalFor($data['model'])) {
            $data['model'] = $canonical;
        }

        $data['lifecycle_stage'] = 'installed';
        $data = $this->applyPurchaseCost($data);

        $machine = Machine::create($data);
        self::recomputeHospitalCounts($machine->hospital_id);

        return response()->json(['data' => new MachineResource($machine->load('hospital'))], 201);
    }

    public function show(Machine $machine)
    {
        $machine->load('hospital');
        // Section 6: Service History must include tickets this machine is
        // on via the multi-machine picker too, not just ones that still
        // point at it through the legacy machine_id column — see
        // Machine::allTicketIds(). setRelation (not load) because this
        // isn't a real Eloquent relation, but MachineResource's
        // whenLoaded('tickets') only cares that something's been assigned.
        $machine->setRelation('tickets', $machine->allTickets()
            ->with(['assignee', 'checklistItems', 'partsUsed.inventoryItem'])
            ->latest()
            ->get());

        return response()->json(['data' => new MachineResource($machine)]);
    }

    public function update(Request $request, Machine $machine)
    {
        $data = $request->validate([
            'serial_no'        => ['sometimes', 'string', 'unique:machines,serial_no,' . $machine->id],
            'model'            => ['sometimes', 'string'],
            'type'             => ['sometimes', 'string'],
            'hospital_id'      => ['sometimes', 'exists:hospitals,id'],
            'ward'             => ['nullable', 'string'],
            'install_date'     => ['nullable', 'date'],
            'warranty_expiry'  => ['nullable', 'date'],
            'status'           => ['sometimes', 'in:operational,needs_service,down,warranty,idle'],
            'revenue_per_month' => ['nullable', 'integer', 'min:0'],
            // Editable here per Section 13 — audit-logged via Machine's
            // LogsActivity (logOnlyDirty, so a no-op resubmit doesn't spam
            // the trail).
            'purchase_cost'          => ['nullable', 'integer', 'min:0'],
            'purchase_cost_currency' => ['nullable', 'string', 'size:3'],
            'purchase_cost_fx_rate'  => ['nullable', 'numeric', 'min:0'],
        ]);

        if (isset($data['model']) && ($canonical = MachineModelNormalizer::canonicalFor($data['model'])) && $canonical !== $data['model']) {
            $data['model'] = $canonical;
        }

        if (array_key_exists('purchase_cost', $data)) {
            $data = $this->applyPurchaseCost($data);
        }

        $previousHospitalId = $machine->getOriginal('hospital_id');
        $machine->update($data);

        if ($machine->hospital_id) {
            self::recomputeHospitalCounts($machine->hospital_id);
        }
        if ($previousHospitalId && $previousHospitalId !== $machine->hospital_id) {
            self::recomputeHospitalCounts($previousHospitalId);
        }

        return response()->json(['data' => new MachineResource($machine->load('hospital'))]);
    }

    // Section 13 of hypermed_claude_code_prompt.md: "Records serial, model,
    // type, manufacturer, arrival date, store, condition, purchase cost, and
    // invoice, packing list and warranty documents." Document upload isn't
    // wired yet (see the checklist) — this covers the data fields, creating
    // the machine In Stock, with no hospital.
    public function receive(Request $request)
    {
        abort_if(! $request->user()->hasMachineReceiveAuthority(), 403,
            'Access Denied: you do not have permission to receive new equipment.');

        $data = $request->validate([
            'serial_no'          => ['required', 'string', 'unique:machines'],
            'model'              => ['required', 'string'],
            'type'               => ['required', 'string'],
            'manufacturer'       => ['nullable', 'string'],
            'condition'          => ['nullable', 'string'],
            'arrival_date'       => ['nullable', 'date'],
            'store_location_id'  => ['required', 'exists:locations,id'],
            'warranty_expiry'    => ['nullable', 'date'],
            'purchase_cost'          => ['nullable', 'integer', 'min:0'],
            'purchase_cost_currency' => ['nullable', 'string', 'size:3'],
            'purchase_cost_fx_rate'  => ['nullable', 'numeric', 'min:0'],
        ]);

        if ($canonical = MachineModelNormalizer::canonicalFor($data['model'])) {
            $data['model'] = $canonical;
        }

        $data['lifecycle_stage'] = 'in_stock';
        $data['status'] = 'idle';
        $data['arrival_date'] = $data['arrival_date'] ?? now()->toDateString();
        $data = $this->applyPurchaseCost($data);

        $machine = Machine::create($data);

        return response()->json(['data' => new MachineResource($machine->load('storeLocation'))], 201);
    }

    // In Stock -> Allocated: reserves a machine for a hospital ahead of
    // installation, tied to the sale that justified it. Section 13's
    // "Allocate" move.
    public function allocate(Request $request, Machine $machine)
    {
        abort_if(! $request->user()->hasMachineAllocateAuthority(), 403,
            'Access Denied: you do not have permission to allocate equipment.');
        abort_if($machine->lifecycle_stage !== 'in_stock', 422,
            'Only an In Stock machine can be allocated.');

        $data = $request->validate([
            'hospital_id'   => ['required', 'exists:hospitals,id'],
            'reason'        => ['nullable', 'string'],
            'quotation_id'  => ['nullable', 'exists:quotations,id'],
            'invoice_id'    => ['nullable', 'exists:invoices,id'],
        ]);

        $fromLocationId = $machine->store_location_id;

        $machine->update([
            'hospital_id'      => $data['hospital_id'],
            'lifecycle_stage'  => 'allocated',
            'store_location_id' => null,
            // Matches the Sales-Order delivery path (MachineRegistrationService),
            // which sets this same status at its own "Allocated" moment — an
            // installation ticket's own gate (ServiceTicketController::store())
            // checks status, not lifecycle_stage, so this keeps that check
            // working for a machine that arrived via Receive+Allocate too.
            'status'           => 'pending_installation',
        ]);

        $machine->transfers()->create([
            'transfer_type'     => 'allocate',
            'to_hospital_id'    => $data['hospital_id'],
            'from_location_id'  => $fromLocationId,
            'reason'            => $data['reason'] ?? null,
            'quotation_id'      => $data['quotation_id'] ?? null,
            'invoice_id'        => $data['invoice_id'] ?? null,
            'approved_by'       => $request->user()->id,
        ]);

        return response()->json(['data' => new MachineResource($machine->load('hospital'))]);
    }

    // Original currency in, TSh comparison amount out — Section 12's
    // viability calculation always reads purchase_cost_tsh so it never has
    // to convert live. TZS itself always carries rate 1 (no conversion).
    private function applyPurchaseCost(array $data): array
    {
        if (! array_key_exists('purchase_cost', $data) || $data['purchase_cost'] === null) {
            return $data;
        }

        $currency = $data['purchase_cost_currency'] ?? 'TZS';
        $rate = $currency === 'TZS' ? 1 : ($data['purchase_cost_fx_rate'] ?? null);
        abort_if($rate === null, 422, 'purchase_cost_fx_rate is required when purchase_cost_currency is not TZS.');

        $data['purchase_cost_currency'] = $currency;
        $data['purchase_cost_fx_rate'] = $rate;
        $data['purchase_cost_tsh'] = (int) round($data['purchase_cost'] * $rate);
        $data['purchase_cost_recorded_at'] = now();

        return $data;
    }

    public function destroy(Machine $machine)
    {
        $hospitalId = $machine->hospital_id;
        $machine->delete();
        self::recomputeHospitalCounts($hospitalId);

        return response()->json(null, 204);
    }

    // Closes the chain-of-custody loop: a supervisor/technician (not the
    // installer themselves — no self-sign-off) confirms the installation was
    // done right. Only reachable once the installation ticket has been
    // resolved (see ServiceTicketController::resolve()).
    public function signOff(Request $request, Machine $machine)
    {
        abort_if(! $request->user()->hasEquipmentSignOffAuthority(), 403,
            'You are not authorised to sign off equipment installations.');
        abort_if($machine->status !== 'pending_signoff', 422,
            'This machine is not awaiting sign-off.');
        abort_if($machine->installed_by === $request->user()->id, 403,
            'The person who installed this equipment cannot also sign off on it.');

        // Section 13's Handover move — completes Allocated -> Installed for
        // any machine that reached pending_signoff, whether it got there via
        // the Sales-Order delivery path or a manual Receive+Allocate. Ownership
        // passes to the hospital at this exact point (the spec's own stated
        // assumption: "ownership passes at the signed handover after
        // installation").
        $wasAllocated = $machine->lifecycle_stage === 'allocated';

        $machine->update([
            'signed_off_by'   => $request->user()->id,
            'signed_off_at'   => now(),
            'status'          => 'operational',
            'lifecycle_stage' => 'installed',
        ]);

        if ($wasAllocated) {
            $machine->transfers()->create([
                'transfer_type'  => 'handover',
                'to_hospital_id' => $machine->hospital_id,
                'approved_by'    => $request->user()->id,
            ]);
        }

        self::recomputeHospitalCounts($machine->hospital_id);

        return response()->json(['data' => new MachineResource($machine->load(['hospital', 'installedBy', 'signedOffBy']))]);
    }

    // Hospital.machine_count/machines_operational are denormalized for the
    // map/dashboard — recompute from the actual rows (not incremental math)
    // so they can't drift out of sync. Public+static so
    // ServiceTicketController::completeMachine() (Section 6's per-machine
    // handover) can reuse the exact same recompute after its own handover,
    // instead of duplicating this query.
    public static function recomputeHospitalCounts(?int $hospitalId): void
    {
        if ($hospitalId === null) {
            return;
        }

        // Allocated machines already carry this hospital_id (reserved target)
        // but aren't installed yet — Section 13 excludes In Stock/Allocated
        // from hospital counts, so only 'installed' counts here.
        Hospital::whereKey($hospitalId)->update([
            'machine_count'        => Machine::where('hospital_id', $hospitalId)->where('lifecycle_stage', 'installed')->count(),
            'machines_operational' => Machine::where('hospital_id', $hospitalId)->where('lifecycle_stage', 'installed')->where('status', 'operational')->count(),
        ]);
    }

    public function map()
    {
        // In Stock/Allocated machines aren't anywhere real yet (Section 13)
        // — excluded from the map, same as the dashboard's machine counts.
        $machines = Cache::remember('machines:map', 60, function () {
            return Machine::with('hospital:id,name,short_code,latitude,longitude,zone')
                ->where('lifecycle_stage', 'installed')
                ->select('id', 'serial_no', 'model', 'type', 'hospital_id', 'status')
                ->get();
        });

        return MachineResource::collection($machines);
    }

    // In Stock machines waiting to be allocated/installed — Section 13's
    // "store view (stage = In Stock) shows what is on hand and how long
    // each machine has been waiting."
    public function inStock()
    {
        $machines = Machine::with('storeLocation')
            ->where('lifecycle_stage', 'in_stock')
            ->orderBy('arrival_date')
            ->get();

        return MachineResource::collection($machines);
    }

    // Section 12: Service Costs tab data (expense history + stats +
    // viability). "the tab and cost endpoints are visible only to Admin,
    // Director, CTO and finance roles, enforced server-side."
    public function costs(Machine $machine, \App\Services\MachineCostService $costs)
    {
        $user = request()->user();
        abort_if(
            ! $user->hasDirectorAuthority()
                && ! $user->hasCtoApprovalAuthority()
                && ! in_array($user->role, ['finance', 'finance_manager'], true),
            403, 'Access Denied: Service Costs is visible to Admin, Director, CTO and finance roles only.'
        );

        return response()->json(['data' => $costs->breakdown($machine)]);
    }
}
