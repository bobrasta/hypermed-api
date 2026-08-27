<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ApplicationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'applicant_id'   => $this->applicant_id,
            'applicant_name' => $this->whenLoaded('applicant', fn () => $this->applicant?->name),
            'vacancy_id'     => $this->vacancy_id,
            'vacancy_title'  => $this->whenLoaded('vacancy', fn () => $this->vacancy?->position?->title),
            'status'         => $this->status,
            'applied_at'     => $this->applied_at?->toDateString(),
            'notes'          => $this->notes,
            'interviews'     => InterviewResource::collection($this->whenLoaded('interviews')),
            'created_at'     => $this->created_at?->toIso8601String(),
        ];
    }
}
