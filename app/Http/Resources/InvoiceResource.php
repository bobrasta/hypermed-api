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
            'client_tin'       => $this->client_tin ?? $this->hospital?->tin,
            // Dates
            'issue_date'       => $this->issue_date?->toDateString(),
            'due_date'         => $this->due_date?->toDateString(),
            'pay_term_number'  => $this->pay_term_number,
            'pay_term_type'    => $this->pay_term_type,
            // Financials
            'subtotal'         => $this->subtotal,
            'tax_rate'         => $this->tax_rate,
            'tax_amount'       => $this->tax_amount,
            'shipping_charges' => (int) $this->shipping_charges,
            'total'            => $this->total,
            'amount_paid'      => $this->amount_paid,
            'balance_due'      => $this->balance_due,
            'status'           => $this->status,
            'currency'         => $this->currency,
            'notes'            => $this->notes,
            // Clickhuduma sale-record fields ("All sales")
            'sale_status'      => in_array($this->status, \App\Models\Invoice::UNFINAL, true) ? $this->status : 'final',
            'payment_status'   => $this->paymentStatus(),
            'contact_phone'    => $this->client_contact ?: $this->hospital?->contact_phone,
            'created_by'       => $this->created_by,
            'added_by'         => $this->creator?->name ?? $this->added_by_name,
            'staff_note'       => $this->staff_note,
            'total_items'      => $this->when(isset($this->total_items), fn () => (float) $this->total_items),
            'payment_methods'  => $this->whenLoaded('payments', fn () => $this->payments->pluck('payment_method')->unique()->values()),
            'credited'         => $this->when(isset($this->credited), fn () => (int) $this->credited),
            'shipping_status'  => $this->shipping_status,
            'shipping_address' => $this->shipping_address,
            'shipping_details' => $this->shipping_details,
            'delivered_to'     => $this->delivered_to,
            // Only what this sale changed; null = company default terms.
            'term_items'       => $this->term_items,
            // Relations
            'line_items'       => InvoiceLineItemResource::collection($this->whenLoaded('lineItems')),
            'payments'         => PaymentResource::collection($this->whenLoaded('payments')),
        ];
    }
}
