<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeaveBalance extends Model
{
    protected $fillable = ['user_id', 'leave_type_id', 'year', 'allocated_days', 'used_days'];

    protected $casts = [
        'year'           => 'integer',
        'allocated_days' => 'float',
        'used_days'      => 'float',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function leaveType()
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function getRemainingDaysAttribute(): float
    {
        return max(0, $this->allocated_days - $this->used_days);
    }
}
