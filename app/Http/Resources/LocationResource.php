<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class LocationResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'        => $this->id,
            'name'      => $this->name,
            'code'      => $this->code,
            'type'      => $this->type,
            'type_label'=> match($this->type) {
                'warehouse' => 'Warehouse',
                'store'     => 'Store',
                'room'      => 'Room',
                'vehicle'   => 'Vehicle',
                default     => 'Other',
            },
            'address'   => $this->address,
            'manager_id' => $this->manager_id,
            'manager_name' => $this->whenLoaded('manager', fn () => $this->manager?->name),
            'notes'     => $this->notes,
            'is_active' => $this->is_active,
            'created_at'=> $this->created_at?->toDateString(),
        ];
    }
}
