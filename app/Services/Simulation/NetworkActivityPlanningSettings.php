<?php

namespace App\Services\Simulation;

use App\Models\Setting;
use Illuminate\Support\Facades\Schema;

class NetworkActivityPlanningSettings
{
    public const GROUP = 'network';

    public const KEY = 'activity_planning';

    public const INTENSITIES = ['quiet', 'balanced', 'active', 'creator'];

    public const SCHEDULE_TIMES = ['03:05', '11:05', '17:05', '21:05'];

    public function get(): array
    {
        $settings = [];

        try {
            if (Schema::hasTable('settings')) {
                $stored = Setting::getValue(self::GROUP, self::KEY);
                $settings = is_array($stored) ? $stored : [];
            }
        } catch (\Throwable) {
            $settings = [];
        }

        return $this->normalize($settings);
    }

    public function save(array $settings): array
    {
        $normalized = $this->normalize($settings);

        Setting::setValue(self::GROUP, self::KEY, $normalized);

        return $normalized;
    }

    protected function normalize(array $settings): array
    {
        $intensity = (string) ($settings['intensity'] ?? 'balanced');

        if (! in_array($intensity, self::INTENSITIES, true)) {
            $intensity = 'balanced';
        }

        return [
            'enabled' => (bool) ($settings['enabled'] ?? true),
            'days' => max(1, min(14, (int) ($settings['days'] ?? 7))),
            'intensity' => $intensity,
            'queue' => (bool) ($settings['queue'] ?? false),
            'times' => self::SCHEDULE_TIMES,
        ];
    }
}
