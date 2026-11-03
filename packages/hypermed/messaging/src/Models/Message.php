<?php

namespace Hypermed\Messaging\Models;

use Hypermed\Messaging\Support\MessagingConfig;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Messages are never hard-deleted by the API: `deleted_at` marks a soft delete and
 * the row is still returned (rendered as a "deleted" placeholder), so the
 * SoftDeletes global scope is intentionally NOT used.
 *
 * @property int $id
 * @property int $conversation_id
 * @property int|null $sender_id
 * @property string $type
 * @property string|null $body
 * @property array|null $meta
 * @property string $client_uuid
 * @property int|null $reply_to_id
 * @property Carbon|null $edited_at
 * @property Carbon|null $deleted_at
 * @property Carbon|null $created_at
 */
class Message extends Model
{
    public const TYPE_TEXT = 'text';

    public const TYPE_FILE = 'file';

    public const TYPE_SYSTEM = 'system';

    public const TYPE_ENTITY_REF = 'entity_ref';

    public const UPDATED_AT = null;

    protected $table = 'msg_messages';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'conversation_id' => 'integer',
            'sender_id' => 'integer',
            'reply_to_id' => 'integer',
            'meta' => 'array',
            'edited_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'conversation_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(MessagingConfig::userModel(), 'sender_id');
    }

    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'reply_to_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class, 'message_id');
    }

    /** Earlier texts of an edited message, oldest first. */
    public function revisions(): HasMany
    {
        return $this->hasMany(MessageRevision::class, 'message_id')->orderBy('id');
    }

    public function isDeleted(): bool
    {
        return $this->deleted_at !== null;
    }

    /** Relations needed to render a Message resource without lazy loading. */
    public static function resourceRelations(): array
    {
        return ['sender', 'attachments', 'replyTo.sender'];
    }
}
