<?php

namespace Hypermed\Messaging\Presence;

use Hypermed\Messaging\Contracts\PresenceChecker;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Cache;

/**
 * Asks the Pusher-compatible HTTP API (Laravel Reverb / Pusher) for the members
 * of the presence channel. The member list is cached for a few seconds so a
 * push fan-out costs at most one HTTP call. On any error everyone counts as
 * offline (better a duplicate notification than a missed one).
 */
class BroadcasterPresenceChecker implements PresenceChecker
{
    public function isOnline(int $userId): bool
    {
        return in_array($userId, $this->onlineUserIds(), true);
    }

    /** @return array<int, int> */
    public function onlineUserIds(): array
    {
        $ttl = (int) config('messaging.presence.cache_seconds', 5);

        return Cache::remember('messaging:presence:members', $ttl, fn () => $this->fetch());
    }

    /** @return array<int, int> */
    protected function fetch(): array
    {
        try {
            $driver = Broadcast::driver();
            if (! method_exists($driver, 'getPusher')) {
                return [];
            }
            $channel = 'presence-'.config('messaging.presence.channel', 'messaging.presence');
            $result = $driver->getPusher()->get('/channels/'.$channel.'/users', [], true);

            return array_values(array_map(fn ($u) => (int) $u['id'], (array) ($result['users'] ?? [])));
        } catch (\Throwable $e) {
            // Reverb/Pusher answer 404 when the presence channel has no members.
            if ((int) $e->getCode() !== 404) {
                report($e);
            }

            return [];
        }
    }
}
