<?php

namespace Hypermed\Messaging\Support;

use Hypermed\Messaging\Models\Message;

final class MessagePreview
{
    public static function for(Message $message): string
    {
        $limit = MessagingConfig::previewLength();

        if ($message->isDeleted()) {
            return 'Message deleted';
        }

        $text = match ($message->type) {
            Message::TYPE_FILE => self::fileText($message),
            Message::TYPE_ENTITY_REF => (string) ($message->body ?: 'Shared a link'),
            default => (string) $message->body,
        };

        return self::truncate(trim(preg_replace('/\s+/u', ' ', $text) ?? ''), $limit);
    }

    /** Truncate to at most $limit characters (ellipsis included). */
    public static function truncate(string $text, ?int $limit = null): string
    {
        $limit ??= MessagingConfig::previewLength();

        return mb_strlen($text) > $limit ? mb_substr($text, 0, $limit - 1).'…' : $text;
    }

    private static function fileText(Message $message): string
    {
        if ($message->body) {
            return (string) $message->body;
        }
        $name = $message->relationLoaded('attachments') ? $message->getRelation('attachments')->first()?->original_name : null;

        return $name ? 'File: '.$name : 'File';
    }
}
