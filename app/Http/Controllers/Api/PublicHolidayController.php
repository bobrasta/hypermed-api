<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PublicHolidayResource;
use App\Models\PublicHoliday;
use Illuminate\Http\Request;

class PublicHolidayController extends Controller
{
    public function index(Request $request)
    {
        $query = PublicHoliday::query();
        if ($request->filled('year')) {
            $query->whereYear('date', $request->integer('year'));
        }

        return PublicHolidayResource::collection($query->orderBy('date')->get());
    }

    public function store(Request $request)
    {
        abort_if(! $request->user()->hasStaffManageAuthority(), 403, 'You are not authorised to manage the holiday calendar.');

        $data = $request->validate([
            'name'      => ['required', 'string', 'max:255'],
            'date'      => ['required', 'date'],
            'recurring' => ['nullable', 'boolean'],
        ]);

        $holiday = PublicHoliday::create($data);

        return (new PublicHolidayResource($holiday))->response()->setStatusCode(201);
    }

    public function update(Request $request, PublicHoliday $publicHoliday)
    {
        abort_if(! $request->user()->hasStaffManageAuthority(), 403, 'You are not authorised to manage the holiday calendar.');

        $data = $request->validate([
            'name'      => ['sometimes', 'string', 'max:255'],
            'date'      => ['sometimes', 'date'],
            'recurring' => ['sometimes', 'boolean'],
        ]);

        $publicHoliday->update($data);

        return new PublicHolidayResource($publicHoliday);
    }

    public function destroy(Request $request, PublicHoliday $publicHoliday)
    {
        abort_if(! $request->user()->hasStaffManageAuthority(), 403, 'You are not authorised to manage the holiday calendar.');
        $publicHoliday->delete();

        return response()->noContent();
    }
}
