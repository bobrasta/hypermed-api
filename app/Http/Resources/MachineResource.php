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
            // hospital_id is nullable now (In Stock machines) — avoid
            // wrapping a null relation in HospitalResource.
            'hospital'         => $this->hospital_id ? new HospitalResource($this->whenLoaded('hospital')) : null,
            'ward'             => $this->ward,
            'install_date'     => $this->install_date?->toDateString(),
            'warranty_expiry'  => $this->warranty_expiry?->toDateString(),
            'status'           => $this->status,
            'status_code'      => Machine::$statusCodes[$this->status] ?? $this->status,
            'revenue_per_month' => $this->revenue_per_month,
            // Section 13
            'lifecycle_stage'  => $this->lifecycle_stage,
            'manufacturer'     => $this->manufacturer,
            'condition'        => $this->condition,
            'arrival_date'     => $this->arrival_date?->toDateString(),
            'store_location_id'   => $this->store_location_id,
            'store_location_name' => $this->whenLoaded('storeLocation', fn () => $this->storeLocation?->name),
            // Section 12 — original currency in, TSh comparison amount out.
            'purchase_cost'             => $this->purchase_cost,
            'purchase_cost_currency'    => $this->purchase_cost_currency,
            'purchase_cost_fx_rate'     => $this->purchase_cost_fx_rate,
            'purchase_cost_tsh'         => $this->purchase_cost_tsh,
            'purchase_cost_recorded_at' => $this->purchase_cost_recorded_at?->toIso8601String(),
            'sales_order_id'         => $this->sales_order_id,
            'installation_ticket_id' => $this->installation_ticket_id,
            'installed_by'      => $this->installed_by,
            'installed_by_name' => $this->whenLoaded('installedBy', fn () => $this->installedBy?->name),
            'installed_at'      => $this->installed_at?->toIso8601String(),
            'signed_off_by'      => $this->signed_off_by,
            'signed_off_by_name' => $this->whenLoaded('signedOffBy', fn () => $this->signedOffBy?->name),
            'signed_off_at'      => $this->signed_off_at?->toIso8601String(),
            'tickets'          => ServiceTicketResource::collection($this->whenLoaded('tickets')),
        ];
    }
}
