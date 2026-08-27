<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CreditNoteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                  => $this->id,
            'credit_note_number'  => $this->credit_note_number,
            'invoice_id'          => $this->invoice_id,
            'reason'              => $this->reason,
            'amount'              => $this->amount,
            'status'              => $this->status,
            'created_by'          => $this->created_by,
            'created_by_name'     => $this->whenLoaded('createdBy', fn () => $this->createdBy?->name),
            'approved_by'         => $this->approved_by,
            'approved_by_name'    => $this->whenLoaded('approvedBy', fn () => $this->approvedBy?->name),
            'applied_at'          => $this->applied_at?->toIso8601String(),
            'created_at'          => $this->created_at?->toIso8601String(),
        ];
    }
}
