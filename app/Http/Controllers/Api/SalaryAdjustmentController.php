<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SalaryAdjustmentResource;
use App\Models\ApprovalLog;
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

    // Records a proposed adjustment in 'pending' status only — it no
    // longer applies to the contract on creation. That "recording it is
    // the approval" convention (still true for career progression) was
    // the exact self-approval gap this policy pass exists to close: an
    // accountant should not be able to unilaterally change their own or
    // anyone else's pay. approve() below applies it, and only the
    // Director can call that.
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
            'status'          => 'pending',
            'created_by'      => $request->user()->id,
        ]);
        ApprovalLog::record($adjustment, 'initiated', $request->user());

        return (new SalaryAdjustmentResource($adjustment->load(['approvedBy', 'createdBy'])))->response()->setStatusCode(201);
    }

    public function approve(Request $request, User $user, SalaryAdjustment $salaryAdjustment)
    {
        abort_if(! $request->user()->hasDirectorAuthority(), 403, 'Only the Director can approve a salary adjustment.');
        abort_if($salaryAdjustment->user_id !== $user->id, 404);
        abort_if($salaryAdjustment->status !== 'pending', 422, 'Only a pending adjustment can be approved.');
        abort_if($salaryAdjustment->created_by === $request->user()->id, 403, 'You cannot approve a salary adjustment you recorded.');

        $salaryAdjustment->update([
            'status'      => 'approved',
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
        ]);

        $salaryAdjustment->contract?->update(['base_salary' => $salaryAdjustment->new_salary]);
        ApprovalLog::record($salaryAdjustment, 'approved', $request->user());

        return new SalaryAdjustmentResource($salaryAdjustment->load(['approvedBy', 'createdBy']));
    }
}
