<?php

namespace Hypermed\Messaging\Events;

/** `conversation.read` on the reader's own user channel (syncs their other devices). */
class ConversationRead extends RealtimeEvent
{
    public function __construct(
        public readonly int $userId,
        public readonly int $conversationId,
        public readonly int $lastReadMessageId,
        public readonly int $unreadCount,
    ) {}

    public function broadcastOn(): array
    {
        return [self::userChannel($this->userId)];
    }

    public function broadcastAs(): string
    {
        return 'conversation.read';
    }

    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->conversationId,
            'last_read_message_id' => $this->lastReadMessageId,
            'unread_count' => $this->unreadCount,
        ];
    }
}
