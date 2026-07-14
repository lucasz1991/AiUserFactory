<?php

namespace App\Jobs;

use App\Services\Workflows\WorkflowCopilotQueueRecoveryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ReconcileWorkflowCopilotSessionsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 50;

    public int $tries = 1;

    public int $uniqueFor = 120;

    public function __construct()
    {
        $this->onConnection('database');
    }

    public function handle(WorkflowCopilotQueueRecoveryService $recovery): void
    {
        $recovery->reconcile();
    }

    public function uniqueId(): string
    {
        return 'workflow-copilot-queue-reconciliation';
    }
}
