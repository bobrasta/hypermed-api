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
            'max_discount_percent' => $this->max_discount_percent,
            'commission_percent'   => $this->commission_percent,
            'manager_id'          => $this->manager_id,
            'manager_name'        => $this->whenLoaded('manager', fn () => $this->manager?->name),
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
            // Section 15.7: always a self-view (auth/me), so the full
            // account number is fine here — masking only applies when
            // someone ELSE views a plan's payment_snapshot (see
            // PerDiemRequestResource::paymentSnapshotForViewer()).
            'payment_profile' => $this->whenLoaded('paymentProfile', fn () => $this->paymentProfile ? [
                'provider'       => $this->paymentProfile->provider,
                'account_number' => $this->paymentProfile->account_number,
                'account_name'   => $this->paymentProfile->account_name,
            ] : null),
            'current_task' => $this->whenLoaded('currentTask', function () {
                return $this->currentTask
                    ? ['id' => $this->currentTask->id, 'title' => $this->currentTask->ticket_number . ' — ' . $this->currentTask->description]
                    : null;
            }, null),
        ];
    }
}
