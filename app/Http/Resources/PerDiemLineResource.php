<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PerDiemLineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'seq_no'         => $this->seq_no,
            'date'           => $this->date?->toDateString(),
            'region'         => $this->region,
            'district'       => $this->district,
            'site_name'      => $this->site_name,
            'activity'       => $this->activity,
            'labor_cost'     => $this->labor_cost,
            'per_diem_cost'  => $this->per_diem_cost,
            'transport_fare' => $this->transport_fare,
        ];
    }
}
