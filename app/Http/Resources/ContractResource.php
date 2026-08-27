<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ContractResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                       => $this->id,
            'user_id'                  => $this->user_id,
            'contract_type'            => $this->contract_type,
            'start_date'               => $this->start_date?->toDateString(),
            'end_date'                 => $this->end_date?->toDateString(),
            'probation_period_days'    => $this->probation_period_days,
            'probation_end_date'       => $this->probation_end_date?->toDateString(),
            'base_salary'              => $this->base_salary,
            'status'                   => $this->status,
            'resignation_date'         => $this->resignation_date?->toDateString(),
            'resignation_reason'       => $this->resignation_reason,
            'renewed_from_contract_id' => $this->renewed_from_contract_id,
            'created_by'               => $this->created_by,
            'created_by_name'          => $this->whenLoaded('createdBy', fn () => $this->createdBy?->name),
            'allowances'               => AllowanceResource::collection($this->whenLoaded('allowances')),
            'created_at'               => $this->created_at?->toIso8601String(),
        ];
    }
}
