<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\VacancyResource;
use App\Models\Vacancy;
use Illuminate\Http\Request;

class VacancyController extends Controller
{
    public function index(Request $request)
    {
        $query = Vacancy::with('position')->withCount('applications');
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        return VacancyResource::collection($query->latest('opened_at')->get());
    }

    public function store(Request $request)
    {
        abort_if(! $request->user()->hasStaffManageAuthority(), 403, 'You are not authorised to manage recruitment.');

        $data = $request->validate([
            'position_id'  => ['required', 'exists:positions,id'],
            'requirements' => ['nullable', 'string'],
            'opened_at'    => ['nullable', 'date'],
        ]);

        $vacancy = Vacancy::create([
            'position_id'  => $data['position_id'],
            'requirements' => $data['requirements'] ?? null,
            'status'       => 'open',
            'opened_at'    => $data['opened_at'] ?? now()->toDateString(),
            'created_by'   => $request->user()->id,
        ]);

        return (new VacancyResource($vacancy->load('position')))->response()->setStatusCode(201);
    }

    public function update(Request $request, Vacancy $vacancy)
    {
        abort_if(! $request->user()->hasStaffManageAuthority(), 403, 'You are not authorised to manage recruitment.');

        $data = $request->validate([
            'requirements' => ['nullable', 'string'],
            'status'       => ['sometimes', 'in:open,on_hold,closed'],
        ]);

        if (($data['status'] ?? null) === 'closed' && $vacancy->status !== 'closed') {
            $data['closed_at'] = now()->toDateString();
        }

        $vacancy->update($data);

        return new VacancyResource($vacancy->load('position'));
    }
}
