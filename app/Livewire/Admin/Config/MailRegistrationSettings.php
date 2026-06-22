<?php

namespace App\Livewire\Admin\Config;

use App\Services\Mail\MailAccountRegistrationRunner;
use Livewire\Component;

class MailRegistrationSettings extends Component
{
    public string $browserEngine = 'cloak-with-chrome-fallback';
    public bool $cloakHumanizeEnabled = false;
    public string $cloakHumanPreset = '';
    public bool $headlessEnabled = false;
    public int $navigationTimeoutSeconds = 120;
    public int $observationTimeoutSeconds = 300;

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
            $this->showRegistrationRunModal = true;
        } catch (\Throwable $exception) {
            $this->registrationRunStatus = [
                'state' => 'failed',
                'stage' => 'start-failed',
                'message' => $exception->getMessage(),
                'events' => [],
            ];
            $this->showRegistrationRunModal = true;
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
