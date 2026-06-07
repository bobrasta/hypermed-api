<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $nameParts = explode(' ', trim($this->name));
        $initials  = collect($nameParts)->map(fn ($p) => strtoupper($p[0] ?? ''))->implode('');

        return [
            'id'           => $this->id,
            'name'         => $this->name,
            'email'        => $this->email,
            'phone'        => $this->phone,
            'role'         => $this->role,
            'group'        => $this->staff_group,
            'zone'         => $this->zone,
            'region'       => $this->region,
            'avail_status' => $this->avail_status,
            'workload'     => $this->workload ?? 0.0,
            'initials'     => $initials,
            'is_active'    => $this->is_active,
            'current_task' => $this->whenLoaded('currentTask', function () {
                return $this->currentTask
                    ? ['id' => $this->currentTask->id, 'title' => $this->currentTask->ticket_number . ' — ' . $this->currentTask->description]
                    : null;
            }, null),
        ];
    }
}
