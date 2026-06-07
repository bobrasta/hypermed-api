<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QuotationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'quotation_number' => $this->quotation_number,
            'lead_id'          => $this->lead_id,
            'client_name'      => $this->client_name,
            'client_contact'   => $this->client_contact,
            'client_email'     => $this->client_email,
            'status'           => $this->status,
            'valid_until'      => $this->valid_until?->toDateString(),
            'currency'         => $this->currency,
            'subtotal'         => $this->subtotal,
            'discount_amount'  => $this->discount_amount,
            'tax_amount'       => $this->tax_amount,
            'total_amount'     => $this->total_amount,
            'notes'            => $this->notes,
            'terms'            => $this->terms,
            'created_by_name'  => $this->createdBy?->name,
            'sent_at'          => $this->sent_at?->toIso8601String(),
            'accepted_at'      => $this->accepted_at?->toIso8601String(),
            'created_at'       => $this->created_at->toIso8601String(),
            'items'            => $this->whenLoaded('items', fn () =>
                $this->items->map(fn ($item) => [
                    'id'                => $item->id,
                    'inventory_item_id' => $item->inventory_item_id,
                    'description'       => $item->description,
                    'unit_of_measure'   => $item->unit_of_measure,
                    'quantity'          => $item->quantity,
                    'unit_price'        => $item->unit_price,
                    'discount_percent'  => $item->discount_percent,
                    'total_price'       => $item->total_price,
                    'item_sku'          => $item->inventoryItem?->sku,
                ])
            ),
        ];
    }
}
