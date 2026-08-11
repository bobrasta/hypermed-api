<?php

namespace App\Http\Resources;

use App\Services\BankReconciliationService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BankReconciliationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $totals = app(BankReconciliationService::class)->totals($this->resource);

        return [
            'id'          => $this->id,
            'period_from' => $this->period_from?->toDateString(),
            'period_to'   => $this->period_to?->toDateString(),
            'currency'    => $this->currency,
            'statement_closing_balance' => $this->statement_closing_balance,
            'status'      => $this->status,
            'notes'       => $this->notes,
            'created_by_name' => $this->createdBy?->name,
            'totals'      => $totals,
            'lines'       => $this->whenLoaded('lines', fn () => $this->lines->map(fn ($l) => [
                'id' => $l->id, 'txn_date' => $l->txn_date?->toDateString(), 'description' => $l->description,
                'debit' => $l->debit, 'credit' => $l->credit,
                'matched_payment_id' => $l->matched_payment_id,
                'matched_expense_id' => $l->matched_expense_id,
                'matched_vendor_bill_payment_id' => $l->matched_vendor_bill_payment_id,
                'is_matched' => $l->is_matched,
            ])),
            'created_at'  => $this->created_at?->toIso8601String(),
        ];
    }
}
