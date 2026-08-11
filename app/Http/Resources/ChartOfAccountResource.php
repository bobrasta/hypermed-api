<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChartOfAccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'code'          => $this->code,
            'name'          => $this->name,
            'category_id'   => $this->category_id,
            'category_type' => $this->category?->type,
            'currency'      => $this->currency,
            'balance'       => $this->balance,
            'status'        => $this->status,
            'created_at'    => $this->created_at?->toIso8601String(),
        ];
    }
}
