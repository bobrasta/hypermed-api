<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceLineItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'inventory_item_id' => $this->inventory_item_id,
            'description' => $this->description,
            'quantity'    => $this->quantity,
            'unit_of_measure' => $this->unit_of_measure,
            'lot_number'  => $this->lot_number,
            'expiry_date' => $this->expiry_date?->toDateString(),
            'unit_price'  => $this->unit_price,
            'discount'    => (int) $this->discount,
            'total'       => $this->total,
        ];
    }
}
