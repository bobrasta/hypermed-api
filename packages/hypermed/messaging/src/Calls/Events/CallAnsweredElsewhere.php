<?php

namespace Hypermed\Messaging\Calls\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * `call.answered_elsewhere` {call_id} → the answering user's own channel, so their other
 * devices stop ringing. The socket that made the request (X-Socket-ID) is excluded.
 */
class CallAnsweredElsewhere implements ShouldBroadcastNow, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public int $callId, public int $userId)
    {
        $this->dontBroadcastToCurrentUser();
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('messaging.user.'.$this->userId)];
    }

    public function broadcastAs(): string
    {
        return 'call.answered_elsewhere';
    }

    public function broadcastWith(): array
    {
        return ['call_id' => $this->callId];
    }
}
