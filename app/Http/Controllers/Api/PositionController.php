<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PositionResource;
use App\Models\Position;
use Illuminate\Http\Request;

class PositionController extends Controller
{
    public function index()
    {
        return PositionResource::collection(Position::orderBy('title')->get());
    }

    public function store(Request $request)
    {
        abort_if(! $request->user()->hasStaffManageAuthority(), 403, 'You are not authorised to manage positions.');

        $data = $request->validate([
            'title'       => ['required', 'string', 'max:255'],
            'department'  => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ]);

        $position = Position::create($data);

        return (new PositionResource($position))->response()->setStatusCode(201);
    }

    public function update(Request $request, Position $position)
    {
        abort_if(! $request->user()->hasStaffManageAuthority(), 403, 'You are not authorised to manage positions.');

        $data = $request->validate([
            'title'       => ['sometimes', 'string', 'max:255'],
            'department'  => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ]);

        $position->update($data);

        return new PositionResource($position);
    }

    public function destroy(Request $request, Position $position)
    {
        abort_if(! $request->user()->hasStaffManageAuthority(), 403, 'You are not authorised to manage positions.');
        abort_if($position->users()->exists(), 422, 'Cannot delete a position that is still assigned to staff.');

        $position->delete();

        return response()->noContent();
    }
}
