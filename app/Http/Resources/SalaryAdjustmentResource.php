<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SalaryAdjustmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'user_id'          => $this->user_id,
            'contract_id'      => $this->contract_id,
            'previous_salary'  => $this->previous_salary,
            'new_salary'       => $this->new_salary,
            'reason'           => $this->reason,
            'effective_date'   => $this->effective_date?->toDateString(),
            'status'           => $this->status,
            'approved_by_name' => $this->whenLoaded('approvedBy', fn () => $this->approvedBy?->name),
            'approved_at'      => $this->approved_at?->toIso8601String(),
            'created_by_name'  => $this->whenLoaded('createdBy', fn () => $this->createdBy?->name),
            'created_at'       => $this->created_at?->toIso8601String(),
        ];
    }
}
