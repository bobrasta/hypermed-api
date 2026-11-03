<?php

namespace Hypermed\Messaging\Models;

use Hypermed\Messaging\Support\MessagingConfig;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $conversation_id
 * @property int $user_id
 * @property string $role
 * @property int|null $last_read_message_id
 * @property int|null $last_delivered_message_id
 * @property Carbon|null $muted_until
 * @property Carbon|null $joined_at
 * @property Carbon|null $left_at
 */
class Participant extends Model
{
    public const ROLE_MEMBER = 'member';

    public const ROLE_ADMIN = 'admin';

    protected $table = 'msg_participants';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'conversation_id' => 'integer',
            'user_id' => 'integer',
            'last_read_message_id' => 'integer',
            'last_delivered_message_id' => 'integer',
            'muted_until' => 'datetime',
            'joined_at' => 'datetime',
            'left_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'conversation_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(MessagingConfig::userModel(), 'user_id');
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function isMuted(): bool
    {
        return $this->muted_until !== null && $this->muted_until->isFuture();
    }
}
