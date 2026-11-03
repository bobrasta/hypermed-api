<?php

namespace Hypermed\Messaging\Calls\Models;

use Hypermed\Messaging\Support\MessagingConfig;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $call_id
 * @property int $user_id
 * @property string $status
 * @property Carbon|null $joined_at
 * @property Carbon|null $left_at
 */
class CallParticipant extends Model
{
    public const STATUS_INVITED = 'invited';

    public const STATUS_RINGING = 'ringing';

    public const STATUS_JOINED = 'joined';

    public const STATUS_DECLINED = 'declined';

    public const STATUS_MISSED = 'missed';

    public const STATUS_LEFT = 'left';

    /** Invitees that have not answered yet. */
    public const PENDING_STATUSES = [self::STATUS_INVITED, self::STATUS_RINGING];

    protected $table = 'msg_call_participants';

    protected $guarded = ['id'];

    protected $casts = [
        'call_id' => 'integer',
        'user_id' => 'integer',
        'joined_at' => 'datetime',
        'left_at' => 'datetime',
    ];

    public function call(): BelongsTo
    {
        return $this->belongsTo(Call::class, 'call_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(MessagingConfig::userModel(), 'user_id');
    }

    public function isPending(): bool
    {
        return in_array($this->status, self::PENDING_STATUSES, true);
    }

    public function isJoined(): bool
    {
        return $this->status === self::STATUS_JOINED;
    }
}
