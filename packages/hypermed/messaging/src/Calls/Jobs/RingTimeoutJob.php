<?php

namespace Hypermed\Messaging\Calls\Jobs;

use Hypermed\Messaging\Calls\Models\Call;
use Hypermed\Messaging\Calls\Services\CallService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Dispatched with a delay of `messaging-calls.ring_timeout` seconds when a call starts.
 * Marks still-ringing invitees as missed; a call nobody answered becomes "missed".
 */
class RingTimeoutJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(public int $callId)
    {
        $connection = config('messaging-calls.queue_connection');
        $queue = config('messaging-calls.queue');
        if ($connection) {
            $this->onConnection($connection);
        }
        if ($queue) {
            $this->onQueue($queue);
        }
    }

    public function handle(CallService $calls): void
    {
        $call = Call::find($this->callId);
        if (! $call || ! $call->isActive()) {
            return;
        }

        $remaining = $calls->ringSecondsRemaining($call);
        if ($remaining > 0) {
            // Ran early (e.g. a driver that ignores delays). Re-queue on real queues;
            // on the sync driver just do nothing — CallService also expires stale
            // ringing calls lazily when the conversation is touched again.
            if ($this->job && $this->job->getConnectionName() !== 'sync') {
                $this->release($remaining);
            }

            return;
        }

        $calls->ringTimeout($call->id);
    }
}
