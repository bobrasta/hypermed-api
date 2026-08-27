<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PositionChange extends Model
{
    protected $fillable = [
        'user_id', 'from_position_id', 'to_position_id', 'change_type',
        'effective_date', 'reason', 'approved_by',
    ];

    protected $casts = [
        'effective_date' => 'date',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function fromPosition()
    {
        return $this->belongsTo(Position::class, 'from_position_id');
    }

    public function toPosition()
    {
        return $this->belongsTo(Position::class, 'to_position_id');
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
