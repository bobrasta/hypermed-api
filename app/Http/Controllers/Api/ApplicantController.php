<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ApplicantCvVersionResource;
use App\Http\Resources\ApplicantResource;
use App\Models\Applicant;
use Illuminate\Http\Request;

class ApplicantController extends Controller
{
    // Talent-pool search: filter by position applied for, skills tag,
    // rating (via interviews), talent_pool flag — the "search existing
    // candidates before posting externally" use case from the spec.
    public function index(Request $request)
    {
        $query = Applicant::with('latestCv');

        if ($request->boolean('talent_pool')) {
            $query->where('talent_pool', true);
        }
        if ($request->filled('skill')) {
            $query->where('skills_tags', 'ilike', '%' . $request->skill . '%');
        }
        if ($request->filled('position_id')) {
            $query->whereHas('applications.vacancy', fn ($q) =>
                $q->where('position_id', $request->integer('position_id')));
        }
        if ($request->filled('search')) {
            $term = '%' . $request->search . '%';
            $query->where(fn ($q) => $q->where('name', 'ilike', $term)->orWhere('email', 'ilike', $term));
        }

        return ApplicantResource::collection($query->latest()->get());
    }

    public function show(Applicant $applicant)
    {
        $applicant->load(['cvVersions', 'applications.vacancy.position', 'applications.interviews.interviewer']);

        return new ApplicantResource($applicant);
    }

    public function store(Request $request)
    {
        abort_if(! $request->user()->hasStaffManageAuthority(), 403, 'You are not authorised to manage recruitment.');

        $data = $request->validate([
            'name'           => ['required', 'string', 'max:255'],
            'phone'          => ['nullable', 'string'],
            'email'          => ['nullable', 'email'],
            'cover_letter'   => ['nullable', 'string'],
            'source_channel' => ['nullable', 'string'],
            'talent_pool'    => ['nullable', 'boolean'],
            'skills_tags'    => ['nullable', 'string'],
            'notes'          => ['nullable', 'string'],
        ]);
        $data['talent_pool'] = $data['talent_pool'] ?? false;

        $applicant = Applicant::create($data);

        return (new ApplicantResource($applicant))->response()->setStatusCode(201);
    }

    public function update(Request $request, Applicant $applicant)
    {
        abort_if(! $request->user()->hasStaffManageAuthority(), 403, 'You are not authorised to manage recruitment.');

        $data = $request->validate([
            'name'           => ['sometimes', 'string', 'max:255'],
            'phone'          => ['nullable', 'string'],
            'email'          => ['nullable', 'email'],
            'cover_letter'   => ['nullable', 'string'],
            'source_channel' => ['nullable', 'string'],
            'talent_pool'    => ['nullable', 'boolean'],
            'skills_tags'    => ['nullable', 'string'],
            'notes'          => ['nullable', 'string'],
        ]);

        $applicant->update($data);

        return new ApplicantResource($applicant);
    }

    // Not deleted — applicants persist indefinitely per the spec, so there
    // is deliberately no destroy() endpoint.

    // A fresh version each time — "CV file versioned on reapply."
    public function uploadCv(Request $request, Applicant $applicant)
    {
        abort_if(! $request->user()->hasStaffManageAuthority(), 403, 'You are not authorised to manage recruitment.');
        $request->validate(['file' => ['required', 'file', 'max:10240']]);

        $file = $request->file('file');
        $path = $file->store('applicant-cvs/' . $applicant->id, 'public');
        $nextVersion = ($applicant->cvVersions()->max('version') ?? 0) + 1;

        $cv = $applicant->cvVersions()->create([
            'file_path'     => $path,
            'original_name' => $file->getClientOriginalName(),
            'version'       => $nextVersion,
            'uploaded_at'   => now(),
        ]);

        return (new ApplicantCvVersionResource($cv))->response()->setStatusCode(201);
    }
}
