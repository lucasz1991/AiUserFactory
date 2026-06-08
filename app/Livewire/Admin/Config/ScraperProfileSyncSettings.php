<?php

namespace App\Livewire\Admin\Config;

use App\Models\Setting;
use Livewire\Component;

class ScraperProfileSyncSettings extends Component
{
    public string $baseApiUrl = '';
    public string $apiPassword = '';

    public function mount(): void
    {
        $settings = Setting::getValue('services', 'webaidetective_base') ?? [];

        $this->baseApiUrl = trim((string) ($settings['scraper_profile_sync_url'] ?? config('services.webaidetective_base.scraper_profile_sync_url')));
        $this->apiPassword = trim((string) ($settings['scraper_profile_sync_password'] ?? $settings['scraper_profile_sync_token'] ?? config('services.webaidetective_base.scraper_profile_sync_password')));
    }

    public function saveSettings(): void
    {
        $validated = $this->validate([
            'baseApiUrl' => ['required', 'url', 'max:2048'],
            'apiPassword' => ['required', 'string', 'max:512'],
        ]);

        Setting::setValue('services', 'webaidetective_base', [
            'scraper_profile_sync_url' => trim($validated['baseApiUrl']),
            'scraper_profile_sync_password' => trim($validated['apiPassword']),
        ]);

        session()->flash('success', 'Einstellungen für den Scraper-Profil-Transfer wurden gespeichert.');
        $this->dispatch('showAlert', 'Einstellungen gespeichert.', 'success');
    }

    public function render()
    {
        return view('livewire.admin.config.scraper-profile-sync-settings')->layout('layouts.master');
    }
}
