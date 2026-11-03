<?php

namespace Hypermed\Messaging\Events;

/** `message.sent` on private-messaging.conversation.{id}: {message: Message} */
class MessageSent extends RealtimeEvent
{
    /** @param array<string, mixed> $message MessageResource array */
    public function __construct(public readonly int $conversationId, public readonly array $message) {}

    public function broadcastOn(): array
    {
        return [self::conversationChannel($this->conversationId)];
    }

    public function broadcastAs(): string
    {
        return 'message.sent';
    }

    public function broadcastWith(): array
    {
        return ['message' => $this->message];
    }
}
