<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ContactResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                  => $this->id,
            'first_name'          => $this->first_name,
            'last_name'           => $this->last_name,
            'job_title'           => $this->job_title,
            'department'          => $this->department,
            'email'               => $this->email,
            'phone'               => $this->phone,
            'whatsapp'            => $this->whatsapp,
            'hospital_id'         => $this->hospital_id,
            'hospital'            => new HospitalResource($this->whenLoaded('hospital')),
            'tags'                => $this->whenLoaded('tags', fn () => $this->tags->pluck('tag')),
            'interactions'        => ContactInteractionResource::collection($this->whenLoaded('interactions')),
            'last_contacted_at'   => $this->last_contacted_at?->toIso8601String(),
            'next_followup_at'    => $this->next_followup_at?->toIso8601String(),
        ];
    }
}
