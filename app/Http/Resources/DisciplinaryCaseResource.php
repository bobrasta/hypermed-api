<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DisciplinaryCaseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'user_id'       => $this->user_id,
            'user_name'     => $this->whenLoaded('user', fn () => $this->user?->name),
            'stage'         => $this->stage,
            'incident_date' => $this->incident_date?->toDateString(),
            'description'   => $this->description,
            'action_taken'  => $this->action_taken,
            'raised_by'     => $this->raised_by,
            'raised_by_name'  => $this->whenLoaded('raisedBy', fn () => $this->raisedBy?->name),
            'handled_by'    => $this->handled_by,
            'handled_by_name' => $this->whenLoaded('handledBy', fn () => $this->handledBy?->name),
            'status'        => $this->status,
            'next_stage'    => $this->nextStage(),
            'notes'         => DisciplinaryCaseNoteResource::collection($this->whenLoaded('notes')),
            'created_at'    => $this->created_at?->toIso8601String(),
        ];
    }
}
