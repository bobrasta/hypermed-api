<?php

namespace Hypermed\Messaging\Events;

/** `conversation.removed` {conversation_id} on private-messaging.user.{id} */
class ConversationRemoved extends RealtimeEvent
{
    /** @param array<int,int> $userIds */
    public function __construct(public readonly array $userIds, public readonly int $conversationId) {}

    public function broadcastOn(): array
    {
        return self::userChannels($this->userIds);
    }

    public function broadcastAs(): string
    {
        return 'conversation.removed';
    }

    public function broadcastWith(): array
    {
        return ['conversation_id' => $this->conversationId];
    }
}
