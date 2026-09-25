<?php

namespace App\Services\Workflows\Tasks;

use App\Models\Person;
use App\Models\PersonEmailAccount;
use App\Services\Mail\MailAccountRegistrationRunner;
use Illuminate\Support\Facades\DB;

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

        $summary = is_array($result['sessionSummary'] ?? null) ? $result['sessionSummary'] : [];

        $session = [
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
        $emailAddress = mb_strtolower(trim((string) (
            $result['mailboxEmail']
            ?? $result['email']
            ?? data_get($result, 'account.email', '')
        )));

        $write = DB::transaction(function () use ($person, $emailAddress, $session): array {
            $lockedPerson = Person::query()->lockForUpdate()->findOrFail($person->getKey());
            $metadata = is_array($lockedPerson->metadata) ? $lockedPerson->metadata : [];
            $emailAccount = is_array($metadata['email_account'] ?? null) ? $metadata['email_account'] : [];

            $query = PersonEmailAccount::query()
                ->where('person_id', $lockedPerson->getKey())
                ->lockForUpdate();

            if ($emailAddress !== '') {
                $query->whereRaw('LOWER(email) = ?', [$emailAddress]);
            } else {
                $query->where('is_primary', true);
            }

            $account = $query->first();

            if ($account) {
                $account->forceFill(['webmail_session' => $session])->save();
                $isMirror = $emailAddress === ''
                    || mb_strtolower(trim((string) ($emailAccount['email'] ?? ''))) === $emailAddress;

                if (! $isMirror && ! (bool) $account->is_primary) {
                    return ['account' => $account, 'stored' => true];
                }
            } elseif ($emailAddress !== ''
                && mb_strtolower(trim((string) ($emailAccount['email'] ?? ''))) !== $emailAddress) {
                // Do not attach one mailbox's session to the person's primary
                // mirror merely because the requested account row is missing.
                return ['account' => null, 'stored' => false];
            }

            $emailAccount['webmail_session'] = $session;
            $metadata['email_account'] = $emailAccount;
            $lockedPerson->forceFill(['metadata' => $metadata])->save();

            return ['account' => $account, 'stored' => true];
        });

        if (! $write['stored']) {
            return [
                'ok' => false,
                'status' => 'failed',
                'statusMessage' => 'Das zugehoerige E-Mail-Konto wurde fuer diese Person nicht gefunden; die Session wurde nicht falsch zugeordnet.',
            ];
        }

        return [
            'ok' => true,
            'status' => 'success',
            'statusMessage' => 'Webmail-Session wurde gespeichert.',
            'session' => collect($session)->except(['payload_encrypted'])->all(),
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
