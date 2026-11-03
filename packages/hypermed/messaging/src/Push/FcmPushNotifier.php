<?php

namespace Hypermed\Messaging\Push;

use Hypermed\Messaging\Contracts\PushNotifier;
use Hypermed\Messaging\Models\DeviceToken;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Firebase Cloud Messaging HTTP v1. The OAuth2 access token is obtained with a
 * service-account JWT (RS256) signed via ext-openssl and cached until shortly
 * before it expires. Sends high-priority DATA-only messages; stale tokens
 * (UNREGISTERED / invalid) are removed.
 */
class FcmPushNotifier implements PushNotifier
{
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    private ?array $credentials = null;

    public function __construct(
        private readonly ?string $credentialsPath,
        private readonly ?string $projectId = null,
        private readonly int $timeout = 10,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            config('messaging.push.fcm.credentials'),
            config('messaging.push.fcm.project_id'),
            (int) config('messaging.push.fcm.timeout', 10),
        );
    }

    public function send(array $userIds, array $data): void
    {
        $tokens = DeviceToken::tokensFor(array_values(array_unique(array_map('intval', $userIds))));
        if ($tokens === []) {
            return;
        }

        $data = array_map(fn ($v) => is_scalar($v) || $v === null ? (string) $v : json_encode($v), $data);
        $url = 'https://fcm.googleapis.com/v1/projects/'.$this->projectId().'/messages:send';

        foreach ($tokens as $token) {
            $payload = ['message' => [
                'token' => $token,
                'data' => (object) $data,
                'android' => ['priority' => 'HIGH', 'ttl' => '120s'],
                'apns' => [
                    'headers' => ['apns-priority' => '5', 'apns-push-type' => 'background'],
                    'payload' => ['aps' => ['content-available' => 1]],
                ],
            ]];

            $response = $this->post($url, $payload);
            if ($response->status() === 401) {
                Cache::forget($this->cacheKey());
                $response = $this->post($url, $payload);
            }

            if (! $response->successful()) {
                $this->handleFailure($token, $response);
            }
        }
    }

    private function post(string $url, array $payload): Response
    {
        return Http::timeout($this->timeout)->withToken($this->accessToken())->acceptJson()->post($url, $payload);
    }

    private function handleFailure(string $token, Response $response): void
    {
        $status = $response->json('error.status');
        $details = json_encode($response->json('error.details') ?? []);
        $stale = $response->status() === 404 || $status === 'NOT_FOUND' || str_contains((string) $details, 'UNREGISTERED')
            || ($status === 'INVALID_ARGUMENT' && str_contains((string) $response->json('error.message'), 'registration token'));

        if ($stale) {
            DeviceToken::query()->where('token_hash', DeviceToken::hashToken($token))->delete();

            return;
        }

        Log::warning('FCM push failed', ['status' => $response->status(), 'error' => $response->json('error.message')]);
    }

    public function accessToken(): string
    {
        $cached = Cache::get($this->cacheKey());
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $creds = $this->credentials();
        $tokenUri = $creds['token_uri'] ?? 'https://oauth2.googleapis.com/token';
        $now = time();
        $jwt = self::signJwt([
            'iss' => $creds['client_email'],
            'scope' => self::SCOPE,
            'aud' => $tokenUri,
            'iat' => $now,
            'exp' => $now + 3600,
        ], $creds['private_key']);

        $response = Http::timeout($this->timeout)->asForm()->post($tokenUri, [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt,
        ]);
        if (! $response->successful() || ! is_string($response->json('access_token'))) {
            throw new RuntimeException('FCM OAuth token request failed with HTTP '.$response->status());
        }

        $token = $response->json('access_token');
        $ttl = max(60, (int) $response->json('expires_in', 3600) - 120);
        Cache::put($this->cacheKey(), $token, $ttl);

        return $token;
    }

    /** RS256 JWT signed with ext-openssl. */
    public static function signJwt(array $claims, string $privateKeyPem): string
    {
        $b64 = fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $input = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])).'.'.$b64(json_encode($claims));

        $key = openssl_pkey_get_private($privateKeyPem);
        if ($key === false || ! openssl_sign($input, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Unable to sign FCM JWT: invalid private key.');
        }

        return $input.'.'.$b64($signature);
    }

    private function projectId(): string
    {
        return $this->projectId ?: (string) ($this->credentials()['project_id'] ?? '');
    }

    private function credentials(): array
    {
        if ($this->credentials !== null) {
            return $this->credentials;
        }
        $path = (string) $this->credentialsPath;
        if ($path === '' || ! is_readable($path)) {
            throw new RuntimeException('FCM credentials file not configured or not readable (MESSAGING_FCM_CREDENTIALS).');
        }
        $json = json_decode((string) file_get_contents($path), true);
        if (! is_array($json) || empty($json['client_email']) || empty($json['private_key'])) {
            throw new RuntimeException('FCM credentials file is not a valid service-account JSON.');
        }

        return $this->credentials = $json;
    }

    private function cacheKey(): string
    {
        return 'messaging:fcm:access_token:'.sha1((string) $this->credentialsPath);
    }
}
