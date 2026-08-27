<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ApplicantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'name'           => $this->name,
            'phone'          => $this->phone,
            'email'          => $this->email,
            'cover_letter'   => $this->cover_letter,
            'source_channel' => $this->source_channel,
            'talent_pool'    => $this->talent_pool,
            'skills_tags'    => $this->skills_tags,
            'notes'          => $this->notes,
            'latest_cv'      => new ApplicantCvVersionResource($this->whenLoaded('latestCv')),
            'cv_versions'    => ApplicantCvVersionResource::collection($this->whenLoaded('cvVersions')),
            'applications'   => ApplicationResource::collection($this->whenLoaded('applications')),
            'created_at'     => $this->created_at?->toIso8601String(),
        ];
    }
}
