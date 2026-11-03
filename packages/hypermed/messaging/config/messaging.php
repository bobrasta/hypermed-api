<?php

return [

    /*
    |--------------------------------------------------------------------------
    | User model & columns
    |--------------------------------------------------------------------------
    | The package refers to users by `user_id` only. These settings tell it how
    | to read display data. Name/avatar must be real columns of the users table
    | (the conversation list reads them with a JOIN to stay within 3 queries).
    | The avatar column may hold an absolute URL or null.
    */
    'user_model' => env('MESSAGING_USER_MODEL', 'App\\Models\\User'),
    'user_name_column' => env('MESSAGING_USER_NAME_COLUMN', 'name'),
    'user_avatar_column' => env('MESSAGING_USER_AVATAR_COLUMN'),
    // Columns searched by the default UserDirectory (GET /users?search=).
    'user_search_columns' => ['name', 'email'],

    /*
    |--------------------------------------------------------------------------
    | HTTP
    |--------------------------------------------------------------------------
    */
    'register_routes' => true,
    'route_prefix' => env('MESSAGING_ROUTE_PREFIX', 'api/messaging'),
    'middleware' => ['api', 'auth:sanctum'],

    /*
    |--------------------------------------------------------------------------
    | Broadcasting
    |--------------------------------------------------------------------------
    | Channel authorization callbacks are registered by the service provider.
    | The host must expose the broadcast auth route (e.g. /broadcasting/auth
    | with auth:sanctum).
    */
    'register_channels' => true,

    /*
    |--------------------------------------------------------------------------
    | Default gates
    |--------------------------------------------------------------------------
    | Registered only when the host app has not defined the gate itself. The
    | value is the result the default gate returns. Privileged abilities
    | default to false so forgetting to define them never grants access.
    */
    'default_gates' => [
        'messaging.send' => true,
        'messaging.group.create' => true,
        'messaging.call' => true,
        'messaging.broadcast.org_unit' => false,
        'messaging.audit.view' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Limits
    |--------------------------------------------------------------------------
    */
    'rate_limit' => [
        // Messages (incl. attachments) a user may send per minute.
        'send_per_minute' => (int) env('MESSAGING_SEND_RATE_LIMIT', 30),
    ],
    'list' => [
        'default_limit' => 30,
        'max_limit' => 50,
        // Participants included per group conversation in the list endpoint.
        'group_participants_preview' => 5,
    ],
    'messages' => [
        'default_limit' => 50,
        'max_limit' => 100,
        'max_body_length' => 10000,
        'preview_length' => 120,
    ],
    'groups' => [
        'max_title_length' => 120,
        'max_participants' => 200,
    ],

    /*
    |--------------------------------------------------------------------------
    | Attachments
    |--------------------------------------------------------------------------
    | Any Laravel filesystem disk ('local' in the harness, an S3-compatible
    | disk such as Cloudflare R2 in production). Downloads are only possible
    | via short-lived signed URLs handed to participants.
    */
    'attachments' => [
        'disk' => env('MESSAGING_ATTACHMENTS_DISK', 'local'),
        'directory' => env('MESSAGING_ATTACHMENTS_DIRECTORY', 'messaging'),
        'max_size_kb' => (int) env('MESSAGING_ATTACHMENTS_MAX_KB', 20480),
        // Signed URL lifetime in minutes.
        'signed_url_ttl' => (int) env('MESSAGING_SIGNED_URL_TTL', 10),
        // Use the disk's own temporaryUrl() (S3/R2) for downloads when available.
        'use_disk_temporary_urls' => (bool) env('MESSAGING_ATTACHMENTS_DISK_TEMP_URLS', true),
        'thumbnail_max' => 320,
        // Blocked extensions (executables/scripts).
        'blocked_extensions' => ['php', 'phtml', 'phar', 'exe', 'bat', 'cmd', 'sh', 'js', 'msi', 'com', 'scr', 'html', 'htm', 'svg'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Push notifications
    |--------------------------------------------------------------------------
    | driver: 'null' or 'fcm'. Push is sent from a queued job to participants
    | who are offline (PresenceChecker) and not muted.
    */
    'push' => [
        'driver' => env('MESSAGING_PUSH_DRIVER', 'null'),
        'connection' => env('MESSAGING_PUSH_QUEUE_CONNECTION'),
        'queue' => env('MESSAGING_PUSH_QUEUE', 'default'),
        'fcm' => [
            // Absolute path to the Firebase service-account JSON (never commit it).
            'credentials' => env('MESSAGING_FCM_CREDENTIALS'),
            // Optional; defaults to project_id inside the credentials file.
            'project_id' => env('MESSAGING_FCM_PROJECT_ID'),
            'timeout' => 10,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Presence
    |--------------------------------------------------------------------------
    | checker: 'broadcaster' asks the Pusher-compatible HTTP API (Reverb) for
    | members of the presence channel; 'null' treats everyone as offline.
    | A class name implementing Contracts\PresenceChecker is accepted too.
    */
    'presence' => [
        'checker' => env('MESSAGING_PRESENCE_CHECKER', 'broadcaster'),
        'channel' => 'messaging.presence',
        'cache_seconds' => 5,
    ],

];
