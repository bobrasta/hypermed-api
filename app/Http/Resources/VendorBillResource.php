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
            'supplier_reference' => $this->supplier_reference,
            'supplier_id'        => $this->supplier_id,
            'supplier_name'      => $this->supplier?->name,
            'purchase_order_id'  => $this->purchase_order_id,
            'purchase_order_number' => $this->purchaseOrder?->po_number,
            'category_id'        => $this->category_id,
            'category_name'      => $this->category?->name,
            'issue_date'         => $this->issue_date?->toDateString(),
            'due_date'           => $this->due_date?->toDateString(),
            // In the invoice currency; total_tzs is what was posted.
            'subtotal'           => (float) $this->subtotal,
            'tax_rate'           => $this->tax_rate,
            'tax_amount'         => (float) $this->tax_amount,
            'total'              => (float) $this->total,
            'amount_paid'        => (float) $this->amount_paid,
            'balance_due'        => $this->balance_due,
            'status'             => $this->status,
            'currency'           => $this->currency,
            'exchange_rate'      => (float) $this->exchange_rate,
            'total_tzs'          => (int) $this->total_tzs,
            'notes'              => $this->notes,
            'created_by_name'    => $this->createdBy?->name,
            'approved_by_name'   => $this->approvedBy?->name,
            'approved_at'        => $this->approved_at?->toIso8601String(),
            'line_items'         => $this->whenLoaded('lineItems', fn () => $this->lineItems->map(fn ($i) => [
                'id' => $i->id, 'description' => $i->description,
                'quantity' => $i->quantity, 'unit_price' => (float) $i->unit_price, 'total' => (float) $i->total,
            ])),
            // Payments requested/approved for this invoice, and earlier advances it used.
            'payments'           => \App\Models\SupplierPayment::with('paidFrom:id,name')
                ->where(fn ($q) => $q->where('vendor_bill_id', $this->id)
                    ->orWhereIn('id', \App\Models\CreditorAllocation::where('vendor_bill_id', $this->id)->whereNotNull('supplier_payment_id')->pluck('supplier_payment_id')))
                ->orderBy('payment_date')->get()->map(fn ($p) => [
                    'id' => $p->id, 'payment_number' => $p->payment_number, 'amount' => (float) $p->amount,
                    'currency' => $p->currency, 'exchange_rate' => (float) $p->exchange_rate, 'status' => $p->status,
                    'paid_from' => $p->paidFrom?->name, 'reference' => $p->reference,
                    'paid_at' => $p->payment_date?->toDateString(),
                    'applied' => (float) \App\Models\CreditorAllocation::where('vendor_bill_id', $this->id)->where('supplier_payment_id', $p->id)->sum('amount'),
                ]),
            'created_at'         => $this->created_at?->toIso8601String(),
        ];
    }
}
