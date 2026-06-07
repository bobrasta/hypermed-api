<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\LocationResource;
use App\Models\Location;
use Illuminate\Http\Request;

class LocationController extends Controller
{
    public function index(Request $request)
    {
        $query = Location::query();

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }
        if (!$request->boolean('include_inactive')) {
            $query->where('is_active', true);
        }
        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'ilike', '%' . $request->search . '%')
                  ->orWhere('code', 'ilike', '%' . $request->search . '%');
            });
        }

        return LocationResource::collection($query->orderBy('type')->orderBy('name')->get());
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'    => ['required', 'string', 'max:255'],
            'code'    => ['nullable', 'string', 'max:50', 'unique:locations'],
            'type'    => ['nullable', 'in:warehouse,store,room,vehicle,other'],
            'address' => ['nullable', 'string'],
            'notes'   => ['nullable', 'string'],
        ]);

        $location = Location::create($data);
        return new LocationResource($location);
    }

    public function show(Location $location)
    {
        return new LocationResource($location);
    }

    public function update(Request $request, Location $location)
    {
        $data = $request->validate([
            'name'      => ['sometimes', 'string', 'max:255'],
            'code'      => ['nullable', 'string', 'max:50', 'unique:locations,code,' . $location->id],
            'type'      => ['nullable', 'in:warehouse,store,room,vehicle,other'],
            'address'   => ['nullable', 'string'],
            'notes'     => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $location->update($data);
        return new LocationResource($location);
    }

    public function destroy(Location $location)
    {
        $location->update(['is_active' => false]);
        return response()->noContent();
    }
}
