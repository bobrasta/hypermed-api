<?php

namespace Hypermed\Messaging\Calls\LiveKit;

use Hypermed\Messaging\Calls\Models\Call;

/**
 * Issues LiveKit access tokens (HS256 JWT signed with the API secret).
 *
 * Grants are scoped to a single room (call_{id}); no admin/create/list/record grants.
 */
class LiveKitTokenIssuer
{
    public function __construct(
        protected string $apiKey,
        protected string $apiSecret,
        protected string $url,
        protected int $ttl = 300,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            (string) config('messaging-calls.livekit.api_key', ''),
            (string) config('messaging-calls.livekit.api_secret', ''),
            (string) config('messaging-calls.livekit.url', ''),
            max(30, (int) config('messaging-calls.livekit.token_ttl', 300)),
        );
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '' && $this->apiSecret !== '' && $this->url !== '';
    }

    /**
     * @return array{url: string, token: string, room: string}
     */
    public function forCall(Call $call, int $userId, string $displayName): array
    {
        $room = $call->roomName();

        return [
            'url' => $this->url,
            'token' => $this->issue($room, (string) $userId, $displayName),
            'room' => $room,
        ];
    }

    public function issue(string $room, string $identity, string $name): string
    {
        $now = time();

        $claims = [
            'iss' => $this->apiKey,
            'sub' => $identity,
            'name' => $name,
            'nbf' => $now,
            'exp' => $now + $this->ttl,
            'jti' => $identity.'-'.bin2hex(random_bytes(8)),
            'video' => [
                'room' => $room,
                'roomJoin' => true,
                'canPublish' => true,
                'canSubscribe' => true,
                'canPublishData' => true,
            ],
        ];

        return Jwt::encode($claims, $this->apiSecret);
    }
}
