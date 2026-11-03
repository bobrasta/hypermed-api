<?php

namespace Hypermed\Messaging\Calls\LiveKit;

use InvalidArgumentException;

/**
 * Verifies LiveKit webhook requests.
 *
 * LiveKit sends `Authorization: <jwt>` where the JWT is HS256-signed with the
 * API secret, `iss` = API key, and claim `sha256` = base64(sha256(raw body)).
 */
class WebhookVerifier
{
    public function __construct(
        protected string $apiKey,
        protected string $apiSecret,
        protected int $leeway = 60,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            (string) config('messaging-calls.livekit.api_key', ''),
            (string) config('messaging-calls.livekit.api_secret', ''),
            (int) config('messaging-calls.livekit.webhook_leeway', 60),
        );
    }

    /**
     * Returns the decoded event payload, or throws when the request is not authentic.
     *
     * @throws InvalidArgumentException
     */
    public function verify(string $body, ?string $authorization): array
    {
        if ($this->apiKey === '' || $this->apiSecret === '') {
            throw new InvalidArgumentException('LiveKit credentials are not configured.');
        }

        $token = trim((string) $authorization);
        if (stripos($token, 'Bearer ') === 0) {
            $token = trim(substr($token, 7));
        }
        if ($token === '') {
            throw new InvalidArgumentException('Missing authorization token.');
        }

        $claims = Jwt::decode($token, $this->apiSecret);

        if (($claims['iss'] ?? null) !== $this->apiKey) {
            throw new InvalidArgumentException('Unexpected token issuer.');
        }

        $now = time();
        if (isset($claims['exp']) && (int) $claims['exp'] + $this->leeway < $now) {
            throw new InvalidArgumentException('Token expired.');
        }
        if (isset($claims['nbf']) && (int) $claims['nbf'] - $this->leeway > $now) {
            throw new InvalidArgumentException('Token not yet valid.');
        }

        $expectedHash = base64_encode(hash('sha256', $body, true));
        if (! is_string($claims['sha256'] ?? null) || ! hash_equals($expectedHash, $claims['sha256'])) {
            throw new InvalidArgumentException('Body hash mismatch.');
        }

        $payload = json_decode($body, true);
        if (! is_array($payload)) {
            throw new InvalidArgumentException('Malformed webhook body.');
        }

        return $payload;
    }
}
