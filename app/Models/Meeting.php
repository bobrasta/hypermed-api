<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Meeting extends Model
{
    public const STATUSES = ['scheduled', 'held', 'minutes_final', 'circulated', 'cancelled'];

    // Suggested categories; free text is allowed (a new kind of meeting
    // shouldn't need a release).
    public const CATEGORIES = ['management', 'department', 'staff', 'board', 'client', 'supplier', 'general'];

    protected $fillable = [
        'title', 'category', 'department_id', 'starts_at', 'ends_at', 'location', 'online_link',
        'chaired_by', 'recorded_by', 'agenda', 'minutes', 'decisions', 'status', 'circulated_at', 'created_by',
    ];

    protected $casts = [
        'starts_at'     => 'datetime',
        'ends_at'       => 'datetime',
        'circulated_at' => 'datetime',
    ];

    public function attendees()
    {
        return $this->hasMany(MeetingAttendee::class);
    }

    public function actionItems()
    {
        return $this->hasMany(MeetingActionItem::class);
    }

    public function attachments()
    {
        return $this->hasMany(MeetingAttachment::class);
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function chair()
    {
        return $this->belongsTo(User::class, 'chaired_by');
    }

    public function secretary()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // Meetings $user may open: all of them with meetings.manage, otherwise
    // the ones they attend (or chair).
    public function scopeVisibleTo(Builder $query, User $user, bool $canManage): Builder
    {
        if ($canManage) {
            return $query;
        }

        return $query->where(fn ($q) => $q
            ->where('chaired_by', $user->id)
            ->orWhereHas('attendees', fn ($a) => $a->where('user_id', $user->id)));
    }

    public function isAttendee(User $user): bool
    {
        return $this->chaired_by === $user->id
            || $this->attendees()->where('user_id', $user->id)->exists();
    }
}
