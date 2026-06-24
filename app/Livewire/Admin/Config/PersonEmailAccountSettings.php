<?php

namespace App\Livewire\Admin\Config;

use App\Models\Person;
use App\Services\Mail\MailAccountRegistrationRunner;
use App\Services\Mail\WebmailSessionRunner;
use Illuminate\Support\Facades\Crypt;
use Livewire\Component;

class PersonEmailAccountSettings extends Component
{
    public int $personId;

    public ?Person $person = null;

    public string $emailAddress = '';

    public string $provider = '';

    public string $accountUsername = '';

    public string $accountPassword = '';

    public bool $hasStoredPassword = false;

    public string $recoveryEmail = '';

    public string $recoveryPhone = '';

    public string $webmailUrl = '';

    public string $imapHost = '';

    public ?int $imapPort = null;

    public string $imapEncryption = '';

    public string $smtpHost = '';

    public ?int $smtpPort = null;

    public string $smtpEncryption = '';

    public string $notes = '';

    public bool $showMailRegistrationModal = false;

    public ?string $mailRegistrationRunId = null;

    public array $mailRegistrationStatus = [];

    public array $webmailSessionResult = [];

    public function mount(int $personId): void
    {
        $this->personId = $personId;
        $this->loadPerson();
    }

    public function saveSettings(): void
    {
        if (! $this->person) {
            return;
        }

        $validated = $this->validate([
            'emailAddress' => ['nullable', 'email', 'max:255'],
            'provider' => ['nullable', 'string', 'max:120'],
            'accountUsername' => ['nullable', 'string', 'max:255'],
            'accountPassword' => ['nullable', 'string', 'max:512'],
            'recoveryEmail' => ['nullable', 'email', 'max:255'],
            'recoveryPhone' => ['nullable', 'string', 'max:120'],
            'webmailUrl' => ['nullable', 'url', 'max:2048'],
            'imapHost' => ['nullable', 'string', 'max:255'],
            'imapPort' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'imapEncryption' => ['nullable', 'string', 'in:,none,ssl,tls,starttls'],
            'smtpHost' => ['nullable', 'string', 'max:255'],
            'smtpPort' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'smtpEncryption' => ['nullable', 'string', 'in:,none,ssl,tls,starttls'],
            'notes' => ['nullable', 'string', 'max:8000'],
        ]);

        $metadata = is_array($this->person->metadata) ? $this->person->metadata : [];
        $existing = is_array($metadata['email_account'] ?? null) ? $metadata['email_account'] : [];
        $encryptedPassword = $existing['password_encrypted'] ?? null;

        if (trim((string) ($validated['accountPassword'] ?? '')) !== '') {
            $encryptedPassword = Crypt::encryptString((string) $validated['accountPassword']);
        }

        $metadata['email_account'] = [
            'email' => $this->nullableString($validated['emailAddress'] ?? null),
            'provider' => $this->nullableString($validated['provider'] ?? null),
            'username' => $this->nullableString($validated['accountUsername'] ?? null),
            'password_encrypted' => $this->nullableString($encryptedPassword),
            'recovery_email' => $this->nullableString($validated['recoveryEmail'] ?? null),
            'recovery_phone' => $this->nullableString($validated['recoveryPhone'] ?? null),
            'webmail_url' => $this->nullableString($validated['webmailUrl'] ?? null),
            'imap' => [
                'host' => $this->nullableString($validated['imapHost'] ?? null),
                'port' => ($validated['imapPort'] ?? null) !== null ? (int) $validated['imapPort'] : null,
                'encryption' => $this->nullableString($validated['imapEncryption'] ?? null),
            ],
            'smtp' => [
                'host' => $this->nullableString($validated['smtpHost'] ?? null),
                'port' => ($validated['smtpPort'] ?? null) !== null ? (int) $validated['smtpPort'] : null,
                'encryption' => $this->nullableString($validated['smtpEncryption'] ?? null),
            ],
            'notes' => $this->nullableString($validated['notes'] ?? null),
            'updated_at' => now()->toIso8601String(),
        ];

        $this->person->forceFill([
            'person_email' => $this->nullableString($validated['emailAddress'] ?? null),
            'metadata' => $metadata,
        ])->save();

        $this->accountPassword = '';
        $this->loadPerson();
        $this->dispatch('refreshPersonDetail');

        session()->flash('success', 'E-Mail-Accountdaten wurden gespeichert.');
        $this->dispatch('showAlert', 'E-Mail-Account gespeichert.', 'success');
    }

    public function clearStoredPassword(): void
    {
        if (! $this->person) {
            return;
        }

        $metadata = is_array($this->person->metadata) ? $this->person->metadata : [];
        $emailAccount = is_array($metadata['email_account'] ?? null) ? $metadata['email_account'] : [];
        $emailAccount['password_encrypted'] = null;
        $emailAccount['updated_at'] = now()->toIso8601String();
        $metadata['email_account'] = $emailAccount;

        $this->person->forceFill([
            'metadata' => $metadata,
        ])->save();

        $this->accountPassword = '';
        $this->loadPerson();
        $this->dispatch('refreshPersonDetail');

        session()->flash('success', 'Gespeichertes E-Mail-Passwort wurde geloescht.');
    }

    public function startMailRegistration(): void
    {
        if (! $this->person) {
            return;
        }

        try {
            $run = app(MailAccountRegistrationRunner::class)->start($this->mailRegistrationSubject());

            $this->mailRegistrationRunId = $run['runId'] ?? null;
            $this->mailRegistrationStatus = $run;
            $this->showMailRegistrationModal = true;
        } catch (\Throwable $exception) {
            $this->mailRegistrationStatus = [
                'state' => 'failed',
                'stage' => 'start-failed',
                'message' => $exception->getMessage(),
                'events' => [],
            ];
            $this->showMailRegistrationModal = true;
            $this->dispatch('showAlert', 'Mail-Registrierung konnte nicht gestartet werden.', 'error');
        }
    }

    public function refreshMailRegistration(): void
    {
        if (! $this->mailRegistrationRunId) {
            return;
        }

        $run = app(MailAccountRegistrationRunner::class)->readRun($this->mailRegistrationRunId);

        if (is_array($run)) {
            $this->mailRegistrationStatus = $run;
        }
    }

    public function applyMailRegistrationResult(): void
    {
        if (! $this->person || ! $this->mailRegistrationRunId) {
            return;
        }

        $result = app(MailAccountRegistrationRunner::class)->readResult($this->mailRegistrationRunId);
        $account = is_array($result) && is_array($result['account'] ?? null) ? $result['account'] : null;

        if (! $account) {
            $this->dispatch('showAlert', 'Der Lauf enthaelt noch keine uebernehmbaren Accountdaten.', 'warning');

            return;
        }

        $this->emailAddress = (string) ($account['email'] ?? $this->emailAddress);
        $this->provider = (string) ($account['provider'] ?? $this->provider);
        $this->accountUsername = (string) ($account['username'] ?? $this->accountUsername);
        $this->webmailUrl = (string) ($account['webmailUrl'] ?? $this->webmailUrl);
        $this->recoveryEmail = (string) ($account['recoveryEmail'] ?? $this->recoveryEmail);

        if (trim((string) ($account['password'] ?? '')) !== '') {
            $this->accountPassword = (string) $account['password'];
        }

        $this->saveSettings();
        $this->showMailRegistrationModal = false;
    }

    public function closeMailRegistrationModal(): void
    {
        $this->showMailRegistrationModal = false;
    }

    public function buildWebmailSession(): void
    {
        if (! $this->person) {
            return;
        }

        try {
            $password = trim($this->accountPassword) !== ''
                ? $this->accountPassword
                : $this->storedEmailPassword();

            $result = app(WebmailSessionRunner::class)->capture([
                'provider' => $this->provider,
                'email' => $this->emailAddress ?: $this->person->person_email,
                'username' => $this->accountUsername ?: $this->emailAddress,
                'password' => $password,
                'webmailUrl' => $this->webmailUrl,
            ], 'person-'.$this->person->id.'-webmail');

            if (! empty($result['encryptedSessionPayload'])) {
                $this->storeWebmailSessionResult($result);
                $this->loadPerson();
                $this->dispatch('refreshPersonDetail');
            }

            unset($result['encryptedSessionPayload']);
            $this->webmailSessionResult = $result;

            $this->dispatch(
                'showAlert',
                ($result['ok'] ?? false) ? 'Webmail-Session wurde gespeichert.' : 'Webmail-Session wurde mit Hinweisen gespeichert.',
                ($result['ok'] ?? false) ? 'success' : 'warning'
            );
        } catch (\Throwable $exception) {
            $this->webmailSessionResult = [
                'ok' => false,
                'statusMessage' => 'Webmail-Session konnte nicht gespeichert werden.',
                'warnings' => [$exception->getMessage()],
                'notes' => [],
            ];

            $this->dispatch('showAlert', 'Webmail-Session konnte nicht gespeichert werden.', 'error');
        }
    }

    public function render()
    {
        return view('livewire.admin.config.person-email-account-settings');
    }

    protected function loadPerson(): void
    {
        $this->person = Person::query()->find($this->personId);

        if (! $this->person) {
            return;
        }

        $metadata = is_array($this->person->metadata) ? $this->person->metadata : [];
        $emailAccount = is_array($metadata['email_account'] ?? null) ? $metadata['email_account'] : [];

        $this->emailAddress = (string) ($emailAccount['email'] ?? $this->person->person_email ?? '');
        $this->provider = (string) ($emailAccount['provider'] ?? '');
        $this->accountUsername = (string) ($emailAccount['username'] ?? $this->emailAddress ?: $this->suggestedUsername());
        $this->accountPassword = '';
        $this->hasStoredPassword = trim((string) ($emailAccount['password_encrypted'] ?? '')) !== '';
        $this->recoveryEmail = (string) ($emailAccount['recovery_email'] ?? '');
        $this->recoveryPhone = (string) ($emailAccount['recovery_phone'] ?? '');
        $this->webmailUrl = (string) ($emailAccount['webmail_url'] ?? '');
        $this->imapHost = (string) data_get($emailAccount, 'imap.host', '');
        $this->imapPort = data_get($emailAccount, 'imap.port') !== null ? (int) data_get($emailAccount, 'imap.port') : null;
        $this->imapEncryption = (string) data_get($emailAccount, 'imap.encryption', '');
        $this->smtpHost = (string) data_get($emailAccount, 'smtp.host', '');
        $this->smtpPort = data_get($emailAccount, 'smtp.port') !== null ? (int) data_get($emailAccount, 'smtp.port') : null;
        $this->smtpEncryption = (string) data_get($emailAccount, 'smtp.encryption', '');
        $this->notes = (string) ($emailAccount['notes'] ?? '');
    }

    protected function storedEmailPassword(): string
    {
        if (! $this->person) {
            return '';
        }

        $metadata = is_array($this->person->metadata) ? $this->person->metadata : [];
        $encrypted = data_get($metadata, 'email_account.password_encrypted');

        if (! is_string($encrypted) || trim($encrypted) === '') {
            return '';
        }

        try {
            return Crypt::decryptString($encrypted);
        } catch (\Throwable) {
            return '';
        }
    }

    protected function storeWebmailSessionResult(array $result): void
    {
        if (! $this->person) {
            return;
        }

        $metadata = is_array($this->person->metadata) ? $this->person->metadata : [];
        $emailAccount = is_array($metadata['email_account'] ?? null) ? $metadata['email_account'] : [];
        $summary = is_array($result['sessionSummary'] ?? null) ? $result['sessionSummary'] : [];

        $emailAccount['webmail_session'] = [
            'payload_encrypted' => (string) $result['encryptedSessionPayload'],
            'payload_hash' => (string) ($result['sessionPayloadHash'] ?? ''),
            'captured_at' => (string) ($summary['capturedAt'] ?? now()->toIso8601String()),
            'final_url' => $summary['finalUrl'] ?? ($result['finalUrl'] ?? null),
            'origin' => $summary['origin'] ?? null,
            'cookie_count' => (int) ($summary['cookieCount'] ?? ($result['cookieCount'] ?? 0)),
            'script_name' => (string) ($result['scriptName'] ?? 'webmail_session.cjs'),
            'script_version' => (int) ($result['scriptVersion'] ?? 1),
            'updated_at' => now()->toIso8601String(),
        ];
        $metadata['email_account'] = $emailAccount;

        $this->person->forceFill([
            'metadata' => $metadata,
        ])->save();
    }

    protected function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    protected function mailRegistrationSubject(): array
    {
        $username = $this->accountUsername ?: $this->emailAddress ?: $this->suggestedUsername();

        return [
            'personId' => $this->person?->id,
            'displayName' => $this->person?->display_name ?? trim($this->person?->profile_label ?? ''),
            'firstName' => $this->person?->person_first_name,
            'lastName' => $this->person?->person_last_name,
            'desiredEmail' => $this->emailAddress ?: ($this->person?->person_email ?? ''),
            'accountUsername' => $username,
            'recoveryEmail' => $this->recoveryEmail,
            'city' => $this->person?->person_city,
            'country' => $this->person?->person_country,
            'timezone' => $this->person?->person_timezone,
        ];
    }

    protected function suggestedUsername(): string
    {
        $source = trim((string) (
            $this->person?->profile_key
            ?: $this->person?->person_alias
            ?: $this->person?->display_name
            ?: ''
        ));

        return str($source)
            ->lower()
            ->ascii()
            ->replaceMatches('/[^a-z0-9._-]+/', '-')
            ->replaceMatches('/^[._-]+|[._-]+$/', '')
            ->replaceMatches('/[._-]{2,}/', '-')
            ->limit(64, '')
            ->toString();
    }
}
