<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PerDiemRevisionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'edited_by'      => $this->edited_by,
            'edited_by_name' => $this->editor?->name,
            'editor_role'    => $this->editor_role,
            'status'         => $this->status,
            'reason'         => $this->reason,
            'before'         => $this->before,
            'after'          => $this->after,
            'reviewed_by'      => $this->reviewed_by,
            'reviewed_by_name' => $this->reviewer?->name,
            'reviewed_at'      => $this->reviewed_at?->toIso8601String(),
            'created_at'     => $this->created_at?->toIso8601String(),
        ];
    }
}
