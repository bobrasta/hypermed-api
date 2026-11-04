<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Approvals\FastTrackService;
use Illuminate\Http\Request;

/** The Director's "Approve now": past every stage still open (see FastTrackService). */
class FastTrackController extends Controller
{
    public function store(Request $request, FastTrackService $fastTrack)
    {
        abort_unless($request->user()->isDirector(), 403, 'Only the Director can approve past the stages.');
        $data = $request->validate([
            'type' => ['required', 'string', 'in:' . implode(',', array_keys(FastTrackService::TYPES))],
            'id'   => ['required', 'integer'],
        ]);

        $record = $fastTrack->approve($data['type'], FastTrackService::find($data['type'], $data['id']), $request->user());

        return response()->json(['data' => [
            'type'   => $data['type'],
            'id'     => $record->getKey(),
            'status' => $record->status ?? $record->approval_status ?? null,
        ]]);
    }
}
