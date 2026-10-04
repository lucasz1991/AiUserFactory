<?php

namespace Tests\Unit;

use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use App\Models\WorkflowStepRun;
use App\Services\Workflows\WorkflowLiveTaskPresenter;
use Illuminate\Database\Eloquent\Collection;
use Tests\TestCase;

class WorkflowLiveTaskPresenterTest extends TestCase
{
    private function fixture(array $payload = []): array
    {
        $workflow = (new Workflow)->forceFill(['id' => 10]);
        $step = (new WorkflowStep)->forceFill([
            'id' => 20, 'workflow_id' => 10, 'action_key' => 'main', 'type' => 'browser_task',
            'config_json' => ['tasks' => [
                ['key' => 'first', 'title' => 'Erster Task', 'task_key' => 'wait.seconds'],
                ['key' => 'second', 'title' => 'Zweiter Task', 'task_key' => 'wait.seconds'],
            ]],
        ]);
        $workflow->setRelation('steps', new Collection([$step]));
        $run = (new WorkflowRun)->forceFill(['id' => 30, 'workflow_id' => 10, 'status' => 'running', 'current_workflow_step_id' => 20, 'context_json' => []]);
        $stepRun = (new WorkflowStepRun)->forceFill(['id' => 40, 'workflow_run_id' => 30, 'workflow_step_id' => 20, 'status' => 'waiting', 'result_json' => $payload]);
        $run->setRelation('stepRuns', new Collection([$stepRun]));

        return [$workflow, $run, $stepRun];
    }

    public function test_actual_running_snapshot_wins_over_step_waiting_and_pending_context(): void
    {
        [$workflow, $run] = $this->fixture(['stage' => 'browser-preview', 'taskKey' => null, 'tasks' => [
            ['key' => 'first', 'status' => 'completed'], ['key' => 'second', 'status' => 'running'],
        ]]);
        $run->context_json = ['next_task_key' => 'first'];
        $cursor = app(WorkflowLiveTaskPresenter::class)->present($workflow, $run);
        $this->assertSame('main::second', $cursor['node']);
        $this->assertSame('running', $cursor['status']);
        $this->assertSame('runtime', $cursor['source']);
    }

    public function test_ambiguous_and_foreign_snapshots_never_select_a_guess(): void
    {
        [$workflow, $run, $stepRun] = $this->fixture(['tasks' => [
            ['key' => 'first', 'status' => 'running'], ['key' => 'second', 'status' => 'waiting'],
        ]]);
        $presenter = app(WorkflowLiveTaskPresenter::class);
        $this->assertNull($presenter->present($workflow, $run));
        $stepRun->result_json = ['taskKey' => 'second', 'tasks' => [
            ['key' => 'first', 'status' => 'running'], ['key' => 'second', 'status' => 'waiting'],
        ]];
        $this->assertSame('main::second', $presenter->present($workflow, $run)['node']);
        $stepRun->workflow_step_id = 99;
        $this->assertNull($presenter->present($workflow, $run));
        $run->workflow_id = 99;
        $this->assertNull($presenter->present($workflow, $run));
    }

    public function test_terminal_snapshot_and_terminal_run_do_not_claim_stale_running_records(): void
    {
        [$workflow, $run] = $this->fixture(['state' => 'cancelled', 'tasks' => [['key' => 'first', 'status' => 'running']]]);
        $presenter = app(WorkflowLiveTaskPresenter::class);
        $this->assertNull($presenter->present($workflow, $run));
        $run->context_json = ['next_task_key' => 'second'];
        $this->assertSame('pending', $presenter->present($workflow, $run)['status']);
        $run->status = 'cancelled';
        $this->assertNull($presenter->present($workflow, $run));
    }

    public function test_snapshot_scan_is_bounded_and_embedded_tasks_map_only_to_known_parent_cards(): void
    {
        [$workflow, $run, $stepRun] = $this->fixture(['tasks' => [
            ['key' => 'embedded-child', 'parent_task_key' => 'second', 'status' => 'running'],
        ]]);
        $presenter = app(WorkflowLiveTaskPresenter::class);
        $this->assertSame('main::second', $presenter->present($workflow, $run)['node']);
        $tasks = array_fill(0, 1000, ['key' => 'template', 'status' => 'template']);
        $tasks[] = ['key' => 'second', 'status' => 'running'];
        $stepRun->result_json = ['tasks' => $tasks];
        $this->assertNull($presenter->present($workflow, $run));
    }

    public function test_latest_completed_attempt_does_not_revive_an_older_running_attempt(): void
    {
        [$workflow, $run, $olderAttempt] = $this->fixture(['tasks' => [['key' => 'first', 'status' => 'running']]]);
        $completedAttempt = (new WorkflowStepRun)->forceFill([
            'id' => 41, 'workflow_run_id' => 30, 'workflow_step_id' => 20, 'status' => 'completed',
            'result_json' => ['tasks' => [['key' => 'first', 'status' => 'completed']]],
        ]);
        $foreignAttempt = (new WorkflowStepRun)->forceFill([
            'id' => 42, 'workflow_run_id' => 99, 'workflow_step_id' => 20, 'status' => 'running',
            'result_json' => ['tasks' => [['key' => 'second', 'status' => 'running']]],
        ]);
        $run->setRelation('stepRuns', new Collection([$olderAttempt, $completedAttempt, $foreignAttempt]));

        $this->assertNull(app(WorkflowLiveTaskPresenter::class)->present($workflow, $run));
        $this->assertTrue($run->relationLoaded('stepRuns'));
        $this->assertCount(3, $run->stepRuns);
        $this->assertSame('waiting', $olderAttempt->status);
        $this->assertSame('completed', $completedAttempt->status);
    }
}
