<?php

namespace App\Console\Commands;

use App\Services\Automation\PersonWorkflowDispatcher;
use Illuminate\Console\Command;

class DispatchPersonWorkflows extends Command
{
    protected $signature = 'network:dispatch-person-workflows
        {--prime : Nur fehlende naechste Ausfuehrungszeitpunkte nachtragen, nichts starten}';

    protected $description = 'Startet faellige, zeitgesteuerte Workflows der Personen.';

    public function handle(PersonWorkflowDispatcher $dispatcher): int
    {
        if ((bool) $this->option('prime')) {
            $primed = $dispatcher->primeMissingSchedules();
            $this->info($primed.' Zeitplaene haben einen naechsten Ausfuehrungszeitpunkt erhalten.');

            return self::SUCCESS;
        }

        $dispatcher->primeMissingSchedules();
        $summary = $dispatcher->dispatchDue();

        if (! $summary['enabled']) {
            $this->warn('Die Personen-Automatisierung ist ausgeschaltet (Netzwerk -> Automatisierung).');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%d faellig, %d gestartet, %d verschoben, %d fehlgeschlagen.',
            $summary['considered'],
            $summary['started'],
            $summary['skipped'],
            $summary['failed'],
        ));

        foreach ($summary['details'] as $detail) {
            $this->line(sprintf(
                '  [%s] %s / %s%s',
                $detail['result'],
                $detail['person'],
                $detail['workflow'],
                isset($detail['reason']) ? ' — '.$detail['reason'] : '',
            ));
        }

        return $summary['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
