<?php

namespace App\Jobs;

use App\Services\Operations\OperationalHeartbeatService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RecordOperationsWorkerHeartbeat implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $uniqueFor = 180;

    public int $tries = 1;

    public function uniqueId(): string
    {
        return 'operations-worker-heartbeat';
    }

    public function handle(OperationalHeartbeatService $heartbeats): void
    {
        $heartbeats->recordWorker();
    }
}
