<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SalaryAdjustmentResource;
use App\Models\SalaryAdjustment;
use App\Models\User;
use Illuminate\Http\Request;

class SalaryAdjustmentController extends Controller
{
    public function index(Request $request, User $user)
    {
        abort_if(! $request->user()->hasAccountantAuthority(), 403, 'You are not authorised to view salary history.');

        return SalaryAdjustmentResource::collection(
            $user->salaryAdjustments()->with(['approvedBy', 'createdBy'])->latest('effective_date')->get()
        );
    }

    // Applies immediately to the staff member's active contract — same
    // "recording it is the approval" convention already used for career
    // progression (position_changes updates users.position_id synchronously
    // on create, no separate approval step).
    public function store(Request $request, User $user)
    {
        abort_if(! $request->user()->hasAccountantAuthority(), 403, 'You are not authorised to record salary adjustments.');

        $data = $request->validate([
            'new_salary'     => ['required', 'integer', 'min:0'],
            'reason'         => ['nullable', 'string'],
            'effective_date' => ['required', 'date'],
        ]);

        $activeContract = $user->activeContract;

        $adjustment = SalaryAdjustment::create([
            'user_id'         => $user->id,
            'contract_id'     => $activeContract?->id,
            'previous_salary' => $activeContract?->base_salary,
            'new_salary'      => $data['new_salary'],
            'reason'          => $data['reason'] ?? null,
            'effective_date'  => $data['effective_date'],
            'approved_by'     => $request->user()->id,
            'created_by'      => $request->user()->id,
        ]);

        $activeContract?->update(['base_salary' => $data['new_salary']]);

        return (new SalaryAdjustmentResource($adjustment->load(['approvedBy', 'createdBy'])))->response()->setStatusCode(201);
    }
}
