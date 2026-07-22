<?php

namespace App\Console;

use App\Jobs\ExpireWorkflowRunsJob;
use App\Jobs\ReconcileWorkflowCopilotSessionsJob;
use App\Jobs\SuperviseManagedProcessesJob;
use App\Jobs\SyncManagedProcessesJob;
use App\Models\NetworkNode;
use App\Services\Simulation\NetworkActivityPlanningSettings;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Schema;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Prozess-Hygiene (Sync/Reaper/Expire/Reconcile) MUSS auch dann laufen,
        // wenn kein queue:work-Daemon aktiv oder der Worker vom Copilot-
        // Supervisor monopolisiert ist. Darum synchron im Scheduler-Prozess
        // (dispatchSync) statt ueber die database-Queue (->job()). Sonst bleiben
        // gestallte Laeufe ewig 'waiting' und geparkte Browser werden nie
        // aufgeraeumt (Haupt-Ursache fuer die Prozess-Akkumulation).
        if (Schema::hasTable('managed_processes')) {
            $schedule->call(static fn () => SyncManagedProcessesJob::dispatchSync())
                ->name('managed-processes-sync')
                ->everyMinute()
                ->withoutOverlapping(5);
            $schedule->call(static fn () => SuperviseManagedProcessesJob::dispatchSync())
                ->name('managed-processes-supervise')
                ->everyMinute()
                ->withoutOverlapping(5);
        }

        if (Schema::hasTable('workflow_step_runs')) {
            $schedule->call(static fn () => ExpireWorkflowRunsJob::dispatchSync())
                ->name('workflow-runs-expire')
                ->everyMinute()
                ->withoutOverlapping(5);
        }

        if (Schema::hasTable('workflow_copilot_sessions')
            && Schema::hasTable('workflow_copilot_events')
            && Schema::hasTable('workflow_runs')) {
            $schedule->call(static fn () => ReconcileWorkflowCopilotSessionsJob::dispatchSync())
                ->name('workflow-copilot-reconcile')
                ->everyMinute()
                ->withoutOverlapping(5);
        }

        if (Schema::hasTable('network_nodes')) {
            $schedule->call(static fn (): int => NetworkNode::expireStale())
                ->name('client-controller-expire-stale-nodes')
                ->everyMinute()
                ->withoutOverlapping(5);
        }

        // Taegliches Aufraeumen: erledigte Prozess-Zeilen, alte Lauf-Verzeichnisse
        // und verwaiste Browser-Profile, damit storage/ und managed_processes
        // nicht unbegrenzt wachsen.
        $schedule->command('workflow:prune-artifacts')
            ->dailyAt('04:20')
            ->timezone(config('app.timezone', 'Europe/Berlin'))
            ->withoutOverlapping(30);

        $settings = app(NetworkActivityPlanningSettings::class)->get();

        if (! $settings['enabled']) {
            return;
        }

        $command = sprintf(
            'network:plan-activities --days=%d --intensity=%s --reason=scheduled%s',
            $settings['days'],
            $settings['intensity'],
            $settings['queue'] ? ' --queue' : '',
        );

        foreach ($settings['times'] as $time) {
            $schedule->command($command)
                ->dailyAt($time)
                ->timezone(config('app.timezone', 'Europe/Berlin'))
                ->withoutOverlapping(30);
        }
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
