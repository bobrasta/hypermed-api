<?php

namespace Hypermed\Messaging\Calls\Jobs;

use Hypermed\Messaging\Contracts\PresenceChecker;
use Hypermed\Messaging\Contracts\PushNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * High-priority FCM data push for call signaling (queued; sockets are never queued).
 *
 * data (all strings): {kind, call_id, conversation_id, call_type, caller_id, caller_name}
 */
class SendCallPushJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /**
     * @param  list<int>  $userIds
     * @param  array<string, string>  $data
     * @param  bool  $onlyOffline  only push users without a live socket (presence)
     */
    public function __construct(public array $userIds, public array $data, public bool $onlyOffline = false)
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

    public function handle(): void
    {
        if (! app()->bound(PushNotifier::class)) {
            return;
        }

        $userIds = array_values(array_unique(array_map('intval', $this->userIds)));

        if ($this->onlyOffline && app()->bound(PresenceChecker::class)) {
            $presence = app(PresenceChecker::class);
            $userIds = array_values(array_filter($userIds, fn (int $id) => ! $presence->isOnline($id)));
        }

        if ($userIds === []) {
            return;
        }

        app(PushNotifier::class)->send($userIds, array_map('strval', $this->data));
    }
}
