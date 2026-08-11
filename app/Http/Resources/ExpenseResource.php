<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExpenseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'name'          => $this->name,
            'category_id'   => $this->category_id,
            'category_name' => $this->category?->name,
            'amount'        => $this->amount,
            'tax_rate'      => $this->tax_rate,
            'tax_amount'    => $this->tax_amount,
            'gross_amount'  => $this->gross_amount,
            'payment_mode'  => $this->payment_mode,
            'expense_date'  => $this->expense_date?->toDateString(),
            'reference'     => $this->reference,
            'notes'         => $this->notes,
            'created_by_name' => $this->createdBy?->name,
            'created_at'    => $this->created_at?->toIso8601String(),
        ];
    }
}
