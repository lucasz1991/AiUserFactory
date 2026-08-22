<?php

return [
    'realtime' => [
        'enabled' => (bool) env('CLIENT_CONTROLLER_REALTIME_ENABLED', false),
        'app_key' => env('REVERB_APP_KEY'),
        'app_secret' => env('REVERB_APP_SECRET'),
        'host' => env('REVERB_HOST'),
        'port' => (int) env('REVERB_PORT', 443),
        'scheme' => env('REVERB_SCHEME', 'https'),
        'path' => env('REVERB_SERVER_PATH', ''),
        'activity_timeout_seconds' => max(10, (int) env('REVERB_APP_ACTIVITY_TIMEOUT', 30)),
    ],
];
