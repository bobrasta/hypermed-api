<?php

namespace Hypermed\Messaging\Calls\Events;

use Hypermed\Messaging\Calls\Models\Call;

/** `call.declined` {call, user_id} → all call participants. */
class CallDeclined extends CallBroadcastEvent
{
    public function __construct(Call $call, iterable $recipientIds, public int $userId)
    {
        parent::__construct($call, $recipientIds);
    }

    public function broadcastAs(): string
    {
        return 'call.declined';
    }

    public function broadcastWith(): array
    {
        return ['call' => $this->call, 'user_id' => $this->userId];
    }
}
