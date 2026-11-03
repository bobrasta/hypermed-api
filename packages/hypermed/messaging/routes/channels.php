<?php

use Hypermed\Messaging\Models\Participant;
use Hypermed\Messaging\Support\UserSummary;
use Illuminate\Support\Facades\Broadcast;

/*
| Channel authorization for hypermed/messaging. Loaded by MessagingServiceProvider.
| The host must expose the broadcast auth endpoint (e.g. POST /broadcasting/auth
| behind auth:sanctum).
*/

// private-messaging.user.{userId}: only the user themself.
Broadcast::channel('messaging.user.{userId}', function ($user, $userId) {
    return (int) $user->getAuthIdentifier() === (int) $userId;
});

// private-messaging.conversation.{id}: active participants only.
Broadcast::channel('messaging.conversation.{conversationId}', function ($user, $conversationId) {
    return Participant::query()
        ->where('conversation_id', (int) $conversationId)
        ->where('user_id', (int) $user->getAuthIdentifier())
        ->whereNull('left_at')
        ->exists();
});

// presence-messaging.presence: every authenticated user; member info {id, name}.
Broadcast::channel(config('messaging.presence.channel', 'messaging.presence'), function ($user) {
    $summary = UserSummary::make($user);

    return ['id' => $summary['id'], 'name' => $summary['name']];
});
