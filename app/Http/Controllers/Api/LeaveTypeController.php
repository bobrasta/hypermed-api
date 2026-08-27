<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\LeaveTypeResource;
use App\Models\LeaveType;
use Illuminate\Http\Request;

class LeaveTypeController extends Controller
{
    public function index(Request $request)
    {
        $query = LeaveType::query();
        if ($request->boolean('active_only')) {
            $query->where('active', true);
        }

        return LeaveTypeResource::collection($query->orderBy('id')->get());
    }

    public function update(Request $request, LeaveType $leaveType)
    {
        abort_if(! $request->user()->hasStaffManageAuthority(), 403, 'You are not authorised to manage leave types.');

        $data = $request->validate([
            'label'                 => ['sometimes', 'string', 'max:255'],
            'default_days_per_year' => ['sometimes', 'integer', 'min:0'],
            'requires_manual_days'  => ['sometimes', 'boolean'],
            'deducts_balance'       => ['sometimes', 'boolean'],
            'active'                => ['sometimes', 'boolean'],
        ]);

        $leaveType->update($data);

        return new LeaveTypeResource($leaveType);
    }
}
