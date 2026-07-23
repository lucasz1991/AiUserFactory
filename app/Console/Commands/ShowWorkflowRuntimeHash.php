<?php

namespace App\Console\Commands;

use App\Services\Workflows\WorkflowRuntimeFingerprint;
use Illuminate\Console\Command;

/**
 * Gibt den Fingerabdruck der Workflow-Node-Runtime aus.
 *
 * Damit wird Teamprotokoll-Regel 7 (Sync der vollstaendigen Workflow-Runtime in
 * den ClientController) pruefbar: Hash auf beiden Seiten ausgeben und vergleichen.
 * Mit `--expect=` eignet sich der Befehl direkt fuer CI oder Deploy-Skripte —
 * Exit-Code 1 bedeutet „nicht synchron".
 */
class ShowWorkflowRuntimeHash extends Command
{
    protected $signature = 'workflow:runtime-hash
        {--files : Einzelhashes je Datei ausgeben}
        {--json : Ausgabe als JSON}
        {--expect= : Erwarteten Hash pruefen; Exit-Code 1 bei Abweichung}';

    protected $description = 'Zeigt den SHA-256-Fingerabdruck aller ausgefuehrten Workflow-Node-Skripte.';

    public function handle(WorkflowRuntimeFingerprint $fingerprint): int
    {
        $summary = $fingerprint->summary();
        $expected = trim((string) $this->option('expect'));
        $matches = $expected === '' || hash_equals($expected, $summary['hash']);

        if ($this->option('json')) {
            $payload = $summary;

            if ($this->option('files')) {
                $payload['files'] = $fingerprint->files();
            }

            if ($expected !== '') {
                $payload['expected'] = $expected;
                $payload['inSync'] = $matches;
            }

            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $matches ? self::SUCCESS : self::FAILURE;
        }

        $this->line(sprintf(
            '%s  (%s, %d Dateien unter %s)',
            $summary['hash'],
            $summary['algorithm'],
            $summary['fileCount'],
            $summary['directory'],
        ));

        if ($summary['fileCount'] === 0) {
            $this->warn('Es wurden keine Runtime-Dateien in den konfigurierten Runtime-Verzeichnissen gefunden.');
        }

        if ($this->option('files')) {
            foreach ($fingerprint->files() as $relativePath => $fileHash) {
                $this->line(sprintf('  %s  %s', substr($fileHash, 0, 16), $relativePath));
            }
        }

        if ($expected !== '') {
            if ($matches) {
                $this->info('Synchron: der erwartete Hash stimmt ueberein.');
            } else {
                $this->error('Nicht synchron. Erwartet: '.$expected);
                $this->line('Die Workflow-Runtime muss in den ClientController synchronisiert werden (Regel 7).');
            }
        }

        return $matches ? self::SUCCESS : self::FAILURE;
    }
}
