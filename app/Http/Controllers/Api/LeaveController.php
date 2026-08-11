<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\LeaveRequestResource;
use App\Models\AppNotification;
use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Http\Request;

class LeaveController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $query = LeaveRequest::with(['user', 'reviewer']);

        if ($user->hasHrAuthority()) {
            if ($request->filled('user_id')) {
                $query->where('user_id', $request->user_id);
            }
        } else {
            $query->where('user_id', $user->id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        return LeaveRequestResource::collection($query->latest()->get());
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'type'       => ['required', 'in:sick,vacation,other'],
            'start_date' => ['required', 'date'],
            'end_date'   => ['required', 'date', 'after_or_equal:start_date'],
            'reason'     => ['nullable', 'string'],
        ]);

        $data['user_id']    = $request->user()->id;
        $data['days_count'] = \Carbon\Carbon::parse($data['start_date'])
            ->diffInDays(\Carbon\Carbon::parse($data['end_date'])) + 1;
        $data['status'] = 'pending';

        $leave = LeaveRequest::create($data);

        $this->notifyHr($leave);

        return response()->json(['data' => new LeaveRequestResource($leave->load('user'))], 201);
    }

    public function approve(Request $request, LeaveRequest $leaveRequest)
    {
        abort_if(! $request->user()->hasHrAuthority(), 403, 'You are not authorised to review leave requests.');
        abort_if($leaveRequest->status !== 'pending', 422, 'Only pending requests can be approved.');

        $leaveRequest->update([
            'status'      => 'approved',
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        $this->notifyRequester($leaveRequest, approved: true);

        return response()->json(['data' => new LeaveRequestResource($leaveRequest->load(['user', 'reviewer']))]);
    }

    public function reject(Request $request, LeaveRequest $leaveRequest)
    {
        abort_if(! $request->user()->hasHrAuthority(), 403, 'You are not authorised to review leave requests.');
        abort_if($leaveRequest->status !== 'pending', 422, 'Only pending requests can be rejected.');

        $data = $request->validate(['rejection_reason' => ['nullable', 'string']]);

        $leaveRequest->update([
            'status'            => 'rejected',
            'reviewed_by'       => $request->user()->id,
            'reviewed_at'       => now(),
            'rejection_reason'  => $data['rejection_reason'] ?? null,
        ]);

        $this->notifyRequester($leaveRequest, approved: false);

        return response()->json(['data' => new LeaveRequestResource($leaveRequest->load(['user', 'reviewer']))]);
    }

    public function cancel(Request $request, LeaveRequest $leaveRequest)
    {
        $user = $request->user();
        abort_if($leaveRequest->user_id !== $user->id && ! $user->hasHrAuthority(), 403, 'Not authorised.');
        abort_if(! in_array($leaveRequest->status, ['pending', 'approved']), 422, 'Only pending or approved requests can be cancelled.');

        $leaveRequest->update(['status' => 'cancelled']);

        return response()->json(['data' => new LeaveRequestResource($leaveRequest->load(['user', 'reviewer']))]);
    }

    private function notifyHr(LeaveRequest $leave): void
    {
        $name = $leave->user?->name ?? 'A staff member';

        User::whereIn('role', User::HR_APPROVAL_ROLES)
            ->pluck('id')
            ->each(fn ($id) => AppNotification::create([
                'user_id'     => $id,
                'type'        => 'leave_requested',
                'title'       => 'Leave Request Submitted',
                'body'        => "{$name} requested {$leave->type} leave, {$leave->start_date->toDateString()} to {$leave->end_date->toDateString()} ({$leave->days_count} day(s)).",
                'entity_type' => 'leave_request',
                'entity_id'   => $leave->id,
                'is_read'     => false,
            ]));
    }

    private function notifyRequester(LeaveRequest $leave, bool $approved): void
    {
        AppNotification::create([
            'user_id'     => $leave->user_id,
            'type'        => $approved ? 'leave_approved' : 'leave_rejected',
            'title'       => $approved ? 'Leave Approved' : 'Leave Rejected',
            'body'        => $approved
                ? "Your {$leave->type} leave ({$leave->start_date->toDateString()} to {$leave->end_date->toDateString()}) was approved."
                : "Your {$leave->type} leave request was rejected." . ($leave->rejection_reason ? " Reason: {$leave->rejection_reason}" : ''),
            'entity_type' => 'leave_request',
            'entity_id'   => $leave->id,
            'is_read'     => false,
        ]);
    }
}
