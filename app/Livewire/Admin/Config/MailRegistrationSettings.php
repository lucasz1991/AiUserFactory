<?php

namespace App\Livewire\Admin\Config;

use App\Services\Mail\MailAccountRegistrationRunner;
use App\Services\Mail\WebmailSessionRunner;
use Illuminate\Support\Facades\Crypt;
use Livewire\Component;

class MailRegistrationSettings extends Component
{
    public string $browserEngine = 'cloak-with-chrome-fallback';
    public bool $cloakHumanizeEnabled = false;
    public string $cloakHumanPreset = '';
    public bool $headlessEnabled = false;
    public bool $previewModalEnabled = true;
    public bool $livePreviewEnabled = true;
    public int $livePreviewIntervalSeconds = 3;
    public bool $browserActivityCheckEnabled = true;
    public bool $domDebugEnabled = true;
    public int $navigationTimeoutSeconds = 120;
    public int $observationTimeoutSeconds = 300;

    public bool $verificationMailboxEnabled = false;
    public string $verificationMailboxEmail = '';
    public string $verificationMailboxProvider = '';
    public string $verificationMailboxUsername = '';
    public string $verificationMailboxPassword = '';
    public bool $hasStoredVerificationMailboxPassword = false;
    public string $verificationMailboxWebmailUrl = '';

    public bool $providerOneEnabled = true;
    public string $providerOneMode = 'proton_username_check';
    public string $providerOneLabel = '';
    public string $providerOneRegistrationUrl = '';
    public string $providerOneCompletionUrlContains = '';
    public string $providerOneCompletionSelector = '';
    public string $providerOneWebmailUrl = '';

    public bool $providerTwoEnabled = false;
    public string $providerTwoLabel = '';
    public string $providerTwoRegistrationUrl = '';

    public bool $providerThreeEnabled = false;
    public string $providerThreeLabel = '';
    public string $providerThreeRegistrationUrl = '';

    public bool $showRegistrationRunModal = false;
    public ?string $registrationRunId = null;
    public array $registrationRunStatus = [];
    public array $verificationMailboxSessionResult = [];
    public bool $showVerificationMailboxSessionModal = false;
    public ?string $verificationMailboxSessionRunId = null;

    public function mount(MailAccountRegistrationRunner $runner): void
    {
        $this->fillFromSettings($runner->settings());
    }

    public function saveSettings(bool $flash = true): void
    {
        $settings = app(MailAccountRegistrationRunner::class)->saveSettings($this->settingsPayload());
        $this->fillFromSettings($settings);

        if ($flash) {
            session()->flash('success', 'Mail-Registrierungseinstellungen wurden gespeichert.');
            $this->dispatch('showAlert', 'Mail-Registrierung gespeichert.', 'success');
        }
    }

    public function startTestRun(): void
    {
        try {
            $this->saveSettings(false);
            $testUsername = 'testlauf-'.now()->format('YmdHis');

            $run = app(MailAccountRegistrationRunner::class)->start([
                'displayName' => 'Testlauf Mail-Registrierung',
                'desiredEmail' => $testUsername.'@proton.me',
                'accountUsername' => $testUsername,
            ]);

            $this->registrationRunId = $run['runId'] ?? null;
            $this->registrationRunStatus = $run;
            $this->showRegistrationRunModal = (bool) ($run['previewModalEnabled'] ?? $this->previewModalEnabled);
            $this->dispatch('showAlert', 'Mail-Registrierung wurde gestartet.', 'success');
        } catch (\Throwable $exception) {
            $this->registrationRunStatus = [
                'state' => 'failed',
                'stage' => 'start-failed',
                'message' => $exception->getMessage(),
                'events' => [],
            ];
            $this->showRegistrationRunModal = $this->previewModalEnabled;
            $this->dispatch('showAlert', 'Mail-Registrierung konnte nicht gestartet werden.', 'error');
        }
    }

    public function refreshRegistrationRun(): void
    {
        if (! $this->registrationRunId) {
            return;
        }

        $run = app(MailAccountRegistrationRunner::class)->readRun($this->registrationRunId);

        if (is_array($run)) {
            $this->registrationRunStatus = $run;
        }
    }

    public function closeRegistrationRunModal(): void
    {
        $this->showRegistrationRunModal = false;
    }

    public function clearVerificationMailboxPassword(): void
    {
        $settings = app(MailAccountRegistrationRunner::class)->settings();
        $mailbox = is_array($settings['verification_mailbox'] ?? null) ? $settings['verification_mailbox'] : [];
        $mailbox['password_encrypted'] = null;
        $settings['verification_mailbox'] = $mailbox;

        $settings = app(MailAccountRegistrationRunner::class)->saveSettings($settings);
        $this->fillFromSettings($settings);

        session()->flash('success', 'Passwort des Verifikations-Postfachs wurde geloescht.');
        $this->dispatch('showAlert', 'Verifikations-Passwort geloescht.', 'success');
    }

    public function updatedVerificationMailboxProvider(mixed $value = null): void
    {
        $this->verificationMailboxProvider = $this->normalizeWebmailProvider($this->verificationMailboxProvider);
        $this->verificationMailboxWebmailUrl = $this->defaultWebmailUrl($this->verificationMailboxProvider);
    }

    public function buildVerificationMailboxWebmailSession(): void
    {
        try {
            $this->saveSettings(false);
            $settings = app(MailAccountRegistrationRunner::class)->settings();
            $mailbox = is_array($settings['verification_mailbox'] ?? null) ? $settings['verification_mailbox'] : [];
            $password = $this->decryptStoredPassword($mailbox['password_encrypted'] ?? null);

            $run = app(WebmailSessionRunner::class)->start([
                'provider' => $mailbox['provider'] ?? '',
                'email' => $mailbox['email'] ?? '',
                'username' => ($mailbox['username'] ?? '') ?: ($mailbox['email'] ?? ''),
                'password' => $password,
                'webmailUrl' => $mailbox['webmail_url'] ?? '',
                'browserEngine' => $settings['browser_engine'] ?? 'cloak-with-chrome-fallback',
                'cloakHumanizeEnabled' => (bool) ($settings['cloak_humanize_enabled'] ?? false),
                'cloakHumanPreset' => $settings['cloak_human_preset'] ?? '',
                'headlessEnabled' => (bool) ($settings['headless_enabled'] ?? false),
                'livePreviewEnabled' => (bool) ($settings['live_preview_enabled'] ?? true),
                'livePreviewIntervalMs' => max(1000, (int) ($settings['live_preview_interval_seconds'] ?? 3) * 1000),
                'livePreviewIntervalSeconds' => max(1, (int) ($settings['live_preview_interval_seconds'] ?? 3)),
                'navigationTimeoutMs' => ((int) ($settings['navigation_timeout_seconds'] ?? 120)) * 1000,
                'observationTimeoutMs' => min(180000, max(30000, ((int) ($settings['observation_timeout_seconds'] ?? 60)) * 1000)),
            ], 'master-mailbox-webmail');

            $this->verificationMailboxSessionRunId = $run['runId'] ?? null;
            $this->verificationMailboxSessionResult = $run;
            $this->showVerificationMailboxSessionModal = (bool) ($settings['preview_modal_enabled'] ?? true);

            $this->dispatch('showAlert', 'Master-Webmail-Sessionlauf wurde gestartet.', 'success');
        } catch (\Throwable $exception) {
            $this->verificationMailboxSessionResult = [
                'ok' => false,
                'state' => 'failed',
                'statusMessage' => 'Master-Webmail-Session konnte nicht gespeichert werden.',
                'message' => 'Master-Webmail-Session konnte nicht gespeichert werden.',
                'warnings' => [$exception->getMessage()],
                'notes' => [],
            ];
            $this->showVerificationMailboxSessionModal = $this->previewModalEnabled;

            $this->dispatch('showAlert', 'Master-Webmail-Session konnte nicht gespeichert werden.', 'error');
        }
    }

    public function refreshVerificationMailboxSessionRun(): void
    {
        if (! $this->verificationMailboxSessionRunId) {
            return;
        }

        $run = app(WebmailSessionRunner::class)->readRun($this->verificationMailboxSessionRunId);

        if (! is_array($run)) {
            return;
        }

        $result = is_array($run['result'] ?? null) ? $run['result'] : [];

        if (! empty($result['encryptedSessionPayload'])) {
            $settings = app(MailAccountRegistrationRunner::class)->settings();
            $settings['verification_mailbox']['webmail_session'] = $this->webmailSessionPayload($result);
            $settings = app(MailAccountRegistrationRunner::class)->saveSettings($settings);
            $this->fillFromSettings($settings);
            unset($result['encryptedSessionPayload']);
            $run['result'] = $result;
            $this->verificationMailboxSessionRunId = null;

            $this->dispatch(
                'showAlert',
                ($result['ok'] ?? false) ? 'Master-Webmail-Session wurde gespeichert.' : 'Master-Webmail-Session wurde mit Hinweisen beendet.',
                ($result['ok'] ?? false) ? 'success' : 'warning'
            );
        }

        $this->verificationMailboxSessionResult = array_replace($run, $result);
    }

    public function closeVerificationMailboxSessionModal(): void
    {
        $this->showVerificationMailboxSessionModal = false;
    }

    public function render()
    {
        return view('livewire.admin.config.mail-registration-settings');
    }

    protected function settingsPayload(): array
    {
        $validated = $this->validate([
            'browserEngine' => ['required', 'string', 'in:chrome,cloak,cloak-with-chrome-fallback'],
            'cloakHumanizeEnabled' => ['boolean'],
            'cloakHumanPreset' => ['nullable', 'string', 'max:120'],
            'headlessEnabled' => ['boolean'],
            'previewModalEnabled' => ['boolean'],
            'livePreviewEnabled' => ['boolean'],
            'livePreviewIntervalSeconds' => ['required', 'integer', 'min:1', 'max:60'],
            'browserActivityCheckEnabled' => ['boolean'],
            'domDebugEnabled' => ['boolean'],
            'navigationTimeoutSeconds' => ['required', 'integer', 'min:30', 'max:300'],
            'observationTimeoutSeconds' => ['required', 'integer', 'min:30', 'max:1800'],

            'verificationMailboxEnabled' => ['boolean'],
            'verificationMailboxEmail' => ['nullable', 'email', 'max:255'],
            'verificationMailboxProvider' => ['required', 'string', 'in:proton,gmx'],
            'verificationMailboxUsername' => ['nullable', 'string', 'max:255'],
            'verificationMailboxPassword' => ['nullable', 'string', 'max:512'],
            'verificationMailboxWebmailUrl' => ['nullable', 'url', 'max:2048'],

            'providerOneEnabled' => ['boolean'],
            'providerOneMode' => ['required', 'string', 'in:observed_manual,proton_username_check'],
            'providerOneLabel' => ['required', 'string', 'max:120'],
            'providerOneRegistrationUrl' => ['nullable', 'url', 'max:2048'],
            'providerOneCompletionUrlContains' => ['nullable', 'string', 'max:512'],
            'providerOneCompletionSelector' => ['nullable', 'string', 'max:512'],
            'providerOneWebmailUrl' => ['nullable', 'url', 'max:2048'],

            'providerTwoEnabled' => ['boolean'],
            'providerTwoLabel' => ['nullable', 'string', 'max:120'],
            'providerTwoRegistrationUrl' => ['nullable', 'url', 'max:2048'],
            'providerThreeEnabled' => ['boolean'],
            'providerThreeLabel' => ['nullable', 'string', 'max:120'],
            'providerThreeRegistrationUrl' => ['nullable', 'url', 'max:2048'],
        ]);
        $existingSettings = app(MailAccountRegistrationRunner::class)->settings();
        $existingMailbox = is_array($existingSettings['verification_mailbox'] ?? null) ? $existingSettings['verification_mailbox'] : [];
        $encryptedVerificationPassword = $existingMailbox['password_encrypted'] ?? null;

        if (trim((string) ($validated['verificationMailboxPassword'] ?? '')) !== '') {
            $encryptedVerificationPassword = Crypt::encryptString((string) $validated['verificationMailboxPassword']);
        }

        $verificationMailboxProvider = $this->normalizeWebmailProvider($validated['verificationMailboxProvider'] ?? 'proton');
        $verificationMailboxWebmailUrl = trim((string) ($validated['verificationMailboxWebmailUrl'] ?? ''))
            ?: $this->defaultWebmailUrl($verificationMailboxProvider);

        return [
            'browser_engine' => $validated['browserEngine'],
            'cloak_humanize_enabled' => (bool) $validated['cloakHumanizeEnabled'],
            'cloak_human_preset' => trim((string) ($validated['cloakHumanPreset'] ?? '')),
            'headless_enabled' => (bool) $validated['headlessEnabled'],
            'preview_modal_enabled' => (bool) $validated['previewModalEnabled'],
            'live_preview_enabled' => (bool) $validated['livePreviewEnabled'],
            'live_preview_interval_seconds' => (int) $validated['livePreviewIntervalSeconds'],
            'browser_activity_check_enabled' => (bool) $validated['browserActivityCheckEnabled'],
            'dom_debug_enabled' => (bool) $validated['domDebugEnabled'],
            'navigation_timeout_seconds' => (int) $validated['navigationTimeoutSeconds'],
            'observation_timeout_seconds' => (int) $validated['observationTimeoutSeconds'],
            'verification_mailbox' => [
                'enabled' => (bool) $validated['verificationMailboxEnabled'],
                'email' => trim((string) ($validated['verificationMailboxEmail'] ?? '')),
                'provider' => $verificationMailboxProvider,
                'username' => trim((string) ($validated['verificationMailboxUsername'] ?? '')),
                'password_encrypted' => $this->nullableString($encryptedVerificationPassword),
                'webmail_url' => $verificationMailboxWebmailUrl,
                'webmail_session' => is_array($existingMailbox['webmail_session'] ?? null) ? $existingMailbox['webmail_session'] : null,
            ],
            'providers' => [
                [
                    'key' => $validated['providerOneMode'] === 'proton_username_check' ? 'proton' : 'observed_manual',
                    'label' => trim($validated['providerOneLabel']),
                    'mode' => $validated['providerOneMode'],
                    'enabled' => (bool) $validated['providerOneEnabled'],
                    'phone_required' => false,
                    'registration_url' => trim((string) ($validated['providerOneRegistrationUrl'] ?? '')),
                    'completion_url_contains' => trim((string) ($validated['providerOneCompletionUrlContains'] ?? '')),
                    'completion_selector' => trim((string) ($validated['providerOneCompletionSelector'] ?? '')),
                    'webmail_url' => trim((string) ($validated['providerOneWebmailUrl'] ?? '')),
                ],
                [
                    'key' => 'provider_2',
                    'label' => trim((string) ($validated['providerTwoLabel'] ?? '')) ?: 'Provider 2',
                    'mode' => 'planned',
                    'enabled' => (bool) $validated['providerTwoEnabled'],
                    'phone_required' => false,
                    'registration_url' => trim((string) ($validated['providerTwoRegistrationUrl'] ?? '')),
                    'completion_url_contains' => '',
                    'completion_selector' => '',
                    'webmail_url' => '',
                ],
                [
                    'key' => 'provider_3',
                    'label' => trim((string) ($validated['providerThreeLabel'] ?? '')) ?: 'Provider 3',
                    'mode' => 'planned',
                    'enabled' => (bool) $validated['providerThreeEnabled'],
                    'phone_required' => false,
                    'registration_url' => trim((string) ($validated['providerThreeRegistrationUrl'] ?? '')),
                    'completion_url_contains' => '',
                    'completion_selector' => '',
                    'webmail_url' => '',
                ],
            ],
        ];
    }

    protected function fillFromSettings(array $settings): void
    {
        $providers = array_values($settings['providers'] ?? []);
        $providerOne = $providers[0] ?? [];
        $providerTwo = $providers[1] ?? [];
        $providerThree = $providers[2] ?? [];

        $this->browserEngine = (string) ($settings['browser_engine'] ?? 'cloak-with-chrome-fallback');
        $this->cloakHumanizeEnabled = (bool) ($settings['cloak_humanize_enabled'] ?? false);
        $this->cloakHumanPreset = (string) ($settings['cloak_human_preset'] ?? '');
        $this->headlessEnabled = (bool) ($settings['headless_enabled'] ?? false);
        $this->previewModalEnabled = (bool) ($settings['preview_modal_enabled'] ?? true);
        $this->livePreviewEnabled = (bool) ($settings['live_preview_enabled'] ?? true);
        $this->livePreviewIntervalSeconds = max(1, min(60, (int) ($settings['live_preview_interval_seconds'] ?? 3)));
        $this->browserActivityCheckEnabled = (bool) ($settings['browser_activity_check_enabled'] ?? true);
        $this->domDebugEnabled = (bool) ($settings['dom_debug_enabled'] ?? true);
        $this->navigationTimeoutSeconds = (int) ($settings['navigation_timeout_seconds'] ?? 120);
        $this->observationTimeoutSeconds = (int) ($settings['observation_timeout_seconds'] ?? 300);
        $verificationMailbox = is_array($settings['verification_mailbox'] ?? null) ? $settings['verification_mailbox'] : [];
        $this->verificationMailboxEnabled = (bool) ($verificationMailbox['enabled'] ?? false);
        $this->verificationMailboxEmail = (string) ($verificationMailbox['email'] ?? '');
        $this->verificationMailboxProvider = $this->normalizeWebmailProvider($verificationMailbox['provider'] ?? 'proton');
        $this->verificationMailboxUsername = (string) ($verificationMailbox['username'] ?? '');
        $this->verificationMailboxPassword = '';
        $this->hasStoredVerificationMailboxPassword = trim((string) ($verificationMailbox['password_encrypted'] ?? '')) !== '';
        $this->verificationMailboxWebmailUrl = (string) ($verificationMailbox['webmail_url'] ?? '') ?: $this->defaultWebmailUrl($this->verificationMailboxProvider);

        $this->providerOneEnabled = (bool) ($providerOne['enabled'] ?? true);
        $this->providerOneMode = in_array(($providerOne['mode'] ?? ''), ['observed_manual', 'proton_username_check'], true)
            ? (string) $providerOne['mode']
            : 'proton_username_check';
        $this->providerOneLabel = (string) ($providerOne['label'] ?? 'Eigener Provider / beobachteter Browserflow');
        $this->providerOneRegistrationUrl = (string) ($providerOne['registration_url'] ?? '');
        $this->providerOneCompletionUrlContains = (string) ($providerOne['completion_url_contains'] ?? '');
        $this->providerOneCompletionSelector = (string) ($providerOne['completion_selector'] ?? '');
        $this->providerOneWebmailUrl = (string) ($providerOne['webmail_url'] ?? '');

        $this->providerTwoEnabled = (bool) ($providerTwo['enabled'] ?? false);
        $this->providerTwoLabel = (string) ($providerTwo['label'] ?? 'Provider 2');
        $this->providerTwoRegistrationUrl = (string) ($providerTwo['registration_url'] ?? '');

        $this->providerThreeEnabled = (bool) ($providerThree['enabled'] ?? false);
        $this->providerThreeLabel = (string) ($providerThree['label'] ?? 'Provider 3');
        $this->providerThreeRegistrationUrl = (string) ($providerThree['registration_url'] ?? '');
    }

    protected function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    protected function decryptStoredPassword(mixed $encrypted): string
    {
        if (! is_string($encrypted) || trim($encrypted) === '') {
            return '';
        }

        try {
            return Crypt::decryptString($encrypted);
        } catch (\Throwable) {
            return '';
        }
    }

    protected function normalizeWebmailProvider(mixed $provider): string
    {
        $provider = strtolower(trim((string) $provider));

        if ($provider === '' || str_contains($provider, 'proton')) {
            return 'proton';
        }

        if (str_contains($provider, 'gmx')) {
            return 'gmx';
        }

        return 'proton';
    }

    protected function defaultWebmailUrl(string $provider): string
    {
        return $provider === 'gmx'
            ? 'https://www.gmx.net'
            : 'https://mail.proton.me';
    }

    protected function webmailSessionPayload(array $result): array
    {
        $summary = is_array($result['sessionSummary'] ?? null) ? $result['sessionSummary'] : [];

        return [
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
    }
}
