<?php

namespace Hypermed\Messaging\Presence;

use Hypermed\Messaging\Contracts\PresenceChecker;

/** Everyone is considered offline (push is always attempted). */
class NullPresenceChecker implements PresenceChecker
{
    public function isOnline(int $userId): bool
    {
        return false;
    }
}
