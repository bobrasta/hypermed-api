<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PayrollItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->id,
            'payroll_run_id'    => $this->payroll_run_id,
            'user_id'           => $this->user_id,
            'user_name'         => $this->whenLoaded('user', fn () => $this->user?->name),
            'base_salary'       => $this->base_salary,
            'allowances_total'  => $this->allowances_total,
            'overtime_amount'   => $this->overtime_amount,
            'paye_amount'       => $this->paye_amount,
            'nssf_amount'       => $this->nssf_amount,
            'heslb_amount'      => $this->heslb_amount,
            'other_deductions'  => $this->other_deductions,
            'gross_pay'         => $this->gross_pay,
            'net_pay'           => $this->net_pay,
            'notes'             => $this->notes,
        ];
    }
}
