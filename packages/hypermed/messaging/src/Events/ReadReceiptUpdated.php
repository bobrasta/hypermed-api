<?php

namespace Hypermed\Messaging\Events;

/** `read.updated` on private-messaging.conversation.{id} */
class ReadReceiptUpdated extends RealtimeEvent
{
    public function __construct(
        public readonly int $conversationId,
        public readonly int $userId,
        public readonly int $lastReadMessageId,
    ) {}

    public function broadcastOn(): array
    {
        return [self::conversationChannel($this->conversationId)];
    }

    public function broadcastAs(): string
    {
        return 'read.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->conversationId,
            'user_id' => $this->userId,
            'last_read_message_id' => $this->lastReadMessageId,
        ];
    }
}
