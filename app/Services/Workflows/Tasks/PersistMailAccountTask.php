<?php

namespace App\Services\Workflows\Tasks;

use App\Models\Person;
use App\Models\PersonEmailAccount;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PersistMailAccountTask
{
    public function handle(Person $person, array $account): array
    {
        return DB::transaction(function () use ($person, $account): array {
            $lockedPerson = Person::query()->whereKey($person->getKey())->lockForUpdate()->first();

            if (! $lockedPerson) {
                throw new RuntimeException('Die Person fuer den Mail-Account existiert nicht mehr.');
            }

            $metadata = is_array($lockedPerson->metadata) ? $lockedPerson->metadata : [];
            $mirror = is_array($metadata['email_account'] ?? null) ? $metadata['email_account'] : [];
            $email = $this->nullableString($account['email'] ?? $mirror['email'] ?? $lockedPerson->person_email);
            $requestedId = $this->accountId($account);
            $accountQuery = PersonEmailAccount::query()->where('person_id', $lockedPerson->id);

            if ($requestedId !== null) {
                $canonicalAccount = (clone $accountQuery)->whereKey($requestedId)->lockForUpdate()->first();

                if (! $canonicalAccount) {
                    throw new RuntimeException('Der angegebene Mail-Account gehoert nicht zu dieser Person.');
                }
            } else {
                $canonicalAccount = $email === null
                    ? null
                    : (clone $accountQuery)
                        ->whereRaw('LOWER(email) = ?', [strtolower($email)])
                        ->orderByDesc('is_primary')
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->first();
            }

            $password = trim((string) ($account['password'] ?? ''));
            $provider = $this->normalizeProvider($account['provider'] ?? $canonicalAccount?->provider ?? $mirror['provider'] ?? 'proton');
            $attributes = [
                'email' => $email,
                'provider' => $provider,
                'username' => $this->nullableString($account['username'] ?? $canonicalAccount?->username ?? $email),
                'recovery_email' => $this->nullableString($account['recoveryEmail'] ?? $account['recovery_email'] ?? $canonicalAccount?->recovery_email ?? null),
                'recovery_phone' => $this->nullableString($account['recoveryPhone'] ?? $account['recovery_phone'] ?? $canonicalAccount?->recovery_phone ?? null),
                'webmail_url' => $this->nullableString($account['webmailUrl'] ?? $account['webmail_url'] ?? $canonicalAccount?->webmail_url)
                    ?: $this->defaultWebmailUrl($provider),
                'imap_host' => $this->nullableString(data_get($account, 'imap.host', $canonicalAccount?->imap_host)),
                'imap_port' => data_get($account, 'imap.port', $canonicalAccount?->imap_port),
                'imap_encryption' => $this->nullableString(data_get($account, 'imap.encryption', $canonicalAccount?->imap_encryption)),
                'smtp_host' => $this->nullableString(data_get($account, 'smtp.host', $canonicalAccount?->smtp_host)),
                'smtp_port' => data_get($account, 'smtp.port', $canonicalAccount?->smtp_port),
                'smtp_encryption' => $this->nullableString(data_get($account, 'smtp.encryption', $canonicalAccount?->smtp_encryption)),
                'notes' => $this->nullableString($account['notes'] ?? $canonicalAccount?->notes),
            ];

            if ($password !== '') {
                $attributes['password_encrypted'] = Crypt::encryptString($password);
            }

            $hasPrimary = (clone $accountQuery)->where('is_primary', true)->exists();
            $requestedPrimary = array_key_exists('is_primary', $account)
                ? filter_var($account['is_primary'], FILTER_VALIDATE_BOOLEAN)
                : null;
            $isPrimary = $requestedPrimary === true || (bool) $canonicalAccount?->is_primary || ! $hasPrimary;

            if ($isPrimary) {
                (clone $accountQuery)->where('is_primary', true)->update(['is_primary' => false]);
            }

            if ($canonicalAccount) {
                $canonicalAccount->forceFill([...$attributes, 'is_primary' => $isPrimary])->save();
            } else {
                $sortOrder = (int) ((clone $accountQuery)->max('sort_order') ?? -1) + 1;
                $canonicalAccount = PersonEmailAccount::query()->create([
                    ...$attributes,
                    'person_id' => $lockedPerson->id,
                    'is_primary' => $isPrimary,
                    'sort_order' => $sortOrder,
                ]);
            }

            $primaryMirror = (clone $accountQuery)
                ->orderByDesc('is_primary')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->first();

            if ($primaryMirror) {
                $metadata['email_account'] = $primaryMirror->toMetadataAccount();
                $lockedPerson->forceFill([
                    'person_email' => $primaryMirror->email,
                    'metadata' => $metadata,
                ])->save();
            } else {
                unset($metadata['email_account']);
                $lockedPerson->forceFill(['metadata' => $metadata])->save();
            }

            return [
                'ok' => true,
                'status' => 'success',
                'statusMessage' => 'Mail-Account wurde in der kanonischen Accounttabelle gespeichert.',
                'accountId' => $canonicalAccount->id,
                'account' => collect($canonicalAccount->fresh()->toArray())->except(['password_encrypted'])->all(),
            ];
        });
    }

    protected function accountId(array $account): ?int
    {
        foreach (['person_email_account_id', 'email_account_id', 'account_id'] as $key) {
            $value = $account[$key] ?? null;

            if (is_numeric($value) && (int) $value > 0) {
                return (int) $value;
            }
        }

        return null;
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
