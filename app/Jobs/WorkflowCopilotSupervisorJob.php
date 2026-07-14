<?php

namespace App\Jobs;

use App\Services\Workflows\WorkflowCopilotSupervisorService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class WorkflowCopilotSupervisorJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 180;

    public int $tries = 2;

    public function __construct(
        public int $workflowCopilotSessionId,
    ) {
        $this->onConnection('database');
    }

    public function handle(WorkflowCopilotSupervisorService $supervisor): void
    {
        $supervisor->supervise($this->workflowCopilotSessionId);
    }
}
