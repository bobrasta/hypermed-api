<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NestSyncRun extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'trigger', 'triggered_by', 'status', 'pages', 'records', 'new_count', 'new_relevant',
        'changes', 'error', 'started_at', 'finished_at',
    ];

    protected $casts = ['started_at' => 'datetime', 'finished_at' => 'datetime'];
}
