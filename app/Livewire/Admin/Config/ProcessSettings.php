<?php

namespace App\Livewire\Admin\Config;

use App\Services\Mail\MailAccountRegistrationRunner;
use Livewire\Component;

class ProcessSettings extends Component
{
    public bool $previewModalEnabled = true;
    public bool $livePreviewEnabled = true;
    public int $livePreviewIntervalSeconds = 3;
    public bool $browserActivityCheckEnabled = true;
    public bool $domDebugEnabled = true;

    public function mount(MailAccountRegistrationRunner $runner): void
    {
        $this->fillFromSettings($runner->settings());
    }

    public function saveSettings(): void
    {
        $validated = $this->validate([
            'previewModalEnabled' => ['boolean'],
            'livePreviewEnabled' => ['boolean'],
            'livePreviewIntervalSeconds' => ['required', 'integer', 'min:1', 'max:60'],
            'browserActivityCheckEnabled' => ['boolean'],
            'domDebugEnabled' => ['boolean'],
        ]);

        $runner = app(MailAccountRegistrationRunner::class);
        $settings = $runner->settings();
        $settings['preview_modal_enabled'] = (bool) $validated['previewModalEnabled'];
        $settings['live_preview_enabled'] = (bool) $validated['livePreviewEnabled'];
        $settings['live_preview_interval_seconds'] = (int) $validated['livePreviewIntervalSeconds'];
        $settings['browser_activity_check_enabled'] = (bool) $validated['browserActivityCheckEnabled'];
        $settings['dom_debug_enabled'] = (bool) $validated['domDebugEnabled'];

        $this->fillFromSettings($runner->saveSettings($settings));

        session()->flash('success', 'Prozesseinstellungen wurden gespeichert.');
        $this->dispatch('showAlert', 'Prozesseinstellungen gespeichert.', 'success');
    }

    public function render()
    {
        return view('livewire.admin.config.process-settings');
    }

    protected function fillFromSettings(array $settings): void
    {
        $this->previewModalEnabled = (bool) ($settings['preview_modal_enabled'] ?? true);
        $this->livePreviewEnabled = (bool) ($settings['live_preview_enabled'] ?? true);
        $this->livePreviewIntervalSeconds = max(1, min(60, (int) ($settings['live_preview_interval_seconds'] ?? 3)));
        $this->browserActivityCheckEnabled = (bool) ($settings['browser_activity_check_enabled'] ?? true);
        $this->domDebugEnabled = (bool) ($settings['dom_debug_enabled'] ?? true);
    }
}
