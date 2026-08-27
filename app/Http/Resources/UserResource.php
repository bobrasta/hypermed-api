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
            'manager_id'          => $this->manager_id,
            'position_id'         => $this->position_id,
            'position_title'      => $this->whenLoaded('position', fn () => $this->position?->title),
            'gender'              => $this->gender,
            'hire_date'           => $this->hire_date?->toDateString(),
            'next_of_kin_name'         => $this->next_of_kin_name,
            'next_of_kin_phone'        => $this->next_of_kin_phone,
            'next_of_kin_relationship' => $this->next_of_kin_relationship,
            'nssf_number'  => $this->nssf_number,
            'tin_number'   => $this->tin_number,
            'nida_number'  => $this->nida_number,
            'biometric_id' => $this->biometric_id,
            'current_task' => $this->whenLoaded('currentTask', function () {
                return $this->currentTask
                    ? ['id' => $this->currentTask->id, 'title' => $this->currentTask->ticket_number . ' — ' . $this->currentTask->description]
                    : null;
            }, null),
        ];
    }
}
