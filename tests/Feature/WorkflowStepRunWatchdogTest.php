<?php

namespace Tests\Feature;

use App\Jobs\MonitorWorkflowStepRunJob;
use App\Jobs\RunWorkflowJob;
use App\Models\ManagedProcess;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Models\WorkflowRunArtifact;
use App\Models\WorkflowStep;
use App\Models\WorkflowStepRun;
use App\Models\WorkflowStudioEvent;
use App\Services\Workflows\WorkflowExecutionService;
use App\Services\Workflows\WorkflowRunCoordinationService;
use App\Services\Workflows\WorkflowStudioSessionService;
use App\Services\Workflows\WorkflowTaskRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
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
        Process::fake(['*' => PHP_OS_FAMILY === 'Windows'
            ? Process::result('INFO: No tasks are running which match the specified criteria.', '', 0)
            : Process::result('', '', 1)]);
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

    public function test_windows_native_probe_requires_the_exact_pid_csv_row_without_cmd_quoting(): void
    {
        Process::fake(['*' => Process::result('"node.exe","4242","Console","1","20.000 K"', '', 0)]);

        $this->assertTrue(WorkflowTaskRunner::windowsProcessIsRunning(4242));
        Process::assertRan(fn ($process): bool => $process->command === ['tasklist.exe', '/FI', 'PID eq 4242', '/FO', 'CSV', '/NH']);
    }

    public function test_windows_native_probe_does_not_claim_another_pid_is_the_requested_runner(): void
    {
        Process::fake(['*' => Process::result('"node.exe","14242","Console","1","20.000 K"', '', 0)]);

        $this->assertNull(WorkflowTaskRunner::windowsProcessIsRunning(4242));
    }

    public function test_windows_native_probe_only_treats_successful_no_match_as_process_exit(): void
    {
        Process::fake(['*' => Process::result('INFORMATION: Es werden keine Aufgaben mit den angegebenen Kriterien ausgefuehrt.', '', 0)]);
        $this->assertFalse(WorkflowTaskRunner::windowsProcessIsRunning(4242));
        Process::fake(['*' => Process::result('', 'FEHLER: Argument/Option ungueltig - "eq".', 1)]);
        $this->assertNull(WorkflowTaskRunner::windowsProcessIsRunning(4242));
        Process::fake(['*' => Process::result('unexpected output', '', 0)]);
        $this->assertNull(WorkflowTaskRunner::windowsProcessIsRunning(4242));
    }

    public function test_stale_inventory_exit_cannot_override_current_live_windows_pid(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('Windows PID-observation regression.');
        }
        Queue::fake();
        Process::fake(['*' => Process::result('"node.exe","4242","Console","1","20.000 K"', '', 0)]);
        [$workflow, $step] = $this->workflow();
        [$run, $stepRun] = $this->waitingWorkflowTaskRun($workflow, $step);
        ManagedProcess::create([
            'pid' => 4242, 'run_id' => $stepRun->external_run_id, 'run_type' => 'workflow-task',
            'status' => 'exited', 'is_root' => true, 'is_managed' => true, 'last_seen_at' => now()->subSeconds(240),
        ]);
        $this->mockTaskRunnerWithStatus($this->externalStatus(now()->subSeconds(240)));

        app(WorkflowExecutionService::class)->monitorStepRun($stepRun->id);

        $this->assertSame('waiting', $stepRun->fresh()->status);
        $this->assertSame('running', $run->fresh()->status);
        $this->assertCount(1, collect(data_get($run->fresh()->context_json, 'watchdog_events', []))->where('key', 'run.heartbeat_stale'));
    }

    public function test_windows_probe_failure_keeps_a_stale_runner_and_records_warning(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('Windows PID-observation regression.');
        }
        Queue::fake();
        Process::fake(['*' => Process::result('', 'tasklist failed', 1)]);
        [$workflow, $step] = $this->workflow();
        [$run, $stepRun] = $this->waitingWorkflowTaskRun($workflow, $step);
        $this->mockTaskRunnerWithStatus($this->externalStatus(now()->subSeconds(240)));

        app(WorkflowExecutionService::class)->monitorStepRun($stepRun->id);

        $this->assertSame('waiting', $stepRun->fresh()->status);
        $this->assertCount(1, collect(data_get($run->fresh()->context_json, 'watchdog_events', []))->where('key', 'run.heartbeat_stale'));
        $this->assertCount(0, collect(data_get($run->fresh()->context_json, 'watchdog_events', []))->where('key', 'run.watchdog_stalled'));
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

    public function test_scheduler_expiry_uses_the_same_claim_as_callback_and_advance(): void
    {
        Queue::fake();
        [$workflow, $step] = $this->workflow();
        [$run, $stepRun] = $this->waitingWorkflowTaskRun($workflow, $step, [], now()->subHours(2));
        $coordination = app(WorkflowRunCoordinationService::class);
        $token = $coordination->acquire($run->id, 'callback');
        $this->assertNotNull($token);

        try {
            app(WorkflowExecutionService::class)->expireTimedOutRuns();
            $this->assertSame('waiting', $stepRun->fresh()->status);
            $this->assertSame('running', $run->fresh()->status);
            Queue::assertPushed(MonitorWorkflowStepRunJob::class, 1);
        } finally {
            $coordination->release($run->id, $token);
        }
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

    #[DataProvider('monitorEntryPoints')]
    public function test_delayed_monitor_consumes_its_timely_terminal_result_before_expiring_the_step(string $entryPoint, bool $atDeadline): void
    {
        Queue::fake();
        [$run, $stepRun, $step] = $this->deadlineRun();
        [$status, $result] = $this->terminalExternalResult($run, $stepRun, $step);
        if ($atDeadline) {
            $result['finishedAt'] = $stepRun->started_at->copy()->addSeconds(120)->toIso8601String();
        }
        $this->mockTerminalTaskRunner($status, $result);

        $execution = app(WorkflowExecutionService::class);
        $entryPoint === 'scheduler'
            ? $execution->expireTimedOutRuns()
            : $execution->monitorStepRun($stepRun->id);

        $this->assertSame('completed', $stepRun->fresh()->status);
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertTrue((bool) data_get($stepRun->fresh()->result_json, 'ok'));
        $this->assertNull(data_get($stepRun->fresh()->result_json, 'timedOutAt'));
        $this->assertNull($stepRun->fresh()->error_message);
        Queue::assertNotPushed(MonitorWorkflowStepRunJob::class);
    }

    public static function monitorEntryPoints(): array
    {
        return [
            'queued monitor' => ['monitor', false], 'scheduler recovery' => ['scheduler', false],
            'queued monitor at deadline' => ['monitor', true], 'scheduler at deadline' => ['scheduler', true],
        ];
    }

    #[DataProvider('untrustedTerminalResults')]
    public function test_untrusted_or_late_terminal_result_cannot_bypass_the_current_step_deadline(string $variant): void
    {
        Queue::fake();
        [$run, $stepRun, $step] = $this->deadlineRun();
        [$status, $result] = $this->terminalExternalResult($run, $stepRun, $step);
        match ($variant) {
            'late' => $result['finishedAt'] = now()->subSeconds(20)->toIso8601String(),
            'missing finish' => $result['finishedAt'] = null,
            'invalid finish' => $result['finishedAt'] = 'not a timestamp',
            'future finish' => $result['finishedAt'] = now()->addMinute()->toIso8601String(),
            'before current step' => $result['finishedAt'] = now()->subSeconds(180)->toIso8601String(),
            'old resumed start' => $status['startedAt'] = now()->subSeconds(300)->toIso8601String(),
            'missing start' => $status['startedAt'] = null,
            'foreign status external id' => $status['runId'] = 'previous-owner',
            'foreign status run id' => $status['workflowRunId'] = $run->id + 100,
            'foreign status step run id' => $status['workflowStepRunId'] = $stepRun->id + 100,
            'foreign status step id' => $status['workflowStepId'] = $step->id + 100,
            'foreign result external id' => $result['browserIdentity']['runId'] = 'previous-owner',
            'foreign result run id' => $result['browserIdentity']['workflowRunId'] = $run->id + 100,
            'foreign result run uuid' => $result['browserIdentity']['workflowRunUuid'] = (string) str()->uuid(),
            'conflicting result alias' => $result['workflow_run_id'] = $run->id + 100,
        };
        $this->mockTerminalTaskRunner($status, $result);

        app(WorkflowExecutionService::class)->monitorStepRun($stepRun->id);

        $this->assertSame('failed', $stepRun->fresh()->status);
        $this->assertSame('failed', $run->fresh()->status);
        $this->assertSame('timeout', data_get($stepRun->fresh()->result_json, 'status'));
        $this->assertFalse((bool) data_get($stepRun->fresh()->result_json, 'ok'));
        $this->assertStringContainsString('120 Sekunden', (string) $stepRun->fresh()->error_message);
    }

    public static function untrustedTerminalResults(): array
    {
        return collect([
            'late', 'missing finish', 'invalid finish', 'future finish', 'before current step',
            'old resumed start', 'missing start', 'foreign status external id', 'foreign status run id',
            'foreign status step run id', 'foreign status step id', 'foreign result external id',
            'foreign result run id', 'foreign result run uuid', 'conflicting result alias',
        ])->mapWithKeys(fn (string $variant): array => [$variant => [$variant]])->all();
    }

    public function test_genuine_timeout_retains_nested_execution_evidence_and_only_times_out_the_running_task(): void
    {
        Queue::fake();
        [$run, $stepRun] = $this->deadlineRun();
        $snapshot = $this->nestedRunningSnapshot($run, $stepRun);
        $olderSnapshot = $snapshot;
        $olderSnapshot['tasks'] = [$snapshot['tasks'][0]];
        $olderSnapshot['events'] = [];
        $olderSnapshot['browserWindows'] = [];
        $stepRun->forceFill(['result_json' => $olderSnapshot])->save();
        $this->mockTaskRunnerWithStatus($snapshot);

        app(WorkflowExecutionService::class)->monitorStepRun($stepRun->id);

        $result = $stepRun->fresh()->result_json;
        $tasks = collect($result['tasks'] ?? [])->keyBy('key');
        $this->assertSame('failed', $stepRun->fresh()->status);
        $this->assertSame('failed', $run->fresh()->status);
        $this->assertSame('timeout', $result['status']);
        $this->assertSame($snapshot['configuredTasks'], $result['configuredTasks']);
        $this->assertSame($snapshot['events'], $result['events']);
        $this->assertSame($snapshot['browserWindows'], $result['browserWindows']);
        $this->assertSame('mail-leaf-main', $result['activeBrowserWindow']);
        $this->assertSame('success', $tasks->get('leaf-open')['status']);
        $this->assertSame($snapshot['tasks'][0]['finishedAt'], $tasks->get('leaf-open')['finishedAt']);
        $this->assertSame('timeout', $tasks->get('leaf-wait')['status']);
        $this->assertSame($snapshot['tasks'][1]['startedAt'], $tasks->get('leaf-wait')['startedAt']);
        $this->assertSame($snapshot['tasks'][1]['embedded_workflow_path'], $tasks->get('leaf-wait')['embedded_workflow_path']);
        $this->assertFalse($tasks->has('leaf-future'));
        $this->assertArrayNotHasKey('browserWsEndpoint', $result);
    }

    public function test_missing_external_status_at_timeout_preserves_the_last_own_nested_snapshot(): void
    {
        Queue::fake();
        [$run, $stepRun] = $this->deadlineRun();
        $snapshot = $this->nestedRunningSnapshot($run, $stepRun);
        $stepRun->forceFill(['result_json' => $snapshot])->save();
        $runner = Mockery::mock(WorkflowTaskRunner::class);
        $runner->shouldReceive('readRun')->andReturnNull();
        $runner->shouldReceive('closeRun')->andReturn(['ok' => true])->byDefault();
        $runner->shouldReceive('cancelRun')->andReturn(['ok' => true])->byDefault();
        $this->app->instance(WorkflowTaskRunner::class, $runner);

        app(WorkflowExecutionService::class)->monitorStepRun($stepRun->id);

        $result = $stepRun->fresh()->result_json;
        $this->assertSame('failed', $run->fresh()->status);
        $this->assertSame('failed', $stepRun->fresh()->status);
        $this->assertSame($snapshot['configuredTasks'], $result['configuredTasks']);
        $this->assertSame($snapshot['events'], $result['events']);
        $this->assertSame($snapshot['browserWindows'], $result['browserWindows']);
        $this->assertSame('success', data_get($result, 'tasks.0.status'));
        $this->assertSame('timeout', data_get($result, 'tasks.1.status'));
    }

    public function test_foreign_terminal_status_cannot_attach_debug_artifacts_or_replace_own_nested_evidence(): void
    {
        Queue::fake();
        [$run, $stepRun, $step] = $this->deadlineRun();
        $snapshot = $this->nestedRunningSnapshot($run, $stepRun);
        $stepRun->forceFill(['result_json' => $snapshot])->save();
        [$status, $result] = $this->terminalExternalResult($run, $stepRun, $step);
        $status['workflowRunId'] = $run->id + 100;
        $status['configuredTasks'] = [['key' => 'foreign-task', 'task_key' => 'wait.seconds']];
        $status['debugArtifacts'] = ['artifacts' => [[
            'phase' => 'after_task', 'artifact_type' => 'dom', 'task_card_key' => 'foreign-task',
            'status' => 'error', 'error_message' => 'Synthetic descriptor only; no file.',
        ]]];
        $this->mockTerminalTaskRunner($status, $result);

        app(WorkflowExecutionService::class)->monitorStepRun($stepRun->id);

        $this->assertSame('failed', $run->fresh()->status);
        $this->assertSame($snapshot['configuredTasks'], data_get($stepRun->fresh()->result_json, 'configuredTasks'));
        $this->assertSame($snapshot['browserWindows'], data_get($stepRun->fresh()->result_json, 'browserWindows'));
        $this->assertSame(0, WorkflowRunArtifact::query()->count());
    }

    public function test_timeout_can_follow_its_configured_recovery_route_without_marking_the_expired_step_completed(): void
    {
        Queue::fake();
        [$run, $stepRun, $step] = $this->deadlineRun();
        $recovery = $step->workflow->steps()->create([
            'name' => 'Recovery', 'type' => WorkflowStep::TYPE_WAIT, 'action_key' => 'recovery',
            'position' => 20, 'is_enabled' => true, 'config_json' => ['seconds' => 0],
        ]);
        $config = $step->config_json;
        $config['routes']['failed'] = ['type' => 'step', 'action_key' => $recovery->action_key];
        $step->forceFill(['config_json' => $config])->save();
        $snapshot = $this->nestedRunningSnapshot($run, $stepRun);
        $stepRun->forceFill(['result_json' => $snapshot])->save();
        $this->mockTaskRunnerWithStatus($snapshot);

        app(WorkflowExecutionService::class)->monitorStepRun($stepRun->id);

        $this->assertSame('failed', $stepRun->fresh()->status);
        $this->assertSame('running', $run->fresh()->status);
        $this->assertSame('recovery', data_get($run->fresh()->context_json, 'next_step_action_key'));
        $this->assertSame($snapshot['configuredTasks'], data_get($stepRun->fresh()->result_json, 'configuredTasks'));
        $this->assertSame('technical_error', data_get($stepRun->fresh()->result_json, 'logicalOutcome'));
        Queue::assertPushed(RunWorkflowJob::class, fn (RunWorkflowJob $job): bool => $job->workflowRunId === $run->id);

        app(WorkflowExecutionService::class)->advance($run->id);

        $this->assertSame('completed', $run->fresh()->status);
        $this->assertSame('completed', $run->stepRuns()->where('workflow_step_id', $recovery->id)->firstOrFail()->status);
        $this->assertSame('failed', $stepRun->fresh()->status);
    }

    public function test_timeout_recovery_can_execute_an_explicit_target_card_in_the_same_failed_list(): void
    {
        Queue::fake();
        [$run, $stepRun, $step] = $this->deadlineRun();
        $config = $step->config_json;
        $config['routes']['failed'] = [
            'type' => 'card', 'action_key' => $step->action_key, 'card_key' => 'second-task', 'max_attempts' => 1,
        ];
        $step->forceFill(['config_json' => $config])->save();
        $snapshot = $this->nestedRunningSnapshot($run, $stepRun);
        $stepRun->forceFill(['result_json' => $snapshot])->save();
        $this->mockRecoveryTaskRunner($snapshot, $step, 'second-task');

        $execution = app(WorkflowExecutionService::class);
        $execution->monitorStepRun($stepRun->id);
        $this->assertSame('failed', $stepRun->fresh()->status);
        $this->assertSame('timeout', data_get($stepRun->fresh()->result_json, 'status'));

        $execution->advance($run->id);

        $this->assertSame('running', $run->fresh()->status);
        $this->assertSame('waiting', $stepRun->fresh()->status);
        $this->assertCount(1, $run->stepRuns()->get());
        $this->assertSame(['success', 'timeout'], collect(data_get($run->fresh()->context_json, 'task_history'))->pluck('status')->all());
        $this->assertNotSame($snapshot['runId'], $stepRun->fresh()->external_run_id);
    }

    public function test_recovery_success_can_return_to_an_earlier_timed_out_list_through_its_explicit_route(): void
    {
        Queue::fake();
        [$run, $stepRun, $step] = $this->deadlineRun();
        $recovery = $step->workflow->steps()->create([
            'name' => 'Recovery', 'type' => WorkflowStep::TYPE_WAIT, 'action_key' => 'recovery',
            'position' => 20, 'is_enabled' => true,
            'config_json' => ['seconds' => 0, 'routes' => ['success' => [
                'type' => 'card', 'action_key' => $step->action_key, 'card_key' => 'second-task',
            ]]],
        ]);
        $config = $step->config_json;
        $config['routes']['failed'] = ['type' => 'step', 'action_key' => $recovery->action_key];
        $step->forceFill(['config_json' => $config])->save();
        $snapshot = $this->nestedRunningSnapshot($run, $stepRun);
        $stepRun->forceFill(['result_json' => $snapshot])->save();
        $this->mockRecoveryTaskRunner($snapshot, $step, 'second-task');

        $execution = app(WorkflowExecutionService::class);
        $execution->monitorStepRun($stepRun->id);
        $execution->advance($run->id);
        $this->assertSame('completed', $run->stepRuns()->where('workflow_step_id', $recovery->id)->firstOrFail()->status);
        $this->assertSame('failed', $stepRun->fresh()->status);
        $this->assertSame($step->action_key, data_get($run->fresh()->context_json, 'next_step_action_key'));

        $execution->advance($run->id);

        $this->assertSame('running', $run->fresh()->status);
        $this->assertSame('waiting', $stepRun->fresh()->status);
        $this->assertSame(['success', 'timeout'], collect(data_get($run->fresh()->context_json, 'task_history'))->pluck('status')->all());
    }

    #[DataProvider('invalidTimeoutRecoveryCursors')]
    public function test_timeout_recovery_cannot_resurrect_a_failed_list_without_its_current_explicit_route(string $corruption): void
    {
        Queue::fake();
        [$run, $stepRun, $step] = $this->deadlineRun();
        $config = $step->config_json;
        $config['routes']['failed'] = [
            'type' => 'card', 'action_key' => $step->action_key, 'card_key' => 'second-task', 'max_attempts' => 1,
        ];
        $step->forceFill(['config_json' => $config])->save();
        $snapshot = $this->nestedRunningSnapshot($run, $stepRun);
        $stepRun->forceFill(['result_json' => $snapshot])->save();
        $this->mockTaskRunnerWithStatus($snapshot);
        $execution = app(WorkflowExecutionService::class);
        $execution->monitorStepRun($stepRun->id);
        $context = $run->refresh()->context_json;

        if ($corruption === 'missing_cursor') {
            unset($context['next_step_action_key']);
        } elseif ($corruption === 'missing_task') {
            $context['next_task_key'] = 'unknown-task';
        } elseif ($corruption === 'old_external_run') {
            $context['route_history'][0]['external_run_id'] = 'old-invocation';
        } elseif ($corruption === 'old_start') {
            $context['route_history'][0]['started_at'] = now()->subHour()->toIso8601String();
        } elseif ($corruption === 'foreign_source') {
            [, $foreignStepRun] = $this->deadlineRun();
            $context['route_history'][0]['workflow_step_run_id'] = $foreignStepRun->id;
        } elseif ($corruption === 'changed_source_route') {
            $result = $stepRun->fresh()->result_json;
            $result['resolved_route'] = ['type' => 'fail'];
            $stepRun->forceFill(['result_json' => $result])->save();
        } elseif ($corruption === 'ordinary_failure') {
            $result = $stepRun->fresh()->result_json;
            $result['status'] = 'failed';
            $stepRun->forceFill(['result_json' => $result])->save();
        } elseif ($corruption === 'terminal_root') {
            $run->forceFill(['status' => 'failed', 'finished_at' => now()])->save();
        }
        $run->forceFill(['context_json' => $context])->save();

        $execution->advance($run->id);

        $this->assertSame('failed', $run->fresh()->status);
        $this->assertSame('failed', $stepRun->fresh()->status);
        $this->assertSame($snapshot['runId'], $stepRun->fresh()->external_run_id);
        $this->assertSame($snapshot['configuredTasks'], data_get($stepRun->fresh()->result_json, 'configuredTasks'));
    }

    public static function invalidTimeoutRecoveryCursors(): array
    {
        return collect([
            'missing_cursor', 'missing_task', 'old_external_run', 'old_start',
            'foreign_source', 'changed_source_route', 'ordinary_failure', 'terminal_root',
        ])->mapWithKeys(fn (string $value): array => [$value => [$value]])->all();
    }

    public function test_explicit_timeout_retry_route_stops_after_its_configured_attempt_budget(): void
    {
        Queue::fake();
        [$run, $stepRun, $step] = $this->deadlineRun();
        $config = $step->config_json;
        $config['routes']['timeout'] = [
            'type' => 'card', 'action_key' => $step->action_key, 'card_key' => 'second-task', 'max_attempts' => 1,
        ];
        $step->forceFill(['config_json' => $config])->save();
        $snapshot = $this->nestedRunningSnapshot($run, $stepRun);
        $stepRun->forceFill(['result_json' => $snapshot])->save();
        $this->mockRecoveryTaskRunner($snapshot, $step, 'second-task');
        $execution = app(WorkflowExecutionService::class);

        $execution->monitorStepRun($stepRun->id);
        $this->assertSame([1], array_values(data_get($run->fresh()->context_json, 'route_attempts')));
        $execution->advance($run->id);
        $this->assertSame('waiting', $stepRun->fresh()->status);
        Carbon::setTestNow(now()->addSeconds(121));

        $execution->monitorStepRun($stepRun->id);
        $execution->advance($run->id);

        $this->assertSame('failed', $run->fresh()->status);
        $this->assertSame('failed', $stepRun->fresh()->status);
        $this->assertTrue(data_get($stepRun->fresh()->result_json, 'retry_blocked'));
        $this->assertSame([1], array_values(data_get($run->fresh()->context_json, 'route_attempts')));
        $this->assertCount(1, data_get($run->fresh()->context_json, 'route_history'));
    }

    public function test_explicit_failure_route_does_not_mark_a_genuine_timeout_completed(): void
    {
        Queue::fake();
        [$run, $stepRun, $step] = $this->deadlineRun();
        $config = $step->config_json;
        $config['routes']['failed'] = ['type' => 'fail'];
        $step->forceFill(['config_json' => $config])->save();
        $snapshot = $this->nestedRunningSnapshot($run, $stepRun);
        $stepRun->forceFill(['result_json' => $snapshot])->save();
        $this->mockTaskRunnerWithStatus($snapshot);

        app(WorkflowExecutionService::class)->monitorStepRun($stepRun->id);

        $this->assertSame('failed', $stepRun->fresh()->status);
        $this->assertSame('failed', $run->fresh()->status);
        $this->assertSame('timeout', data_get($stepRun->fresh()->result_json, 'status'));
        $this->assertSame($snapshot['configuredTasks'], data_get($stepRun->fresh()->result_json, 'configuredTasks'));
        Queue::assertNotPushed(RunWorkflowJob::class);
    }

    public function test_timely_native_error_route_is_consumed_and_continues_the_parent_workflow(): void
    {
        Queue::fake();
        [$run, $stepRun, $step] = $this->deadlineRun();
        $recovery = $step->workflow->steps()->create([
            'name' => 'Recovery', 'type' => WorkflowStep::TYPE_BROWSER_TASK, 'action_key' => 'recovery',
            'position' => 20, 'is_enabled' => true,
            'config_json' => ['tasks' => [['key' => 'recover', 'task_key' => 'wait.seconds', 'value' => 0]]],
        ]);
        $config = $step->config_json;
        $config['tasks'][0]['on_error'] = ['type' => 'card', 'action_key' => $recovery->action_key, 'card_key' => 'recover'];
        $step->forceFill(['config_json' => $config])->save();
        [$status, $result] = $this->terminalExternalResult($run, $stepRun, $step);
        $result['tasks'] = [[...$result['tasks'][0], 'status' => 'failed', 'statusMessage' => 'Own test task failed.']];
        $result['configuredTasks'] = $step->task_cards;
        $result['routeRequested'] = true;
        $result['routeOutcome'] = 'failed';
        $result['completedTaskKey'] = 'first-task';
        $result['logicalOutcome'] = 'technical_error';
        $this->mockTerminalTaskRunner($status, $result);

        app(WorkflowExecutionService::class)->monitorStepRun($stepRun->id);

        $this->assertSame('running', $run->fresh()->status);
        $this->assertSame('recovery', data_get($run->fresh()->context_json, 'next_step_action_key'));
        $this->assertSame('recover', data_get($run->fresh()->context_json, 'next_task_key'));
        $this->assertNull(data_get($stepRun->fresh()->result_json, 'timedOutAt'));
        $this->assertSame('technical_error', data_get($stepRun->fresh()->result_json, 'logicalOutcome'));
        $this->assertSame('failed', data_get($stepRun->fresh()->result_json, 'tasks.0.status'));
        $this->assertNotContains(data_get($stepRun->fresh()->result_json, 'tasks.1.status'), ['success', 'completed']);
        Queue::assertPushed(RunWorkflowJob::class, fn (RunWorkflowJob $job): bool => $job->workflowRunId === $run->id);
    }

    private function deadlineRun(): array
    {
        [$workflow, $step] = $this->workflow();
        $config = $step->config_json;
        $config['timeout_seconds'] = 120;
        $step->forceFill(['config_json' => $config])->save();
        [$run, $stepRun] = $this->waitingWorkflowTaskRun($workflow, $step, ['interactive_debug' => false]);

        return [$run, $stepRun, $step];
    }

    /** Native Node final: owner IDs live in status and browserIdentity, not invented top-level result fields. */
    private function terminalExternalResult(WorkflowRun $run, WorkflowStepRun $stepRun, WorkflowStep $step): array
    {
        $status = [
            'runId' => $stepRun->external_run_id,
            'workflowRunId' => $run->id,
            'workflowRunUuid' => $run->run_uuid,
            'workflowStepId' => $step->id,
            'workflowStepRunId' => $stepRun->id,
            'startedAt' => now()->subSeconds(145)->toIso8601String(),
            'state' => 'completed', 'isRunning' => false,
        ];
        $result = [
            'ok' => true, 'status' => 'success', 'finishedAt' => now()->subSeconds(60)->toIso8601String(),
            'browserIdentity' => [
                'runId' => $stepRun->external_run_id,
                'workflowRunId' => $run->id,
                'workflowRunUuid' => $run->run_uuid,
            ],
            'tasks' => collect($step->task_cards)->map(fn (array $task): array => [
                ...$task, 'status' => 'success',
                'startedAt' => now()->subSeconds(100)->toIso8601String(),
                'finishedAt' => now()->subSeconds(60)->toIso8601String(),
            ])->all(),
        ];

        return [$status, $result];
    }

    private function mockTerminalTaskRunner(array $status, array $result): void
    {
        $runner = Mockery::mock(WorkflowTaskRunner::class);
        $runner->shouldReceive('readRun')->andReturn($status)->byDefault();
        $runner->shouldReceive('readResult')->andReturn($result)->byDefault();
        $runner->shouldReceive('closeRun')->andReturn(['ok' => true])->byDefault();
        $runner->shouldReceive('cancelRun')->andReturn(['ok' => true])->byDefault();
        $this->app->instance(WorkflowTaskRunner::class, $runner);
    }

    private function mockRecoveryTaskRunner(array $snapshot, WorkflowStep $target, string $targetTaskKey): void
    {
        $runner = Mockery::mock(WorkflowTaskRunner::class);
        $runner->shouldReceive('readRun')->andReturn($snapshot)->byDefault();
        $runner->shouldReceive('closeRun')->andReturn(['ok' => true])->byDefault();
        $runner->shouldReceive('cancelRun')->andReturn(['ok' => true])->byDefault();
        $runner->shouldReceive('start')->once()->andReturnUsing(function (
            WorkflowRun $run, WorkflowStep $step, WorkflowStepRun $stepRun, array $context, string $externalRunId,
        ) use ($target, $targetTaskKey): array {
            $this->assertSame($target->id, $step->id);
            $this->assertSame($targetTaskKey, $context['nextTaskKey']);

            return ['runId' => $externalRunId, 'state' => 'running', 'isRunning' => true];
        });
        $this->app->instance(WorkflowTaskRunner::class, $runner);
    }

    private function nestedRunningSnapshot(WorkflowRun $run, WorkflowStepRun $stepRun): array
    {
        $path = [
            ['frame_key' => 'middle-12', 'workflow_id' => 12, 'include_task_key' => 'first-task'],
            ['frame_key' => 'leaf-42', 'parent_frame_key' => 'middle-12', 'workflow_id' => 42, 'include_task_key' => 'leaf-include'],
        ];
        $configured = collect(['leaf-open', 'leaf-wait', 'leaf-future'])->map(fn (string $key): array => [
            'key' => $key, 'task_key' => 'wait.seconds', 'runner' => 'node', 'value' => 0,
            'status' => 'configured', 'parent_task_key' => 'first-task',
            'source_workflow_id' => 42, 'source_workflow_step_id' => 171,
            'embedded_workflow_path' => $path, 'browser_window' => 'mail-leaf-main',
        ])->all();

        return [
            ...$this->externalStatus(now()->subSeconds(5)),
            'runId' => $stepRun->external_run_id, 'workflowRunId' => $run->id,
            'workflowStepId' => $stepRun->workflow_step_id, 'workflowStepRunId' => $stepRun->id,
            'startedAt' => now()->subSeconds(145)->toIso8601String(),
            'browserWsEndpoint' => 'ws://127.0.0.1/private-test-endpoint',
            'activeBrowserWindow' => 'mail-leaf-main',
            'configuredTasks' => $configured,
            'tasks' => [
                [...$configured[0], 'status' => 'success', 'startedAt' => now()->subSeconds(100)->toIso8601String(), 'finishedAt' => now()->subSeconds(90)->toIso8601String()],
                [...$configured[1], 'status' => 'running', 'startedAt' => now()->subSeconds(80)->toIso8601String()],
            ],
            'events' => [['stage' => 'task.started', 'taskKey' => 'leaf-wait', 'at' => now()->subSeconds(80)->toIso8601String()]],
            'browserWindows' => [['name' => 'mail-leaf-main', 'targetId' => 'own-leaf-target', 'isClosed' => false]],
        ];
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
