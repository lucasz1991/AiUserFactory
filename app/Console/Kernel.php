<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        foreach (['03:05', '11:05', '17:05', '21:05'] as $time) {
            $schedule->command('network:plan-activities --days=7 --intensity=balanced --reason=scheduled')
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
