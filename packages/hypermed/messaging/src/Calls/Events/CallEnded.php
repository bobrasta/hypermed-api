<?php

namespace Hypermed\Messaging\Calls\Events;

/** `call.ended` {call} → all call participants (any terminal status). */
class CallEnded extends CallBroadcastEvent
{
    public function broadcastAs(): string
    {
        return 'call.ended';
    }
}
