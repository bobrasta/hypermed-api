<?php

namespace Hypermed\Messaging\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $type
 * @property string|null $title
 * @property string|null $direct_key
 * @property string|null $org_unit_key
 * @property string|null $entity_type
 * @property int|null $entity_id
 * @property int|null $last_message_id
 * @property Carbon|null $last_activity_at
 * @property int|null $created_by
 */
class Conversation extends Model
{
    public const TYPE_DIRECT = 'direct';

    public const TYPE_GROUP = 'group';

    public const TYPE_DEPARTMENT = 'department';

    public const TYPE_ENTITY = 'entity';

    protected $table = 'msg_conversations';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'entity_id' => 'integer',
            'last_message_id' => 'integer',
            'created_by' => 'integer',
            'last_activity_at' => 'datetime',
        ];
    }

    public function participants(): HasMany
    {
        return $this->hasMany(Participant::class, 'conversation_id');
    }

    public function activeParticipants(): HasMany
    {
        return $this->hasMany(Participant::class, 'conversation_id')->whereNull('left_at');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'conversation_id');
    }

    public function lastMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'last_message_id');
    }

    public function isActiveParticipant(int $userId): bool
    {
        if ($this->relationLoaded('activeParticipants')) {
            return $this->activeParticipants->contains('user_id', $userId);
        }

        return $this->activeParticipants()->where('user_id', $userId)->exists();
    }

    /** @return array<int, int> */
    public function activeParticipantIds(): array
    {
        return $this->activeParticipants()->pluck('user_id')->map(fn ($id) => (int) $id)->all();
    }

    public function isDirect(): bool
    {
        return $this->type === self::TYPE_DIRECT;
    }

    public function isGroup(): bool
    {
        return $this->type === self::TYPE_GROUP;
    }

    public static function directKey(int $a, int $b): string
    {
        return min($a, $b).':'.max($a, $b);
    }
}
