<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PayrollRunResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->id,
            'period_month'      => $this->period_month,
            'period_year'       => $this->period_year,
            'status'            => $this->status,
            'gross_total'       => $this->gross_total,
            'deductions_total'  => $this->deductions_total,
            'net_total'         => $this->net_total,
            'items_count'       => $this->whenCounted('items'),
            'items'             => PayrollItemResource::collection($this->whenLoaded('items')),
            'created_by_name'   => $this->whenLoaded('createdBy', fn () => $this->createdBy?->name),
            'approved_by_name'  => $this->whenLoaded('approvedBy', fn () => $this->approvedBy?->name),
            'approved_at'       => $this->approved_at?->toIso8601String(),
            'paid_at'           => $this->paid_at?->toIso8601String(),
            'expense_id'        => $this->expense_id,
            'created_at'        => $this->created_at?->toIso8601String(),
        ];
    }
}
