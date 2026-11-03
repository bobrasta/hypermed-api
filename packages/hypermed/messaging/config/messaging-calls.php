<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Calls feature switch
    |--------------------------------------------------------------------------
    | When false, the calls routes are not registered at all.
    */
    'enabled' => env('MESSAGING_CALLS_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | LiveKit
    |--------------------------------------------------------------------------
    | url:        WebSocket URL clients connect to (e.g. wss://livekit.example.com).
    | api_key:    LiveKit API key (JWT "iss").
    | api_secret: LiveKit API secret (HS256 signing key for tokens and webhooks).
    | token_ttl:  Access-token lifetime in seconds. Keep it short: it only has to
    |             cover the initial join (LiveKit refreshes tokens for connected
    |             participants itself).
    */
    'livekit' => [
        'url' => env('LIVEKIT_URL', ''),
        'api_key' => env('LIVEKIT_API_KEY', ''),
        'api_secret' => env('LIVEKIT_API_SECRET', ''),
        'token_ttl' => (int) env('LIVEKIT_TOKEN_TTL', 300),
        // Allowed clock skew (seconds) when verifying webhook JWTs.
        'webhook_leeway' => (int) env('LIVEKIT_WEBHOOK_LEEWAY', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Ringing
    |--------------------------------------------------------------------------
    | Seconds a call rings before unanswered invitees are marked "missed".
    */
    'ring_timeout' => (int) env('MESSAGING_CALL_RING_TIMEOUT', 45),

    /*
    |--------------------------------------------------------------------------
    | Queue for call push notifications and ring-timeout jobs
    |--------------------------------------------------------------------------
    | null = default connection / queue.
    */
    'queue_connection' => env('MESSAGING_CALL_QUEUE_CONNECTION'),
    'queue' => env('MESSAGING_CALL_QUEUE'),

    /*
    |--------------------------------------------------------------------------
    | Webhook route middleware (no auth: requests are signature-verified)
    |--------------------------------------------------------------------------
    */
    'webhook_middleware' => ['api'],

    /*
    |--------------------------------------------------------------------------
    | History page size
    |--------------------------------------------------------------------------
    */
    'history_default_limit' => 30,
    'history_max_limit' => 50,
];
