<?php

namespace App\Models;

use NotificationChannels\WebPush\PushSubscription as BasePushSubscription;

/**
 * Portiert aus RailTime. Zwei Abweichungen zum dortigen Stand:
 *
 * 1. `content_encoding` bleibt ein String. Das Enum `Minishlink\WebPush\
 *    ContentEncoding` gibt es erst ab web-push 10; diese Anwendung laeuft auf
 *    Laravel 10 und damit auf web-push 9, wo `Subscription` einen `?string`
 *    erwartet. Ein Enum-Cast wuerde beim Versand einen TypeError ausloesen.
 * 2. Endpunkt und Schluessel liegen verschluesselt in der Datenbank. Weil ein
 *    verschluesselter Wert nicht durchsuchbar ist, traegt jede Zeile
 *    zusaetzlich den SHA-256-Hash des Endpunkts; `findByEndpoint()` der
 *    Basisklasse wuerde sonst nie treffen.
 */
class PushSubscription extends BasePushSubscription
{
    protected $fillable = [
        'endpoint',
        'public_key',
        'auth_token',
        'content_encoding',
        'device_name',
        'platform',
        'browser',
        'user_agent_hash',
        'last_seen_at',
        'last_success_at',
        'last_failure_at',
        'failure_count',
        'revoked_at',
    ];

    protected $casts = [
        'endpoint' => 'encrypted',
        'public_key' => 'encrypted',
        'auth_token' => 'encrypted',
        'last_seen_at' => 'datetime',
        'last_success_at' => 'datetime',
        'last_failure_at' => 'datetime',
        'revoked_at' => 'datetime',
        'failure_count' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $subscription): void {
            if (filled($subscription->endpoint)) {
                $subscription->endpoint_hash = self::hashEndpoint($subscription->endpoint);
            }
        });
    }

    public static function findByEndpoint($endpoint): ?static
    {
        return static::firstWhere('endpoint_hash', static::hashEndpoint((string) $endpoint));
    }

    public static function hashEndpoint(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }
}
