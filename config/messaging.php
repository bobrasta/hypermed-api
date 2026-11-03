<?php

// Hypermed's settings for packages/hypermed/messaging. Only the keys set
// here replace the package defaults (packages/hypermed/messaging/config).
// Gates, user search, chat review and avatars are wired in
// App\Providers\MessagingServiceProvider.

return [
    'user_avatar_column' => 'avatar_path',
    'user_search_columns' => ['name', 'email'],
];
