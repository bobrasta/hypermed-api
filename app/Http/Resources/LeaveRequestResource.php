<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LeaveRequestResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->id,
            'user_id'           => $this->user_id,
            'user_name'         => $this->user?->name,
            'user_role'         => $this->user?->role,
            'type'              => $this->type,
            'start_date'        => $this->start_date?->toDateString(),
            'end_date'          => $this->end_date?->toDateString(),
            'days_count'        => $this->days_count,
            'reason'            => $this->reason,
            'status'            => $this->status,
            'reviewed_by'       => $this->reviewed_by,
            'reviewer_name'     => $this->reviewer?->name,
            'reviewed_at'       => $this->reviewed_at?->toIso8601String(),
            'rejection_reason'  => $this->rejection_reason,
            'created_at'        => $this->created_at?->toIso8601String(),
        ];
    }
}
