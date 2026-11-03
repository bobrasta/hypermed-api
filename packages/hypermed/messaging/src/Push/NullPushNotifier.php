<?php

namespace Hypermed\Messaging\Push;

use Hypermed\Messaging\Contracts\PushNotifier;

/** Discards pushes (harness/dev). Keeps the last calls for inspection in tests. */
class NullPushNotifier implements PushNotifier
{
    /** @var array<int, array{user_ids: array<int,int>, data: array<string,string>}> */
    public array $sent = [];

    public function send(array $userIds, array $data): void
    {
        $this->sent[] = ['user_ids' => array_values($userIds), 'data' => $data];
    }
}
