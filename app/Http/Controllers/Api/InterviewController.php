<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\InterviewResource;
use App\Models\Application;
use App\Models\Interview;
use Illuminate\Http\Request;

class InterviewController extends Controller
{
    public function store(Request $request, Application $application)
    {
        abort_if(! $request->user()->hasStaffManageAuthority(), 403, 'You are not authorised to manage recruitment.');

        $data = $request->validate([
            'scheduled_at'   => ['required', 'date'],
            'stage'          => ['nullable', 'string', 'max:255'],
            'panel'          => ['nullable', 'string', 'max:255'],
            'interviewer_id' => ['nullable', 'exists:users,id'],
            'notes'          => ['nullable', 'string'],
            'rating'         => ['nullable', 'integer', 'min:1', 'max:5'],
        ]);
        $data['application_id'] = $application->id;

        $interview = Interview::create($data);

        return (new InterviewResource($interview->load('interviewer')))->response()->setStatusCode(201);
    }

    public function update(Request $request, Interview $interview)
    {
        abort_if(! $request->user()->hasStaffManageAuthority(), 403, 'You are not authorised to manage recruitment.');

        $data = $request->validate([
            'notes'  => ['nullable', 'string'],
            'rating' => ['nullable', 'integer', 'min:1', 'max:5'],
        ]);

        $interview->update($data);

        return new InterviewResource($interview->load('interviewer'));
    }
}
