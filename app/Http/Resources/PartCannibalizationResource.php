<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PartCannibalizationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $sourceSerial = $this->sourceSerialNumber;
        $partUsed     = $this->partUsed;
        $ticket       = $partUsed?->ticket;

        return [
            'id'                    => $this->id,
            'source_serial_number_id' => $this->source_serial_number_id,
            'source_serial_number'  => $sourceSerial?->serial_number,
            'source_item_name'      => $sourceSerial?->inventoryItem?->name,
            'part_name'             => $partUsed?->inventoryItem?->name,
            'part_qty'              => $partUsed?->qty,
            'destination_ticket_id'     => $ticket?->id,
            'destination_ticket_number' => $ticket?->ticket_number,
            'destination_machine_name'  => $ticket?->machine?->model,
            'removed_by_name'       => $this->removedBy?->name,
            'removed_at'            => $this->removed_at?->toIso8601String(),
            'status'                => $this->status,
            'replacement_purchase_order_id' => $this->replacement_purchase_order_id,
            'replacement_po_number' => $this->replacementPurchaseOrder?->po_number,
            'replacement_ordered_at'   => $this->replacement_ordered_at?->toIso8601String(),
            'replacement_received_at'  => $this->replacement_received_at?->toIso8601String(),
            'resolved_at'           => $this->resolved_at?->toIso8601String(),
            'notes'                 => $this->notes,
            'created_at'            => $this->created_at?->toIso8601String(),
        ];
    }
}
