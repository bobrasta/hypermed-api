<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SparePartResource;
use App\Models\SparePart;
use Illuminate\Http\Request;

class SparePartController extends Controller
{
    public function index(Request $request)
    {
        $query = SparePart::with('compatibleModels');

        if ($request->boolean('low_stock')) {
            $query->whereColumn('stock_qty', '<=', 'reorder_level');
        }
        if ($request->filled('supplier')) {
            $query->where('supplier', $request->supplier);
        }

        return SparePartResource::collection($query->paginate(20));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'part_number'       => ['required', 'string', 'unique:spare_parts'],
            'name'              => ['required', 'string'],
            'description'       => ['nullable', 'string'],
            'unit_cost'         => ['required', 'integer', 'min:0'],
            'currency'          => ['nullable', 'string', 'max:10'],
            'stock_qty'         => ['required', 'integer', 'min:0'],
            'reorder_level'     => ['required', 'integer', 'min:0'],
            'supplier'          => ['nullable', 'string'],
            'compatible_models' => ['nullable', 'array'],
            'compatible_models.*' => ['string'],
        ]);

        $models = $data['compatible_models'] ?? [];
        unset($data['compatible_models']);

        $part = SparePart::create($data);

        foreach ($models as $model) {
            $part->compatibleModels()->create(['machine_model' => $model]);
        }

        return response()->json(['data' => new SparePartResource($part->load('compatibleModels'))], 201);
    }

    public function show(SparePart $sparePart)
    {
        $sparePart->load('compatibleModels');

        return response()->json(['data' => new SparePartResource($sparePart)]);
    }

    public function update(Request $request, SparePart $sparePart)
    {
        $data = $request->validate([
            'part_number'   => ['sometimes', 'string', 'unique:spare_parts,part_number,' . $sparePart->id],
            'name'          => ['sometimes', 'string'],
            'description'   => ['nullable', 'string'],
            'unit_cost'     => ['sometimes', 'integer', 'min:0'],
            'stock_qty'     => ['sometimes', 'integer', 'min:0'],
            'reorder_level' => ['sometimes', 'integer', 'min:0'],
            'supplier'      => ['nullable', 'string'],
        ]);

        $sparePart->update($data);

        return response()->json(['data' => new SparePartResource($sparePart->load('compatibleModels'))]);
    }
}
