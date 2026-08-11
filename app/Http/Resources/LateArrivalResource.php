<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LateArrivalResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'user_id'        => $this->user_id,
            'user_name'      => $this->user?->name,
            'date'           => $this->date?->toDateString(),
            'expected_time'  => $this->expected_time,
            'reason'         => $this->reason,
            'created_at'     => $this->created_at?->toIso8601String(),
        ];
    }
}
