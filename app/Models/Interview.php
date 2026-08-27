<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Interview extends Model
{
    protected $fillable = [
        'application_id', 'scheduled_at', 'stage', 'panel',
        'interviewer_id', 'notes', 'rating',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'rating'       => 'integer',
    ];

    public function application()
    {
        return $this->belongsTo(Application::class);
    }

    public function interviewer()
    {
        return $this->belongsTo(User::class, 'interviewer_id');
    }
}
