<?php

namespace Hypermed\Messaging\Events;

/** `message.deleted` on private-messaging.conversation.{id}: {message: Message} */
class MessageDeleted extends RealtimeEvent
{
    /** @param array<string, mixed> $message MessageResource array */
    public function __construct(public readonly int $conversationId, public readonly array $message) {}

    public function broadcastOn(): array
    {
        return [self::conversationChannel($this->conversationId)];
    }

    public function broadcastAs(): string
    {
        return 'message.deleted';
    }

    public function broadcastWith(): array
    {
        return ['message' => $this->message];
    }
}
