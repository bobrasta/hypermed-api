<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LeaveTypeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                    => $this->id,
            'key'                   => $this->key,
            'label'                 => $this->label,
            'default_days_per_year' => $this->default_days_per_year,
            'requires_manual_days'  => $this->requires_manual_days,
            'auto_from_calendar'    => $this->auto_from_calendar,
            'deducts_balance'       => $this->deducts_balance,
            'active'                => $this->active,
        ];
    }
}
