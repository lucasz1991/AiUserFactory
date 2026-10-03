<?php

namespace App\Jobs;

use App\Services\Workflows\WorkflowExecutionService;
use App\Support\WorkflowQueues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RunWorkflowJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 120;

    public int $tries = 1;

    public function __construct(
        public int $workflowRunId,
    ) {
        $this->onConnection(WorkflowQueues::CONTROL_CONNECTION)->onQueue(WorkflowQueues::CONTROL)->afterCommit();
    }

    public function handle(WorkflowExecutionService $workflows): void
    {
        try {
            $workflows->advance($this->workflowRunId);
        } catch (ModelNotFoundException) {
            return;
        }
    }
}
