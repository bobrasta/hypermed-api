<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class ApplicantCvVersionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'version'       => $this->version,
            'original_name' => $this->original_name,
            'url'           => Storage::disk('public')->url($this->file_path),
            'uploaded_at'   => $this->uploaded_at?->toIso8601String(),
        ];
    }
}
