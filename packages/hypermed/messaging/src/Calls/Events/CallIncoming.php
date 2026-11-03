<?php

namespace Hypermed\Messaging\Calls\Events;

/** `call.incoming` {call} → invitees. */
class CallIncoming extends CallBroadcastEvent
{
    public function broadcastAs(): string
    {
        return 'call.incoming';
    }
}
