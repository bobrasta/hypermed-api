<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PerDiemEditGrantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'scope'           => $this->scope,
            'granted_by'      => $this->granted_by,
            'granted_by_name' => $this->grantedBy?->name,
            'expires_at'      => $this->expires_at?->toIso8601String(),
            'revoked_at'      => $this->revoked_at?->toIso8601String(),
            'is_active'       => $this->isActive(),
            'created_at'      => $this->created_at?->toIso8601String(),
        ];
    }
}
