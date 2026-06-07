<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class SerialNumberResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'                    => $this->id,
            'inventory_item_id'     => $this->inventory_item_id,
            'serial_number'         => $this->serial_number,
            'status'                => $this->status,
            'location_id'           => $this->location_id,
            'location_name'         => $this->whenLoaded('location', fn() => $this->location?->name),
            'assigned_to_machine_id'=> $this->assigned_to_machine_id,
            'machine_name'          => $this->whenLoaded('assignedMachine', fn() => $this->assignedMachine?->name),
            'purchase_date'         => $this->purchase_date?->toDateString(),
            'warranty_expires_at'   => $this->warranty_expires_at?->toDateString(),
            'is_warranty_expired'   => $this->isWarrantyExpired(),
            'notes'                 => $this->notes,
            'created_at'            => $this->created_at?->toDateString(),
        ];
    }
}
