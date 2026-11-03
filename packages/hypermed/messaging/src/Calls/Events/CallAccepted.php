<?php

namespace Hypermed\Messaging\Calls\Events;

use Hypermed\Messaging\Calls\Models\Call;

/** `call.accepted` {call, user_id} → all call participants. */
class CallAccepted extends CallBroadcastEvent
{
    public function __construct(Call $call, iterable $recipientIds, public int $userId)
    {
        parent::__construct($call, $recipientIds);
    }

    public function broadcastAs(): string
    {
        return 'call.accepted';
    }

    public function broadcastWith(): array
    {
        return ['call' => $this->call, 'user_id' => $this->userId];
    }
}
