<?php

namespace Hypermed\Messaging\Calls\Models;

use Hypermed\Messaging\Models\Conversation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $conversation_id
 * @property int $initiated_by
 * @property string $type
 * @property string $status
 * @property Carbon|null $started_at
 * @property Carbon|null $answered_at
 * @property Carbon|null $ended_at
 * @property string|null $end_reason
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Call extends Model
{
    public const TYPE_AUDIO = 'audio';

    public const TYPE_VIDEO = 'video';

    public const TYPES = [self::TYPE_AUDIO, self::TYPE_VIDEO];

    public const STATUS_RINGING = 'ringing';

    public const STATUS_ONGOING = 'ongoing';

    public const STATUS_ENDED = 'ended';

    public const STATUS_MISSED = 'missed';

    public const STATUS_DECLINED = 'declined';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_FAILED = 'failed';

    public const ACTIVE_STATUSES = [self::STATUS_RINGING, self::STATUS_ONGOING];

    protected $table = 'msg_calls';

    protected $guarded = ['id'];

    protected $casts = [
        'conversation_id' => 'integer',
        'initiated_by' => 'integer',
        'started_at' => 'datetime',
        'answered_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'conversation_id');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(CallParticipant::class, 'call_id')->orderBy('id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', self::ACTIVE_STATUSES);
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }

    public function isRinging(): bool
    {
        return $this->status === self::STATUS_RINGING;
    }

    public function roomName(): string
    {
        return 'call_'.$this->id;
    }

    public function participantFor(int $userId): ?CallParticipant
    {
        return $this->participants->firstWhere('user_id', $userId);
    }

    /** Seconds the call was connected (answer → end); 0 when never answered. */
    public function durationSeconds(): int
    {
        if (! $this->answered_at) {
            return 0;
        }

        $end = $this->ended_at ?? now();

        return max(0, (int) $this->answered_at->diffInSeconds($end, true));
    }

    public static function roomToId(string $room): ?int
    {
        return preg_match('/^call_(\d+)$/', $room, $m) ? (int) $m[1] : null;
    }
}
