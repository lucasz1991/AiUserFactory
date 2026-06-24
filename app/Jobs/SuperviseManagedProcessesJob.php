<?php

namespace App\Jobs;

use App\Services\Processes\ManagedProcessSupervisor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SuperviseManagedProcessesJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 60;

    public int $tries = 1;

    public function __construct(
        public ?string $runId = null,
    ) {
        $this->onConnection('database');
    }

    public function handle(ManagedProcessSupervisor $supervisor): void
    {
        $supervisor->supervise($this->runId);
    }
}
