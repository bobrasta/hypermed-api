<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeaveType extends Model
{
    protected $fillable = [
        'key', 'label', 'default_days_per_year',
        'requires_manual_days', 'auto_from_calendar', 'deducts_balance', 'active',
    ];

    protected $casts = [
        'requires_manual_days' => 'boolean',
        'auto_from_calendar'   => 'boolean',
        'deducts_balance'      => 'boolean',
        'active'               => 'boolean',
        'default_days_per_year' => 'integer',
    ];

    public function leaveRequests()
    {
        return $this->hasMany(LeaveRequest::class);
    }

    public function balances()
    {
        return $this->hasMany(LeaveBalance::class);
    }
}
