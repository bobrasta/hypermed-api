<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SalesOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                     => $this->id,
            'order_number'           => $this->order_number,
            'quotation_id'           => $this->quotation_id,
            'quotation_number'       => $this->quotation?->quotation_number,
            'client_name'            => $this->client_name,
            'client_contact'         => $this->client_contact,
            'status'                 => $this->status,
            'currency'               => $this->currency,
            'subtotal'               => $this->subtotal,
            'discount_amount'        => $this->discount_amount,
            'tax_amount'             => $this->tax_amount,
            'total_amount'           => $this->total_amount,
            'notes'                  => $this->notes,
            'expected_delivery_date' => $this->expected_delivery_date?->toDateString(),
            'created_by_name'        => $this->createdBy?->name,
            'confirmed_by_name'      => $this->confirmedBy?->name,
            'confirmed_at'           => $this->confirmed_at?->toIso8601String(),
            'delivered_at'           => $this->delivered_at?->toIso8601String(),
            'created_at'             => $this->created_at->toIso8601String(),
            'items'                  => $this->whenLoaded('items', fn () =>
                $this->items->map(fn ($item) => [
                    'id'                 => $item->id,
                    'inventory_item_id'  => $item->inventory_item_id,
                    'description'        => $item->description,
                    'unit_of_measure'    => $item->unit_of_measure,
                    'quantity_ordered'   => $item->quantity_ordered,
                    'quantity_delivered' => $item->quantity_delivered,
                    'unit_price'         => $item->unit_price,
                    'total_price'        => $item->total_price,
                    'item_sku'           => $item->inventoryItem?->sku,
                ])
            ),
        ];
    }
}
