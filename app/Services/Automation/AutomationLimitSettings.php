<?php

namespace App\Services\Automation;

use App\Models\Setting;
use Illuminate\Support\Facades\Schema;

/**
 * Globale Grenzen und Not-Aus der Personen-Automatisierung.
 *
 * Nach dem etablierten Muster von `NetworkActivityPlanningSettings`: Ablage in
 * `settings`, Auswertung im Dispatcher, Bedienung ueber Netzwerk -> Automatisierung.
 */
class AutomationLimitSettings
{
    public const GROUP = 'automation';

    public const KEY = 'person_workflows';

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
        return [
            // Not-Aus. Steht bewusst getrennt von den Einzelschaltern der
            // Zeitplaene, damit ein Stopp nichts loescht.
            'enabled' => (bool) ($settings['enabled'] ?? false),

            // Obergrenze gleichzeitiger, vom Zeitplan gestarteter Laeufe ueber
            // alle Personen hinweg.
            'max_concurrent_runs' => max(1, min(200, (int) ($settings['max_concurrent_runs'] ?? 5))),

            // Wie viele Zeitplaene ein Dispatcher-Durchlauf hoechstens startet.
            'max_starts_per_tick' => max(1, min(100, (int) ($settings['max_starts_per_tick'] ?? 5))),

            // Obergrenze geplanter Starts je Person und Tag, unabhaengig von den
            // Einzeldeckeln der Zeitplaene.
            'max_runs_per_person_per_day' => max(0, min(500, (int) ($settings['max_runs_per_person_per_day'] ?? 0))),

            // Nach so vielen Fehlschlaegen in Folge pausiert ein Zeitplan.
            'pause_after_failures' => max(0, min(50, (int) ($settings['pause_after_failures'] ?? 5))),

            // Grundwert des exponentiellen Backoffs in Minuten.
            'failure_backoff_minutes' => max(1, min(1440, (int) ($settings['failure_backoff_minutes'] ?? 15))),

            // Personen-Fabrik getrennt schaltbar.
            'factory_enabled' => (bool) ($settings['factory_enabled'] ?? false),
            'factory_max_per_day' => max(0, min(500, (int) ($settings['factory_max_per_day'] ?? 5))),
        ];
    }
}
