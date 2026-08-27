<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InterviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'application_id'   => $this->application_id,
            'scheduled_at'     => $this->scheduled_at?->toIso8601String(),
            'stage'            => $this->stage,
            'panel'            => $this->panel,
            'interviewer_id'   => $this->interviewer_id,
            'interviewer_name' => $this->whenLoaded('interviewer', fn () => $this->interviewer?->name),
            'notes'            => $this->notes,
            'rating'           => $this->rating,
        ];
    }
}
