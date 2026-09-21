<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PerDiemAdjustmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'amount'           => $this->amount,
            'reason'           => $this->reason,
            'created_by'       => $this->created_by,
            'created_by_name'  => $this->createdBy?->name,
            'created_at'       => $this->created_at?->toIso8601String(),
        ];
    }
}
