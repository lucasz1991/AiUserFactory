<?php

namespace App\Services\Workflows\Tasks;

use App\Models\Person;

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

        $summary = $this->summary($result);
        $domain = $this->normalizeDomain($summary['domain'] ?? $result['domain'] ?? $result['sessionDomain'] ?? '');
        $sessionKey = $this->sessionKey($result['sessionKey'] ?? $summary['sessionKey'] ?? $domain);
        $metadata = is_array($person->metadata) ? $person->metadata : [];
        $sessions = is_array($metadata['browser_sessions'] ?? null) ? $metadata['browser_sessions'] : [];

        $sessions[$sessionKey] = [
            'payload_encrypted' => $encryptedPayload,
            'payload_hash' => (string) ($result['browserSessionPayloadHash'] ?? $result['sessionPayloadHash'] ?? ''),
            'session_key' => $sessionKey,
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
        ];

        $metadata['browser_sessions'] = $sessions;
        $person->forceFill(['metadata' => $metadata])->save();

        return [
            'ok' => true,
            'status' => 'success',
            'statusMessage' => 'Browser-Session wurde gespeichert.',
            'sessionKey' => $sessionKey,
            'domain' => $domain,
            'session' => collect($sessions[$sessionKey])->except(['payload_encrypted'])->all(),
        ];
    }

    public function delete(Person $person, array $result): array
    {
        $metadata = is_array($person->metadata) ? $person->metadata : [];
        $sessions = is_array($metadata['browser_sessions'] ?? null) ? $metadata['browser_sessions'] : [];
        $domain = $this->normalizeDomain($result['sessionDomain'] ?? $result['domain'] ?? '');
        $sessionKey = $this->sessionKey($result['sessionKey'] ?? $domain);
        $deletedKeys = [];

        foreach ($sessions as $key => $session) {
            $storedDomain = $this->normalizeDomain(is_array($session) ? ($session['domain'] ?? '') : '');
            $storedDomains = is_array($session['domains'] ?? null) ? $session['domains'] : [];
            $storedCookieDomains = is_array($session['cookie_domains'] ?? null) ? $session['cookie_domains'] : [];
            $matchesKey = $sessionKey !== '' && (string) $key === $sessionKey;
            $matchesDomain = $domain !== '' && (
                $this->domainMatches($storedDomain, $domain)
                || collect([...$storedDomains, ...$storedCookieDomains])->contains(fn ($candidate) => $this->domainMatches((string) $candidate, $domain))
            );

            if (! $matchesKey && ! $matchesDomain) {
                continue;
            }

            unset($sessions[$key]);
            $deletedKeys[] = (string) $key;
        }

        $metadata['browser_sessions'] = $sessions;
        $person->forceFill(['metadata' => $metadata])->save();

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

    protected function domainMatches(string $candidate, string $target): bool
    {
        $candidate = $this->normalizeDomain($candidate);
        $target = $this->normalizeDomain($target);

        if ($candidate === '' || $target === '') {
            return false;
        }

        return $candidate === $target
            || str_ends_with($candidate, '.'.$target)
            || str_ends_with($target, '.'.$candidate);
    }
}
