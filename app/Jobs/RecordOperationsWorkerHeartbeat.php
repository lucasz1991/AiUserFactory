<?php

namespace App\Jobs;

use App\Services\Operations\OperationalHeartbeatService;
use App\Support\WorkflowQueues;
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

    public int $timeout = 10;

    public string $lane = 'default';

    public function __construct(string $lane = 'default')
    {
        $this->lane = array_key_exists($lane, WorkflowQueues::lanes()) ? $lane : 'default';
        $this->uniqueFor = max(180, WorkflowQueues::reservationSeconds($this->lane) + 120);
        $this->onConnection(WorkflowQueues::lanes()[$this->lane])->onQueue($this->lane)->afterCommit();
    }

    public function uniqueId(): string
    {
        return 'operations-worker-heartbeat:'.$this->lane;
    }

    public function handle(OperationalHeartbeatService $heartbeats): void
    {
        $heartbeats->recordWorker($this->lane);
    }
}
