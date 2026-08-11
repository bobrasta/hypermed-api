<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class StockLevelResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'                 => $this->id,
            'quantity_on_hand'   => $this->quantity_on_hand,
            'quantity_reserved'  => $this->quantity_reserved,
            'quantity_available' => $this->quantity_available,
            'item'               => $this->whenLoaded('inventoryItem', fn () => [
                'id'             => $this->inventoryItem->id,
                'sku'            => $this->inventoryItem->sku,
                'name'           => $this->inventoryItem->name,
                'reorder_point'  => $this->inventoryItem->reorder_level,
            ]),
            'warehouse'          => $this->whenLoaded('location', fn () => [
                'id'   => $this->location->id,
                'name' => $this->location->name,
            ]),
        ];
    }
}
