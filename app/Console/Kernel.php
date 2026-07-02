<?php

namespace App\Console;

use App\Jobs\ExpireWorkflowRunsJob;
use App\Jobs\SyncManagedProcessesJob;
use App\Jobs\SuperviseManagedProcessesJob;
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
        if (Schema::hasTable('managed_processes')) {
            $schedule->job(new SyncManagedProcessesJob)
                ->everyMinute()
                ->withoutOverlapping(5);
            $schedule->job(new SuperviseManagedProcessesJob)
                ->everyMinute()
                ->withoutOverlapping(5);
        }

        if (Schema::hasTable('workflow_step_runs')) {
            $schedule->job(new ExpireWorkflowRunsJob)
                ->everyMinute()
                ->withoutOverlapping(5);
        }

        if (Schema::hasTable('network_nodes')) {
            $schedule->call(static fn (): int => NetworkNode::expireStale())
                ->name('client-controller-expire-stale-nodes')
                ->everyMinute()
                ->withoutOverlapping(5);
        }

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
