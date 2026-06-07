<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'type'        => $this->type,
            'title'       => $this->title,
            'body'        => $this->body,
            'entity_type' => $this->entity_type,
            'entity_id'   => $this->entity_id,
            'is_read'     => $this->is_read,
            'created_at'  => $this->created_at?->toIso8601String(),
        ];
    }
}
