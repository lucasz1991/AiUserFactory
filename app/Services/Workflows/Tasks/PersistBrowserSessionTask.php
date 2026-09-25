<?php

namespace App\Services\Workflows\Tasks;

use App\Models\Person;
use App\Services\Mail\MailAccountRegistrationRunner;
use Illuminate\Support\Facades\DB;

class PersistBrowserSessionTask
{
    public function handle(Person $person, array $result): array
    {
        $encryptedPayload = trim((string) ($result['encryptedBrowserSessionPayload'] ?? $result['encryptedSessionPayload'] ?? ''));

        if ($encryptedPayload === '') {
            return [
                'ok' => false,
                'status' => 'failed',
                'statusMessage' => 'Keine verschluesselte Browser-Session im Ergebnis gefunden.',
            ];
        }

        [$sessionKey, $session] = $this->sessionRecord($result, $encryptedPayload);
        DB::transaction(function () use ($person, $sessionKey, $session): void {
            $lockedPerson = Person::query()->lockForUpdate()->findOrFail($person->getKey());
            $metadata = is_array($lockedPerson->metadata) ? $lockedPerson->metadata : [];
            $sessions = is_array($metadata['browser_sessions'] ?? null) ? $metadata['browser_sessions'] : [];
            $sessions[$sessionKey] = $session;
            $metadata['browser_sessions'] = $sessions;
            $lockedPerson->forceFill(['metadata' => $metadata])->save();
        });

        return [
            'ok' => true,
            'status' => 'success',
            'statusMessage' => 'Browser-Session wurde gespeichert.',
            'sessionKey' => $sessionKey,
            'domain' => $session['domain'],
            'session' => collect($session)->except(['payload_encrypted'])->all(),
        ];
    }

    public function handleVerificationMailbox(array $result): array
    {
        $encryptedPayload = trim((string) ($result['encryptedBrowserSessionPayload'] ?? $result['encryptedSessionPayload'] ?? ''));

        if ($encryptedPayload === '') {
            return [
                'ok' => false,
                'status' => 'failed',
                'statusMessage' => 'Keine verschluesselte Browser-Session im Ergebnis gefunden.',
            ];
        }

        $runner = app(MailAccountRegistrationRunner::class);
        $settings = $runner->settings();
        $mailbox = is_array($settings['verification_mailbox'] ?? null) ? $settings['verification_mailbox'] : [];
        $sessions = is_array($mailbox['browser_sessions'] ?? null) ? $mailbox['browser_sessions'] : [];
        [$sessionKey, $session] = $this->sessionRecord($result, $encryptedPayload);
        $sessions[$sessionKey] = $session;
        $mailbox['browser_sessions'] = $sessions;
        $settings['verification_mailbox'] = $mailbox;
        $runner->saveSettings($settings);

        return [
            'ok' => true,
            'status' => 'success',
            'statusMessage' => 'Browser-Session des Haupt-Verifikationskontos wurde gespeichert.',
            'sessionKey' => $sessionKey,
            'domain' => $session['domain'],
            'session' => collect($session)->except(['payload_encrypted'])->all(),
        ];
    }

    public function delete(Person $person, array $result): array
    {
        [$deletedKeys, $domain, $sessionKey] = DB::transaction(function () use ($person, $result): array {
            $lockedPerson = Person::query()->lockForUpdate()->findOrFail($person->getKey());
            $metadata = is_array($lockedPerson->metadata) ? $lockedPerson->metadata : [];
            $sessions = is_array($metadata['browser_sessions'] ?? null) ? $metadata['browser_sessions'] : [];
            [$sessions, $deletedKeys, $domain, $sessionKey] = $this->deleteFromSessions($sessions, $result);
            $metadata['browser_sessions'] = $sessions;
            $lockedPerson->forceFill(['metadata' => $metadata])->save();

            return [$deletedKeys, $domain, $sessionKey];
        });

        return [
            'ok' => true,
            'status' => 'success',
            'statusMessage' => count($deletedKeys) > 0
                ? 'Gespeicherte Browser-Session wurde geloescht.'
                : 'Keine gespeicherte Browser-Session fuer diese Domain gefunden.',
            'deletedSessionKeys' => $deletedKeys,
            'domain' => $domain,
            'sessionKey' => $sessionKey,
        ];
    }

    public function deleteVerificationMailbox(array $result): array
    {
        $runner = app(MailAccountRegistrationRunner::class);
        $settings = $runner->settings();
        $mailbox = is_array($settings['verification_mailbox'] ?? null) ? $settings['verification_mailbox'] : [];
        $sessions = is_array($mailbox['browser_sessions'] ?? null) ? $mailbox['browser_sessions'] : [];
        [$sessions, $deletedKeys, $domain, $sessionKey] = $this->deleteFromSessions($sessions, $result);
        $mailbox['browser_sessions'] = $sessions;
        $settings['verification_mailbox'] = $mailbox;
        $runner->saveSettings($settings);

        return [
            'ok' => true,
            'status' => 'success',
            'statusMessage' => count($deletedKeys) > 0
                ? 'Gespeicherte Browser-Session des Haupt-Verifikationskontos wurde geloescht.'
                : 'Keine gespeicherte Browser-Session fuer das Haupt-Verifikationskonto gefunden.',
            'deletedSessionKeys' => $deletedKeys,
            'domain' => $domain,
            'sessionKey' => $sessionKey,
        ];
    }

    protected function sessionRecord(array $result, string $encryptedPayload): array
    {
        $summary = $this->summary($result);
        $domain = $this->normalizeDomain($summary['domain'] ?? $result['domain'] ?? $result['sessionDomain'] ?? '');
        $sessionKey = $this->sessionKey($result['sessionKey'] ?? $summary['sessionKey'] ?? $domain);
        $ownerSessionKey = $this->sessionKey($result['ownerSessionKey'] ?? $summary['ownerSessionKey'] ?? $sessionKey);

        return [$sessionKey, [
            'payload_encrypted' => $encryptedPayload,
            'payload_hash' => (string) ($result['browserSessionPayloadHash'] ?? $result['sessionPayloadHash'] ?? ''),
            'session_key' => $sessionKey,
            'owner_session_key' => $ownerSessionKey,
            'label' => (string) ($result['sessionLabel'] ?? $summary['label'] ?? $sessionKey),
            'domain' => $domain,
            'domains' => $this->stringList($summary['domains'] ?? $result['domains'] ?? []),
            'cookie_domains' => $this->stringList($summary['cookieDomains'] ?? $result['cookieDomains'] ?? []),
            'captured_at' => (string) ($summary['capturedAt'] ?? now()->toIso8601String()),
            'final_url' => $summary['finalUrl'] ?? ($result['finalUrl'] ?? null),
            'origin' => $summary['origin'] ?? null,
            'cookie_count' => (int) ($summary['cookieCount'] ?? ($result['cookieCount'] ?? 0)),
            'script_name' => (string) ($result['scriptName'] ?? 'persist_browser_session.cjs'),
            'script_version' => (int) ($result['scriptVersion'] ?? 1),
            'updated_at' => now()->toIso8601String(),
        ]];
    }

    protected function deleteFromSessions(array $sessions, array $result): array
    {
        $domain = $this->normalizeDomain($result['sessionDomain'] ?? $result['domain'] ?? '');
        $requestedKey = trim((string) ($result['sessionKey'] ?? $result['session_key'] ?? ''));
        $sessionKey = $this->sessionKey($requestedKey !== '' ? $requestedKey : $domain);
        $deletedKeys = [];

        foreach ($sessions as $key => $session) {
            $storedDomain = $this->normalizeDomain(is_array($session) ? ($session['domain'] ?? '') : '');
            $storedDomains = is_array($session['domains'] ?? null) ? $session['domains'] : [];
            $storedOwnerKey = $this->sessionKey(is_array($session) ? ($session['owner_session_key'] ?? '') : '');
            $matchesKey = $sessionKey !== '' && ((string) $key === $sessionKey || $storedOwnerKey === $sessionKey);
            $matchesDomain = $domain !== '' && (
                $storedDomain === $domain
                || collect($storedDomains)->contains(fn ($candidate) => $this->normalizeDomain((string) $candidate) === $domain)
            );

            $matchesTarget = $requestedKey !== ''
                ? ($matchesKey && ($domain === '' || $matchesDomain))
                : ($domain !== '' ? $matchesDomain : $matchesKey);

            if (! $matchesTarget) {
                continue;
            }

            unset($sessions[$key]);
            $deletedKeys[] = (string) $key;
        }

        return [$sessions, $deletedKeys, $domain, $sessionKey];
    }

    protected function summary(array $result): array
    {
        if (is_array($result['browserSessionSummary'] ?? null)) {
            return $result['browserSessionSummary'];
        }

        return is_array($result['sessionSummary'] ?? null) ? $result['sessionSummary'] : [];
    }

    protected function stringList(mixed $values): array
    {
        if (! is_array($values)) {
            $values = [$values];
        }

        return array_values(array_unique(array_filter(array_map(
            fn ($value) => trim((string) $value),
            $values,
        ))));
    }

    protected function sessionKey(mixed $value): string
    {
        $value = strtolower(trim((string) $value));
        $value = preg_replace('/[^a-z0-9._-]+/', '-', $value) ?? '';
        $value = trim($value, '-');

        return $value !== '' ? $value : 'browser-session';
    }

    protected function normalizeDomain(mixed $value): string
    {
        $value = strtolower(trim((string) $value));

        if ($value === '') {
            return '';
        }

        $host = parse_url($value, PHP_URL_HOST);

        if (is_string($host) && trim($host) !== '') {
            $value = $host;
        }

        $value = preg_replace('#^https?://#i', '', $value) ?? $value;
        $value = explode('/', $value)[0] ?? $value;
        $value = explode(':', $value)[0] ?? $value;

        return trim($value, " \t\n\r\0\x0B.");
    }

}
