<?php

namespace App\Console\Commands;

use App\Jobs\PlanPersonaNetworkActivities;
use App\Services\Simulation\PersonaNetworkPlanningService;
use Illuminate\Console\Command;

class PlanNetworkActivities extends Command
{
    protected $signature = 'network:plan-activities
        {--days=7 : Anzahl der zu planenden Tage, maximal 14}
        {--intensity=balanced : quiet, balanced, active oder creator}
        {--reason=manual-command : Grund/Quelle des Planungslaufs}
        {--queue : Planung als Queue-Job dispatchen}';

    protected $description = 'Plant interne Sandbox-Aktivitaeten fuer alle aktiven Personas.';

    public function handle(PersonaNetworkPlanningService $planning): int
    {
        $days = max(1, min(14, (int) $this->option('days')));
        $intensity = in_array($this->option('intensity'), ['quiet', 'balanced', 'active', 'creator'], true)
            ? (string) $this->option('intensity')
            : 'balanced';
        $reason = trim((string) $this->option('reason')) ?: 'manual-command';

        if ((bool) $this->option('queue')) {
            PlanPersonaNetworkActivities::dispatch($days, $intensity, $reason);

            $this->info('Netzwerk-Aktivitaetsplanung wurde als Queue-Job dispatcht.');

            return self::SUCCESS;
        }

        $summary = $planning->planActiveNetwork(
            days: $days,
            intensity: $intensity,
            reason: $reason,
        );

        $this->info(sprintf(
            'Planung abgeschlossen: %d/%d Personen geplant, %d uebersprungen, %d interne Eingangsevents beruecksichtigt.',
            $summary['persons_planned'],
            $summary['persons_total'],
            $summary['persons_skipped'],
            $summary['incoming_events'],
        ));

        if ($summary['errors'] !== []) {
            $this->warn(count($summary['errors']).' Fehler sind aufgetreten.');
        }

        return $summary['errors'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
