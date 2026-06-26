<?php

namespace App\Jobs;

use App\Services\Workflows\WorkflowExecutionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
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
        $this->onConnection('database');
    }

    public function handle(WorkflowExecutionService $workflows): void
    {
        $workflows->advance($this->workflowRunId);
    }
}
