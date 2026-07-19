<?php

namespace App\Livewire\Admin\Config;

use App\Models\Person;
use App\Models\PersonEmailAccount;
use App\Services\Mail\MailAccountRegistrationRunner;
use App\Services\Mail\WebmailSessionRunner;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\Rule;
use Livewire\Component;

class PersonEmailAccountSettings extends Component
{
    /**
     * Unterstuetzte Provider. Nur proton/gmx erlauben die automatische
     * Registrierung und Webmail-Session; custom ist rein manuell.
     */
    public const PROVIDERS = [
        'proton' => ['label' => 'Proton Mail', 'webmail' => 'https://mail.proton.me', 'auto' => true],
        'gmx' => ['label' => 'GMX', 'webmail' => 'https://www.gmx.net', 'auto' => true],
        'custom' => ['label' => 'Custom / Andere', 'webmail' => '', 'auto' => false],
    ];

    public int $personId;

    public ?Person $person = null;

    /** @var array<int, array<string, mixed>> Anzeige-Liste der Accounts (ohne Klartext-Passwoerter). */
    public array $accounts = [];

    // --- Formularzustand (ein Account, neu oder in Bearbeitung) ---
    public ?int $editingAccountId = null;

    public bool $showForm = false;

    public string $emailAddress = '';

    public string $provider = 'proton';

    public string $accountUsername = '';

    public string $accountPassword = '';

    public bool $hasStoredPassword = false;

    public array $editingWebmailSession = [];

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

    // --- Mail-Registrierung / Webmail-Session ---
    public bool $showMailRegistrationModal = false;

    public ?string $mailRegistrationRunId = null;

    public array $mailRegistrationStatus = [];

    public array $webmailSessionResult = [];

    public function mount(int $personId): void
    {
        $this->personId = $personId;
        $this->loadPerson();
    }

    public function render()
    {
        return view('livewire.admin.config.person-email-account-settings', [
            'providers' => self::PROVIDERS,
        ]);
    }

    // ==================================================================
    // Listen- / Formularsteuerung
    // ==================================================================

    public function newAccount(): void
    {
        $this->resetForm();
        $this->editingAccountId = null;
        $this->provider = 'proton';
        $this->accountUsername = $this->suggestedUsername();
        $this->webmailUrl = $this->defaultWebmailUrl('proton');
        $this->webmailSessionResult = [];
        $this->showForm = true;
    }

    public function editAccount(int $accountId): void
    {
        $account = $this->findAccount($accountId);

        if (! $account) {
            return;
        }

        $this->fillFormFromAccount($account);
        $this->editingAccountId = $account->id;
        $this->webmailSessionResult = [];
        $this->resetErrorBag();
        $this->showForm = true;
    }

    public function cancelForm(): void
    {
        $this->resetForm();
        $this->showForm = false;
        $this->editingAccountId = null;
    }

    public function saveSettings(): void
    {
        if (! $this->person) {
            return;
        }

        $validated = $this->validate([
            'emailAddress' => ['nullable', 'email', 'max:255'],
            'provider' => ['required', 'string', Rule::in(array_keys(self::PROVIDERS))],
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

        $account = $this->persistAccountFromForm($validated);

        $this->editingAccountId = $account->id;
        $this->accountPassword = '';
        $this->syncPrimaryMirror();
        $this->loadPerson();
        $this->fillFormFromAccount($account->fresh());
        $this->dispatch('refreshPersonDetail');

        session()->flash('success', 'E-Mail-Account wurde gespeichert.');
        $this->dispatch('showAlert', 'E-Mail-Account gespeichert.', 'success');
    }

    public function deleteAccount(int $accountId): void
    {
        $account = $this->findAccount($accountId);

        if (! $account) {
            return;
        }

        $wasPrimary = (bool) $account->is_primary;
        $account->delete();

        if ($wasPrimary) {
            $next = $this->person->emailAccounts()->first();
            if ($next) {
                $next->forceFill(['is_primary' => true])->save();
            }
        }

        if ($this->editingAccountId === $accountId) {
            $this->cancelForm();
        }

        $this->syncPrimaryMirror();
        $this->loadPerson();
        $this->dispatch('refreshPersonDetail');
        $this->dispatch('showAlert', 'E-Mail-Account geloescht.', 'success');
    }

    public function setPrimaryAccount(int $accountId): void
    {
        $account = $this->findAccount($accountId);

        if (! $account) {
            return;
        }

        $this->person->emailAccounts()->update(['is_primary' => false]);
        $account->forceFill(['is_primary' => true])->save();

        $this->syncPrimaryMirror();
        $this->loadPerson();
        $this->dispatch('refreshPersonDetail');
        $this->dispatch('showAlert', 'Primaerer Account gesetzt.', 'success');
    }

    public function clearStoredPassword(): void
    {
        if (! $this->editingAccountId) {
            return;
        }

        $account = $this->findAccount($this->editingAccountId);

        if (! $account) {
            return;
        }

        $account->forceFill(['password_encrypted' => null])->save();

        $this->accountPassword = '';
        $this->hasStoredPassword = false;
        $this->syncPrimaryMirror();
        $this->loadPerson();
        $this->dispatch('refreshPersonDetail');

        session()->flash('success', 'Gespeichertes E-Mail-Passwort wurde geloescht.');
    }

    public function updatedProvider(): void
    {
        $this->provider = $this->normalizeProvider($this->provider);

        $default = $this->defaultWebmailUrl($this->provider);
        $knownDefaults = array_values(array_filter(array_map(static fn ($p) => $p['webmail'], self::PROVIDERS)));

        if ($default !== '' && (trim($this->webmailUrl) === '' || in_array(trim($this->webmailUrl), $knownDefaults, true))) {
            $this->webmailUrl = $default;
        }
    }

    // ==================================================================
    // Mail-Registrierung (nur proton/gmx)
    // ==================================================================

    public function startMailRegistration(): void
    {
        if (! $this->person) {
            return;
        }

        if (! $this->showForm) {
            $this->newAccount();
        }

        try {
            $runner = app(MailAccountRegistrationRunner::class);
            $settings = $runner->settings();
            $run = $runner->start($this->mailRegistrationSubject());

            $this->mailRegistrationRunId = $run['runId'] ?? null;
            $this->mailRegistrationStatus = $run;
            $this->showMailRegistrationModal = (bool) ($settings['preview_modal_enabled'] ?? true);
            $this->dispatch('showAlert', 'Mail-Registrierung wurde gestartet.', 'success');
        } catch (\Throwable $exception) {
            $settings = app(MailAccountRegistrationRunner::class)->settings();
            $this->mailRegistrationStatus = [
                'state' => 'failed',
                'stage' => 'start-failed',
                'message' => $exception->getMessage(),
                'events' => [],
            ];
            $this->showMailRegistrationModal = (bool) ($settings['preview_modal_enabled'] ?? true);
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
        $this->provider = $this->normalizeProvider($account['provider'] ?? $this->provider);
        $this->accountUsername = (string) ($account['username'] ?? $this->accountUsername);
        $this->webmailUrl = (string) ($account['webmailUrl'] ?? $this->webmailUrl) ?: $this->defaultWebmailUrl($this->provider);
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

    // ==================================================================
    // Webmail-Session (nur proton/gmx)
    // ==================================================================

    public function buildWebmailSession(): void
    {
        if (! $this->person) {
            return;
        }

        // Account muss persistiert sein, damit die Session einem Account zugeordnet werden kann.
        if (! $this->editingAccountId) {
            $this->saveSettings();
        }

        $account = $this->editingAccountId ? $this->findAccount($this->editingAccountId) : null;

        if (! $account) {
            $this->dispatch('showAlert', 'Bitte den Account zuerst speichern.', 'warning');

            return;
        }

        try {
            $password = trim($this->accountPassword) !== ''
                ? $this->accountPassword
                : $this->storedEmailPassword($account);

            $result = app(WebmailSessionRunner::class)->capture([
                'provider' => $this->provider,
                'email' => $this->emailAddress ?: $this->person->person_email,
                'username' => $this->accountUsername ?: $this->emailAddress,
                'password' => $password,
                'webmailUrl' => $this->webmailUrl,
            ], 'person-'.$this->person->id.'-account-'.$account->id.'-webmail');

            if (! empty($result['encryptedSessionPayload'])) {
                $this->storeWebmailSessionResult($account, $result);
                $this->syncPrimaryMirror();
                $this->loadPerson();
                $this->editingWebmailSession = is_array($account->fresh()->webmail_session) ? $account->fresh()->webmail_session : [];
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

    // ==================================================================
    // Interne Helfer
    // ==================================================================

    protected function loadPerson(): void
    {
        $this->person = Person::query()->find($this->personId);

        if (! $this->person) {
            $this->accounts = [];

            return;
        }

        $this->accounts = $this->person->emailAccounts()->get()->map(function (PersonEmailAccount $account): array {
            return [
                'id' => $account->id,
                'email' => (string) ($account->email ?? ''),
                'provider' => $account->provider,
                'provider_label' => self::PROVIDERS[$account->provider]['label'] ?? ucfirst((string) $account->provider),
                'username' => (string) ($account->username ?? ''),
                'is_primary' => (bool) $account->is_primary,
                'has_password' => $account->hasStoredPassword(),
                'has_webmail_session' => $account->hasWebmailSession(),
                'webmail_cookie_count' => (int) data_get($account->webmail_session, 'cookie_count', 0),
                'updated_at_label' => optional($account->updated_at)->format('d.m.Y H:i') ?? '-',
            ];
        })->all();
    }

    protected function findAccount(int $accountId): ?PersonEmailAccount
    {
        if (! $this->person) {
            return null;
        }

        return $this->person->emailAccounts()->whereKey($accountId)->first();
    }

    protected function fillFormFromAccount(PersonEmailAccount $account): void
    {
        $this->emailAddress = (string) ($account->email ?? '');
        $this->provider = $this->normalizeProvider($account->provider);
        $this->accountUsername = (string) ($account->username ?? '');
        $this->accountPassword = '';
        $this->hasStoredPassword = $account->hasStoredPassword();
        $this->recoveryEmail = (string) ($account->recovery_email ?? '');
        $this->recoveryPhone = (string) ($account->recovery_phone ?? '');
        $this->webmailUrl = (string) ($account->webmail_url ?? '') ?: $this->defaultWebmailUrl($this->provider);
        $this->imapHost = (string) ($account->imap_host ?? '');
        $this->imapPort = $account->imap_port !== null ? (int) $account->imap_port : null;
        $this->imapEncryption = (string) ($account->imap_encryption ?? '');
        $this->smtpHost = (string) ($account->smtp_host ?? '');
        $this->smtpPort = $account->smtp_port !== null ? (int) $account->smtp_port : null;
        $this->smtpEncryption = (string) ($account->smtp_encryption ?? '');
        $this->notes = (string) ($account->notes ?? '');
        $this->editingWebmailSession = is_array($account->webmail_session) ? $account->webmail_session : [];
    }

    protected function persistAccountFromForm(array $validated): PersonEmailAccount
    {
        $account = $this->editingAccountId ? $this->findAccount($this->editingAccountId) : null;

        if (! $account) {
            $account = new PersonEmailAccount(['person_id' => $this->person->id]);
            $account->person_id = $this->person->id;
        }

        $encryptedPassword = $account->password_encrypted;
        if (trim((string) ($validated['accountPassword'] ?? '')) !== '') {
            $encryptedPassword = Crypt::encryptString((string) $validated['accountPassword']);
        }

        $provider = $this->normalizeProvider($validated['provider'] ?? 'proton');
        $webmailUrl = trim((string) ($validated['webmailUrl'] ?? '')) ?: $this->defaultWebmailUrl($provider);

        $account->forceFill([
            'email' => $this->nullableString($validated['emailAddress'] ?? null),
            'provider' => $provider,
            'username' => $this->nullableString($validated['accountUsername'] ?? null),
            'password_encrypted' => $encryptedPassword,
            'recovery_email' => $this->nullableString($validated['recoveryEmail'] ?? null),
            'recovery_phone' => $this->nullableString($validated['recoveryPhone'] ?? null),
            'webmail_url' => $this->nullableString($webmailUrl),
            'imap_host' => $this->nullableString($validated['imapHost'] ?? null),
            'imap_port' => ($validated['imapPort'] ?? null) !== null ? (int) $validated['imapPort'] : null,
            'imap_encryption' => $this->nullableString($validated['imapEncryption'] ?? null),
            'smtp_host' => $this->nullableString($validated['smtpHost'] ?? null),
            'smtp_port' => ($validated['smtpPort'] ?? null) !== null ? (int) $validated['smtpPort'] : null,
            'smtp_encryption' => $this->nullableString($validated['smtpEncryption'] ?? null),
            'notes' => $this->nullableString($validated['notes'] ?? null),
        ]);

        // Erster Account einer Person wird automatisch primaer.
        if (! $account->exists && $this->person->emailAccounts()->count() === 0) {
            $account->is_primary = true;
        }

        $account->save();

        $this->ensureSinglePrimary();

        return $account->fresh();
    }

    protected function ensureSinglePrimary(): void
    {
        $accounts = $this->person->emailAccounts()->get();

        if ($accounts->isEmpty()) {
            return;
        }

        $primaries = $accounts->where('is_primary', true);

        if ($primaries->count() === 1) {
            return;
        }

        if ($primaries->isEmpty()) {
            $accounts->first()->forceFill(['is_primary' => true])->save();

            return;
        }

        $keepId = $primaries->first()->id;
        foreach ($primaries as $primary) {
            if ($primary->id !== $keepId) {
                $primary->forceFill(['is_primary' => false])->save();
            }
        }
    }

    /**
     * Spiegelt den primaeren Account nach persons.metadata['email_account']
     * und person_email, damit Automatisierung/Workflow-Leser unveraendert laufen.
     */
    protected function syncPrimaryMirror(): void
    {
        if (! $this->person) {
            return;
        }

        $primary = $this->person->emailAccounts()->where('is_primary', true)->first()
            ?? $this->person->emailAccounts()->first();

        $metadata = is_array($this->person->metadata) ? $this->person->metadata : [];

        if ($primary) {
            $metadata['email_account'] = $primary->toMetadataAccount();
            $this->person->forceFill([
                'metadata' => $metadata,
                'person_email' => $primary->email,
            ])->save();
        } else {
            unset($metadata['email_account']);
            $this->person->forceFill(['metadata' => $metadata])->save();
        }
    }

    protected function storeWebmailSessionResult(PersonEmailAccount $account, array $result): void
    {
        $summary = is_array($result['sessionSummary'] ?? null) ? $result['sessionSummary'] : [];

        $account->forceFill([
            'webmail_session' => [
                'payload_encrypted' => (string) $result['encryptedSessionPayload'],
                'payload_hash' => (string) ($result['sessionPayloadHash'] ?? ''),
                'captured_at' => (string) ($summary['capturedAt'] ?? now()->toIso8601String()),
                'final_url' => $summary['finalUrl'] ?? ($result['finalUrl'] ?? null),
                'origin' => $summary['origin'] ?? null,
                'cookie_count' => (int) ($summary['cookieCount'] ?? ($result['cookieCount'] ?? 0)),
                'script_name' => (string) ($result['scriptName'] ?? 'webmail_session.cjs'),
                'script_version' => (int) ($result['scriptVersion'] ?? 1),
                'updated_at' => now()->toIso8601String(),
            ],
        ])->save();
    }

    protected function storedEmailPassword(PersonEmailAccount $account): string
    {
        $encrypted = $account->password_encrypted;

        if (! is_string($encrypted) || trim($encrypted) === '') {
            return '';
        }

        try {
            return Crypt::decryptString($encrypted);
        } catch (\Throwable) {
            return '';
        }
    }

    protected function resetForm(): void
    {
        $this->emailAddress = '';
        $this->provider = 'proton';
        $this->accountUsername = '';
        $this->accountPassword = '';
        $this->hasStoredPassword = false;
        $this->recoveryEmail = '';
        $this->recoveryPhone = '';
        $this->webmailUrl = '';
        $this->imapHost = '';
        $this->imapPort = null;
        $this->imapEncryption = '';
        $this->smtpHost = '';
        $this->smtpPort = null;
        $this->smtpEncryption = '';
        $this->notes = '';
        $this->editingWebmailSession = [];
        $this->resetErrorBag();
    }

    protected function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    protected function normalizeProvider(mixed $provider): string
    {
        $provider = strtolower(trim((string) $provider));

        if (array_key_exists($provider, self::PROVIDERS)) {
            return $provider;
        }

        if (str_contains($provider, 'proton')) {
            return 'proton';
        }

        if (str_contains($provider, 'gmx')) {
            return 'gmx';
        }

        return 'custom';
    }

    protected function defaultWebmailUrl(string $provider): string
    {
        return self::PROVIDERS[$provider]['webmail'] ?? '';
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
