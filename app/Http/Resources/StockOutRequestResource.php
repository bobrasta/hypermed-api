<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockOutRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                 => $this->id,
            'inventory_item_id'  => $this->inventory_item_id,
            'item_name'          => $this->inventoryItem?->name,
            'item_sku'           => $this->inventoryItem?->sku,
            'location_id'        => $this->location_id,
            'location_name'      => $this->location?->name,
            'type'               => $this->type,
            'quantity'           => $this->quantity,
            'reason'             => $this->reason,
            'requested_by'       => $this->requested_by,
            'requester_name'     => $this->requester?->name,
            'status'             => $this->status,
            'reviewed_by'        => $this->reviewed_by,
            'reviewer_name'      => $this->reviewer?->name,
            'reviewed_at'        => $this->reviewed_at?->toIso8601String(),
            'rejection_reason'   => $this->rejection_reason,
            'stock_movement_id'  => $this->stock_movement_id,
            'created_at'         => $this->created_at?->toIso8601String(),
        ];
    }
}
