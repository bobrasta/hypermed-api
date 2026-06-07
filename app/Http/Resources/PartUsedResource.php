<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PartUsedResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'inventory_item' => new InventoryItemResource($this->whenLoaded('inventoryItem')),
            'qty'            => $this->qty,
            'unit_cost'      => $this->unit_cost,
        ];
    }
}
