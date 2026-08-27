<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AllowanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'contract_id'    => $this->contract_id,
            'type'           => $this->type,
            'amount'         => $this->amount,
            'recurring'      => $this->recurring,
            'effective_date' => $this->effective_date?->toDateString(),
        ];
    }
}
