<?php

namespace App\Livewire\Admin\Config;

use App\Services\Mail\MailAccountRegistrationRunner;
use App\Services\Mail\VerificationAccountPool;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Validation\ValidationException;
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

    /** Ergebnis der letzten Postfach-Aktion, sichtbar auf der Seite. */
    public string $mailboxNotice = '';

    public string $mailboxNoticeType = 'success';

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

    public function mount(MailAccountRegistrationRunner $runner): void
    {
        $this->fillFromSettings($runner->settings());
    }

    public function saveSettings(bool $flash = true): void
    {
        $runner = app(MailAccountRegistrationRunner::class);

        try {
            // Unter der Sperre der Einstellungszeile: Ein alter Stand ueberschriebe
            // sonst eine gleichzeitige Aenderung.
            $settings = $runner->withVerificationMailboxLock(function () use ($runner): array {
                $existing = $runner->settings();

                return $runner->saveSettings(array_replace($existing, $this->settingsPayload()));
            });
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages([
                'navigationTimeoutSeconds' => 'Die Einstellungen werden gerade aktualisiert. Bitte in einigen Sekunden erneut speichern.',
            ]);
        }

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
        } catch (ValidationException $exception) {
            throw $exception;
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

    /**
     * Gibt ein als verbraucht vermerktes Verifizierungs-Postfach wieder frei, wenn
     * der Anbieter die Adresse nachweislich wieder annimmt.
     */
    public function releaseVerificationAccount(int $accountId): void
    {
        $released = app(VerificationAccountPool::class)->release($accountId);
        $this->mailboxNotice = $released
            ? 'Das Postfach wurde freigegeben und kann wieder fuer Registrierungen verwendet werden.'
            : 'Das Postfach war nicht als verbraucht vermerkt.';
        $this->mailboxNoticeType = $released ? 'success' : 'warning';
        $this->dispatch('showAlert', $this->mailboxNotice, $this->mailboxNoticeType);
    }

    public function render()
    {
        $pool = app(VerificationAccountPool::class);

        return view('livewire.admin.config.mail-registration-settings', [
            'verificationAccounts' => $pool->overview(),
            'availableVerificationAccounts' => $pool->availableCount(),
            'supportedProviders' => VerificationAccountPool::SUPPORTED_PROVIDERS,
        ]);
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
}
