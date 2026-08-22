<?php

namespace App\Console\Commands;

use App\Services\Operations\OperationalAlertService;
use Illuminate\Console\Command;

class DispatchOperationalAlerts extends Command
{
    protected $signature = 'operations:alert
        {--json : Maschinenlesbares Ergebnis ausgeben}
        {--fail-on-critical : Bei einem kritischen Alarm mit Fehlercode enden}';

    protected $description = 'Emittiert deduplizierte Security-, Kosten- und SLO-Alarme in den konfigurierten Logkanal.';

    public function handle(OperationalAlertService $alerts): int
    {
        $result = $alerts->dispatch();

        if ($this->option('json')) {
            $this->line((string) json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->info(sprintf(
                'Alarme: %d, neu emittiert: %d, im Cooldown unterdrueckt: %d.',
                count($result['alerts']),
                count($result['emitted']),
                count($result['suppressed']),
            ));
        }

        return $this->option('fail-on-critical') && $result['critical']
            ? self::FAILURE
            : self::SUCCESS;
    }
}
