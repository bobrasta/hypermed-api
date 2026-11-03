<?php

namespace Hypermed\Messaging\Calls\Events;

/** `call.cancelled` {call} → all call participants. */
class CallCancelled extends CallBroadcastEvent
{
    public function broadcastAs(): string
    {
        return 'call.cancelled';
    }
}
