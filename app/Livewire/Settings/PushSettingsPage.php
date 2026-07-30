<?php

namespace App\Livewire\Settings;

use App\Support\Push\WebPushConfiguration;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Vollseitiger Einstieg unter /app-installation.
 *
 * Traegt die Installationsanleitungen je Plattform und bindet das Panel
 * `settings.push-settings` mit aktivem Testversand ein.
 */
class PushSettingsPage extends Component
{
    public function render(): View
    {
        return view('livewire.settings.push-settings-page', [
            'pushDiagnostics' => WebPushConfiguration::diagnostics(),
        ])->layout('layouts.master');
    }
}
