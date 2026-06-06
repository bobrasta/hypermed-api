<?php

namespace App\Http\Resources;

use App\Models\Machine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MachineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'serial_no'        => $this->serial_no,
            'model'            => $this->model,
            'type'             => $this->type,
            'hospital_id'      => $this->hospital_id,
            'hospital'         => new HospitalResource($this->whenLoaded('hospital')),
            'ward'             => $this->ward,
            'install_date'     => $this->install_date?->toDateString(),
            'warranty_expiry'  => $this->warranty_expiry?->toDateString(),
            'status'           => $this->status,
            'status_code'      => Machine::$statusCodes[$this->status] ?? $this->status,
            'revenue_per_month' => $this->revenue_per_month,
            'tickets'          => ServiceTicketResource::collection($this->whenLoaded('tickets')),
        ];
    }
}
