<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class BatchLotResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'               => $this->id,
            'inventory_item_id'=> $this->inventory_item_id,
            'batch_number'     => $this->batch_number,
            'lot_number'       => $this->lot_number,
            'expiry_date'      => $this->expiry_date?->toDateString(),
            'manufactured_date'=> $this->manufactured_date?->toDateString(),
            'qty_received'     => $this->qty_received,
            'qty_remaining'    => $this->qty_remaining,
            'supplier_id'      => $this->supplier_id,
            'supplier_name'    => $this->whenLoaded('supplier', fn() => $this->supplier?->name),
            'unit_cost'        => $this->unit_cost,
            'currency'         => $this->currency,
            'received_at'      => $this->received_at?->toDateString(),
            'notes'            => $this->notes,
            'is_expired'       => $this->isExpired(),
            'is_expiring_soon' => $this->isExpiringSoon(),
            'created_at'       => $this->created_at?->toDateString(),
        ];
    }
}
