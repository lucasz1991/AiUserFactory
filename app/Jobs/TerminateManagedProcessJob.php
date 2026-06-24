<?php

namespace App\Jobs;

use App\Models\ManagedProcess;
use App\Services\Processes\ManagedProcessInventory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class TerminateManagedProcessJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 30;

    public int $tries = 1;

    public function __construct(
        public int $managedProcessId,
        public bool $force = false,
    ) {
        $this->onConnection('database');
    }

    public function handle(ManagedProcessInventory $inventory): void
    {
        $process = ManagedProcess::query()->find($this->managedProcessId);

        if (! $process) {
            return;
        }

        $inventory->terminate($process, $this->force);
    }
}
