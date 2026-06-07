<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'payment_number' => $this->payment_number,
            'invoice_id'     => $this->invoice_id,
            'amount'         => $this->amount,
            'payment_method' => $this->payment_method,
            'reference'      => $this->reference,
            'paid_at'        => $this->paid_at?->toDateString(),
            'notes'          => $this->notes,
            'recorded_by'    => $this->recordedBy?->name,
            'created_at'     => $this->created_at?->toDateTimeString(),
        ];
    }
}
