<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class CategoryResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'          => $this->id,
            'name'        => $this->name,
            'slug'        => $this->slug,
            'parent_id'   => $this->parent_id,
            'description' => $this->description,
            'is_active'   => $this->is_active,
            'items_count' => $this->whenCounted('items'),
            'created_at'  => $this->created_at?->toDateString(),
        ];
    }
}
