<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VacancyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'position_id'     => $this->position_id,
            'position_title'  => $this->whenLoaded('position', fn () => $this->position?->title),
            'department'      => $this->whenLoaded('position', fn () => $this->position?->department),
            'requirements'    => $this->requirements,
            'status'          => $this->status,
            'opened_at'       => $this->opened_at?->toDateString(),
            'closed_at'       => $this->closed_at?->toDateString(),
            'applications_count' => $this->whenCounted('applications'),
            'created_at'      => $this->created_at?->toIso8601String(),
        ];
    }
}
