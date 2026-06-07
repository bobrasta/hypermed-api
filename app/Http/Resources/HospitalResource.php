<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class HospitalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                  => $this->id,
            'name'                => $this->name,
            'short_code'          => $this->short_code,
            'type'                => $this->type,
            'region'              => $this->region,
            'district'            => $this->district,
            'latitude'            => $this->latitude,
            'longitude'           => $this->longitude,
            'zone'                => $this->zone,
            'machine_count'       => $this->machine_count,
            'machines_operational' => $this->machines_operational,
            'revenue_monthly'     => $this->revenue_monthly,
            'contact_name'        => $this->contact_name,
            'contact_phone'       => $this->contact_phone,
            'contact_email'       => $this->contact_email,
            'notes'               => $this->notes,
            'machines'            => MachineResource::collection($this->whenLoaded('machines')),
        ];
    }
}
