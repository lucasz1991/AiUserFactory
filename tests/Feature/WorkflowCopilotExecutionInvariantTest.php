<?php

namespace Tests\Feature;

use App\Jobs\RunWorkflowJob;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use App\Models\WorkflowStepRun;
use App\Services\Workflows\WorkflowCopilotSessionService;
use App\Services\Workflows\WorkflowExecutionService;
use App\Services\Workflows\WorkflowTaskRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
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

    public function test_wait_step_with_task_cards_executes_the_cards_instead_of_the_wait_fallback(): void
    {
        Queue::fake();
        [$workflow, $step] = $this->workflow();
        $step->forceFill(['type' => WorkflowStep::TYPE_WAIT])->save();
        $run = WorkflowRun::query()->create([
            'run_uuid' => (string) str()->uuid(),
            'workflow_id' => $workflow->id,
            'status' => 'running',
            'context_json' => ['execution_target' => 'system'],
            'result_json' => [],
        ]);
        $stepRun = WorkflowStepRun::query()->create([
            'workflow_run_id' => $run->id,
            'workflow_step_id' => $step->id,
            'status' => 'pending',
            'result_json' => [],
        ]);
        $reservedRunId = null;
        $runner = Mockery::mock(WorkflowTaskRunner::class);
        $runner->shouldReceive('start')
            ->once()
            ->withArgs(function (
                WorkflowRun $runArg,
                WorkflowStep $stepArg,
                WorkflowStepRun $stepRunArg,
                array $runtimeContext,
                string $runId,
            ) use ($run, $step, $stepRun, &$reservedRunId): bool {
                $reservedRunId = $runId;

                return $runArg->is($run)
                    && $stepArg->is($step)
                    && $stepRunArg->is($stepRun)
                    && $runtimeContext !== []
                    && Str::isUuid($runId);
            })
            ->andReturnUsing(fn (
                WorkflowRun $runArg,
                WorkflowStep $stepArg,
                WorkflowStepRun $stepRunArg,
                array $runtimeContext,
                string $runId,
            ): array => [
                'runId' => $runId,
                'status' => 'running',
                'livePreviewPollIntervalSeconds' => 1,
            ]);
        $this->app->instance(WorkflowTaskRunner::class, $runner);
        $method = new ReflectionMethod(WorkflowExecutionService::class, 'executeStep');

        $status = $method->invoke(app(WorkflowExecutionService::class), $run, $step, $stepRun);

        $this->assertSame('waiting', $status);
        $this->assertSame('workflow-task', $stepRun->fresh()->external_run_type);
        $this->assertNotNull($reservedRunId);
        $this->assertSame($reservedRunId, $stepRun->fresh()->external_run_id);
    }

    public function test_manual_run_can_pause_with_persisted_runtime_context_and_resume_at_selected_task(): void
    {
        Queue::fake();
        [$workflow, $step] = $this->workflow();
        $execution = app(WorkflowExecutionService::class);
        $run = $execution->start($workflow, [
            'execution_target' => 'system',
            'workflow_variables' => ['collected' => ['one']],
            'browser_windows' => ['main' => ['currentUrl' => 'https://example.test']],
        ]);

        $pause = $execution->requestManualPause($run);
        $paused = $run->fresh();

        $this->assertTrue($pause['ok']);
        $this->assertSame('paused', $paused->status);
        $this->assertSame(['one'], data_get($paused->context_json, 'manual_pause_checkpoint.workflow_variables.collected'));
        $this->assertSame('https://example.test', data_get($paused->context_json, 'manual_pause_checkpoint.browser_windows.main.currentUrl'));

        $resume = $execution->resumeManualPause($paused, $step->id, 'second-task');
        $resumed = $run->fresh();

        $this->assertTrue($resume['ok']);
        $this->assertSame('running', $resumed->status);
        $this->assertSame($step->id, $resumed->current_workflow_step_id);
        $this->assertSame('second-task', data_get($resumed->context_json, 'next_task_key'));
        $this->assertNull(data_get($resumed->context_json, 'manual_pause_checkpoint'));
        Queue::assertPushed(RunWorkflowJob::class, fn (RunWorkflowJob $job): bool => $job->workflowRunId === $run->id);
    }

    public function test_interactive_continuous_runtime_is_batched_while_one_shot_stays_segmented(): void
    {
        [$workflow, $step] = $this->workflow();
        $run = WorkflowRun::query()->create([
            'run_uuid' => (string) str()->uuid(),
            'workflow_id' => $workflow->id,
            'status' => 'running',
            'context_json' => [
                'execution_target' => 'system',
                'interactive_debug' => true,
                'next_task_key' => 'first-task',
            ],
            'result_json' => [],
        ]);
        $stepRun = WorkflowStepRun::query()->create([
            'workflow_run_id' => $run->id,
            'workflow_step_id' => $step->id,
            'status' => 'running',
            'result_json' => [],
        ]);
        $method = new ReflectionMethod(WorkflowExecutionService::class, 'workflowRuntimeContext');
        $method->setAccessible(true);
        $execution = app(WorkflowExecutionService::class);

        $continuous = $method->invoke($execution, $run->fresh(), $step, $stepRun);
        $this->assertFalse($continuous['copilotSupervised']);
        $this->assertFalse($continuous['segmentTasks']);

        $run->forceFill(['context_json' => [
            ...$run->context_json,
            'studio_single_task' => true,
        ]])->save();
        $single = $method->invoke($execution, $run->fresh(), $step, $stepRun);

        $this->assertFalse($single['copilotSupervised']);
        $this->assertTrue($single['studioSingleTask']);
        $this->assertTrue($single['segmentTasks']);
    }

    public function test_normal_dev_run_respects_explicit_capture_flags_even_for_single_task_execution(): void
    {
        [$workflow, $step] = $this->workflow();
        $workflow->forceFill(['settings_json' => [
            'dev_mode' => true,
            'dev_capture_dom_after_step' => true,
            'dev_capture_screenshot_after_step' => true,
        ]])->save();
        $stepRun = (new WorkflowStepRun)->forceFill(['id' => 9002]);
        $runner = app(WorkflowTaskRunner::class);
        $method = new ReflectionMethod($runner, 'devDebugRuntimeConfig');
        $method->setAccessible(true);
        $run = (new WorkflowRun)->forceFill([
            'workflow_id' => $workflow->id,
            'run_uuid' => (string) str()->uuid(),
            'context_json' => ['execution_target' => 'system'],
        ]);
        $run->setRelation('workflow', $workflow->fresh());

        $continuous = $method->invoke($runner, $run, $step, $stepRun, true);
        $this->assertTrue($continuous['enabled']);
        $this->assertFalse($continuous['captureDomBeforeStep']);
        $this->assertFalse($continuous['captureScreenshotBeforeStep']);
        $this->assertTrue($continuous['captureDomAfterStep']);
        $this->assertTrue($continuous['captureScreenshotAfterStep']);

        $run->context_json = ['execution_target' => 'system', 'studio_single_task' => true];
        $single = $method->invoke($runner, $run, $step, $stepRun, true);
        $this->assertFalse($single['captureDomBeforeStep']);
        $this->assertFalse($single['captureScreenshotBeforeStep']);
        $this->assertTrue($single['captureDomAfterStep']);
        $this->assertTrue($single['captureScreenshotAfterStep']);
    }

    public function test_supervised_runtime_keeps_a_paired_dom_loop_atomic_and_continues_after_its_end(): void
    {
        [, $step] = $this->workflow();
        $config = $step->config_json;
        $config['tasks'] = [[
            'key' => 'result-loop',
            'task_key' => 'loop.for_each_element',
            'title' => 'Resultate durchlaufen',
            'selector' => 'a[data-result-link]',
            'loop_pair_id' => 'loop-results',
            'loop_pair_segment' => 'start',
            'loop_start_key' => 'result-loop',
            'loop_end_key' => 'result-loop-end',
            'empty_target' => 'result-loop-end',
        ], [
            'key' => 'read-result',
            'task_key' => 'browser.read_searchengine_result',
            'title' => 'Resultat lesen',
            'scope_variable' => 'current_result',
            'output_variable' => 'current_result',
        ], [
            'key' => 'append-result',
            'task_key' => 'data.append_to_array',
            'title' => 'Resultat anhaengen',
            'array_name' => 'top_results',
            'value_from_variable' => 'current_result',
        ], [
            'key' => 'result-loop-end',
            'task_key' => 'loop.end',
            'title' => 'Loop-Ende',
            'loop_pair_id' => 'loop-results',
            'loop_pair_segment' => 'end',
            'loop_start_key' => 'result-loop',
            'loop_end_key' => 'result-loop-end',
        ], [
            'key' => 'after-loop',
            'task_key' => 'wait.seconds',
            'title' => 'Nach dem Loop',
            'value' => 0,
        ]];
        $step->forceFill(['config_json' => $config])->save();
        $runtimeTasks = new ReflectionMethod(WorkflowTaskRunner::class, 'runtimeTasks');
        $runtimeTasks->setAccessible(true);
        $segment = $runtimeTasks->invoke(
            app(WorkflowTaskRunner::class),
            $step->fresh(),
            'result-loop',
            true,
        );
        $continuation = new ReflectionMethod(WorkflowExecutionService::class, 'successfulCheckpointContinuation');
        $continuation->setAccessible(true);
        [$nextAction, $nextTaskKey] = $continuation->invoke(
            app(WorkflowExecutionService::class),
            $step->fresh(),
            'result-loop',
            ['ok' => true, 'status' => 'success'],
        );

        $this->assertSame(
            ['result-loop', 'read-result', 'append-result', 'result-loop-end'],
            array_column($segment, 'key'),
        );
        $this->assertSame('next_task', $nextAction);
        $this->assertSame('after-loop', $nextTaskKey);
    }

    public function test_paused_session_cannot_start_or_resume_a_copilot_run(): void
    {
        Queue::fake();
        [$workflow, $step] = $this->workflow();
        $sessions = app(WorkflowCopilotSessionService::class);
        $session = $sessions->start($workflow);
        $run = app(WorkflowExecutionService::class)->start($workflow, [
            'workflow_copilot_session_id' => $session->id,
            'copilot_supervised' => true,
            'execution_target' => 'client_controller',
        ], 'workflow-copilot');
        $this->putRunAtCheckpoint($run, $step, 'first-task');
        $sessions->pause($session);
        Queue::fake();

        try {
            app(WorkflowExecutionService::class)->start($workflow, [
                'workflow_copilot_session_id' => $session->id,
                'copilot_supervised' => true,
            ], 'workflow-copilot');
            $this->fail('A paused session must not start a new run.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('pausiert', $exception->getMessage());
        }

        app(WorkflowExecutionService::class)->resumeCopilotCheckpoint($run);
        app(WorkflowExecutionService::class)->retryCopilotTask($run, 'first-task');

        $run->refresh();
        $this->assertSame('waiting', $run->status);
        $this->assertSame('checkpoint-first-task', data_get($run->context_json, 'copilot_checkpoint.id'));
        Queue::assertNotPushed(RunWorkflowJob::class);
    }

    public function test_worker_guard_does_not_start_a_step_after_session_pause_or_stop(): void
    {
        Queue::fake();
        [$pausedWorkflow] = $this->workflow();
        $sessions = app(WorkflowCopilotSessionService::class);
        $execution = app(WorkflowExecutionService::class);
        $pausedSession = $sessions->start($pausedWorkflow);
        $pausedRun = $execution->start($pausedWorkflow, [
            'workflow_copilot_session_id' => $pausedSession->id,
            'copilot_supervised' => true,
        ], 'workflow-copilot');
        $sessions->pause($pausedSession);

        (new RunWorkflowJob($pausedRun->id))->handle($execution);

        $this->assertSame('queued', $pausedRun->fresh()->status);
        $this->assertDatabaseMissing('workflow_step_runs', ['workflow_run_id' => $pausedRun->id]);

        [$stoppedWorkflow] = $this->workflow();
        $stoppedSession = $sessions->start($stoppedWorkflow);
        $stoppedRun = $execution->start($stoppedWorkflow, [
            'workflow_copilot_session_id' => $stoppedSession->id,
            'copilot_supervised' => true,
        ], 'workflow-copilot');
        $sessions->stop($stoppedSession);

        (new RunWorkflowJob($stoppedRun->id))->handle($execution);

        $stoppedRun->refresh();
        $this->assertSame('cancelled', $stoppedRun->status);
        $this->assertSame('workflow-copilot-advance-guard', data_get($stoppedRun->result_json, 'source'));
        $this->assertDatabaseMissing('workflow_step_runs', ['workflow_run_id' => $stoppedRun->id]);
    }

    public function test_copilot_retry_rejects_a_client_target_even_when_the_ids_match(): void
    {
        Queue::fake();
        [$workflow, $step] = $this->workflow();
        $session = app(WorkflowCopilotSessionService::class)->start($workflow);
        $run = app(WorkflowExecutionService::class)->start($workflow, [
            'workflow_copilot_session_id' => $session->id,
            'copilot_supervised' => true,
        ], 'workflow-copilot');
        $this->putRunAtCheckpoint($run, $step, 'first-task');
        $run->refresh();
        $context = $run->context_json;
        $context['execution_target'] = 'client_controller';
        $run->forceFill(['context_json' => $context])->save();
        Queue::fake();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('execution_target=system');

        app(WorkflowExecutionService::class)->retryCopilotTask($run, 'first-task');
    }

    public function test_valid_retry_and_resume_are_dispatched_only_after_locked_state_changes(): void
    {
        Queue::fake();
        [$workflow, $step] = $this->workflow();
        $session = app(WorkflowCopilotSessionService::class)->start($workflow);
        $execution = app(WorkflowExecutionService::class);
        $retryRun = $execution->start($workflow, [
            'workflow_copilot_session_id' => $session->id,
            'copilot_supervised' => true,
        ], 'workflow-copilot');
        $retryStepRun = $this->putRunAtCheckpoint($retryRun, $step, 'first-task');
        Queue::fake();

        $execution->retryCopilotTask($retryRun, 'first-task');

        $retryRun->refresh();
        $this->assertSame('running', $retryRun->status);
        $this->assertSame('system', data_get($retryRun->context_json, 'execution_target'));
        $this->assertNull(data_get($retryRun->context_json, 'copilot_checkpoint'));
        $this->assertSame('queued', $retryStepRun->fresh()->status);
        Queue::assertPushed(RunWorkflowJob::class, fn (RunWorkflowJob $job): bool => $job->workflowRunId === $retryRun->id);

        [$resumeWorkflow, $resumeStep] = $this->workflow();
        $resumeSession = app(WorkflowCopilotSessionService::class)->start($resumeWorkflow);
        $resumeRun = $execution->start($resumeWorkflow, [
            'workflow_copilot_session_id' => $resumeSession->id,
            'copilot_supervised' => true,
        ], 'workflow-copilot');
        $resumeStepRun = $this->putRunAtCheckpoint($resumeRun, $resumeStep, 'first-task');
        Queue::fake();

        $execution->resumeCopilotCheckpoint($resumeRun);

        $resumeRun->refresh();
        $this->assertSame('running', $resumeRun->status);
        $this->assertSame('second-task', data_get($resumeRun->context_json, 'next_task_key'));
        $this->assertNull(data_get($resumeRun->context_json, 'copilot_checkpoint'));
        $this->assertSame('queued', $resumeStepRun->fresh()->status);
        Queue::assertPushed(RunWorkflowJob::class, fn (RunWorkflowJob $job): bool => $job->workflowRunId === $resumeRun->id);
    }

    public function test_successful_probe_resumes_after_the_original_task_instead_of_waiting_on_repair(): void
    {
        Queue::fake();
        [$workflow, $step] = $this->workflow();
        $session = app(WorkflowCopilotSessionService::class)->start($workflow);
        $execution = app(WorkflowExecutionService::class);
        $run = $execution->start($workflow, [
            'workflow_copilot_session_id' => $session->id,
            'copilot_supervised' => true,
        ], 'workflow-copilot');
        $stepRun = $this->putRunAtCheckpoint($run, $step, 'first-task');
        $context = $run->fresh()->context_json;
        $context['copilot_repair_plan'] = [
            'action' => 'probe_update',
            'original_task_key' => 'first-task',
        ];
        $context['copilot_checkpoint'] = [
            'id' => 'successful-probe-checkpoint',
            'kind' => 'probe',
            'workflow_step_id' => $step->id,
            'task_key' => 'first-task--copilot-probe',
            'successful' => true,
            'outcome' => 'success',
            'next_action' => 'repair',
            'next_task_key' => null,
            'result' => [
                'ok' => true,
                'status' => 'success',
                'tasks' => [[
                    'key' => 'first-task--copilot-probe',
                    'task_key' => 'wait.seconds',
                    'status' => 'success',
                ]],
            ],
        ];
        $run->forceFill(['context_json' => $context])->save();
        Queue::fake();

        $continued = $execution->resumeCopilotCheckpoint($run, 'first-task');

        $run->refresh();
        $this->assertTrue($continued);
        $this->assertSame('running', $run->status);
        $this->assertSame($step->id, $run->current_workflow_step_id);
        $this->assertSame('second-task', data_get($run->context_json, 'next_task_key'));
        $this->assertNull(data_get($run->context_json, 'copilot_checkpoint'));
        $this->assertNull(data_get($run->context_json, 'copilot_transient_task'));
        $this->assertNull(data_get($run->context_json, 'copilot_repair_plan'));
        $this->assertSame('queued', $stepRun->fresh()->status);
        Queue::assertPushed(RunWorkflowJob::class, fn (RunWorkflowJob $job): bool => $job->workflowRunId === $run->id);
    }

    public function test_step_success_route_does_not_skip_remaining_tasks_after_a_checkpoint(): void
    {
        [$workflow, $step] = $this->workflow();
        $targetStep = $workflow->steps()->create([
            'name' => 'Result',
            'type' => WorkflowStep::TYPE_DATA_TASK,
            'action_key' => 'result',
            'position' => 20,
            'is_enabled' => true,
            'config_json' => ['tasks' => []],
        ]);
        $config = $step->config_json;
        $config['routes']['success'] = [
            'type' => 'step',
            'action_key' => $targetStep->action_key,
            'step' => $targetStep->action_key,
        ];
        $step->forceFill(['config_json' => $config])->save();

        $method = new ReflectionMethod(WorkflowExecutionService::class, 'successfulCheckpointContinuation');
        $method->setAccessible(true);
        [$action, $nextTaskKey] = $method->invoke(
            app(WorkflowExecutionService::class),
            $step->fresh(),
            'first-task',
            [
                'ok' => true,
                'status' => 'success',
                'completedTaskKey' => 'first-task',
            ],
        );

        $this->assertSame('next_task', $action);
        $this->assertSame('second-task', $nextTaskKey);
    }

    public function test_resolved_optional_obstacle_is_skipped_and_continues_with_the_next_task(): void
    {
        Queue::fake();
        [$workflow, $step] = $this->workflow();
        $config = $step->config_json;
        $config['tasks'][0] = array_replace($config['tasks'][0], [
            'task_key' => 'browser.click',
            'title' => 'Consent: Alle ablehnen',
            'selector' => '#W0wltc',
        ]);
        $step->forceFill(['config_json' => $config])->save();
        $session = app(WorkflowCopilotSessionService::class)->start($workflow);
        $execution = app(WorkflowExecutionService::class);
        $run = $execution->start($workflow, [
            'workflow_copilot_session_id' => $session->id,
            'copilot_supervised' => true,
        ], 'workflow-copilot');
        $stepRun = $this->putRunAtCheckpoint($run, $step->fresh(), 'first-task');
        $context = $run->fresh()->context_json;
        $context['copilot_checkpoint'] = array_replace($context['copilot_checkpoint'], [
            'successful' => false,
            'outcome' => 'failed',
            'next_action' => 'repair',
            'next_task_key' => null,
            'result' => [
                'ok' => false,
                'status' => 'failed',
                'failedTaskKey' => 'first-task',
                'tasks' => [[
                    'key' => 'first-task',
                    'task_key' => 'browser.click',
                    'status' => 'failed',
                ]],
            ],
        ]);
        $run->forceFill(['context_json' => $context])->save();
        Queue::fake();

        $continued = $execution->skipResolvedCopilotTask($run, 'first-task');

        $run->refresh();
        $this->assertTrue($continued);
        $this->assertSame('running', $run->status);
        $this->assertSame('second-task', data_get($run->context_json, 'next_task_key'));
        $this->assertNull(data_get($run->context_json, 'copilot_checkpoint'));
        $this->assertSame('queued', $stepRun->fresh()->status);
        $this->assertSame('skipped', data_get($stepRun->fresh()->result_json, 'tasks.0.status'));
        Queue::assertPushed(RunWorkflowJob::class, fn (RunWorkflowJob $job): bool => $job->workflowRunId === $run->id);
    }

    public function test_resumed_task_cursor_keeps_its_checkpoint_step_when_an_earlier_step_was_skipped(): void
    {
        Queue::fake();
        [$workflow, $checkpointStep] = $this->workflow();
        $checkpointStep->forceFill(['position' => 30])->save();
        $completedStep = $workflow->steps()->create([
            'name' => 'Completed entry step',
            'type' => WorkflowStep::TYPE_DATA_TASK,
            'action_key' => 'completed-entry-step',
            'position' => 10,
            'is_enabled' => true,
            'config_json' => ['tasks' => []],
        ]);
        $skippedStep = $workflow->steps()->create([
            'name' => 'Skipped linear step',
            'type' => WorkflowStep::TYPE_DATA_TASK,
            'action_key' => 'skipped-linear-step',
            'position' => 20,
            'is_enabled' => true,
            'config_json' => ['tasks' => [[
                'key' => 'unrelated-task',
                'task_key' => 'wait.seconds',
                'title' => 'Unrelated task',
                'value' => 0,
            ]]],
        ]);
        $session = app(WorkflowCopilotSessionService::class)->start($workflow->fresh());
        $execution = app(WorkflowExecutionService::class);
        $run = $execution->start($workflow->fresh(), [
            'workflow_copilot_session_id' => $session->id,
            'copilot_supervised' => true,
        ], 'workflow-copilot');
        WorkflowStepRun::query()->create([
            'workflow_run_id' => $run->id,
            'workflow_step_id' => $completedStep->id,
            'status' => 'completed',
            'result_json' => ['ok' => true],
        ]);
        $checkpointStepRun = $this->putRunAtCheckpoint($run, $checkpointStep, 'first-task');
        Queue::fake();

        $execution->resumeCopilotCheckpoint($run);

        $run->refresh();
        $resolver = new ReflectionMethod($execution, 'nextStepForRun');
        $resolver->setAccessible(true);
        $resolvedStep = $resolver->invoke($execution, $run);
        $runtimeTasks = new ReflectionMethod(WorkflowTaskRunner::class, 'runtimeTasks');
        $runtimeTasks->setAccessible(true);
        $tasks = $runtimeTasks->invoke(app(WorkflowTaskRunner::class), $resolvedStep, 'second-task', true);

        $this->assertSame($checkpointStep->id, $resolvedStep->id);
        $this->assertNotSame($skippedStep->id, $resolvedStep->id);
        $this->assertSame($checkpointStep->id, $run->current_workflow_step_id);
        $this->assertSame('queued', $checkpointStepRun->fresh()->status);
        $this->assertSame('second-task', $tasks[0]['key']);
        $this->assertDatabaseMissing('workflow_step_runs', [
            'workflow_run_id' => $run->id,
            'workflow_step_id' => $skippedStep->id,
        ]);
    }

    public function test_repeated_monitoring_reuses_the_runtime_checkpoint_uuid(): void
    {
        Queue::fake();
        [$workflow, $step] = $this->workflow();
        $session = app(WorkflowCopilotSessionService::class)->start($workflow);
        $run = app(WorkflowExecutionService::class)->start($workflow, [
            'workflow_copilot_session_id' => $session->id,
            'copilot_supervised' => true,
        ], 'workflow-copilot');
        $stepRun = $this->putRunAtCheckpoint($run, $step, 'first-task', false);
        $execution = app(WorkflowExecutionService::class);
        $method = new ReflectionMethod($execution, 'holdCopilotTaskCheckpoint');
        $method->setAccessible(true);
        $status = ['state' => 'failed', 'message' => 'Element nicht gefunden.'];
        $result = ['ok' => false, 'status' => 'failed', 'statusMessage' => 'Element nicht gefunden.'];

        $firstId = $method->invoke($execution, $stepRun, $status, $result, false);
        $secondId = $method->invoke($execution, $stepRun->fresh(), $status, $result, false);

        $this->assertNotSame('', $firstId);
        $this->assertSame($firstId, $secondId);
        $this->assertSame($firstId, data_get($run->fresh()->context_json, 'copilot_checkpoint.id'));
        $this->assertSame($firstId, data_get($stepRun->fresh()->result_json, 'copilotCheckpointId'));
    }

    public function test_delayed_monitor_does_not_replace_a_held_checkpoint_after_the_external_id_was_cleared(): void
    {
        Queue::fake();
        [$workflow, $step] = $this->workflow();
        $session = app(WorkflowCopilotSessionService::class)->start($workflow);
        $run = app(WorkflowExecutionService::class)->start($workflow, [
            'workflow_copilot_session_id' => $session->id,
            'copilot_supervised' => true,
        ], 'workflow-copilot');
        $stepRun = $this->putRunAtCheckpoint($run, $step, 'first-task');
        $stepRun->forceFill([
            'external_run_type' => null,
            'external_run_id' => null,
        ])->save();

        app(WorkflowExecutionService::class)->monitorStepRun($stepRun->id);

        $this->assertSame('checkpoint-first-task', data_get($run->fresh()->context_json, 'copilot_checkpoint.id'));
        $this->assertSame('waiting', $stepRun->fresh()->status);
        $this->assertDatabaseCount('workflow_task_attempts', 0);
        $this->assertDatabaseCount('workflow_run_checkpoints', 0);
    }

    public function test_delayed_run_job_does_not_change_or_monitor_a_held_copilot_checkpoint(): void
    {
        Queue::fake();
        [$workflow, $step] = $this->workflow();
        $session = app(WorkflowCopilotSessionService::class)->start($workflow);
        $execution = app(WorkflowExecutionService::class);
        $run = $execution->start($workflow, [
            'workflow_copilot_session_id' => $session->id,
            'copilot_supervised' => true,
        ], 'workflow-copilot');
        $stepRun = $this->putRunAtCheckpoint($run, $step, 'first-task');
        Queue::fake();

        (new RunWorkflowJob($run->id))->handle($execution);

        $this->assertSame('waiting', $run->fresh()->status);
        $this->assertSame('waiting', $stepRun->fresh()->status);
        $this->assertSame('checkpoint-first-task', data_get($run->fresh()->context_json, 'copilot_checkpoint.id'));
        Queue::assertNothingPushed();
    }

    public function test_resume_recovers_a_held_checkpoint_whose_run_was_incorrectly_marked_running(): void
    {
        Queue::fake();
        [$workflow, $step] = $this->workflow();
        $session = app(WorkflowCopilotSessionService::class)->start($workflow);
        $execution = app(WorkflowExecutionService::class);
        $run = $execution->start($workflow, [
            'workflow_copilot_session_id' => $session->id,
            'copilot_supervised' => true,
        ], 'workflow-copilot');
        $stepRun = $this->putRunAtCheckpoint($run, $step, 'first-task');
        $run->forceFill([
            'status' => 'running',
            'current_workflow_step_id' => null,
        ])->save();
        Queue::fake();

        $continued = $execution->resumeCopilotCheckpoint($run);

        $run->refresh();
        $this->assertTrue($continued);
        $this->assertSame('running', $run->status);
        $this->assertSame($step->id, $run->current_workflow_step_id);
        $this->assertSame('second-task', data_get($run->context_json, 'next_task_key'));
        $this->assertNull(data_get($run->context_json, 'copilot_checkpoint'));
        $this->assertSame('queued', $stepRun->fresh()->status);
        Queue::assertPushed(RunWorkflowJob::class, fn (RunWorkflowJob $job): bool => $job->workflowRunId === $run->id);
    }

    public function test_system_copilot_run_always_captures_observation_artifacts_without_dev_mode(): void
    {
        Queue::fake();
        [$workflow, $step] = $this->workflow();
        $workflow->forceFill(['settings_json' => [
            'dev_mode' => false,
            'development' => false,
            'dev_capture_dom_before_step' => false,
            'dev_capture_dom_after_step' => false,
            'dev_capture_screenshot_before_step' => false,
            'dev_capture_screenshot_after_step' => false,
            'dev_keep_artifacts' => false,
        ]])->save();
        $session = app(WorkflowCopilotSessionService::class)->start($workflow);
        $run = app(WorkflowExecutionService::class)->start($workflow, [
            'workflow_copilot_session_id' => $session->id,
            'copilot_supervised' => true,
        ], 'workflow-copilot');
        $stepRun = (new WorkflowStepRun)->forceFill(['id' => 9001]);
        $runner = app(WorkflowTaskRunner::class);
        $method = new ReflectionMethod($runner, 'devDebugRuntimeConfig');
        $method->setAccessible(true);

        $copilotConfig = $method->invoke($runner, $run, $step, $stepRun, true);
        $normalRun = (new WorkflowRun)->forceFill([
            'workflow_id' => $workflow->id,
            'context_json' => ['execution_target' => 'system'],
        ]);
        $normalRun->setRelation('workflow', $workflow->fresh());
        $normalConfig = $method->invoke($runner, $normalRun, $step, $stepRun, true);

        $this->assertTrue($copilotConfig['enabled']);
        $this->assertTrue($copilotConfig['copilotObservation']);
        $this->assertTrue($copilotConfig['captureDomBeforeStep']);
        $this->assertTrue($copilotConfig['captureDomAfterStep']);
        $this->assertTrue($copilotConfig['captureScreenshotBeforeStep']);
        $this->assertTrue($copilotConfig['captureScreenshotAfterStep']);
        $this->assertTrue($copilotConfig['keepArtifacts']);
        $this->assertFalse($normalConfig['enabled']);
        $this->assertFalse($normalConfig['copilotObservation']);
    }

    public function test_waiting_copilot_checkpoint_is_not_expired_by_the_general_step_timeout(): void
    {
        Queue::fake();
        [$workflow, $step] = $this->workflow();
        $session = app(WorkflowCopilotSessionService::class)->start($workflow);
        $execution = app(WorkflowExecutionService::class);
        $run = $execution->start($workflow, [
            'workflow_copilot_session_id' => $session->id,
            'copilot_supervised' => true,
        ], 'workflow-copilot');
        $stepRun = $this->putRunAtCheckpoint($run, $step, 'first-task');
        $stepRun->forceFill(['started_at' => now()->subHours(2)])->save();

        $execution->expireTimedOutRuns();

        $this->assertSame('waiting', $run->fresh()->status);
        $this->assertSame('waiting', $stepRun->fresh()->status);
        $this->assertSame('checkpoint-first-task', data_get($run->fresh()->context_json, 'copilot_checkpoint.id'));
        $this->assertNull($stepRun->fresh()->finished_at);
    }

    public function test_single_task_dynamic_loop_route_is_returned_to_the_php_cursor(): void
    {
        [, $step] = $this->workflow();
        $method = new ReflectionMethod(WorkflowExecutionService::class, 'routeForResult');
        $method->setAccessible(true);

        $route = $method->invoke(
            app(WorkflowExecutionService::class),
            $step,
            'success',
            [
                'routeRequested' => true,
                'completedTaskKey' => 'loop-end',
                'routeOutcome' => 'loop',
                'routeTargetKey' => 'loop-start',
            ],
        );

        $this->assertSame('card', $route['type']);
        $this->assertSame('loop-start', $route['card_key']);
        $this->assertSame($step->action_key, $route['action_key']);
    }

    public function test_task_result_persists_arrays_loop_cursor_and_null_scope_values(): void
    {
        [$workflow] = $this->workflow();
        $run = WorkflowRun::query()->create([
            'run_uuid' => (string) str()->uuid(),
            'workflow_id' => $workflow->id,
            'workflow_revision' => 0,
            'status' => 'running',
            'context_json' => [
                'workflow_variables' => ['current_element' => ['index' => 0]],
                'loop_state' => [],
            ],
            'result_json' => [],
        ]);
        $method = new ReflectionMethod(WorkflowExecutionService::class, 'applyWorkflowVariablesResult');
        $method->setAccessible(true);
        $method->invoke(app(WorkflowExecutionService::class), $run, [
            'workflow_variables' => [
                'results' => [['title' => 'One'], ['title' => 'Two']],
                'current_element' => null,
                '__workflow_loop_state_collect' => [
                    'cursor' => 2,
                    'processed' => 2,
                    'active' => false,
                ],
            ],
        ]);

        $context = $run->fresh()->context_json;
        $this->assertCount(2, data_get($context, 'workflow_variables.results'));
        $this->assertNull(data_get($context, 'workflow_variables.current_element'));
        $this->assertSame(2, data_get($context, 'loop_state.collect.cursor'));
        $this->assertSame(2, data_get($context, 'loop_state.collect.processed'));
    }

    private function putRunAtCheckpoint(
        WorkflowRun $run,
        WorkflowStep $step,
        string $taskKey,
        bool $withCheckpoint = true,
    ): WorkflowStepRun {
        $context = is_array($run->context_json) ? $run->context_json : [];
        $context['copilot_supervised'] = true;
        $context['execution_target'] = 'system';
        $context['copilot_current_task_key'] = $taskKey;

        if ($withCheckpoint) {
            $context['copilot_checkpoint'] = [
                'id' => 'checkpoint-'.$taskKey,
                'workflow_step_id' => $step->id,
                'task_key' => $taskKey,
                'successful' => true,
                'outcome' => 'success',
                'next_action' => 'next_task',
                'next_task_key' => 'second-task',
                'result' => ['ok' => true],
            ];
        } else {
            unset($context['copilot_checkpoint']);
        }

        $run->forceFill([
            'status' => 'waiting',
            'current_workflow_step_id' => $step->id,
            'context_json' => $context,
        ])->save();

        return WorkflowStepRun::query()->create([
            'workflow_run_id' => $run->id,
            'workflow_step_id' => $step->id,
            'status' => 'waiting',
            'external_run_type' => 'workflow-task',
            'external_run_id' => 'external-'.$taskKey,
            'result_json' => [],
        ]);
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
