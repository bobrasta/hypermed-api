<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ApplicationResource;
use App\Models\Application;
use App\Models\Vacancy;
use Illuminate\Http\Request;

class ApplicationController extends Controller
{
    // Pipeline view for one vacancy — "applicants per stage" for the
    // vacancy-pipeline report.
    public function index(Request $request, Vacancy $vacancy)
    {
        return ApplicationResource::collection(
            $vacancy->applications()->with(['applicant', 'vacancy.position', 'interviews.interviewer'])->latest('applied_at')->get()
        );
    }

    public function store(Request $request, Vacancy $vacancy)
    {
        abort_if(! $request->user()->hasStaffManageAuthority(), 403, 'You are not authorised to manage recruitment.');

        $data = $request->validate([
            'applicant_id' => ['required', 'exists:applicants,id'],
            'applied_at'   => ['nullable', 'date'],
        ]);

        $application = Application::create([
            'applicant_id' => $data['applicant_id'],
            'vacancy_id'   => $vacancy->id,
            'status'       => 'applied',
            'applied_at'   => $data['applied_at'] ?? now()->toDateString(),
        ]);

        return (new ApplicationResource($application->load(['applicant', 'vacancy.position'])))
            ->response()->setStatusCode(201);
    }

    public function updateStage(Request $request, Application $application)
    {
        abort_if(! $request->user()->hasStaffManageAuthority(), 403, 'You are not authorised to manage recruitment.');

        $data = $request->validate([
            'status' => ['required', 'in:' . implode(',', Application::STAGES)],
            'notes'  => ['nullable', 'string'],
        ]);

        $application->update($data);

        return new ApplicationResource($application->load(['applicant', 'vacancy.position', 'interviews.interviewer']));
    }
}
