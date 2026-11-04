<?php

// Hypermed's settings for packages/hypermed/messaging. Only the keys set
// here replace the package defaults (packages/hypermed/messaging/config).
// Gates, user search, chat review and avatars are wired in
// App\Providers\MessagingServiceProvider.

return [
    'user_avatar_column' => 'avatar_path',
    'user_search_columns' => ['name', 'email'],
    'realtime' => [
        'key' => env('REVERB_APP_KEY'),
        'host' => env('MESSAGING_REALTIME_HOST'),
        'port' => (int) env('MESSAGING_REALTIME_PORT', 443),
        'tls' => (bool) env('MESSAGING_REALTIME_TLS', true),
        // bootstrap/app.php puts the broadcast auth route under /api.
        'auth_path' => '/api/broadcasting/auth',
    ],
];
