<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayrollItem extends Model
{
    protected $fillable = [
        'payroll_run_id', 'user_id', 'base_salary', 'allowances_total',
        'overtime_amount', 'paye_amount', 'nssf_amount', 'nssf_employer_amount', 'heslb_amount',
        'other_deductions', 'gross_pay', 'net_pay', 'notes',
    ];

    public function payrollRun()
    {
        return $this->belongsTo(PayrollRun::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
