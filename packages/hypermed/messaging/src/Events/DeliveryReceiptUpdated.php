<?php

namespace Hypermed\Messaging\Events;

/** `delivered.updated` on private-messaging.conversation.{id} */
class DeliveryReceiptUpdated extends RealtimeEvent
{
    public function __construct(
        public readonly int $conversationId,
        public readonly int $userId,
        public readonly int $lastDeliveredMessageId,
    ) {}

    public function broadcastOn(): array
    {
        return [self::conversationChannel($this->conversationId)];
    }

    public function broadcastAs(): string
    {
        return 'delivered.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->conversationId,
            'user_id' => $this->userId,
            'last_delivered_message_id' => $this->lastDeliveredMessageId,
        ];
    }
}
