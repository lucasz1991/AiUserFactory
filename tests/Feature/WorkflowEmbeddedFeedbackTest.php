<?php

namespace Tests\Feature;

use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use App\Models\WorkflowStepRun;
use App\Services\Workflows\WorkflowRunTaskFeedback;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkflowEmbeddedFeedbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_native_feedback_reads_unloaded_database_results_and_fresh_loaded_poll_results(): void
    {
        $workflow = Workflow::query()->create(['name' => 'Feedback', 'slug' => 'embedded-feedback', 'is_active' => true, 'trigger_type' => 'manual']);
        $step = $workflow->steps()->create(['name' => 'Tasks', 'type' => WorkflowStep::TYPE_BROWSER_TASK, 'action_key' => 'tasks', 'position' => 10, 'is_enabled' => true,
            'config_json' => ['tasks' => [['key' => 'first', 'task_key' => 'wait.seconds', 'title' => 'First', 'value' => 0]]]]);
        $run = WorkflowRun::query()->create(['run_uuid' => (string) str()->uuid(), 'workflow_id' => $workflow->id, 'status' => 'running']);
        $stepRun = WorkflowStepRun::query()->create(['workflow_run_id' => $run->id, 'workflow_step_id' => $step->id, 'status' => 'running', 'result_json' => ['tasks' => [['key' => 'first', 'status' => 'running']]]]);
        $service = app(WorkflowRunTaskFeedback::class);
        $this->assertSame('running', $service->tasks($workflow, $run)[$step->id]['first']['status']);
        $loadedRun = $run->fresh()->load('stepRuns');
        $this->assertSame('running', $service->tasks($workflow, $loadedRun)[$step->id]['first']['status']);
        $stepRun->update(['result_json' => ['tasks' => [['key' => 'first', 'status' => 'success']]]]);
        // Studio's render/poll hydrates a fresh run, so the relation-aware fast
        // path does not cache a previous poll's task state.
        $freshPollRun = $run->fresh()->load('stepRuns');
        $this->assertSame('success', $service->tasks($workflow, $freshPollRun)[$step->id]['first']['status']);
        $this->assertSame('success', $service->tasks($workflow, $run->fresh())[$step->id]['first']['status']);
    }
}
