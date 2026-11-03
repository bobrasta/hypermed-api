<?php

namespace Hypermed\Messaging\Contracts;

interface PresenceChecker
{
    /** Whether the user currently has a live realtime connection. */
    public function isOnline(int $userId): bool;
}
