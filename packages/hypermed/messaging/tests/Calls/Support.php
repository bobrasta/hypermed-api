<?php

/*
 * Shared helpers for the Calls test-suite (required by each Calls test file).
 */

use Hypermed\Messaging\Calls\LiveKit\Jwt;
use Hypermed\Messaging\Models\Conversation;
use Hypermed\Messaging\Models\Participant;
use Hypermed\Messaging\Support\MessagingConfig;
use Illuminate\Support\Str;

const CALLS_TEST_LK_KEY = 'APItestkey';
const CALLS_TEST_LK_SECRET = 'test-secret-0123456789abcdef0123456789abcdef';
const CALLS_TEST_LK_URL = 'wss://livekit.test';

if (! function_exists('calls_configure')) {
    function calls_configure(): void
    {
        config([
            'messaging-calls.livekit.api_key' => CALLS_TEST_LK_KEY,
            'messaging-calls.livekit.api_secret' => CALLS_TEST_LK_SECRET,
            'messaging-calls.livekit.url' => CALLS_TEST_LK_URL,
            'messaging-calls.livekit.token_ttl' => 300,
            'messaging-calls.ring_timeout' => 45,
        ]);
    }

    function calls_user(array $attrs = [])
    {
        static $n = 0;
        $n++;
        $model = MessagingConfig::userModel();

        return $model::query()->forceCreate($attrs + [
            'name' => 'Caller '.$n,
            'email' => 'calls-'.$n.'-'.Str::random(6).'@example.test',
            'password' => 'not-a-real-hash',
        ]);
    }

    /** Create a conversation with the given users as active participants. */
    function calls_conversation(array $users, string $type = 'group'): Conversation
    {
        $ids = array_map(fn ($u) => (int) $u->getKey(), $users);
        $attrs = [
            'type' => $type,
            'title' => $type === 'group' ? 'Test group' : null,
            'created_by' => $ids[0],
        ];
        if ($type === 'direct') {
            $sorted = $ids;
            sort($sorted);
            $attrs['direct_key'] = implode(':', $sorted);
        }

        $conversation = Conversation::query()->forceCreate($attrs);

        foreach ($ids as $i => $id) {
            Participant::query()->forceCreate([
                'conversation_id' => $conversation->getKey(),
                'user_id' => $id,
                'role' => $i === 0 ? 'admin' : 'member',
                'joined_at' => now(),
            ]);
        }

        return $conversation;
    }

    function calls_jwt_claims(string $token): array
    {
        return Jwt::decode($token, CALLS_TEST_LK_SECRET);
    }

    /** Build a LiveKit-style webhook Authorization token for $body. */
    function calls_webhook_auth(string $body, string $secret = CALLS_TEST_LK_SECRET, string $key = CALLS_TEST_LK_KEY): string
    {
        return Jwt::encode([
            'iss' => $key,
            'nbf' => time(),
            'exp' => time() + 300,
            'sha256' => base64_encode(hash('sha256', $body, true)),
        ], $secret);
    }
}
