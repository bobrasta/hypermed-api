<?php

namespace Hypermed\Messaging\Calls\Events;

use Hypermed\Messaging\Calls\Http\Resources\CallResource;
use Hypermed\Messaging\Calls\Models\Call;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Base class for call signaling events. Broadcast synchronously (never via the queue)
 * and only after the surrounding DB transaction commits, on each recipient's
 * private `messaging.user.{id}` channel.
 *
 * The call payload is snapshotted at construction time so every event carries the
 * state of the call at the moment it happened.
 */
abstract class CallBroadcastEvent implements ShouldBroadcastNow, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets;

    /** @var array<string, mixed> */
    public array $call;

    /** @var list<int> */
    public array $recipientIds;

    /**
     * @param  iterable<int>  $recipientIds
     */
    public function __construct(Call $call, iterable $recipientIds)
    {
        $this->call = CallResource::make($call)->resolve();
        $this->recipientIds = array_values(array_unique(array_map('intval', is_array($recipientIds) ? $recipientIds : iterator_to_array($recipientIds))));
    }

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return array_map(fn (int $id) => new PrivateChannel('messaging.user.'.$id), $this->recipientIds);
    }

    public function broadcastWhen(): bool
    {
        return $this->recipientIds !== [];
    }

    abstract public function broadcastAs(): string;

    public function broadcastWith(): array
    {
        return ['call' => $this->call];
    }
}
