<?php

namespace Hypermed\Messaging\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * Base for every messaging socket event: broadcast synchronously (never via the
 * default queue) and only after the surrounding DB transaction commits.
 * Payloads are computed eagerly as plain arrays at construction time.
 */
abstract class RealtimeEvent implements ShouldBroadcastNow, ShouldDispatchAfterCommit
{
    abstract public function broadcastAs(): string;

    abstract public function broadcastWith(): array;

    public static function userChannel(int $userId): PrivateChannel
    {
        return new PrivateChannel('messaging.user.'.$userId);
    }

    public static function conversationChannel(int $conversationId): PrivateChannel
    {
        return new PrivateChannel('messaging.conversation.'.$conversationId);
    }

    /** @param array<int,int> $userIds @return array<int, PrivateChannel> */
    protected static function userChannels(array $userIds): array
    {
        return array_values(array_map(fn ($id) => self::userChannel((int) $id), array_unique($userIds)));
    }
}
