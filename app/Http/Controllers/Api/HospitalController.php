<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\HospitalResource;
use App\Models\Hospital;
use App\Services\CreditCheckService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class HospitalController extends Controller
{
    public function index(Request $request)
    {
        // q present => combobox search mode: small, capped result set, never
        // the "load everything and filter locally" pattern the rest of this
        // endpoint uses. The hospital directory is modeled to grow into the
        // thousands (national facility registry import), so a combobox
        // backing it must never assume the full list is cheap to hold client
        // side — see hypermed_claude_code_prompt.md Section 4.
        if ($request->filled('q')) {
            $perPage = min($request->integer('per_page', 20), 50);
            $q       = trim((string) $request->string('q'));

            $query = Hospital::query()
                ->where(function ($w) use ($q) {
                    $w->where('name', 'ilike', "%{$q}%")
                      ->orWhere('short_code', 'ilike', "%{$q}%");
                })
                ->orderBy('name');

            if ($request->filled('type')) {
                $query->where('type', $request->type);
            }
            if ($request->filled('region')) {
                $query->where('region', $request->region);
            }
            if ($request->filled('zone')) {
                $query->where('zone', $request->zone);
            }

            return HospitalResource::collection($query->limit($perPage)->get());
        }

        // See InventoryController::index() — same reasoning: callers load a
        // big batch once and reveal/filter locally, don't silently truncate.
        $perPage = min($request->integer('per_page', 20), 1000);
        $page    = $request->integer('page', 1);
        $filters = $request->only(['type', 'region', 'zone']);

        // Same TTL-cache pattern as DashboardController — this list barely
        // changes between requests, and was a big chunk of the 2-3s load
        // time on the Machines/Dashboard screens before this was added.
        $cacheKey = 'hospitals:index:' . md5(json_encode($filters) . ":{$perPage}:{$page}");
        $hospitals = Cache::remember($cacheKey, 60, function () use ($request, $perPage) {
            $query = Hospital::query();

            if ($request->filled('type')) {
                $query->where('type', $request->type);
            }
            if ($request->filled('region')) {
                $query->where('region', $request->region);
            }
            if ($request->filled('zone')) {
                $query->where('zone', $request->zone);
            }

            return $query->paginate($perPage);
        });

        return HospitalResource::collection($hospitals);
    }

    public function store(Request $request)
    {
        abort_if(! $request->user()->hasHospitalManageAuthority(), 403,
            'Access Denied: you do not have permission to add hospital records.');

        $data = $request->validate([
            'name'                => ['required', 'string'],
            'short_code'          => ['nullable', 'string', 'max:20'],
            'type'                => ['required', 'in:public,private,mission,clinic'],
            'region'              => ['nullable', 'string'],
            'district'            => ['nullable', 'string'],
            'latitude'            => ['nullable', 'numeric'],
            'longitude'           => ['nullable', 'numeric'],
            'zone'                => ['nullable', 'in:coastal,northern,lake,central,shighland,southern'],
            'revenue_monthly'     => ['nullable', 'integer', 'min:0'],
            'credit_limit'        => ['nullable', 'integer', 'min:0'],
            'contact_name'        => ['nullable', 'string'],
            'contact_phone'       => ['nullable', 'string'],
            'contact_email'       => ['nullable', 'email'],
            'notes'               => ['nullable', 'string'],
        ]);

        $hospital = Hospital::create($data);

        return response()->json(['data' => new HospitalResource($hospital)], 201);
    }

    public function show(Hospital $hospital, CreditCheckService $creditCheck)
    {
        $hospital->load('machines');

        $uptime = $hospital->machine_count > 0
            ? round(($hospital->machines_operational / $hospital->machine_count) * 100, 1)
            : 0;

        $resource = new HospitalResource($hospital);
        $data = $resource->toArray(request());
        $data['uptime_percent'] = $uptime;

        if ($hospital->credit_limit !== null) {
            $data['outstanding_balance'] = $creditCheck->getOutstandingBalance($hospital);
            $data['credit_available'] = max(0, $hospital->credit_limit - $data['outstanding_balance']);
        }

        return response()->json(['data' => $data]);
    }

    public function update(Request $request, Hospital $hospital)
    {
        abort_if(! $request->user()->hasHospitalManageAuthority(), 403,
            'Access Denied: you do not have permission to edit hospital records.');

        $data = $request->validate([
            'name'                => ['sometimes', 'string'],
            'short_code'          => ['nullable', 'string', 'max:20'],
            'type'                => ['sometimes', 'in:public,private,mission,clinic'],
            'region'              => ['nullable', 'string'],
            'district'            => ['nullable', 'string'],
            'latitude'            => ['nullable', 'numeric'],
            'longitude'           => ['nullable', 'numeric'],
            'zone'                => ['nullable', 'in:coastal,northern,lake,central,shighland,southern'],
            'revenue_monthly'     => ['nullable', 'integer', 'min:0'],
            'credit_limit'        => ['nullable', 'integer', 'min:0'],
            'contact_name'        => ['nullable', 'string'],
            'contact_phone'       => ['nullable', 'string'],
            'contact_email'       => ['nullable', 'email'],
            'notes'               => ['nullable', 'string'],
        ]);

        $hospital->update($data);

        return response()->json(['data' => new HospitalResource($hospital)]);
    }

    public function destroy(Request $request, Hospital $hospital)
    {
        abort_if(! $request->user()->hasHospitalManageAuthority(), 403,
            'Access Denied: you do not have permission to delete hospital records.');

        $hospital->delete();

        return response()->json(null, 204);
    }
}
