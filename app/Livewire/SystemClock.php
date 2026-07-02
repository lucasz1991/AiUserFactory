<?php

namespace App\Livewire;

use Illuminate\Support\Carbon;
use Livewire\Component;

class SystemClock extends Component
{
    public string $serverTime = '';
    public string $timezone = '';
    public string $offset = '';
    public string $phpTimezone = '';
    public string $configuredTimezone = '';
    public string $utcTime = '';

    public function mount(): void
    {
        $this->refreshClock();
    }

    public function refreshClock(): void
    {
        $this->configuredTimezone = trim((string) config('app.timezone', 'UTC')) ?: 'UTC';
        $this->phpTimezone = date_default_timezone_get();
        $timezone = $this->validTimezone($this->configuredTimezone) ?: $this->validTimezone($this->phpTimezone) ?: 'UTC';
        $now = Carbon::now($timezone);

        $this->serverTime = $now->format('d.m.Y H:i:s');
        $this->timezone = $now->timezoneName;
        $this->offset = $now->format('P');
        $this->utcTime = Carbon::now('UTC')->format('d.m.Y H:i:s');
    }

    public function render()
    {
        return view('livewire.system-clock');
    }

    protected function validTimezone(string $timezone): ?string
    {
        try {
            new \DateTimeZone($timezone);

            return $timezone;
        } catch (\Throwable) {
            return null;
        }
    }
}
