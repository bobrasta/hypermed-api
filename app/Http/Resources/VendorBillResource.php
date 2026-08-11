<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VendorBillResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                 => $this->id,
            'bill_number'        => $this->bill_number,
            'supplier_id'        => $this->supplier_id,
            'supplier_name'      => $this->supplier?->name,
            'purchase_order_id'  => $this->purchase_order_id,
            'purchase_order_number' => $this->purchaseOrder?->po_number,
            'category_id'        => $this->category_id,
            'category_name'      => $this->category?->name,
            'issue_date'         => $this->issue_date?->toDateString(),
            'due_date'           => $this->due_date?->toDateString(),
            'subtotal'           => $this->subtotal,
            'tax_rate'           => $this->tax_rate,
            'tax_amount'         => $this->tax_amount,
            'total'              => $this->total,
            'amount_paid'        => $this->amount_paid,
            'balance_due'        => $this->balance_due,
            'status'             => $this->status,
            'currency'           => $this->currency,
            'notes'              => $this->notes,
            'created_by_name'    => $this->createdBy?->name,
            'line_items'         => $this->whenLoaded('lineItems', fn () => $this->lineItems->map(fn ($i) => [
                'id' => $i->id, 'description' => $i->description,
                'quantity' => $i->quantity, 'unit_price' => $i->unit_price, 'total' => $i->total,
            ])),
            'payments'           => $this->whenLoaded('payments', fn () => $this->payments->map(fn ($p) => [
                'id' => $p->id, 'payment_number' => $p->payment_number, 'amount' => $p->amount,
                'payment_method' => $p->payment_method, 'reference' => $p->reference,
                'paid_at' => $p->paid_at?->toDateString(), 'recorded_by' => $p->recordedBy?->name,
            ])),
            'created_at'         => $this->created_at?->toIso8601String(),
        ];
    }
}
