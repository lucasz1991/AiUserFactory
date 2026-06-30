<?php

namespace App\Services\Workflows\Tasks;

use App\Models\Person;
use App\Services\Mail\MailAccountRegistrationRunner;

class PersistWebmailSessionTask
{
    public function handle(Person $person, array $result): array
    {
        $encryptedPayload = trim((string) ($result['encryptedSessionPayload'] ?? ''));

        if ($encryptedPayload === '') {
            return [
                'ok' => false,
                'status' => 'failed',
                'statusMessage' => 'Keine verschluesselte Webmail-Session im Ergebnis gefunden.',
            ];
        }

        $metadata = is_array($person->metadata) ? $person->metadata : [];
        $emailAccount = is_array($metadata['email_account'] ?? null) ? $metadata['email_account'] : [];
        $summary = is_array($result['sessionSummary'] ?? null) ? $result['sessionSummary'] : [];

        $emailAccount['webmail_session'] = [
            'payload_encrypted' => $encryptedPayload,
            'payload_hash' => (string) ($result['sessionPayloadHash'] ?? ''),
            'captured_at' => (string) ($summary['capturedAt'] ?? now()->toIso8601String()),
            'final_url' => $summary['finalUrl'] ?? ($result['finalUrl'] ?? null),
            'origin' => $summary['origin'] ?? null,
            'domain' => $summary['domain'] ?? ($result['domain'] ?? null),
            'domains' => is_array($summary['domains'] ?? null) ? $summary['domains'] : [],
            'cookie_domains' => is_array($summary['cookieDomains'] ?? null) ? $summary['cookieDomains'] : [],
            'cookie_count' => (int) ($summary['cookieCount'] ?? ($result['cookieCount'] ?? 0)),
            'script_name' => (string) ($result['scriptName'] ?? 'webmail_session.cjs'),
            'script_version' => (int) ($result['scriptVersion'] ?? 1),
            'updated_at' => now()->toIso8601String(),
        ];
        $metadata['email_account'] = $emailAccount;

        $person->forceFill(['metadata' => $metadata])->save();

        return [
            'ok' => true,
            'status' => 'success',
            'statusMessage' => 'Webmail-Session wurde gespeichert.',
            'session' => collect($emailAccount['webmail_session'])->except(['payload_encrypted'])->all(),
        ];
    }

    public function handleVerificationMailbox(array $result): array
    {
        $encryptedPayload = trim((string) ($result['encryptedSessionPayload'] ?? ''));

        if ($encryptedPayload === '') {
            return [
                'ok' => false,
                'status' => 'failed',
                'statusMessage' => 'Keine verschluesselte Webmail-Session im Ergebnis gefunden.',
            ];
        }

        $runner = app(MailAccountRegistrationRunner::class);
        $settings = $runner->settings();
        $mailbox = is_array($settings['verification_mailbox'] ?? null) ? $settings['verification_mailbox'] : [];
        $summary = is_array($result['sessionSummary'] ?? null) ? $result['sessionSummary'] : [];

        $mailbox['webmail_session'] = [
            'payload_encrypted' => $encryptedPayload,
            'payload_hash' => (string) ($result['sessionPayloadHash'] ?? ''),
            'captured_at' => (string) ($summary['capturedAt'] ?? now()->toIso8601String()),
            'final_url' => $summary['finalUrl'] ?? ($result['finalUrl'] ?? null),
            'origin' => $summary['origin'] ?? null,
            'domain' => $summary['domain'] ?? ($result['domain'] ?? null),
            'domains' => is_array($summary['domains'] ?? null) ? $summary['domains'] : [],
            'cookie_domains' => is_array($summary['cookieDomains'] ?? null) ? $summary['cookieDomains'] : [],
            'cookie_count' => (int) ($summary['cookieCount'] ?? ($result['cookieCount'] ?? 0)),
            'script_name' => (string) ($result['scriptName'] ?? 'webmail_session.cjs'),
            'script_version' => (int) ($result['scriptVersion'] ?? 1),
            'updated_at' => now()->toIso8601String(),
        ];
        $settings['verification_mailbox'] = $mailbox;
        $runner->saveSettings($settings);

        return [
            'ok' => true,
            'status' => 'success',
            'statusMessage' => 'Webmail-Session des Haupt-Verifikationskontos wurde gespeichert.',
            'session' => collect($mailbox['webmail_session'])->except(['payload_encrypted'])->all(),
        ];
    }
}
