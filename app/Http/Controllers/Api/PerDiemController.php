<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PerDiemRequestResource;
use App\Models\AppNotification;
use App\Models\PerDiemRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PerDiemController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $query = PerDiemRequest::with(['user', 'teamLeadReviewer', 'reviewer', 'paidBy', 'lines']);

        // Accountant needs visibility into everyone's approved-awaiting-payment
        // requests to act on markPaid() — same self-scoping exemption as
        // team-lead/cto authority, otherwise they'd only ever see their own.
        if (! $user->hasTeamLeadAuthority() && ! $user->hasAccountantAuthority()) {
            $query->where('user_id', $user->id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        return PerDiemRequestResource::collection($query->latest()->get());
    }

    /**
     * Creates a per-diem request — either a simple single-destination request
     * (destination/start_date/end_date/amount) or a full day-by-day travel
     * plan (a `lines` itinerary: one row per day with region/district/site/
     * activity and its own labor/per-diem/transport cost). When `lines` is
     * given, the summary fields (amount, start/end date, days_count,
     * destination) are derived server-side from the itinerary — never
     * trusted from the client — so the two can't drift apart.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'service_ticket_id' => ['nullable', 'exists:service_tickets,id'],
            'destination'       => ['nullable', 'string'],
            'start_date'        => ['nullable', 'date'],
            'end_date'          => ['nullable', 'date', 'after_or_equal:start_date'],
            'daily_rate'        => ['nullable', 'integer', 'min:0'],
            'amount'            => ['nullable', 'integer', 'min:0'],
            'purpose'           => ['nullable', 'string'],
            'lines'                   => ['nullable', 'array', 'min:1'],
            'lines.*.date'            => ['required_with:lines', 'date'],
            'lines.*.region'          => ['nullable', 'string'],
            'lines.*.district'        => ['nullable', 'string'],
            'lines.*.site_name'       => ['nullable', 'string'],
            'lines.*.activity'        => ['nullable', 'string'],
            'lines.*.labor_cost'      => ['nullable', 'integer', 'min:0'],
            'lines.*.per_diem_cost'   => ['nullable', 'integer', 'min:0'],
            'lines.*.transport_fare'  => ['nullable', 'integer', 'min:0'],
        ]);

        $lines = $data['lines'] ?? [];
        unset($data['lines']);

        if (count($lines) > 0) {
            $dates = collect($lines)->map(fn ($l) => \Carbon\Carbon::parse($l['date']));
            $data['start_date'] = $dates->min()->toDateString();
            $data['end_date']   = $dates->max()->toDateString();
            $data['days_count'] = $dates->map(fn ($d) => $d->toDateString())->unique()->count();
            $data['amount']     = collect($lines)->sum(fn ($l) =>
                ($l['labor_cost'] ?? 0) + ($l['per_diem_cost'] ?? 0) + ($l['transport_fare'] ?? 0));

            if (empty($data['destination'])) {
                $sites = collect($lines)->pluck('site_name')->filter()->unique();
                $data['destination'] = $sites->isNotEmpty()
                    ? $sites->implode(', ')
                    : collect($lines)->pluck('district')->filter()->unique()->implode(', ');
            }
        } else {
            $request->validate([
                'destination' => ['required', 'string'],
                'start_date'  => ['required', 'date'],
                'end_date'    => ['required', 'date', 'after_or_equal:start_date'],
                'amount'      => ['required', 'integer', 'min:0'],
            ]);
            $data['days_count'] = \Carbon\Carbon::parse($data['start_date'])
                ->diffInDays(\Carbon\Carbon::parse($data['end_date'])) + 1;
        }

        $data['user_id'] = $request->user()->id;
        $data['status']  = 'pending_team_lead';

        $perDiem = DB::transaction(function () use ($data, $lines) {
            $perDiem = PerDiemRequest::create($data);
            foreach ($lines as $i => $line) {
                $perDiem->lines()->create([
                    'seq_no'         => $i + 1,
                    'date'           => $line['date'],
                    'region'         => $line['region'] ?? null,
                    'district'       => $line['district'] ?? null,
                    'site_name'      => $line['site_name'] ?? null,
                    'activity'       => $line['activity'] ?? null,
                    'labor_cost'     => $line['labor_cost'] ?? 0,
                    'per_diem_cost'  => $line['per_diem_cost'] ?? 0,
                    'transport_fare' => $line['transport_fare'] ?? 0,
                ]);
            }
            return $perDiem;
        });

        $this->notifyTeamLead($perDiem);

        return response()->json(['data' => new PerDiemRequestResource($perDiem->load(['user', 'lines']))], 201);
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
            $perDiemRequest->load(['user', 'teamLeadReviewer', 'lines'])
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
            $perDiemRequest->load(['user', 'teamLeadReviewer', 'lines'])
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
            $perDiemRequest->load(['user', 'teamLeadReviewer', 'reviewer', 'lines'])
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
            $perDiemRequest->load(['user', 'teamLeadReviewer', 'reviewer', 'lines'])
        )]);
    }

    // Closes the loop left dangling by approve()'s "ready for payment"
    // notification — status stays 'approved' (no new status value), this
    // just records who actually paid it and when.
    public function markPaid(Request $request, PerDiemRequest $perDiemRequest)
    {
        abort_if(! $request->user()->hasAccountantAuthority(), 403, 'You are not authorised to mark per-diem requests as paid.');
        abort_if($perDiemRequest->status !== 'approved', 422, 'Only approved requests can be marked paid.');
        abort_if($perDiemRequest->paid_at !== null, 422, 'This request has already been marked paid.');

        $perDiemRequest->update([
            'paid_by' => $request->user()->id,
            'paid_at' => now(),
        ]);

        AppNotification::create([
            'user_id'     => $perDiemRequest->user_id,
            'type'        => 'per_diem_paid',
            'title'       => 'Per-Diem Paid',
            'body'        => "Your per-diem request for {$perDiemRequest->destination} has been paid.",
            'entity_type' => 'per_diem_request',
            'entity_id'   => $perDiemRequest->id,
            'is_read'     => false,
        ]);

        return response()->json(['data' => new PerDiemRequestResource(
            $perDiemRequest->load(['user', 'teamLeadReviewer', 'reviewer', 'paidBy', 'lines'])
        )]);
    }

    public function cancel(Request $request, PerDiemRequest $perDiemRequest)
    {
        $user = $request->user();
        abort_if($perDiemRequest->user_id !== $user->id && ! $user->hasTeamLeadAuthority(), 403, 'Not authorised.');
        abort_if(! in_array($perDiemRequest->status, ['pending_team_lead', 'pending_cto'], true), 422, 'Only pending requests can be cancelled.');

        $perDiemRequest->update(['status' => 'cancelled']);

        return response()->json(['data' => new PerDiemRequestResource($perDiemRequest->load(['user', 'lines']))]);
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

        User::whereIn('role', ['finance_manager', 'finance', 'accountant'])
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
