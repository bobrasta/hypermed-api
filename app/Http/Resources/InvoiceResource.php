<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'invoice_number'   => $this->invoice_number,
            // Hospital-linked (service invoices)
            'hospital_id'      => $this->hospital_id,
            'hospital'         => new HospitalResource($this->whenLoaded('hospital')),
            'machine_id'       => $this->machine_id,
            'machine'          => new MachineResource($this->whenLoaded('machine')),
            // Sales-linked
            'sales_order_id'   => $this->sales_order_id,
            'sales_order_number' => $this->whenLoaded('salesOrder', fn () => $this->salesOrder?->order_number),
            'client_name'      => $this->client_name
                                    ?? $this->whenLoaded('hospital', fn () => $this->hospital?->name),
            'client_contact'   => $this->client_contact,
            'client_email'     => $this->client_email,
            // Dates
            'issue_date'       => $this->issue_date?->toDateString(),
            'due_date'         => $this->due_date?->toDateString(),
            // Financials
            'subtotal'         => $this->subtotal,
            'tax_rate'         => $this->tax_rate,
            'tax_amount'       => $this->tax_amount,
            'total'            => $this->total,
            'amount_paid'      => $this->amount_paid,
            'balance_due'      => $this->balance_due,
            'status'           => $this->status,
            'currency'         => $this->currency,
            'notes'            => $this->notes,
            // Relations
            'line_items'       => InvoiceLineItemResource::collection($this->whenLoaded('lineItems')),
            'payments'         => PaymentResource::collection($this->whenLoaded('payments')),
        ];
    }
}
