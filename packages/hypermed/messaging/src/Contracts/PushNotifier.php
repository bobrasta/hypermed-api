<?php

namespace Hypermed\Messaging\Contracts;

interface PushNotifier
{
    /**
     * Send a high-priority DATA message to every registered device of the users.
     *
     * @param  array<int, int>  $userIds
     * @param  array<string, string>  $data  flat string map
     */
    public function send(array $userIds, array $data): void;
}
