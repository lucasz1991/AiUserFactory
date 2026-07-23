<?php

namespace Tests\Feature;

use App\Jobs\MonitorWorkflowStepRunJob;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use App\Models\WorkflowStepRun;
use App\Models\WorkflowStudioEvent;
use App\Services\Workflows\WorkflowExecutionService;
use App\Services\Workflows\WorkflowStudioSessionService;
use App\Services\Workflows\WorkflowTaskRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

class WorkflowStepRunWatchdogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=']);
        Carbon::setTestNow(Carbon::parse('2026-07-19 12:00:00'));
    }

    public function test_dead_process_with_stale_heartbeat_fails_the_step_run_with_a_single_watchdog_event(): void
    {
        Queue::fake();
        Process::fake(['*' => Process::result('', '', 1)]);
        [$workflow, $step] = $this->workflow();
        $studio = app(WorkflowStudioSessionService::class)->open($workflow);
        [$run, $stepRun] = $this->waitingWorkflowTaskRun($workflow, $step);
        app(WorkflowStudioSessionService::class)->attachRun($studio, $run);
        $this->mockTaskRunnerWithStatus($this->externalStatus(now()->subSeconds(240)));

        app(WorkflowExecutionService::class)->monitorStepRun($stepRun->id);
        app(WorkflowExecutionService::class)->monitorStepRun($stepRun->id);

        $stepRun->refresh();
        $run->refresh();
        $this->assertSame('failed', $stepRun->status);
        $this->assertStringContainsString('Watchdog', (string) $stepRun->error_message);
        $this->assertSame('failed', $run->status);
        $events = collect(data_get($run->context_json, 'watchdog_events', []));
        $stalled = $events->where('key', 'run.watchdog_stalled')->values();
        $this->assertCount(1, $stalled);
        $this->assertSame($stepRun->id, (int) data_get($stalled, '0.workflow_step_run_id'));
        $this->assertSame(4242, (int) data_get($stalled, '0.pid'));
        $this->assertSame('first-task', data_get($stalled, '0.task_cursor'));
        $this->assertNotEmpty(data_get($stalled, '0.last_heartbeat_at'));
        $this->assertCount(0, $events->where('key', 'run.heartbeat_stale'));
        $this->assertSame(
            1,
            WorkflowStudioEvent::query()->where('event_type', 'run.watchdog_stalled')->count(),
        );
    }

    public function test_living_process_with_stale_heartbeat_records_the_stale_event_only_once(): void
    {
        Queue::fake();
        Process::fake(['*' => Process::result('', '', 0)]);
        [$workflow, $step] = $this->workflow();
        [$run, $stepRun] = $this->waitingWorkflowTaskRun($workflow, $step);
        $this->mockTaskRunnerWithStatus($this->externalStatus(now()->subSeconds(240)));

        app(WorkflowExecutionService::class)->monitorStepRun($stepRun->id);
        app(WorkflowExecutionService::class)->monitorStepRun($stepRun->id);

        $stepRun->refresh();
        $run->refresh();
        $this->assertSame('waiting', $stepRun->status);
        $this->assertSame('running', $run->status);
        $events = collect(data_get($run->context_json, 'watchdog_events', []));
        $this->assertCount(1, $events->where('key', 'run.heartbeat_stale'));
        $this->assertCount(0, $events->where('key', 'run.watchdog_stalled'));
        Queue::assertPushed(
            MonitorWorkflowStepRunJob::class,
            fn (MonitorWorkflowStepRunJob $job): bool => $job->workflowStepRunId === $stepRun->id,
        );
    }

    public function test_fresh_heartbeat_keeps_the_step_run_untouched(): void
    {
        Queue::fake();
        Process::fake(['*' => Process::result('', '', 0)]);
        [$workflow, $step] = $this->workflow();
        [$run, $stepRun] = $this->waitingWorkflowTaskRun($workflow, $step);
        $this->mockTaskRunnerWithStatus($this->externalStatus(now()->subSeconds(5)));

        app(WorkflowExecutionService::class)->monitorStepRun($stepRun->id);

        $stepRun->refresh();
        $run->refresh();
        $this->assertSame('waiting', $stepRun->status);
        $this->assertSame('running', $run->status);
        $this->assertSame([], data_get($run->context_json, 'watchdog_events', []));
        $this->assertNull($stepRun->error_message);
        Queue::assertPushed(
            MonitorWorkflowStepRunJob::class,
            fn (MonitorWorkflowStepRunJob $job): bool => $job->workflowStepRunId === $stepRun->id,
        );
    }

    public function test_copilot_session_runs_are_excluded_from_the_watchdog(): void
    {
        Queue::fake();
        Process::fake(['*' => Process::result('', '', 1)]);
        [$workflow, $step] = $this->workflow();
        [$run, $stepRun] = $this->waitingWorkflowTaskRun($workflow, $step, [
            'workflow_copilot_session_id' => 123,
            'copilot_supervised' => true,
        ]);
        $this->mockTaskRunnerWithStatus($this->externalStatus(now()->subSeconds(240)));

        app(WorkflowExecutionService::class)->monitorStepRun($stepRun->id);

        $stepRun->refresh();
        $run->refresh();
        $this->assertSame('waiting', $stepRun->status);
        $this->assertSame('running', $run->status);
        $this->assertSame([], data_get($run->context_json, 'watchdog_events', []));
    }

    public function test_real_workflow_task_monitoring_polls_young_runs_faster_than_old_runs(): void
    {
        Queue::fake();
        Process::fake(['*' => Process::result('', '', 0)]);
        [$workflow, $step] = $this->workflow();
        [, $youngStepRun] = $this->waitingWorkflowTaskRun($workflow, $step, [], now()->subSeconds(10));
        [, $oldStepRun] = $this->waitingWorkflowTaskRun($workflow, $step, [], now()->subSeconds(120));
        $this->mockTaskRunnerWithStatus([
            ...$this->externalStatus(now()->subSeconds(5)),
            'livePreviewPollIntervalSeconds' => 47,
        ]);

        $execution = app(WorkflowExecutionService::class);
        $execution->monitorStepRun($youngStepRun->id);
        $execution->monitorStepRun($oldStepRun->id);

        $this->assertMonitorDelaySeconds($youngStepRun->id, 1);
        $this->assertMonitorDelaySeconds($oldStepRun->id, 3);
    }

    public function test_schedule_monitor_keeps_explicit_delays_for_non_workflow_runtimes(): void
    {
        Queue::fake();
        [$workflow, $step] = $this->workflow();
        [, $stepRun] = $this->waitingWorkflowTaskRun($workflow, $step);
        $stepRun->forceFill(['external_run_type' => 'mail-registration'])->save();
        $method = new ReflectionMethod(WorkflowExecutionService::class, 'scheduleMonitor');
        $method->setAccessible(true);
        $execution = app(WorkflowExecutionService::class);

        $method->invoke($execution, $stepRun, 25);
        $method->invoke($execution, $stepRun, 600);

        $this->assertMonitorDelaySeconds($stepRun->id, 25);
        $this->assertMonitorDelaySeconds($stepRun->id, 60);
    }

    private function assertMonitorDelaySeconds(int $stepRunId, int $expectedSeconds): void
    {
        $expectedAt = now()->addSeconds($expectedSeconds)->getTimestamp();

        Queue::assertPushed(
            MonitorWorkflowStepRunJob::class,
            fn (MonitorWorkflowStepRunJob $job): bool => $job->workflowStepRunId === $stepRunId
                && $job->delay instanceof \DateTimeInterface
                && $job->delay->getTimestamp() === $expectedAt,
        );
    }

    /**
     * @return array{0: WorkflowRun, 1: WorkflowStepRun}
     */
    private function waitingWorkflowTaskRun(
        Workflow $workflow,
        WorkflowStep $step,
        array $contextOverrides = [],
        ?Carbon $stepStartedAt = null,
    ): array {
        $run = WorkflowRun::query()->create([
            'run_uuid' => (string) str()->uuid(),
            'workflow_id' => $workflow->id,
            'status' => 'running',
            'started_at' => now()->subSeconds(150),
            'current_workflow_step_id' => $step->id,
            'context_json' => [
                'execution_target' => 'system',
                'interactive_debug' => true,
                'next_task_key' => 'first-task',
                ...$contextOverrides,
            ],
            'result_json' => [],
        ]);
        $stepRun = WorkflowStepRun::query()->create([
            'workflow_run_id' => $run->id,
            'workflow_step_id' => $step->id,
            'status' => 'waiting',
            'started_at' => $stepStartedAt ?: now()->subSeconds(150),
            'external_run_type' => 'workflow-task',
            'external_run_id' => 'watchdog-run-'.$run->id,
            'result_json' => [],
        ]);

        return [$run, $stepRun];
    }

    /**
     * Status-JSON des Node-Laufs wie von WorkflowTaskRunner::readRun geliefert.
     */
    private function externalStatus(Carbon $heartbeatAt): array
    {
        return [
            'state' => 'running',
            'stage' => 'task-10',
            'message' => 'Task 10 von 19 laeuft.',
            'isRunning' => true,
            'browserIdentity' => [
                'runnerProcessId' => 4242,
                'runner_process_id' => 4242,
            ],
            'at' => $heartbeatAt->toIso8601String(),
            'livePreviewPollIntervalSeconds' => 3,
            'events' => [[
                'at' => $heartbeatAt->toIso8601String(),
                'stage' => 'run.started',
                'message' => 'Node-Lauf gestartet.',
            ]],
        ];
    }

    private function mockTaskRunnerWithStatus(array $status): void
    {
        $runner = Mockery::mock(WorkflowTaskRunner::class);
        $runner->shouldReceive('readRun')
            ->andReturnUsing(fn (?string $runId): array => [...$status, 'runId' => (string) $runId]);
        $runner->shouldReceive('closeRun')->andReturn(['ok' => true])->byDefault();
        $runner->shouldReceive('cancelRun')->andReturn(['ok' => true])->byDefault();
        $this->app->instance(WorkflowTaskRunner::class, $runner);
    }

    private function workflow(): array
    {
        $workflow = Workflow::query()->create([
            'name' => 'Watchdog '.str()->random(6),
            'slug' => 'watchdog-'.str()->random(10),
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
