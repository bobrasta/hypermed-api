<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PerDiemRequestResource;
use App\Models\AppNotification;
use App\Models\PerDiemRequest;
use App\Models\User;
use Illuminate\Http\Request;

class PerDiemController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $query = PerDiemRequest::with(['user', 'teamLeadReviewer', 'reviewer']);

        if (! $user->hasTeamLeadAuthority()) {
            $query->where('user_id', $user->id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        return PerDiemRequestResource::collection($query->latest()->get());
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'service_ticket_id' => ['nullable', 'exists:service_tickets,id'],
            'destination'       => ['required', 'string'],
            'start_date'        => ['required', 'date'],
            'end_date'          => ['required', 'date', 'after_or_equal:start_date'],
            'daily_rate'        => ['nullable', 'integer', 'min:0'],
            'amount'            => ['required', 'integer', 'min:0'],
            'purpose'           => ['nullable', 'string'],
        ]);

        $data['user_id']    = $request->user()->id;
        $data['days_count'] = \Carbon\Carbon::parse($data['start_date'])
            ->diffInDays(\Carbon\Carbon::parse($data['end_date'])) + 1;
        $data['status'] = 'pending_team_lead';

        $perDiem = PerDiemRequest::create($data);

        $this->notifyTeamLead($perDiem);

        return response()->json(['data' => new PerDiemRequestResource($perDiem->load('user'))], 201);
    }

    public function approveTeamLead(Request $request, PerDiemRequest $perDiemRequest)
    {
        abort_if(! $request->user()->hasTeamLeadAuthority(), 403, 'You are not authorised to review per-diem requests.');
        abort_if($perDiemRequest->status !== 'pending_team_lead', 422, 'Only requests awaiting team-lead review can be forwarded.');

        $perDiemRequest->update([
            'status'                 => 'pending_cto',
            'team_lead_reviewed_by'  => $request->user()->id,
            'team_lead_reviewed_at'  => now(),
        ]);

        $this->notifyCto($perDiemRequest);

        return response()->json(['data' => new PerDiemRequestResource(
            $perDiemRequest->load(['user', 'teamLeadReviewer'])
        )]);
    }

    public function rejectTeamLead(Request $request, PerDiemRequest $perDiemRequest)
    {
        abort_if(! $request->user()->hasTeamLeadAuthority(), 403, 'You are not authorised to review per-diem requests.');
        abort_if($perDiemRequest->status !== 'pending_team_lead', 422, 'Only requests awaiting team-lead review can be rejected.');

        $data = $request->validate(['rejection_reason' => ['nullable', 'string']]);

        $perDiemRequest->update([
            'status'                      => 'rejected',
            'team_lead_reviewed_by'       => $request->user()->id,
            'team_lead_reviewed_at'       => now(),
            'team_lead_rejection_reason'  => $data['rejection_reason'] ?? null,
        ]);

        $this->notifyRequester($perDiemRequest, approved: false, rejectedAtTeamLead: true);

        return response()->json(['data' => new PerDiemRequestResource(
            $perDiemRequest->load(['user', 'teamLeadReviewer'])
        )]);
    }

    public function approve(Request $request, PerDiemRequest $perDiemRequest)
    {
        abort_if(! $request->user()->hasCtoApprovalAuthority(), 403, 'You are not authorised to approve per-diem requests.');
        abort_if($perDiemRequest->status !== 'pending_cto', 422, 'Only requests awaiting CTO/Director review can be approved.');

        $perDiemRequest->update([
            'status'      => 'approved',
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        $this->notifyRequester($perDiemRequest, approved: true, rejectedAtTeamLead: false);
        $this->notifyFinance($perDiemRequest);

        return response()->json(['data' => new PerDiemRequestResource(
            $perDiemRequest->load(['user', 'teamLeadReviewer', 'reviewer'])
        )]);
    }

    public function reject(Request $request, PerDiemRequest $perDiemRequest)
    {
        abort_if(! $request->user()->hasCtoApprovalAuthority(), 403, 'You are not authorised to review per-diem requests.');
        abort_if($perDiemRequest->status !== 'pending_cto', 422, 'Only requests awaiting CTO/Director review can be rejected.');

        $data = $request->validate(['rejection_reason' => ['nullable', 'string']]);

        $perDiemRequest->update([
            'status'            => 'rejected',
            'reviewed_by'       => $request->user()->id,
            'reviewed_at'       => now(),
            'rejection_reason'  => $data['rejection_reason'] ?? null,
        ]);

        $this->notifyRequester($perDiemRequest, approved: false, rejectedAtTeamLead: false);

        return response()->json(['data' => new PerDiemRequestResource(
            $perDiemRequest->load(['user', 'teamLeadReviewer', 'reviewer'])
        )]);
    }

    public function cancel(Request $request, PerDiemRequest $perDiemRequest)
    {
        $user = $request->user();
        abort_if($perDiemRequest->user_id !== $user->id && ! $user->hasTeamLeadAuthority(), 403, 'Not authorised.');
        abort_if(! in_array($perDiemRequest->status, ['pending_team_lead', 'pending_cto'], true), 422, 'Only pending requests can be cancelled.');

        $perDiemRequest->update(['status' => 'cancelled']);

        return response()->json(['data' => new PerDiemRequestResource($perDiemRequest->load('user'))]);
    }

    private function notifyTeamLead(PerDiemRequest $perDiem): void
    {
        $name = $perDiem->user?->name ?? 'A staff member';

        $teamLeadIds = User::where('role', 'team_leader')->pluck('id');
        $recipientIds = $teamLeadIds->isNotEmpty() ? $teamLeadIds : User::whereIn('role', User::CTO_TIER)->pluck('id');

        $recipientIds->each(fn ($id) => AppNotification::create([
            'user_id'     => $id,
            'type'        => 'per_diem_requested',
            'title'       => 'Per-Diem Request Submitted',
            'body'        => "{$name} requested per-diem for {$perDiem->destination}, {$perDiem->start_date->toDateString()} to {$perDiem->end_date->toDateString()} ({$perDiem->days_count} day(s)).",
            'entity_type' => 'per_diem_request',
            'entity_id'   => $perDiem->id,
            'is_read'     => false,
        ]));
    }

    private function notifyCto(PerDiemRequest $perDiem): void
    {
        $name = $perDiem->user?->name ?? 'A staff member';

        User::whereIn('role', User::CTO_TIER)
            ->pluck('id')
            ->each(fn ($id) => AppNotification::create([
                'user_id'     => $id,
                'type'        => 'per_diem_forwarded',
                'title'       => 'Per-Diem Request Forwarded',
                'body'        => "Team lead forwarded {$name}'s per-diem request for {$perDiem->destination}.",
                'entity_type' => 'per_diem_request',
                'entity_id'   => $perDiem->id,
                'is_read'     => false,
            ]));
    }

    private function notifyFinance(PerDiemRequest $perDiem): void
    {
        $name = $perDiem->user?->name ?? 'A staff member';

        User::whereIn('role', ['finance_manager', 'finance'])
            ->pluck('id')
            ->each(fn ($id) => AppNotification::create([
                'user_id'     => $id,
                'type'        => 'per_diem_approved',
                'title'       => 'Per-Diem Ready to Pay',
                'body'        => "{$name}'s per-diem request for {$perDiem->destination} was approved — ready for payment.",
                'entity_type' => 'per_diem_request',
                'entity_id'   => $perDiem->id,
                'is_read'     => false,
            ]));
    }

    private function notifyRequester(PerDiemRequest $perDiem, bool $approved, bool $rejectedAtTeamLead): void
    {
        AppNotification::create([
            'user_id'     => $perDiem->user_id,
            'type'        => $approved ? 'per_diem_approved' : 'per_diem_rejected',
            'title'       => $approved ? 'Per-Diem Approved' : 'Per-Diem Rejected',
            'body'        => $approved
                ? "Your per-diem request for {$perDiem->destination} was approved."
                : "Your per-diem request for {$perDiem->destination} was rejected."
                    . ($rejectedAtTeamLead
                        ? ($perDiem->team_lead_rejection_reason ? " Reason: {$perDiem->team_lead_rejection_reason}" : '')
                        : ($perDiem->rejection_reason ? " Reason: {$perDiem->rejection_reason}" : '')),
            'entity_type' => 'per_diem_request',
            'entity_id'   => $perDiem->id,
            'is_read'     => false,
        ]);
    }
}
