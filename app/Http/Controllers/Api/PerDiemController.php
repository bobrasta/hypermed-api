<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PerDiemEditGrantResource;
use App\Http\Resources\PerDiemRequestResource;
use App\Http\Resources\PerDiemRevisionResource;
use App\Models\AppNotification;
use App\Models\ApprovalLog;
use App\Models\PerDiemAdjustment;
use App\Models\PerDiemRequest;
use App\Models\PerDiemRevision;
use App\Models\User;
use App\Services\NotificationTemplateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PerDiemController extends Controller
{
    // No self-approval at any stage, for anyone — a team_leader/CTO/
    // accountant/Director who happens to also be the requester on THIS
    // request must not be able to action it themselves, even though their
    // role would otherwise have the authority. Checked in addition to (not
    // instead of) each action's own role-authority check.
    private function abortIfSelfActioning(PerDiemRequest $perDiemRequest, Request $request): void
    {
        abort_if($perDiemRequest->user_id === $request->user()->id, 403,
            'You cannot action your own per-diem request.');
    }

    public function index(Request $request)
    {
        $user = $request->user();

        $query = PerDiemRequest::with(['user', 'teamLeadReviewer', 'reviewer', 'paymentInitiatedBy', 'paidBy', 'lines']);

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
        $this->abortIfSelfActioning($perDiemRequest, $request);
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
        $this->abortIfSelfActioning($perDiemRequest, $request);
        abort_if($perDiemRequest->status !== 'pending_team_lead', 422, 'Only requests awaiting team-lead review can be rejected.');

        $data = $request->validate(['rejection_reason' => ['required', 'string', 'min:10']]);

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
        $this->abortIfSelfActioning($perDiemRequest, $request);
        abort_if($perDiemRequest->status !== 'pending_cto', 422, 'Only requests awaiting CTO/Director review can be approved.');

        $perDiemRequest->update([
            'status'      => 'pending_payment',
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);
        ApprovalLog::record($perDiemRequest, 'approved', $request->user());

        $this->notifyRequester($perDiemRequest, approved: true, rejectedAtTeamLead: false);
        $this->notifyFinance($perDiemRequest);

        return response()->json(['data' => new PerDiemRequestResource(
            $perDiemRequest->load(['user', 'teamLeadReviewer', 'reviewer', 'lines'])
        )]);
    }

    public function reject(Request $request, PerDiemRequest $perDiemRequest)
    {
        abort_if(! $request->user()->hasCtoApprovalAuthority(), 403, 'You are not authorised to review per-diem requests.');
        $this->abortIfSelfActioning($perDiemRequest, $request);
        abort_if($perDiemRequest->status !== 'pending_cto', 422, 'Only requests awaiting CTO/Director review can be rejected.');

        $data = $request->validate(['rejection_reason' => ['required', 'string', 'min:10']]);

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

    // Finance prepares the payment (method + reference) but doesn't move
    // money on their own authority — mirrors PurchaseOrderController's
    // initiatePayment()/approveDirectorFinal() split. The accountant who
    // initiates cannot also be the director who authorizes it below.
    public function initiatePayment(Request $request, PerDiemRequest $perDiemRequest)
    {
        abort_if(! $request->user()->hasAccountantAuthority(), 403, 'You are not authorised to initiate payment on per-diem requests.');
        $this->abortIfSelfActioning($perDiemRequest, $request);
        abort_if($perDiemRequest->status !== 'pending_payment', 422, 'Only requests awaiting payment initiation can be actioned at this stage.');

        $data = $request->validate([
            'payment_method'    => ['nullable', 'in:cash,bank_transfer,mobile_money,cheque'],
            'payment_reference' => ['nullable', 'string', 'max:255'],
        ]);

        $perDiemRequest->update([
            'status'               => 'pending_director',
            'payment_initiated_by' => $request->user()->id,
            'payment_initiated_at' => now(),
            'payment_method'       => $data['payment_method'] ?? null,
            'payment_reference'    => $data['payment_reference'] ?? null,
        ]);
        ApprovalLog::record($perDiemRequest, 'payment_initiated', $request->user());

        $this->notifyDirector($perDiemRequest);
        // Section 11: the reference flow's stage 4 ("Accountant initiated
        // payment, awaiting Director approval") is a requester-facing
        // status change too — found missing during the audit: only the
        // Director was ever notified at this point.
        $this->notifyRequesterPaymentInitiated($perDiemRequest);

        return response()->json(['data' => new PerDiemRequestResource(
            $perDiemRequest->load(['user', 'teamLeadReviewer', 'reviewer', 'paymentInitiatedBy', 'lines'])
        )]);
    }

    // "Money is out" — the actual release, only reachable once finance has
    // prepared the payment. The Director retains the authority (they
    // approve all money leaving the bank), but since the Director is often
    // busy, the accountant can trigger this in-app too — either can click
    // it, but never the same person who initiated the payment (the guard
    // below), so releasing still always takes two people.
    public function markPaid(Request $request, PerDiemRequest $perDiemRequest)
    {
        abort_if(! $request->user()->hasAccountantAuthority() && ! $request->user()->hasDirectorAuthority(), 403,
            'Only the accountant or Director can release per-diem payment.');
        $this->abortIfSelfActioning($perDiemRequest, $request);
        abort_if($perDiemRequest->status !== 'pending_director', 422, 'Only requests awaiting Director authorization can be marked paid.');
        abort_if($perDiemRequest->payment_initiated_by === $request->user()->id, 403, 'You cannot authorize a payment you initiated.');

        $perDiemRequest->update([
            'status'  => 'paid',
            'paid_by' => $request->user()->id,
            'paid_at' => now(),
        ]);
        ApprovalLog::record($perDiemRequest, 'paid', $request->user());

        AppNotification::create([
            'user_id'     => $perDiemRequest->user_id,
            ...app(NotificationTemplateService::class)->render('per_diem.paid', [
                'destination' => $perDiemRequest->destination,
            ]),
            'entity_type' => 'per_diem_request',
            'entity_id'   => $perDiemRequest->id,
            'is_read'     => false,
        ]);

        return response()->json(['data' => new PerDiemRequestResource(
            $perDiemRequest->load(['user', 'teamLeadReviewer', 'reviewer', 'paymentInitiatedBy', 'paidBy', 'lines'])
        )]);
    }

    // Section 9: "a technician can cancel their own plan only before it is
    // fully approved. After approval or once at Money is Out, only the CTO
    // can cancel." — generalized the old requester-or-team-lead check into
    // requester-while-still-pending, or CTO-tier at any active status.
    public function cancel(Request $request, PerDiemRequest $perDiemRequest)
    {
        $user = $request->user();
        $isSelf = $perDiemRequest->user_id === $user->id;
        $isCto = $user->hasCtoApprovalAuthority();

        abort_if(! $isSelf && ! $isCto, 403, 'Not authorised.');
        abort_if(in_array($perDiemRequest->status, ['rejected', 'cancelled'], true), 422,
            'This request has already been closed out.');

        if ($isSelf && ! $isCto) {
            abort_if(! in_array($perDiemRequest->status, ['pending_team_lead', 'pending_cto'], true), 422,
                'This request has already been approved — only the CTO or Director can cancel it now.');
        }

        $data = $request->validate(['cancellation_reason' => ['required', 'string', 'min:10']]);
        $wasPaid = $perDiemRequest->status === 'paid';

        DB::transaction(function () use ($perDiemRequest, $user, $data, $wasPaid) {
            $perDiemRequest->update([
                'status'               => 'cancelled',
                'cancelled_by'         => $user->id,
                'cancelled_at'         => now(),
                'cancellation_reason'  => $data['cancellation_reason'],
            ]);

            // Section 9: "If a plan is cancelled after Money is Out, still
            // require the reason and create the return-of-funds adjustment
            // (section 8)." The original release amount is never rewritten
            // — the adjustment is the full amount, signed negative (owed
            // back to Hypermed).
            if ($wasPaid) {
                PerDiemAdjustment::create([
                    'per_diem_request_id' => $perDiemRequest->id,
                    'amount'              => -$perDiemRequest->amount,
                    'reason'              => "Cancelled after payment: {$data['cancellation_reason']}",
                    'created_by'          => $user->id,
                ]);
            }
        });

        if ($wasPaid) {
            $this->notifyFinanceAdjustment($perDiemRequest);
        }

        return response()->json(['data' => new PerDiemRequestResource(
            $perDiemRequest->fresh()->load(['user', 'cancelledBy', 'lines', 'adjustments'])
        )]);
    }

    // ── Section 8: day-by-day CTO editing, technician edit grants ──────────

    public function show(Request $request, PerDiemRequest $perDiemRequest)
    {
        $user = $request->user();
        abort_if($perDiemRequest->user_id !== $user->id && ! $user->hasTeamLeadAuthority() && ! $user->hasAccountantAuthority(), 403,
            'Not authorised.');

        return response()->json(['data' => new PerDiemRequestResource($perDiemRequest->load([
            'user', 'teamLeadReviewer', 'reviewer', 'paymentInitiatedBy', 'paidBy', 'cancelledBy',
            'lines', 'revisions', 'editGrants', 'adjustments',
        ]))]);
    }

    // "The CTO can edit any active plan (not completed, rejected or
    // cancelled) in three modes: edit a single day; edit from a chosen day
    // onwards; add or remove days." One endpoint covers all three: send
    // the full lines array to replace everything (single-day edit or add/
    // remove), or from_seq_no to leave earlier days untouched and replace
    // only from that point on (the mid-service re-route case).
    public function revise(Request $request, PerDiemRequest $perDiemRequest)
    {
        abort_if(! $request->user()->hasCtoApprovalAuthority(), 403, 'Only the CTO or Director can edit a travel plan.');
        $this->abortIfSelfActioning($perDiemRequest, $request);
        abort_if(in_array($perDiemRequest->status, ['rejected', 'cancelled'], true), 422,
            'Only an active travel plan can be edited.');

        $data = $request->validate($this->reviseValidationRules());

        $this->applyLineEdit($perDiemRequest, $data['lines'], $data['from_seq_no'] ?? null, $data['reason'], $request->user(), 'cto');

        $this->notifyPlanEdited($perDiemRequest->fresh());
        if ($perDiemRequest->fresh()->status === 'paid' && $perDiemRequest->adjustments()->exists()) {
            $this->notifyFinanceAdjustment($perDiemRequest);
        }

        return response()->json(['data' => new PerDiemRequestResource(
            $perDiemRequest->fresh()->load(['user', 'lines', 'revisions', 'adjustments'])
        )]);
    }

    private function reviseValidationRules(): array
    {
        return [
            'reason'                  => ['required', 'string', 'min:10'],
            'from_seq_no'             => ['nullable', 'integer', 'min:1'],
            'lines'                   => ['required', 'array', 'min:1'],
            'lines.*.date'            => ['required', 'date'],
            'lines.*.region'          => ['nullable', 'string'],
            'lines.*.district'        => ['nullable', 'string'],
            'lines.*.site_name'       => ['nullable', 'string'],
            'lines.*.activity'        => ['nullable', 'string'],
            'lines.*.labor_cost'      => ['nullable', 'integer', 'min:0'],
            'lines.*.per_diem_cost'   => ['nullable', 'integer', 'min:0'],
            'lines.*.transport_fare'  => ['nullable', 'integer', 'min:0'],
        ];
    }

    // Shared by revise() (applies immediately) and approveTechnicianEdit()
    // (applies a previously-stored proposal) — replaces lines from
    // from_seq_no onward (or all of them, if null), recomputes the plan's
    // summary fields, and records a revision. If the plan is already
    // 'paid', the original release amount is never rewritten — the delta
    // becomes a PerDiemAdjustment instead ("Edits to plans not yet paid
    // simply update the amounts").
    private function applyLineEdit(
        PerDiemRequest $perDiem,
        array $newLines,
        ?int $fromSeqNo,
        string $reason,
        User $editor,
        string $editorRole,
        ?PerDiemRevision $existingRevision = null,
    ): PerDiemRevision {
        $before = [
            'lines'  => $perDiem->lines()->get()->map->only(['seq_no', 'date', 'region', 'district', 'site_name', 'activity', 'labor_cost', 'per_diem_cost', 'transport_fare'])->all(),
            'amount' => $perDiem->amount,
        ];
        $wasPaid = $perDiem->status === 'paid';
        $previousAmount = $perDiem->amount;

        DB::transaction(function () use ($perDiem, $newLines, $fromSeqNo) {
            if ($fromSeqNo !== null) {
                $perDiem->lines()->where('seq_no', '>=', $fromSeqNo)->delete();
                $startSeq = $fromSeqNo;
            } else {
                $perDiem->lines()->delete();
                $startSeq = 1;
            }

            foreach ($newLines as $i => $line) {
                $perDiem->lines()->create([
                    'seq_no'         => $startSeq + $i,
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
        });

        $allLines = $perDiem->lines()->orderBy('seq_no')->get();
        $dates = $allLines->pluck('date');
        $newAmount = (int) $allLines->sum(fn ($l) => $l->labor_cost + $l->per_diem_cost + $l->transport_fare);

        $perDiem->update([
            'start_date' => $dates->min(),
            'end_date'   => $dates->max(),
            'days_count' => $dates->map(fn ($d) => $d->toDateString())->unique()->count(),
            'amount'     => $newAmount,
        ]);

        $revision = $existingRevision ?? new PerDiemRevision([
            'per_diem_request_id' => $perDiem->id,
            'edited_by'           => $editor->id,
            'editor_role'         => $editorRole,
            'reason'              => $reason,
            'before'              => $before,
        ]);
        $revision->status = 'applied';
        $revision->after = [
            'lines'  => $allLines->map->only(['seq_no', 'date', 'region', 'district', 'site_name', 'activity', 'labor_cost', 'per_diem_cost', 'transport_fare'])->all(),
            'amount' => $newAmount,
        ];
        $revision->save();

        if ($wasPaid && $newAmount !== $previousAmount) {
            PerDiemAdjustment::create([
                'per_diem_request_id'  => $perDiem->id,
                'per_diem_revision_id' => $revision->id,
                'amount'               => $newAmount - $previousAmount,
                'reason'               => $reason,
                'created_by'           => $editor->id,
            ]);
        }

        return $revision;
    }

    // "The CTO grants edit permission on a specific plan, for the whole
    // plan or specific days, with an optional expiry, and can revoke it at
    // any time."
    public function grantEditAccess(Request $request, PerDiemRequest $perDiemRequest)
    {
        abort_if(! $request->user()->hasCtoApprovalAuthority(), 403, 'Only the CTO or Director can grant edit access.');
        abort_if(in_array($perDiemRequest->status, ['rejected', 'cancelled'], true), 422,
            'Only an active travel plan can be granted edit access.');

        $data = $request->validate([
            // Omitted/null = the whole plan; otherwise specific day
            // seq_no values. v1 records this for audit/display but
            // enforces only "has an active grant at all" — see
            // PerDiemRequest::hasActiveEditGrant().
            'scope'      => ['nullable', 'array'],
            'scope.*'    => ['integer', 'min:1'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);

        $grant = $perDiemRequest->editGrants()->create([
            'technician_id' => $perDiemRequest->user_id,
            'granted_by'    => $request->user()->id,
            'scope'         => $data['scope'] ?? null,
            'expires_at'    => $data['expires_at'] ?? null,
        ]);

        $this->notifyEditGrantChanged($perDiemRequest, granted: true);

        return response()->json(['data' => new PerDiemEditGrantResource($grant->load('grantedBy'))], 201);
    }

    public function revokeEditAccess(Request $request, PerDiemRequest $perDiemRequest)
    {
        abort_if(! $request->user()->hasCtoApprovalAuthority(), 403, 'Only the CTO or Director can revoke edit access.');

        $active = $perDiemRequest->editGrants()->get()->filter(fn ($g) => $g->isActive());
        abort_if($active->isEmpty(), 422, 'This plan has no active edit grant to revoke.');

        foreach ($active as $grant) {
            $grant->update(['revoked_by' => $request->user()->id, 'revoked_at' => now()]);
        }

        $this->notifyEditGrantChanged($perDiemRequest, granted: false);

        return response()->json(['data' => PerDiemEditGrantResource::collection($perDiemRequest->editGrants()->get())]);
    }

    // "While granted, the technician can edit only what was unlocked. A
    // technician's edit does not take effect on its own: it goes back to
    // the CTO for approval, and until approved the plan keeps its previous
    // approved values."
    public function submitTechnicianEdit(Request $request, PerDiemRequest $perDiemRequest)
    {
        $user = $request->user();
        abort_if($perDiemRequest->user_id !== $user->id, 403, 'You can only propose edits to your own travel plan.');
        abort_if(! $perDiemRequest->hasActiveEditGrant(), 403,
            'You do not have edit access to this travel plan — ask the CTO to grant it.');
        abort_if(in_array($perDiemRequest->status, ['rejected', 'cancelled'], true), 422,
            'Only an active travel plan can be edited.');

        $data = $request->validate($this->reviseValidationRules());

        $revision = PerDiemRevision::create([
            'per_diem_request_id' => $perDiemRequest->id,
            'edited_by'           => $user->id,
            'editor_role'         => 'technician',
            'status'              => 'pending_cto_approval',
            'reason'              => $data['reason'],
            'before' => [
                'lines'  => $perDiemRequest->lines()->get()->map->only(['seq_no', 'date', 'region', 'district', 'site_name', 'activity', 'labor_cost', 'per_diem_cost', 'transport_fare'])->all(),
                'amount' => $perDiemRequest->amount,
            ],
            'proposed' => ['lines' => $data['lines'], 'from_seq_no' => $data['from_seq_no'] ?? null],
        ]);

        $this->notifyTechnicianEditSubmitted($perDiemRequest, $revision);

        return response()->json(['data' => new PerDiemRevisionResource($revision->load('editor'))], 201);
    }

    // "A technician can never approve their own change" — a technician
    // lacks hasCtoApprovalAuthority() entirely, so this is already
    // structurally impossible, not just guarded.
    public function approveTechnicianEdit(Request $request, PerDiemRequest $perDiemRequest, PerDiemRevision $revision)
    {
        abort_if(! $request->user()->hasCtoApprovalAuthority(), 403, 'Only the CTO or Director can approve a proposed edit.');
        abort_if($revision->per_diem_request_id !== $perDiemRequest->id, 404);
        abort_if($revision->status !== 'pending_cto_approval', 422, 'This edit has already been reviewed.');

        $proposed = $revision->proposed;
        $this->applyLineEdit(
            $perDiemRequest, $proposed['lines'], $proposed['from_seq_no'] ?? null,
            $revision->reason, $revision->editor, 'technician', existingRevision: $revision,
        );
        $revision->update(['reviewed_by' => $request->user()->id, 'reviewed_at' => now()]);

        $this->notifyTechnicianEditReviewed($perDiemRequest, $revision, approved: true);

        return response()->json(['data' => new PerDiemRequestResource(
            $perDiemRequest->fresh()->load(['user', 'lines', 'revisions', 'adjustments'])
        )]);
    }

    public function rejectTechnicianEdit(Request $request, PerDiemRequest $perDiemRequest, PerDiemRevision $revision)
    {
        abort_if(! $request->user()->hasCtoApprovalAuthority(), 403, 'Only the CTO or Director can reject a proposed edit.');
        abort_if($revision->per_diem_request_id !== $perDiemRequest->id, 404);
        abort_if($revision->status !== 'pending_cto_approval', 422, 'This edit has already been reviewed.');

        $revision->update(['status' => 'rejected', 'reviewed_by' => $request->user()->id, 'reviewed_at' => now()]);

        $this->notifyTechnicianEditReviewed($perDiemRequest, $revision, approved: false);

        return response()->json(['data' => new PerDiemRevisionResource($revision->load(['editor', 'reviewer']))]);
    }

    private function notifyTeamLead(PerDiemRequest $perDiem): void
    {
        $name = $perDiem->user?->name ?? 'A staff member';

        $teamLeadIds = User::where('role', 'team_leader')->pluck('id');
        $recipientIds = $teamLeadIds->isNotEmpty() ? $teamLeadIds : User::whereIn('role', User::CTO_TIER)->pluck('id');

        $recipientIds->each(fn ($id) => AppNotification::create([
            'user_id'     => $id,
            ...app(NotificationTemplateService::class)->render('per_diem.notify_team_lead', [
                'name'        => $name,
                'destination' => $perDiem->destination,
                'start_date'  => $perDiem->start_date->toDateString(),
                'end_date'    => $perDiem->end_date->toDateString(),
                'days_count'  => $perDiem->days_count,
            ]),
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
                ...app(NotificationTemplateService::class)->render('per_diem.forwarded_cto', [
                    'name'        => $name,
                    'destination' => $perDiem->destination,
                ]),
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
                ...app(NotificationTemplateService::class)->render('per_diem.ready_to_pay_finance', [
                    'name'        => $name,
                    'destination' => $perDiem->destination,
                ]),
                'entity_type' => 'per_diem_request',
                'entity_id'   => $perDiem->id,
                'is_read'     => false,
            ]));
    }

    private function notifyDirector(PerDiemRequest $perDiem): void
    {
        $name = $perDiem->user?->name ?? 'A staff member';

        User::whereIn('role', User::ADMIN_TIER)
            ->pluck('id')
            ->each(fn ($id) => AppNotification::create([
                'user_id'     => $id,
                ...app(NotificationTemplateService::class)->render('per_diem.final_authorization_director', [
                    'name' => $name,
                ]),
                'entity_type' => 'per_diem_request',
                'entity_id'   => $perDiem->id,
                'is_read'     => false,
            ]));
    }

    // Section 11: reference flow stage 4 ("Accountant initiated payment,
    // awaiting Director approval") — the requester is a recipient at every
    // stage change, not just the next approver.
    private function notifyRequesterPaymentInitiated(PerDiemRequest $perDiem): void
    {
        AppNotification::create([
            'user_id'     => $perDiem->user_id,
            ...app(NotificationTemplateService::class)->render('per_diem.payment_initiated_requester', [
                'destination' => $perDiem->destination,
            ]),
            'entity_type' => 'per_diem_request',
            'entity_id'   => $perDiem->id,
            'is_read'     => false,
        ]);
    }

    private function notifyRequester(PerDiemRequest $perDiem, bool $approved, bool $rejectedAtTeamLead): void
    {
        $templateKey = $approved ? 'per_diem.approved_requester' : 'per_diem.rejected_requester';
        $reasonSuffix = '';
        if (! $approved) {
            $reasonSuffix = $rejectedAtTeamLead
                ? ($perDiem->team_lead_rejection_reason ? " Reason: {$perDiem->team_lead_rejection_reason}" : '')
                : ($perDiem->rejection_reason ? " Reason: {$perDiem->rejection_reason}" : '');
        }

        AppNotification::create([
            'user_id'     => $perDiem->user_id,
            ...app(NotificationTemplateService::class)->render($templateKey, [
                'destination'   => $perDiem->destination,
                'reason_suffix' => $reasonSuffix,
            ]),
            'entity_type' => 'per_diem_request',
            'entity_id'   => $perDiem->id,
            'is_read'     => false,
        ]);
    }

    // Section 8: "Notify the technician when the CTO changes their plan."
    private function notifyPlanEdited(PerDiemRequest $perDiem): void
    {
        AppNotification::create([
            'user_id'     => $perDiem->user_id,
            ...app(NotificationTemplateService::class)->render('per_diem.plan_edited', [
                'destination' => $perDiem->destination,
            ]),
            'entity_type' => 'per_diem_request',
            'entity_id'   => $perDiem->id,
            'is_read'     => false,
        ]);
    }

    // "Notify finance when an adjustment is created."
    private function notifyFinanceAdjustment(PerDiemRequest $perDiem): void
    {
        $name = $perDiem->user?->name ?? 'A staff member';

        User::whereIn('role', ['finance_manager', 'finance', 'accountant'])
            ->pluck('id')
            ->each(fn ($id) => AppNotification::create([
                'user_id'     => $id,
                ...app(NotificationTemplateService::class)->render('per_diem.adjustment_created', [
                    'name'        => $name,
                    'destination' => $perDiem->destination,
                ]),
                'entity_type' => 'per_diem_request',
                'entity_id'   => $perDiem->id,
                'is_read'     => false,
            ]));
    }

    // "Notify the technician when... the CTO grants or revokes edit access."
    private function notifyEditGrantChanged(PerDiemRequest $perDiem, bool $granted): void
    {
        AppNotification::create([
            'user_id'     => $perDiem->user_id,
            ...app(NotificationTemplateService::class)->render(
                $granted ? 'per_diem.edit_access_granted' : 'per_diem.edit_access_revoked',
                ['destination' => $perDiem->destination],
            ),
            'entity_type' => 'per_diem_request',
            'entity_id'   => $perDiem->id,
            'is_read'     => false,
        ]);
    }

    // "Notify the CTO when a technician submits an edit."
    private function notifyTechnicianEditSubmitted(PerDiemRequest $perDiem, PerDiemRevision $revision): void
    {
        $name = $perDiem->user?->name ?? 'A staff member';

        User::whereIn('role', User::CTO_TIER)
            ->pluck('id')
            ->each(fn ($id) => AppNotification::create([
                'user_id'     => $id,
                ...app(NotificationTemplateService::class)->render('per_diem.technician_edit_submitted', [
                    'name'        => $name,
                    'destination' => $perDiem->destination,
                ]),
                'entity_type' => 'per_diem_request',
                'entity_id'   => $perDiem->id,
                'is_read'     => false,
            ]));
    }

    private function notifyTechnicianEditReviewed(PerDiemRequest $perDiem, PerDiemRevision $revision, bool $approved): void
    {
        AppNotification::create([
            'user_id'     => $revision->edited_by,
            ...app(NotificationTemplateService::class)->render(
                $approved ? 'per_diem.technician_edit_approved' : 'per_diem.technician_edit_rejected',
                ['destination' => $perDiem->destination],
            ),
            'entity_type' => 'per_diem_request',
            'entity_id'   => $perDiem->id,
            'is_read'     => false,
        ]);
    }
}
