<?php

namespace Hypermed\Messaging\Calls\Events;

use Hypermed\Messaging\Calls\Models\Call;

/** `call.participant_changed` {call, user_id, status} → all call participants. */
class CallParticipantChanged extends CallBroadcastEvent
{
    public function __construct(Call $call, iterable $recipientIds, public int $userId, public string $status)
    {
        parent::__construct($call, $recipientIds);
    }

    public function broadcastAs(): string
    {
        return 'call.participant_changed';
    }

    public function broadcastWith(): array
    {
        return ['call' => $this->call, 'user_id' => $this->userId, 'status' => $this->status];
    }
}
