<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\ClientControllerApiController;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use App\Models\WorkflowStepRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

class ClientWorkflowStepSnapshotConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_stale_progress_model_cannot_resurrect_a_completed_step(): void
    {
        $stale = $this->stepRun();
        $finishedAt = now()->startOfSecond();
        WorkflowStepRun::query()->whereKey($stale->id)->update([
            'status' => 'completed',
            'result_json' => json_encode(['final' => true]),
            'logs_json' => json_encode([['stage' => 'completed']]),
            'finished_at' => $finishedAt,
        ]);

        $this->project($stale, ['stage' => 'late-progress', 'events' => [['stage' => 'late']]]);

        $current = $stale->fresh();
        $this->assertSame('completed', $current->status);
        $this->assertSame(['final' => true], $current->result_json);
        $this->assertSame([['stage' => 'completed']], $current->logs_json);
        $this->assertTrue($finishedAt->equalTo($current->finished_at));
    }

    public function test_a_stale_snapshot_cannot_overwrite_a_replaced_external_attempt(): void
    {
        foreach ([
            ['external_run_id' => (string) str()->uuid()],
            ['external_run_type' => 'workflow-task'],
        ] as $replacement) {
            $stale = $this->stepRun();
            $stale->fresh()->forceFill(array_merge($replacement, [
                'status' => 'running',
                'result_json' => ['attempt' => 'replacement'],
                'logs_json' => [['stage' => 'new-attempt']],
            ]))->save();

            $this->project($stale, ['stage' => 'old-attempt', 'events' => [['stage' => 'old']]]);

            $current = $stale->fresh();
            $this->assertSame('running', $current->status);
            $this->assertSame(['attempt' => 'replacement'], $current->result_json);
            $this->assertSame([['stage' => 'new-attempt']], $current->logs_json);
            foreach ($replacement as $attribute => $value) {
                $this->assertSame($value, $current->{$attribute});
            }
        }
    }

    public function test_progress_cannot_change_a_step_after_run_control_or_completion(): void
    {
        foreach (['paused', 'stop_requested', 'completed', 'failed', 'cancelled', 'timed_out'] as $status) {
            $stale = $this->stepRun();
            $stale->workflowRun->forceFill(['status' => $status])->save();

            $this->project($stale, ['stage' => 'late-progress', 'events' => [['stage' => 'late']]]);

            $current = $stale->fresh();
            $this->assertSame('running', $current->status, $status);
            $this->assertSame(['initial' => true], $current->result_json, $status);
            $this->assertSame([], $current->logs_json, $status);
            $this->assertSame($status, $current->workflowRun->status);
        }
    }

    public function test_current_progress_still_updates_an_active_step_and_filters_invalid_events(): void
    {
        $stepRun = $this->stepRun();
        $snapshot = ['stage' => 'task-running', 'events' => [['stage' => 'task-started'], 'invalid', null]];

        $this->project($stepRun, $snapshot);

        $current = $stepRun->fresh();
        $this->assertSame('waiting', $current->status);
        $this->assertSame($snapshot, $current->result_json);
        $this->assertSame([['stage' => 'task-started']], $current->logs_json);
        $this->assertSame('running', $current->workflowRun->status);
        $this->assertNull($current->finished_at);
    }

    private function project(WorkflowStepRun $stepRun, array $snapshot): void
    {
        $controller = app(ClientControllerApiController::class);
        $method = new ReflectionMethod($controller, 'syncWorkflowStepSnapshot');
        $method->invoke($controller, $stepRun, $snapshot);
    }

    private function stepRun(): WorkflowStepRun
    {
        $workflow = Workflow::query()->create([
            'name' => 'Client snapshot',
            'slug' => 'client-snapshot-'.str()->random(10),
            'category' => 'test',
            'is_active' => true,
            'is_locked' => false,
            'trigger_type' => 'manual',
            'settings_json' => [],
        ]);
        $step = $workflow->steps()->create([
            'name' => 'Client task',
            'type' => WorkflowStep::TYPE_BROWSER_TASK,
            'action_key' => 'client-task',
            'position' => 10,
            'is_enabled' => true,
            'config_json' => ['tasks' => []],
        ]);
        $run = WorkflowRun::query()->create([
            'run_uuid' => (string) str()->uuid(),
            'workflow_id' => $workflow->id,
            'status' => 'running',
            'context_json' => [],
            'result_json' => [],
        ]);

        return WorkflowStepRun::query()->create([
            'workflow_run_id' => $run->id,
            'workflow_step_id' => $step->id,
            'status' => 'running',
            'external_run_type' => 'client-controller-workflow-task',
            'external_run_id' => (string) str()->uuid(),
            'result_json' => ['initial' => true],
            'logs_json' => [],
        ]);
    }
}
