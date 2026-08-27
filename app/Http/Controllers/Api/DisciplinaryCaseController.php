<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DisciplinaryCaseNoteResource;
use App\Http\Resources\DisciplinaryCaseResource;
use App\Models\DisciplinaryCase;
use App\Models\User;
use Illuminate\Http\Request;

class DisciplinaryCaseController extends Controller
{
    public function index(Request $request, User $user)
    {
        return DisciplinaryCaseResource::collection(
            $user->disciplinaryCases()->with(['raisedBy', 'handledBy', 'notes.createdBy'])->latest('incident_date')->get()
        );
    }

    public function store(Request $request, User $user)
    {
        abort_if(! $request->user()->hasStaffManageAuthority(), 403, 'You are not authorised to manage disciplinary cases.');

        $data = $request->validate([
            'incident_date' => ['required', 'date'],
            'description'   => ['required', 'string'],
            'handled_by'    => ['nullable', 'exists:users,id'],
        ]);

        $case = DisciplinaryCase::create([
            'user_id'       => $user->id,
            'stage'         => 'verbal_warning',
            'incident_date' => $data['incident_date'],
            'description'   => $data['description'],
            'handled_by'    => $data['handled_by'] ?? null,
            'raised_by'     => $request->user()->id,
            'status'        => 'open',
        ]);

        return (new DisciplinaryCaseResource($case->load(['raisedBy', 'handledBy', 'notes'])))->response()->setStatusCode(201);
    }

    public function addNote(Request $request, DisciplinaryCase $disciplinaryCase)
    {
        abort_if(! $request->user()->hasStaffManageAuthority(), 403, 'You are not authorised to manage disciplinary cases.');

        $data = $request->validate(['note' => ['required', 'string']]);

        $note = $disciplinaryCase->notes()->create([
            'note'       => $data['note'],
            'created_by' => $request->user()->id,
        ]);

        return (new DisciplinaryCaseNoteResource($note->load('createdBy')))->response()->setStatusCode(201);
    }

    // Advances to the next stage in DisciplinaryCase::STAGES — a single
    // continuous matter escalating in place, not a new row per stage.
    public function advanceStage(Request $request, DisciplinaryCase $disciplinaryCase)
    {
        abort_if(! $request->user()->hasStaffManageAuthority(), 403, 'You are not authorised to manage disciplinary cases.');
        abort_if($disciplinaryCase->status !== 'open', 422, 'This case is already closed.');

        $next = $disciplinaryCase->nextStage();
        abort_if($next === null, 422, 'This case is already at its final stage.');

        $data = $request->validate(['action_taken' => ['nullable', 'string']]);

        $disciplinaryCase->update([
            'stage'        => $next,
            'action_taken' => $next === 'action_taken' ? ($data['action_taken'] ?? $disciplinaryCase->action_taken) : $disciplinaryCase->action_taken,
        ]);

        return new DisciplinaryCaseResource($disciplinaryCase->load(['raisedBy', 'handledBy', 'notes.createdBy']));
    }

    public function close(Request $request, DisciplinaryCase $disciplinaryCase)
    {
        abort_if(! $request->user()->hasStaffManageAuthority(), 403, 'You are not authorised to manage disciplinary cases.');

        $disciplinaryCase->update(['status' => 'closed']);

        return new DisciplinaryCaseResource($disciplinaryCase->load(['raisedBy', 'handledBy', 'notes.createdBy']));
    }
}
