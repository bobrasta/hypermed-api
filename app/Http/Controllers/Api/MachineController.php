<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MachineResource;
use App\Models\Hospital;
use App\Models\Machine;
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
        $filters = $request->only(['status', 'hospital_id', 'type', 'model', 'zone']);

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

            return $query->paginate($perPage);
        });

        return MachineResource::collection($machines);
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
            'status'           => ['required', 'in:operational,needs_service,down,warranty,idle'],
            'revenue_per_month' => ['nullable', 'integer', 'min:0'],
        ]);

        $machine = Machine::create($data);
        $this->recomputeHospitalCounts($machine->hospital_id);

        return response()->json(['data' => new MachineResource($machine->load('hospital'))], 201);
    }

    public function show(Machine $machine)
    {
        $machine->load(['hospital', 'tickets.assignee', 'tickets.checklistItems', 'tickets.partsUsed.inventoryItem']);

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
        ]);

        $previousHospitalId = $machine->getOriginal('hospital_id');
        $machine->update($data);

        $this->recomputeHospitalCounts($machine->hospital_id);
        if ($previousHospitalId !== $machine->hospital_id) {
            $this->recomputeHospitalCounts($previousHospitalId);
        }

        return response()->json(['data' => new MachineResource($machine->load('hospital'))]);
    }

    public function destroy(Machine $machine)
    {
        $hospitalId = $machine->hospital_id;
        $machine->delete();
        $this->recomputeHospitalCounts($hospitalId);

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

        $machine->update([
            'signed_off_by' => $request->user()->id,
            'signed_off_at' => now(),
            'status'        => 'operational',
        ]);

        return response()->json(['data' => new MachineResource($machine->load(['hospital', 'installedBy', 'signedOffBy']))]);
    }

    // Hospital.machine_count/machines_operational are denormalized for the
    // map/dashboard — recompute from the actual rows (not incremental math)
    // so they can't drift out of sync.
    private function recomputeHospitalCounts(int $hospitalId): void
    {
        Hospital::whereKey($hospitalId)->update([
            'machine_count'        => Machine::where('hospital_id', $hospitalId)->count(),
            'machines_operational' => Machine::where('hospital_id', $hospitalId)->where('status', 'operational')->count(),
        ]);
    }

    public function map()
    {
        $machines = Cache::remember('machines:map', 60, function () {
            return Machine::with('hospital:id,name,short_code,latitude,longitude,zone')
                ->select('id', 'serial_no', 'model', 'type', 'hospital_id', 'status')
                ->get();
        });

        return MachineResource::collection($machines);
    }
}
