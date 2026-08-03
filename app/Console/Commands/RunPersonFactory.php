<?php

namespace App\Console\Commands;

use App\Services\Persons\PersonFactoryService;
use Illuminate\Console\Command;

class RunPersonFactory extends Command
{
    protected $signature = 'network:run-person-factory';

    protected $description = 'Erzeugt faellige Personen-Entwuerfe aus den aktiven Bauplaenen der Personen-Fabrik.';

    public function handle(PersonFactoryService $factory): int
    {
        $summary = $factory->runDueBlueprints();

        if (! $summary['enabled']) {
            $this->warn('Die Personen-Fabrik ist ausgeschaltet (Netzwerk -> Personen-Fabrik).');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%d Bauplaene geprueft, %d Entwuerfe erzeugt, %d fehlgeschlagen.',
            $summary['blueprints'],
            $summary['created'],
            $summary['failed'],
        ));

        foreach ($summary['details'] as $detail) {
            $this->line(sprintf(
                '  [%s] %s%s',
                $detail['result'],
                $detail['blueprint'] ?? '-',
                isset($detail['person']) ? ' — '.$detail['person'] : (isset($detail['reason']) ? ' — '.$detail['reason'] : ''),
            ));
        }

        return $summary['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
