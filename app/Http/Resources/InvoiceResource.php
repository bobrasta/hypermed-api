<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'invoice_number' => $this->invoice_number,
            'hospital_id'    => $this->hospital_id,
            'hospital'       => new HospitalResource($this->whenLoaded('hospital')),
            'machine_id'     => $this->machine_id,
            'machine'        => new MachineResource($this->whenLoaded('machine')),
            'issue_date'     => $this->issue_date?->toDateString(),
            'due_date'       => $this->due_date?->toDateString(),
            'subtotal'       => $this->subtotal,
            'tax_rate'       => $this->tax_rate,
            'tax_amount'     => $this->tax_amount,
            'total'          => $this->total,
            'amount_paid'    => $this->amount_paid,
            'status'         => $this->status,
            'currency'       => $this->currency,
            'notes'          => $this->notes,
            'line_items'     => InvoiceLineItemResource::collection($this->whenLoaded('lineItems')),
        ];
    }
}
