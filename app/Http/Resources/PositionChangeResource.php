<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PositionChangeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->id,
            'user_id'           => $this->user_id,
            'from_position_id'  => $this->from_position_id,
            'from_position_title' => $this->whenLoaded('fromPosition', fn () => $this->fromPosition?->title),
            'to_position_id'    => $this->to_position_id,
            'to_position_title' => $this->whenLoaded('toPosition', fn () => $this->toPosition?->title),
            'change_type'       => $this->change_type,
            'effective_date'    => $this->effective_date?->toDateString(),
            'reason'            => $this->reason,
            'approved_by'       => $this->approved_by,
            'approved_by_name'  => $this->whenLoaded('approvedBy', fn () => $this->approvedBy?->name),
            'created_at'        => $this->created_at?->toIso8601String(),
        ];
    }
}
