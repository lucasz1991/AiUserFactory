<?php

namespace App\Livewire\Admin\Config;

use App\Services\Simulation\NetworkActivityPlanningSettings;
use Livewire\Component;

class ActivityPlanningSettings extends Component
{
    public bool $autoPlanningEnabled = true;

    public int $autoPlanningDays = 7;

    public string $autoPlanningIntensity = 'balanced';

    public bool $autoPlanningQueued = false;

    public function mount(): void
    {
        $settings = app(NetworkActivityPlanningSettings::class)->get();

        $this->autoPlanningEnabled = (bool) $settings['enabled'];
        $this->autoPlanningDays = (int) $settings['days'];
        $this->autoPlanningIntensity = (string) $settings['intensity'];
        $this->autoPlanningQueued = (bool) $settings['queue'];
    }

    public function saveSettings(): void
    {
        $validated = $this->validate([
            'autoPlanningEnabled' => ['boolean'],
            'autoPlanningDays' => ['required', 'integer', 'min:1', 'max:14'],
            'autoPlanningIntensity' => ['required', 'string', 'in:quiet,balanced,active,creator'],
            'autoPlanningQueued' => ['boolean'],
        ]);

        $settings = app(NetworkActivityPlanningSettings::class)->save([
            'enabled' => (bool) $validated['autoPlanningEnabled'],
            'days' => (int) $validated['autoPlanningDays'],
            'intensity' => (string) $validated['autoPlanningIntensity'],
            'queue' => (bool) $validated['autoPlanningQueued'],
        ]);

        $this->autoPlanningEnabled = (bool) $settings['enabled'];
        $this->autoPlanningDays = (int) $settings['days'];
        $this->autoPlanningIntensity = (string) $settings['intensity'];
        $this->autoPlanningQueued = (bool) $settings['queue'];

        session()->flash('success', 'Einstellungen fuer die automatische Aktivitaetsplanung wurden gespeichert.');
        $this->dispatch('showAlert', 'Aktivitaetsplanung gespeichert.', 'success');
    }

    public function render()
    {
        return view('livewire.admin.config.activity-planning-settings', [
            'scheduleTimes' => NetworkActivityPlanningSettings::SCHEDULE_TIMES,
        ]);
    }
}
