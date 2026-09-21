<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PerDiemRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                          => $this->id,
            'user_id'                     => $this->user_id,
            'user_name'                   => $this->user?->name,
            'service_ticket_id'           => $this->service_ticket_id,
            'destination'                 => $this->destination,
            'start_date'                  => $this->start_date?->toDateString(),
            'end_date'                    => $this->end_date?->toDateString(),
            'days_count'                  => $this->days_count,
            'daily_rate'                  => $this->daily_rate,
            'amount'                      => $this->amount,
            'purpose'                     => $this->purpose,
            'status'                      => $this->status,
            'team_lead_reviewed_by'       => $this->team_lead_reviewed_by,
            'team_lead_reviewer_name'     => $this->teamLeadReviewer?->name,
            'team_lead_reviewed_at'       => $this->team_lead_reviewed_at?->toIso8601String(),
            'team_lead_rejection_reason'  => $this->team_lead_rejection_reason,
            'reviewed_by'                 => $this->reviewed_by,
            'reviewer_name'               => $this->reviewer?->name,
            'reviewed_at'                 => $this->reviewed_at?->toIso8601String(),
            'rejection_reason'            => $this->rejection_reason,
            'payment_initiated_by'        => $this->payment_initiated_by,
            'payment_initiated_by_name'   => $this->paymentInitiatedBy?->name,
            'payment_initiated_at'        => $this->payment_initiated_at?->toIso8601String(),
            'payment_method'              => $this->payment_method,
            'payment_reference'           => $this->payment_reference,
            'paid_by'                     => $this->paid_by,
            'paid_by_name'                => $this->paidBy?->name,
            'paid_at'                     => $this->paid_at?->toIso8601String(),
            'cancelled_by'                => $this->cancelled_by,
            'cancelled_by_name'           => $this->cancelledBy?->name,
            'cancelled_at'                => $this->cancelled_at?->toIso8601String(),
            'cancellation_reason'         => $this->cancellation_reason,
            'created_at'                  => $this->created_at?->toIso8601String(),
            'lines'                       => PerDiemLineResource::collection($this->whenLoaded('lines')),
            // Section 8: revision history, technician edit grants, and any
            // post-payment adjustments ("the original release is never
            // rewritten").
            'revisions'                   => PerDiemRevisionResource::collection($this->whenLoaded('revisions')),
            'edit_grants'                 => PerDiemEditGrantResource::collection($this->whenLoaded('editGrants')),
            'adjustments'                 => PerDiemAdjustmentResource::collection($this->whenLoaded('adjustments')),
            'has_active_edit_grant'       => $this->whenLoaded('editGrants', fn () => $this->editGrants->contains(fn ($g) => $g->isActive())),
            'was_edited'                  => $this->whenLoaded('revisions', fn () => $this->revisions->contains(fn ($r) => $r->status === 'applied')),
        ];
    }
}
