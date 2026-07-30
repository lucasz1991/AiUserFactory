<?php

use App\Models\PushSubscription;

/*
|--------------------------------------------------------------------------
| Web Push
|--------------------------------------------------------------------------
|
| Portiert aus RailTime (C:\xampp\htdocs\RailTime\App\config\webpush.php).
| Die Werte werden ueber mergeConfigFrom mit der Paketvorgabe von
| laravel-notification-channels/webpush zusammengefuehrt; der dort
| deklarierte, veraltete `gcm`-Block bleibt deshalb erhalten, ohne dass er
| hier wiederholt werden muss.
|
| Abweichung zu RailTime: `enabled` und `test_enabled` sind hier per Default
| aktiv. Die VAPID-Schluessel provisioniert sich die Anwendung selbst, und ein
| Push erreicht ausschliesslich Geraete, die sich zuvor ausdruecklich im
| Browser angemeldet haben — ein zusaetzlicher Env-Schalter waere nur eine
| stille Fehlerquelle. Zum Abschalten `WEBPUSH_ENABLED=false` setzen.
|
*/

return [
    'enabled' => (bool) env('WEBPUSH_ENABLED', true),
    'test_enabled' => (bool) env('WEBPUSH_TEST_ENABLED', true),
    'auto_provision' => (bool) env('WEBPUSH_AUTO_PROVISION', true),
    'auto_provision_path' => env(
        'WEBPUSH_AUTO_PROVISION_PATH',
        storage_path('app/private/webpush-vapid.json'),
    ),
    'queue' => env('WEBPUSH_QUEUE', 'default'),
    'default_ttl' => (int) env('WEBPUSH_DEFAULT_TTL', 3600),

    'vapid' => [
        'subject' => env('VAPID_SUBJECT'),
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
        'pem_file' => env('VAPID_PEM_FILE'),
    ],

    'model' => PushSubscription::class,
    'table_name' => env('WEBPUSH_DB_TABLE', 'push_subscriptions'),
    'database_connection' => env('WEBPUSH_DB_CONNECTION', env('DB_CONNECTION', 'mysql')),
    'allowed_endpoint_hosts' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env(
            'WEBPUSH_ALLOWED_ENDPOINT_HOSTS',
            'fcm.googleapis.com,*.push.services.mozilla.com,*.push.apple.com,*.notify.windows.com,*.wns.windows.com,*.push.samsung.com'
        ))
    ))),
    'client_options' => [
        'allow_redirects' => false,
        'connect_timeout' => 10,
        'timeout' => 30,
    ],
    'automatic_padding' => (bool) env('WEBPUSH_AUTOMATIC_PADDING', true),
];
