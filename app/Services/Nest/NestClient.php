<?php

namespace App\Services\Nest;

use App\Models\Setting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

/**
 * Talks to NeST. The tender list uses the public GraphQL query from
 * config/nest.php (no login). Signed-in calls (tender documents) use the
 * company's NeST account from settings, via NeST's OAuth password grant;
 * the token is cached and refreshed like NeST's own web client does.
 */
class NestClient
{
    private const TOKEN_CACHE = 'nest:oauth-token';

    // ------------------------------------------------------------ listing

    /**
     * One page of the public listing.
     *
     * @return array{totalPages: int, totalRecords: int, currentPage: int, hasNext: bool, rows: list<array>}
     *
     * @throws NestException on HTTP failure or GraphQL errors
     */
    public function fetchPage(int $page, int $pageSize): array
    {
        $variables = $this->fill(config('nest.variables'), ['__PAGE__' => $page, '__PAGE_SIZE__' => $pageSize]);
        $json = $this->post(config('nest.endpoint'), [
            'operationName' => config('nest.operation_name'),
            'query' => config('nest.query'),
            'variables' => $variables,
        ]);

        $items = $json['data']['items'] ?? null;
        if (! is_array($items) || ! is_array($items['rows'] ?? null)) {
            throw new NestException('NeST returned no tender list (unexpected response shape).');
        }

        return [
            'totalPages' => (int) ($items['totalPages'] ?? 1),
            'totalRecords' => (int) ($items['totalRecords'] ?? count($items['rows'])),
            'currentPage' => (int) ($items['currentPage'] ?? $page),
            'hasNext' => (bool) ($items['hasNext'] ?? false),
            'rows' => $items['rows'],
        ];
    }

    /** Replace placeholder strings anywhere in the variables tree, keeping ints as ints. */
    public function fill(mixed $node, array $map): mixed
    {
        if (is_array($node)) {
            return array_map(fn ($v) => $this->fill($v, $map), $node);
        }
        if (is_string($node) && array_key_exists($node, $map)) {
            return $map[$node];
        }

        return $node;
    }

    // ---------------------------------------------------- signed-in calls

    public static function hasCredentials(): bool
    {
        return filled(Setting::get('nest.username')) && filled(Setting::get('nest.password'))
            && filled(config('nest.auth.client_secret'));
    }

    public static function storeCredentials(string $username, ?string $password, ?int $by): void
    {
        Setting::set('nest.username', $username, $by);
        if ($password !== null && $password !== '') {
            Setting::set('nest.password', Crypt::encryptString($password), $by);
        }
        Cache::forget(self::TOKEN_CACHE);
    }

    public static function forgetCredentials(?int $by): void
    {
        Setting::whereIn('key', ['nest.username', 'nest.password', 'nest.login_ok_at', 'nest.login_error'])->delete();
        Cache::forget(self::TOKEN_CACHE);
    }

    /** Log in fresh (used by "Test login"); records the outcome in settings. */
    public function testLogin(): void
    {
        Cache::forget(self::TOKEN_CACHE);
        $this->token();
    }

    /** GraphQL call on a NeST service (nest-app, nest-templates, …) as the company account. */
    public function authedGraphql(string $service, string $query, array $variables): array
    {
        return $this->post(config('nest.gateway') . "/{$service}/graphql", [
            'query' => $query,
            'variables' => $variables,
        ], $this->token());
    }

    /** POST to a NeST REST path (relative to the gateway) as the company account. */
    public function authedPost(string $path, array $body): array
    {
        return $this->post(config('nest.gateway') . '/' . ltrim($path, '/'), $body, $this->token(), graphql: false);
    }

    /** Bearer token for NeST calls made outside GraphQL (file downloads). */
    public function accessToken(): string
    {
        return $this->token();
    }

    private function token(): string
    {
        if (! self::hasCredentials()) {
            throw new NestException(filled(config('nest.auth.client_secret'))
                ? 'Enter the company NeST login under NeST tenders → Settings first.'
                : 'NeST sign-in is not configured on the server (NEST_CLIENT_SECRET).');
        }

        $cached = Cache::get(self::TOKEN_CACHE);
        if (is_array($cached) && ($cached['expires_at'] ?? 0) > time() + 60) {
            return $cached['access_token'];
        }
        if (is_array($cached) && ! empty($cached['refresh_token'])) {
            try {
                return $this->requestToken(['grant_type' => 'refresh_token', 'refresh_token' => $cached['refresh_token']]);
            } catch (NestException) {
                // fall through to a fresh password login
            }
        }

        try {
            $token = $this->requestToken([
                'grant_type' => 'password',
                'username' => Setting::get('nest.username'),
                'password' => Crypt::decryptString((string) Setting::get('nest.password')),
            ]);
            Setting::set('nest.login_ok_at', now()->toIso8601String());
            Setting::whereKey('nest.login_error')->delete();

            return $token;
        } catch (NestException $e) {
            Setting::set('nest.login_error', mb_substr($e->getMessage(), 0, 250));
            throw $e;
        }
    }

    private function requestToken(array $form): string
    {
        try {
            $res = Http::asForm()->acceptJson()->timeout((int) config('nest.timeout'))
                ->withBasicAuth((string) config('nest.auth.client_id'), (string) config('nest.auth.client_secret'))
                ->post(config('nest.auth.token_url'), $form);
        } catch (ConnectionException $e) {
            throw new NestException('Could not reach NeST to sign in: ' . $e->getMessage());
        }

        $json = $res->json() ?? [];
        if (! $res->successful() || empty($json['access_token'])) {
            $why = $json['error_description'] ?? $json['message'] ?? $json['error'] ?? ('HTTP ' . $res->status());
            throw new NestException(($form['grant_type'] === 'password' ? 'NeST rejected the login: ' : 'NeST token refresh failed: ') . $why);
        }

        Cache::put(self::TOKEN_CACHE, [
            'access_token' => $json['access_token'],
            'refresh_token' => $json['refresh_token'] ?? null,
            'expires_at' => time() + (int) ($json['expires_in'] ?? 1800),
        ], now()->addDays(7));

        return $json['access_token'];
    }

    // --------------------------------------------------------------- HTTP

    private function post(string $url, array $body, ?string $bearer = null, bool $graphql = true): array
    {
        $request = Http::acceptJson()->asJson()
            ->timeout((int) config('nest.timeout'))
            ->retry((int) config('nest.retries') + 1, 2000, fn ($e) => $e instanceof ConnectionException
                || ($e instanceof RequestException && $e->response->serverError()), throw: false)
            ->withHeaders(['Origin' => config('nest.web_url'), 'Referer' => config('nest.web_url') . '/']);
        if ($bearer) {
            $request = $request->withToken($bearer);
        }

        try {
            $res = $request->post($url, $body);
        } catch (ConnectionException $e) {
            throw new NestException('Could not reach NeST: ' . $e->getMessage());
        }

        if ($res->status() === 401 && $bearer) {
            Cache::forget(self::TOKEN_CACHE);
        }
        if (! $res->successful()) {
            throw new NestException('NeST answered HTTP ' . $res->status() . '.');
        }
        $json = $res->json();
        if (! is_array($json)) {
            throw new NestException('NeST returned something that is not JSON.');
        }
        if ($graphql && ! empty($json['errors'])) {
            $msg = collect($json['errors'])->pluck('message')->filter()->implode('; ');
            throw new NestException('NeST GraphQL error: ' . ($msg ?: 'unknown'));
        }

        return $json;
    }
}
