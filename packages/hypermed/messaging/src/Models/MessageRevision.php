<?php

namespace Hypermed\Messaging\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The text a message had before an edit. Written on every edit so the
 * history survives; only shown through chat review.
 *
 * @property int $id
 * @property int $message_id
 * @property string|null $body
 * @property Carbon|null $created_at  when it was replaced
 */
class MessageRevision extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'msg_message_revisions';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['message_id' => 'integer'];
    }
}
