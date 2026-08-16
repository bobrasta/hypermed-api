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
            'created_at'                  => $this->created_at?->toIso8601String(),
        ];
    }
}
