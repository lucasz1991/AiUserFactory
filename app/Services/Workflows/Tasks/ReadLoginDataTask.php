<?php

namespace App\Services\Workflows\Tasks;

use App\Models\Person;
use Illuminate\Support\Facades\Crypt;

class ReadLoginDataTask
{
    public function handle(Person $person): array
    {
        $emailAccount = is_array(data_get($person->metadata, 'email_account'))
            ? data_get($person->metadata, 'email_account')
            : [];

        $email = trim((string) ($emailAccount['email'] ?? $person->person_email ?? ''));
        $username = trim((string) ($emailAccount['username'] ?? $email));
        $password = $this->decryptString($emailAccount['password_encrypted'] ?? null);

        if ($email === '' || $username === '' || trim((string) $password) === '') {
            return [
                'ok' => false,
                'status' => 'failed',
                'statusMessage' => 'Login-Daten sind unvollstaendig.',
            ];
        }

        return [
            'ok' => true,
            'status' => 'success',
            'statusMessage' => 'Login-Daten wurden vorbereitet.',
            'account' => [
                'provider' => (string) ($emailAccount['provider'] ?? 'proton'),
                'email' => $email,
                'username' => $username,
                'webmailUrl' => $emailAccount['webmail_url'] ?? null,
            ],
        ];
    }

    protected function decryptString(mixed $encrypted): ?string
    {
        if (! is_string($encrypted) || trim($encrypted) === '') {
            return null;
        }

        try {
            return Crypt::decryptString($encrypted);
        } catch (\Throwable) {
            return null;
        }
    }
}
