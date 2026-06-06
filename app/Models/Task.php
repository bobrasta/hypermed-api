<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Task extends Model
{
    protected $fillable = [
        'title', 'description', 'category', 'task_type',
        'priority', 'status', 'assigned_to', 'created_by',
        'due_date', 'started_at', 'completed_at',
    ];

    protected $casts = [
        'due_date'     => 'datetime',
        'started_at'   => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // Scope: auto-compute overdue without persisting the status change,
    // so the original record stays clean and can be re-evaluated later.
    public function getEffectiveStatusAttribute(): string
    {
        if (
            $this->status === 'assigned'
            && $this->due_date
            && $this->due_date->isPast()
        ) {
            return 'overdue';
        }

        return $this->status;
    }
}
