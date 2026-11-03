<?php

namespace Hypermed\Messaging\Calls\LiveKit;

use InvalidArgumentException;

/**
 * Minimal HS256 JWT encode/decode (no external dependency, see docs/DECISIONS.md).
 */
final class Jwt
{
    public static function encode(array $claims, string $secret): string
    {
        if ($secret === '') {
            throw new InvalidArgumentException('JWT signing secret is empty.');
        }

        $header = self::b64url(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $payload = self::b64url(json_encode($claims, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $signature = self::b64url(hash_hmac('sha256', $header.'.'.$payload, $secret, true));

        return $header.'.'.$payload.'.'.$signature;
    }

    /**
     * Verifies the HS256 signature and returns the claims.
     * Does NOT check time-based claims; callers do that.
     *
     * @throws InvalidArgumentException on malformed token / bad signature / wrong alg
     */
    public static function decode(string $token, string $secret): array
    {
        if ($secret === '') {
            throw new InvalidArgumentException('JWT secret is empty.');
        }

        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new InvalidArgumentException('Malformed JWT.');
        }

        [$h, $p, $s] = $parts;
        $header = json_decode(self::b64urlDecode($h), true);
        if (! is_array($header) || ($header['alg'] ?? null) !== 'HS256') {
            throw new InvalidArgumentException('Unsupported JWT algorithm.');
        }

        $expected = hash_hmac('sha256', $h.'.'.$p, $secret, true);
        if (! hash_equals($expected, self::b64urlDecode($s))) {
            throw new InvalidArgumentException('Invalid JWT signature.');
        }

        $claims = json_decode(self::b64urlDecode($p), true);
        if (! is_array($claims)) {
            throw new InvalidArgumentException('Malformed JWT payload.');
        }

        return $claims;
    }

    public static function b64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public static function b64urlDecode(string $data): string
    {
        $decoded = base64_decode(strtr($data, '-_', '+/').str_repeat('=', (4 - strlen($data) % 4) % 4), true);

        return $decoded === false ? '' : $decoded;
    }
}
