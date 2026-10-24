<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Delegation extends Model
{
    protected $fillable = [
        'delegator_id', 'delegate_id', 'standby_for_id', 'reason', 'starts_at', 'ends_at', 'revoked_at', 'revoked_by',
    ];

    protected $casts = [
        'starts_at'  => 'datetime',
        'ends_at'    => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function delegator()
    {
        return $this->belongsTo(User::class, 'delegator_id');
    }

    public function delegate()
    {
        return $this->belongsTo(User::class, 'delegate_id');
    }

    /** Only takes effect while this person is not around (Gabriel stands by for Sharif). */
    public function standbyFor()
    {
        return $this->belongsTo(User::class, 'standby_for_id');
    }

    /** "Not around" = on approved leave covering today — a record, not a guess. */
    public static function isAway(int $userId): bool
    {
        return LeaveRequest::where('user_id', $userId)->where('status', 'approved')
            ->whereDate('start_date', '<=', today())->whereDate('end_date', '>=', today())->exists();
    }

    /** In its dates, not revoked, and — when standing by — the other person is away. */
    public function isEffective(): bool
    {
        return $this->revoked_at === null && $this->starts_at <= now() && $this->ends_at >= now()
            && ($this->standby_for_id === null || self::isAway($this->standby_for_id));
    }

    public function revokedBy()
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at')
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>=', now());
    }
}
