<?php

namespace App\Console\Commands;

use App\Models\ManagedProcess;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * Raeumt Altlasten der Workflow-Ausfuehrung auf: erledigte Prozess-Datensaetze,
 * alte Lauf-Verzeichnisse und verwaiste/zu alte Browser-Profile. Verhindert,
 * dass storage/ und die managed_processes-Tabelle unbegrenzt wachsen (der
 * Restart-Profil-Retry-Pfad legt sonst dauerhaft neue Profilordner an).
 */
class PruneWorkflowProcessArtifacts extends Command
{
    protected $signature = 'workflow:prune-artifacts
        {--process-days=7 : Erledigte managed_processes-Zeilen aelter als N Tage loeschen}
        {--run-days=3 : Lauf-Verzeichnisse (storage/app/workflow-task-runs) aelter als N Tage loeschen}
        {--profile-days=7 : Browser-Profile aelter als N Tage loeschen}
        {--dry-run : Nur anzeigen, was geloescht wuerde}';

    protected $description = 'Raeumt erledigte Prozess-Datensaetze, alte Lauf-Verzeichnisse und verwaiste Browser-Profile auf.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $processes = $this->pruneManagedProcessRows(max(1, (int) $this->option('process-days')), $dryRun);
        $runDirs = $this->pruneDirectories(
            storage_path('app/workflow-task-runs'),
            max(1, (int) $this->option('run-days')),
            $dryRun,
        );
        $profiles = $this->pruneDirectories(
            storage_path('app/browser-profiles/workflows'),
            max(1, (int) $this->option('profile-days')),
            $dryRun,
        );

        $this->info(sprintf(
            '%sProzess-Zeilen: %d, Lauf-Verzeichnisse: %d, Browser-Profile: %d.',
            $dryRun ? '[dry-run] ' : '',
            $processes,
            $runDirs,
            $profiles,
        ));

        return self::SUCCESS;
    }

    protected function pruneManagedProcessRows(int $days, bool $dryRun): int
    {
        if (! Schema::hasTable('managed_processes')) {
            return 0;
        }

        $threshold = Carbon::now()->subDays($days);

        $query = ManagedProcess::query()
            ->whereIn('status', ['exited', 'killed', 'terminated', 'failed'])
            ->where(function ($query) use ($threshold): void {
                $query->where('exited_at', '<', $threshold)
                    ->orWhere(function ($inner) use ($threshold): void {
                        $inner->whereNull('exited_at')->where('last_seen_at', '<', $threshold);
                    });
            });

        if ($dryRun) {
            return (int) $query->count();
        }

        return (int) $query->delete();
    }

    protected function pruneDirectories(string $basePath, int $days, bool $dryRun): int
    {
        if (! File::isDirectory($basePath)) {
            return 0;
        }

        $thresholdTs = Carbon::now()->subDays($days)->getTimestamp();
        $removed = 0;

        foreach (File::directories($basePath) as $directory) {
            // mtime des Verzeichnisses als Alterskriterium – aktive Laeufe
            // schreiben laufend status.json/Screenshots und bleiben so frisch.
            if (@filemtime($directory) === false || filemtime($directory) >= $thresholdTs) {
                continue;
            }

            if ($dryRun) {
                $this->line('  wuerde loeschen: '.$directory);
                $removed++;

                continue;
            }

            if (File::deleteDirectory($directory)) {
                $removed++;
            }
        }

        return $removed;
    }
}
