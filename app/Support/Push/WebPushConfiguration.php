<?php

namespace App\Support\Push;

use Minishlink\WebPush\VAPID;
use Throwable;

class WebPushConfiguration
{
    public const ISSUE_DISABLED = 'disabled';

    public const ISSUE_SUBJECT_MISSING = 'subject_missing';

    public const ISSUE_SUBJECT_INVALID = 'subject_invalid';

    public const ISSUE_PUBLIC_KEY_MISSING = 'public_key_missing';

    public const ISSUE_PRIVATE_KEY_MISSING = 'private_key_missing';

    public const ISSUE_CREDENTIALS_INVALID = 'credentials_invalid';

    /**
     * Nicht umkehrbare Kennung des angemeldeten Kontos.
     *
     * Der Browser speichert sie neben der Abo-ID im localStorage. Wechselt das
     * Konto im selben Browser, erkennt der Client das an der abweichenden
     * Kennung und baut das fremde Abo ab, bevor er ein eigenes anlegt — ohne
     * dass jemals eine Benutzer-ID im localStorage landet.
     */
    public static function accountBinding(int|string $userId): string
    {
        return hash_hmac(
            'sha256',
            'factory-webpush-account:'.$userId,
            (string) config('app.key'),
        );
    }

    public static function isConfigured(): bool
    {
        return self::diagnostics()['configured'];
    }

    /**
     * Return non-sensitive reasons why Web Push is not ready.
     *
     * Secret values and validation exceptions deliberately never leave this
     * class. The issue codes are safe to render in an authenticated status UI.
     *
     * @return array{
     *     enabled: bool,
     *     configured: bool,
     *     ready: bool,
     *     issues: list<string>,
     *     auto_provisioned: bool
     * }
     */
    public static function diagnostics(): array
    {
        $autoProvision = app(VapidAutoProvisioner::class)->ensureConfigured();
        $enabled = (bool) config('webpush.enabled');
        $subject = trim((string) config('webpush.vapid.subject'));
        $publicKey = trim((string) config('webpush.vapid.public_key'));
        $privateKey = trim((string) config('webpush.vapid.private_key'));
        $issues = [];

        if (! $enabled) {
            $issues[] = self::ISSUE_DISABLED;
        }

        $validSubject = self::isValidSubject($subject);

        if ($subject === '') {
            $issues[] = self::ISSUE_SUBJECT_MISSING;
        } elseif (! $validSubject) {
            $issues[] = self::ISSUE_SUBJECT_INVALID;
        }

        if ($publicKey === '') {
            $issues[] = self::ISSUE_PUBLIC_KEY_MISSING;
        }

        if ($privateKey === '') {
            $issues[] = self::ISSUE_PRIVATE_KEY_MISSING;
        }

        if ($autoProvision['issue'] !== null) {
            $issues[] = $autoProvision['issue'];
        }

        $hasCredentials = $validSubject
            && $publicKey !== ''
            && $privateKey !== '';

        if ($hasCredentials) {
            $vapid = [
                'subject' => $subject,
                'publicKey' => $publicKey,
                'privateKey' => $privateKey,
            ];

            try {
                VAPID::validate($vapid);
            } catch (Throwable) {
                $issues[] = self::ISSUE_CREDENTIALS_INVALID;
            }
        }

        $configurationIssues = array_values(array_filter(
            $issues,
            fn (string $issue): bool => $issue !== self::ISSUE_DISABLED,
        ));
        $configured = $configurationIssues === [];

        return [
            'enabled' => $enabled,
            'configured' => $configured,
            'ready' => $enabled && $configured,
            'issues' => $issues,
            'auto_provisioned' => $autoProvision['provisioned'],
        ];
    }

    public static function isValidSubject(string $subject): bool
    {
        if (str_starts_with($subject, 'mailto:')) {
            return filter_var(substr($subject, 7), FILTER_VALIDATE_EMAIL) !== false;
        }

        return filter_var($subject, FILTER_VALIDATE_URL) !== false
            && parse_url($subject, PHP_URL_SCHEME) === 'https';
    }
}
