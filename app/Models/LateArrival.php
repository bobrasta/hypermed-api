<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LateArrival extends Model
{
    protected $fillable = ['user_id', 'date', 'expected_time', 'reason'];

    protected $casts = [
        'date' => 'date',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
