<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PositionChangeResource;
use App\Models\PositionChange;
use App\Models\User;
use Illuminate\Http\Request;

class PositionChangeController extends Controller
{
    public function index(User $user)
    {
        return PositionChangeResource::collection(
            $user->positionChanges()->with(['fromPosition', 'toPosition', 'approvedBy'])->latest('effective_date')->get()
        );
    }

    // Creating this record IS the approval — applies to users.position_id
    // immediately, synchronous, no scheduled activation (matches how every
    // other approval chain in this codebase applies its effect on approval).
    public function store(Request $request, User $user)
    {
        abort_if(! $request->user()->hasStaffManageAuthority(), 403, 'You are not authorised to manage career progression.');

        $data = $request->validate([
            'to_position_id' => ['required', 'exists:positions,id'],
            'change_type'    => ['required', 'in:promotion,demotion,lateral'],
            'effective_date' => ['required', 'date'],
            'reason'         => ['nullable', 'string'],
        ]);

        $change = PositionChange::create([
            'user_id'          => $user->id,
            'from_position_id' => $user->position_id,
            'to_position_id'   => $data['to_position_id'],
            'change_type'      => $data['change_type'],
            'effective_date'   => $data['effective_date'],
            'reason'           => $data['reason'] ?? null,
            'approved_by'      => $request->user()->id,
        ]);

        $user->update(['position_id' => $data['to_position_id']]);

        return (new PositionChangeResource($change->load(['fromPosition', 'toPosition', 'approvedBy'])))
            ->response()->setStatusCode(201);
    }
}
