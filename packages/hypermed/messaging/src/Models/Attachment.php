<?php

namespace Hypermed\Messaging\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $message_id
 * @property string $disk
 * @property string $path
 * @property string $mime
 * @property int $size
 * @property string $original_name
 * @property string|null $thumb_path
 */
class Attachment extends Model
{
    protected $table = 'msg_attachments';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'message_id' => 'integer',
            'size' => 'integer',
        ];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'message_id');
    }

    public function isImage(): bool
    {
        return str_starts_with((string) $this->mime, 'image/');
    }
}
