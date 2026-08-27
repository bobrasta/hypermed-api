<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\LeaveRequestResource;
use App\Models\AppNotification;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use Illuminate\Http\Request;

class LeaveController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $query = LeaveRequest::with(['user', 'reviewer', 'leaveType']);

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
        if ($request->filled('leave_type_id')) {
            $query->where('leave_type_id', $request->leave_type_id);
        } elseif ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        return LeaveRequestResource::collection($query->latest()->get());
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'leave_type_id' => ['required', 'exists:leave_types,id'],
            'start_date'    => ['required', 'date'],
            'end_date'      => ['required', 'date', 'after_or_equal:start_date'],
            'reason'        => ['nullable', 'string'],
        ]);

        $leaveType = LeaveType::findOrFail($data['leave_type_id']);
        abort_if(! $leaveType->active, 422, 'This leave type is not currently available.');
        abort_if($leaveType->auto_from_calendar, 422,
            'Public Holiday leave is populated automatically from the holiday calendar and cannot be requested directly.');

        $data['type']       = $leaveType->key;
        $data['user_id']    = $request->user()->id;
        $data['days_count'] = \Carbon\Carbon::parse($data['start_date'])
            ->diffInDays(\Carbon\Carbon::parse($data['end_date'])) + 1;
        $data['status'] = 'pending';

        $leave = LeaveRequest::create($data);

        $this->notifyHr($leave);

        return response()->json(['data' => new LeaveRequestResource($leave->load(['user', 'leaveType']))], 201);
    }

    public function approve(Request $request, LeaveRequest $leaveRequest)
    {
        abort_if(! $request->user()->hasHrAuthority(), 403, 'You are not authorised to review leave requests.');
        abort_if($leaveRequest->status !== 'pending', 422, 'Only pending requests can be approved.');

        $leaveType = $leaveRequest->leaveType;

        $data = $request->validate([
            // Compassionate: the approver sets the final day count here
            // rather than trusting the requester's own date-range math.
            'days_count' => [$leaveType?->requires_manual_days ? 'required' : 'nullable', 'integer', 'min:1'],
        ]);

        $finalDays = $leaveType?->requires_manual_days && isset($data['days_count'])
            ? $data['days_count']
            : $leaveRequest->days_count;

        $leaveRequest->update([
            'status'      => 'approved',
            'days_count'  => $finalDays,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        if ($leaveType && $leaveType->deducts_balance) {
            $this->applyBalanceDeduction($leaveRequest, $leaveType, $finalDays);
        }

        $this->notifyRequester($leaveRequest, approved: true);

        return response()->json(['data' => new LeaveRequestResource($leaveRequest->load(['user', 'reviewer', 'leaveType']))]);
    }

    // Lazily creates the staff member's balance row for this type/year (no
    // carry-over by design — each calendar year starts fresh), then debits
    // it. allocated_days defaults from the type's catalog value; HR can
    // still hand-adjust an individual's allocated_days directly on the
    // leave_balances row later without this logic overwriting it, since
    // firstOrCreate only sets allocated_days on first creation.
    private function applyBalanceDeduction(LeaveRequest $leaveRequest, LeaveType $leaveType, int $days): void
    {
        $year = $leaveRequest->start_date->year;

        $balance = LeaveBalance::firstOrCreate(
            ['user_id' => $leaveRequest->user_id, 'leave_type_id' => $leaveType->id, 'year' => $year],
            ['allocated_days' => $leaveType->default_days_per_year, 'used_days' => 0],
        );

        $balance->increment('used_days', $days);
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

        return response()->json(['data' => new LeaveRequestResource($leaveRequest->load(['user', 'reviewer', 'leaveType']))]);
    }

    public function cancel(Request $request, LeaveRequest $leaveRequest)
    {
        $user = $request->user();
        abort_if($leaveRequest->user_id !== $user->id && ! $user->hasHrAuthority(), 403, 'Not authorised.');
        abort_if($leaveRequest->status !== 'pending', 422, 'Only pending requests can be cancelled.');

        $leaveRequest->update(['status' => 'cancelled']);

        return response()->json(['data' => new LeaveRequestResource($leaveRequest->load(['user', 'reviewer', 'leaveType']))]);
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
