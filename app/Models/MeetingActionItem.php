<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Each action item is also a Task on the owner's board; its status is the
// task's, so finishing the task is what marks the item done in the minutes.
class MeetingActionItem extends Model
{
    protected $fillable = ['meeting_id', 'description', 'owner_id', 'due_date', 'task_id'];

    protected $casts = ['due_date' => 'date'];

    public function meeting()
    {
        return $this->belongsTo(Meeting::class);
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function task()
    {
        return $this->belongsTo(Task::class);
    }

    // open | in_progress | done | overdue
    public function getStatusAttribute(): string
    {
        $taskStatus = $this->task?->effective_status;
        if ($taskStatus === 'completed') {
            return 'done';
        }
        if ($taskStatus === 'in_progress') {
            return 'in_progress';
        }
        if ($taskStatus === 'overdue' || ($this->due_date && $this->due_date->isBefore(today()))) {
            return 'overdue';
        }

        return 'open';
    }
}
