<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ContactInteractionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'type'             => $this->type,
            'summary'          => $this->summary,
            'outcome'          => $this->outcome,
            'next_action'      => $this->next_action,
            'next_action_date' => $this->next_action_date?->toDateString(),
            'created_at'       => $this->created_at?->toIso8601String(),
        ];
    }
}
