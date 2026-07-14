<?php

namespace Tests\Feature;

use App\Jobs\RunWorkflowJob;
use App\Models\Workflow;
use App\Models\WorkflowCopilotSession;
use App\Models\WorkflowStep;
use App\Services\Workflows\WorkflowCopilotSessionService;
use App\Services\Workflows\WorkflowExecutionService;
use App\Services\Workflows\WorkflowTaskRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

class WorkflowCopilotExecutionInvariantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=']);
    }

    public function test_locked_workflow_only_accepts_its_active_copilot_session_and_forces_system(): void
    {
        Queue::fake();
        [$workflow] = $this->workflow();
        $session = app(WorkflowCopilotSessionService::class)->start($workflow);

        try {
            app(WorkflowExecutionService::class)->start($workflow->fresh(), [
                'execution_target' => 'system',
            ]);
            $this->fail('A normal run must not bypass an active Copilot workflow lock.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('exklusiv gesperrt', $exception->getMessage());
        }

        $run = app(WorkflowExecutionService::class)->start($workflow->fresh(), [
            'workflow_copilot_session_id' => $session->id,
            'copilot_supervised' => true,
            'execution_target' => 'client_controller',
            'network_node_id' => 123,
            'device_id' => 456,
        ], 'workflow-copilot');

        $this->assertSame($session->id, $run->workflow_copilot_session_id);
        $this->assertSame(0, $run->workflow_revision);
        $this->assertSame('system', data_get($run->context_json, 'execution_target'));
        $this->assertNull(data_get($run->context_json, 'network_node_id'));
        $this->assertNull(data_get($run->context_json, 'device_id'));
        Queue::assertPushed(RunWorkflowJob::class, fn (RunWorkflowJob $job): bool => $job->workflowRunId === $run->id);
    }

    public function test_supervised_runtime_contains_exactly_the_selected_top_level_task(): void
    {
        [, $step] = $this->workflow();
        $reflection = new ReflectionClass(WorkflowTaskRunner::class);
        $runner = $reflection->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(WorkflowTaskRunner::class, 'runtimeTasks');
        $method->setAccessible(true);

        $all = $method->invoke($runner, $step, null, false);
        $segment = $method->invoke($runner, $step, 'second-task', true);

        $this->assertCount(2, $all);
        $this->assertCount(1, $segment);
        $this->assertSame('second-task', $segment[0]['key']);
        $this->assertSame('wait.seconds', $segment[0]['task_key']);
    }

    private function workflow(): array
    {
        $workflow = Workflow::query()->create([
            'name' => 'System only '.str()->random(6),
            'slug' => 'system-only-'.str()->random(10),
            'description' => '',
            'category' => 'test',
            'is_active' => true,
            'is_locked' => false,
            'trigger_type' => 'manual',
            'settings_json' => [],
        ]);
        $step = $workflow->steps()->create([
            'name' => 'Tasks',
            'type' => WorkflowStep::TYPE_BROWSER_TASK,
            'action_key' => 'tasks',
            'position' => 10,
            'is_enabled' => true,
            'config_json' => [
                'tasks' => [
                    [
                        'key' => 'first-task',
                        'task_key' => 'wait.seconds',
                        'title' => 'Erster Task',
                        'value' => 0,
                    ],
                    [
                        'key' => 'second-task',
                        'task_key' => 'wait.seconds',
                        'title' => 'Zweiter Task',
                        'value' => 0,
                    ],
                ],
            ],
        ]);

        return [$workflow, $step];
    }
}
