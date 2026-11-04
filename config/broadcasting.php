<?php

// Only what differs from Laravel's defaults (the rest comes from the
// framework's config/broadcasting.php).

return [
    'connections' => [
        'reverb' => [
            'driver' => 'reverb',
            'key' => env('REVERB_APP_KEY'),
            'secret' => env('REVERB_APP_SECRET'),
            'app_id' => env('REVERB_APP_ID'),
            'options' => [
                'host' => env('REVERB_HOST', '127.0.0.1'),
                'port' => env('REVERB_PORT', 8080),
                'scheme' => env('REVERB_SCHEME', 'http'),
                'useTLS' => env('REVERB_SCHEME', 'http') === 'https',
            ],
            // Reverb runs on the same box; if it is down or stuck, a chat
            // message must still save quickly (the live push is skipped).
            'client_options' => ['timeout' => 3, 'connect_timeout' => 2],
        ],
    ],
];
