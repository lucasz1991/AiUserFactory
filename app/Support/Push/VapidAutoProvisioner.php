<?php

namespace App\Support\Push;

use Minishlink\WebPush\VAPID;
use Throwable;

class VapidAutoProvisioner
{
    public const ISSUE_PERSISTENCE_FAILED = 'auto_provision_failed';

    /**
     * Load persisted credentials and create a key pair once when both keys are missing.
     *
     * @return array{provisioned: bool, issue: string|null}
     */
    public function ensureConfigured(): array
    {
        if (! (bool) config('webpush.auto_provision', true)) {
            return $this->result();
        }

        $this->hydratePersistedCredentials();

        $subject = $this->resolvedSubject();
        $publicKey = trim((string) config('webpush.vapid.public_key'));
        $privateKey = trim((string) config('webpush.vapid.private_key'));

        if ($this->credentialsAreValid($subject, $publicKey, $privateKey)) {
            return $this->result();
        }

        if (! WebPushConfiguration::isValidSubject($subject)) {
            return $this->result();
        }

        if (($publicKey === '') !== ($privateKey === '')) {
            return $this->result();
        }

        return $this->withExclusiveLock(function () use ($subject): array {
            $this->hydratePersistedCredentials();

            $lockedSubject = $this->resolvedSubject();
            $lockedPublicKey = trim((string) config('webpush.vapid.public_key'));
            $lockedPrivateKey = trim((string) config('webpush.vapid.private_key'));

            if ($this->credentialsAreValid($lockedSubject, $lockedPublicKey, $lockedPrivateKey)) {
                return $this->result();
            }

            if (($lockedPublicKey === '') !== ($lockedPrivateKey === '')) {
                return $this->result();
            }

            if ($lockedPublicKey !== '' || $lockedPrivateKey !== '') {
                return $this->result();
            }

            try {
                $keys = VAPID::createVapidKeys();
                $credentials = [
                    'version' => 1,
                    'subject' => $lockedSubject ?: $subject,
                    'public_key' => $keys['publicKey'],
                    'private_key' => $keys['privateKey'],
                    'created_at' => now()->toIso8601String(),
                ];
            } catch (Throwable) {
                return $this->result(self::ISSUE_PERSISTENCE_FAILED);
            }

            if (! $this->persist($credentials)) {
                return $this->result(self::ISSUE_PERSISTENCE_FAILED);
            }

            $this->apply($credentials);

            return $this->result(provisioned: true);
        });
    }

    private function hydratePersistedCredentials(): void
    {
        $path = $this->credentialsPath();

        if (! is_file($path) || ! is_readable($path)) {
            return;
        }

        try {
            $credentials = json_decode(
                (string) file_get_contents($path),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
        } catch (Throwable) {
            return;
        }

        if (! is_array($credentials) || ! $this->credentialsAreValid(
            trim((string) ($credentials['subject'] ?? '')),
            trim((string) ($credentials['public_key'] ?? '')),
            trim((string) ($credentials['private_key'] ?? '')),
        )) {
            return;
        }

        $this->apply($credentials);
    }

    private function resolvedSubject(): string
    {
        $configured = trim((string) config('webpush.vapid.subject'));

        if (WebPushConfiguration::isValidSubject($configured)) {
            return $configured;
        }

        $appUrl = rtrim(trim((string) config('app.url')), '/');

        if (WebPushConfiguration::isValidSubject($appUrl)) {
            config(['webpush.vapid.subject' => $appUrl]);

            return $appUrl;
        }

        return $configured;
    }

    private function credentialsAreValid(
        string $subject,
        string $publicKey,
        string $privateKey,
    ): bool {
        if (
            ! WebPushConfiguration::isValidSubject($subject)
            || $publicKey === ''
            || $privateKey === ''
        ) {
            return false;
        }

        try {
            VAPID::validate([
                'subject' => $subject,
                'publicKey' => $publicKey,
                'privateKey' => $privateKey,
            ]);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  callable(): array{provisioned: bool, issue: string|null}  $callback
     * @return array{provisioned: bool, issue: string|null}
     */
    private function withExclusiveLock(callable $callback): array
    {
        $directory = dirname($this->credentialsPath());

        if (! $this->ensurePrivateDirectory($directory)) {
            return $this->result(self::ISSUE_PERSISTENCE_FAILED);
        }

        $lock = @fopen($this->credentialsPath().'.lock', 'c');

        if ($lock === false) {
            return $this->result(self::ISSUE_PERSISTENCE_FAILED);
        }

        try {
            if (! flock($lock, LOCK_EX)) {
                return $this->result(self::ISSUE_PERSISTENCE_FAILED);
            }

            return $callback();
        } finally {
            @flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * @param  array{version: int, subject: string, public_key: string, private_key: string, created_at: string}  $credentials
     */
    private function persist(array $credentials): bool
    {
        $path = $this->credentialsPath();
        $directory = dirname($path);

        if (is_file($path) || ! $this->ensurePrivateDirectory($directory)) {
            return false;
        }

        try {
            $json = json_encode(
                $credentials,
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ).PHP_EOL;
        } catch (Throwable) {
            return false;
        }

        $temporaryPath = @tempnam($directory, '.vapid-');

        if ($temporaryPath === false) {
            return false;
        }

        try {
            if (@file_put_contents($temporaryPath, $json, LOCK_EX) === false) {
                return false;
            }

            @chmod($temporaryPath, 0600);

            return @rename($temporaryPath, $path);
        } finally {
            if (is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }
        }
    }

    private function ensurePrivateDirectory(string $directory): bool
    {
        if (is_dir($directory)) {
            return is_writable($directory);
        }

        return @mkdir($directory, 0700, true) && is_writable($directory);
    }

    /**
     * @param  array{subject: string, public_key: string, private_key: string}  $credentials
     */
    private function apply(array $credentials): void
    {
        config([
            'webpush.vapid.subject' => trim($credentials['subject']),
            'webpush.vapid.public_key' => trim($credentials['public_key']),
            'webpush.vapid.private_key' => trim($credentials['private_key']),
        ]);
    }

    private function credentialsPath(): string
    {
        return (string) config(
            'webpush.auto_provision_path',
            storage_path('app/private/webpush-vapid.json'),
        );
    }

    /**
     * @return array{provisioned: bool, issue: string|null}
     */
    private function result(?string $issue = null, bool $provisioned = false): array
    {
        return [
            'provisioned' => $provisioned,
            'issue' => $issue,
        ];
    }
}
