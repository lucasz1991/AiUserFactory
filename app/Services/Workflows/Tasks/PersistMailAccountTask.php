<?php

namespace App\Services\Workflows\Tasks;

use App\Models\Person;
use Illuminate\Support\Facades\Crypt;

class PersistMailAccountTask
{
    public function handle(Person $person, array $account): array
    {
        $metadata = is_array($person->metadata) ? $person->metadata : [];
        $emailAccount = is_array($metadata['email_account'] ?? null) ? $metadata['email_account'] : [];
        $email = $this->nullableString($account['email'] ?? $emailAccount['email'] ?? $person->person_email);
        $password = trim((string) ($account['password'] ?? ''));

        $emailAccount = array_replace($emailAccount, [
            'email' => $email,
            'provider' => $this->normalizeProvider($account['provider'] ?? $emailAccount['provider'] ?? 'proton'),
            'username' => $this->nullableString($account['username'] ?? $email ?? null),
            'recovery_email' => $this->nullableString($account['recoveryEmail'] ?? $emailAccount['recovery_email'] ?? null),
            'webmail_url' => $this->nullableString($account['webmailUrl'] ?? $emailAccount['webmail_url'] ?? null)
                ?: $this->defaultWebmailUrl($this->normalizeProvider($account['provider'] ?? $emailAccount['provider'] ?? 'proton')),
            'updated_at' => now()->toIso8601String(),
        ]);

        if ($password !== '') {
            $emailAccount['password_encrypted'] = Crypt::encryptString($password);
        }

        $metadata['email_account'] = $emailAccount;

        $person->forceFill([
            'person_email' => $email,
            'metadata' => $metadata,
        ])->save();

        return [
            'ok' => true,
            'status' => 'success',
            'statusMessage' => 'Mail-Account wurde gespeichert.',
            'account' => collect($emailAccount)->except(['password_encrypted'])->all(),
        ];
    }

    protected function normalizeProvider(mixed $provider): string
    {
        $provider = strtolower(trim((string) $provider));

        if ($provider === '' || str_contains($provider, 'proton')) {
            return 'proton';
        }

        if (str_contains($provider, 'gmx')) {
            return 'gmx';
        }

        return $provider;
    }

    protected function defaultWebmailUrl(string $provider): string
    {
        return $provider === 'gmx'
            ? 'https://www.gmx.net'
            : 'https://mail.proton.me';
    }

    protected function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
