<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LeaveBalanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'user_id'        => $this->user_id,
            'leave_type_id'  => $this->leave_type_id,
            'leave_type_key'   => $this->whenLoaded('leaveType', fn () => $this->leaveType?->key),
            'leave_type_label' => $this->whenLoaded('leaveType', fn () => $this->leaveType?->label),
            'year'           => $this->year,
            'allocated_days' => $this->allocated_days,
            'used_days'      => $this->used_days,
            'remaining_days' => $this->remaining_days,
        ];
    }
}
