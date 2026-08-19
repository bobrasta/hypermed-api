<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MachineResource;
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

        $machine->update($data);

        return response()->json(['data' => new MachineResource($machine->load('hospital'))]);
    }

    public function destroy(Machine $machine)
    {
        $machine->delete();

        return response()->json(null, 204);
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
