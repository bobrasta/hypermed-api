<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SalesLeadResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                 => $this->id,
            'hospital_id'        => $this->hospital_id,
            'hospital'           => new HospitalResource($this->whenLoaded('hospital')),
            'hospital_name_raw'  => $this->hospital_name_raw,
            'contact_id'         => $this->contact_id,
            'contact_name_raw'   => $this->contact_name_raw,
            'machine_type'       => $this->machine_type,
            'deal_value'         => $this->deal_value,
            'days_in_stage'      => $this->days_in_stage,
            'stage'              => $this->stage,
            'demo_date'          => $this->demo_date?->toDateString(),
            'assigned_to'        => $this->assigned_to,
            'assignee'           => new UserResource($this->whenLoaded('assignee')),
            'created_at'         => $this->created_at?->toIso8601String(),
            'updated_at'         => $this->updated_at?->toIso8601String(),
        ];
    }
}
