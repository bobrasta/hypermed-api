<?php

namespace Hypermed\Messaging\Events;

/**
 * `message.created` on private-messaging.user.{id} of every active participant
 * (lightweight payload for conversation list / unread badge updates).
 */
class UserMessageCreated extends RealtimeEvent
{
    /**
     * @param  array<int,int>  $userIds
     * @param  array{conversation_id:int, message_id:int, sender_id:?int, type:string, preview:string, client_uuid:string, created_at:?string}  $payload
     */
    public function __construct(public readonly array $userIds, public readonly array $payload) {}

    public function broadcastOn(): array
    {
        return self::userChannels($this->userIds);
    }

    public function broadcastAs(): string
    {
        return 'message.created';
    }

    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
