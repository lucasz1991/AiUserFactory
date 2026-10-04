<?php

namespace Tests\Feature;

use App\Jobs\MonitorWorkflowStepRunJob;
use App\Jobs\RunWorkflowJob;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use App\Models\WorkflowStepRun;
use App\Services\Mail\MailAccountRegistrationRunner;
use App\Services\Workflows\WorkflowExecutionService;
use App\Services\Workflows\WorkflowRunCoordinationService;
use App\Services\Workflows\WorkflowRunDebugPackageService;
use App\Services\Workflows\WorkflowTaskRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

class WorkflowRuntimeCallbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_signed_completion_callback_triggers_the_existing_monitor_immediately(): void
    {
        Bus::fake([MonitorWorkflowStepRunJob::class]);
        $stepRun = $this->stepRun();

        $this->postJson($this->signedCallbackUrl($stepRun))
            ->assertStatus(202)
            ->assertJson([
                'ok' => true,
                'accepted' => true,
                'duplicate' => false,
                'workflow_step_run_id' => $stepRun->id,
            ]);

        Bus::assertDispatchedAfterResponse(MonitorWorkflowStepRunJob::class, function (MonitorWorkflowStepRunJob $job) use ($stepRun): bool {
            return $job->workflowStepRunId === $stepRun->id;
        });
    }

    public function test_unsigned_or_mismatched_completion_callback_is_rejected(): void
    {
        Bus::fake([MonitorWorkflowStepRunJob::class]);
        $stepRun = $this->stepRun();

        $this->postJson(route('workflow-runtime.completed', [
            'workflowStepRun' => $stepRun->id,
            'externalRunId' => $stepRun->external_run_id,
        ], false))->assertForbidden();

        $mismatchedUrl = URL::temporarySignedRoute(
            'workflow-runtime.completed',
            now()->addMinute(),
            [
                'workflowStepRun' => $stepRun->id,
                'externalRunId' => 'another-runtime',
            ],
            false,
        );
        $this->postJson($mismatchedUrl)
            ->assertStatus(409)
            ->assertJsonPath('code', 'runtime_callback_mismatch');

        Bus::assertNotDispatched(MonitorWorkflowStepRunJob::class);
    }

    public function test_repeated_callback_for_a_terminal_step_is_idempotent(): void
    {
        Bus::fake([MonitorWorkflowStepRunJob::class]);
        $stepRun = $this->stepRun(['status' => 'completed']);

        $this->postJson($this->signedCallbackUrl($stepRun))
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'duplicate' => true,
                'status' => 'completed',
            ]);

        Bus::assertNotDispatched(MonitorWorkflowStepRunJob::class);
    }

    public function test_monitor_contention_is_serialized_and_retried_once(): void
    {
        Queue::fake();
        $stepRun = $this->stepRun();
        $lock = Cache::lock('workflow-step-run-monitor:'.$stepRun->id, 150);
        $this->assertTrue($lock->get());

        try {
            app(WorkflowExecutionService::class)->monitorStepRun($stepRun->id);
        } finally {
            $lock->release();
        }

        Queue::assertPushed(MonitorWorkflowStepRunJob::class, function (MonitorWorkflowStepRunJob $job) use ($stepRun): bool {
            return $job->workflowStepRunId === $stepRun->id;
        });
        $this->assertSame('waiting', $stepRun->fresh()->status);
    }

    public function test_run_claim_serializes_reentrant_advance_and_callback_during_a_process_start(): void
    {
        Queue::fake();
        $stepRun = $this->stepRun(['status' => 'queued', 'external_run_type' => null, 'external_run_id' => null]);
        $stepRun->workflowStep->forceFill([
            'type' => WorkflowStep::TYPE_BROWSER_TASK,
            'config_json' => ['tasks' => [['key' => 'first', 'task_key' => 'wait.seconds', 'value' => 0]]],
        ])->save();
        $transactionLevel = DB::transactionLevel();
        $runner = Mockery::mock(WorkflowTaskRunner::class);
        $runner->shouldReceive('start')->once()->andReturnUsing(function ($run, $step, $starting, $context, $externalId) use ($transactionLevel): array {
            $this->assertSame($transactionLevel, DB::transactionLevel(), 'Runner startup must not hold orchestration database locks.');
            $this->assertSame($externalId, $starting->fresh()->external_run_id);
            app(WorkflowExecutionService::class)->advance($run->id);
            app(WorkflowExecutionService::class)->monitorStepRun($starting->id);

            return ['runId' => $externalId, 'state' => 'running'];
        });
        $this->app->instance(WorkflowTaskRunner::class, $runner);

        app(WorkflowExecutionService::class)->advance($stepRun->workflow_run_id);

        $this->assertDatabaseCount('workflow_step_runs', 1);
        $this->assertSame('waiting', $stepRun->fresh()->status);
        Queue::assertPushed(RunWorkflowJob::class, 1);
        Queue::assertPushed(MonitorWorkflowStepRunJob::class, 2);
        $this->assertNull(DB::table('workflow_run_leases')->where('workflow_run_id', $stepRun->workflow_run_id)->value('token'));
    }

    public function test_stop_during_spawn_remains_responsive_and_cannot_be_overwritten_by_late_start_result(): void
    {
        Queue::fake();
        $stepRun = $this->stepRun(['status' => 'queued', 'external_run_type' => null, 'external_run_id' => null]);
        $stepRun->workflowStep->forceFill([
            'type' => WorkflowStep::TYPE_BROWSER_TASK,
            'config_json' => ['tasks' => [['key' => 'first', 'task_key' => 'wait.seconds', 'value' => 0]]],
        ])->save();
        $runner = Mockery::mock(WorkflowTaskRunner::class);
        $runner->shouldReceive('cancelRun')->andReturn(['ok' => true]);
        $runner->shouldReceive('start')->once()->andReturnUsing(function ($run, $step, $starting, $context, $externalId): array {
            $stopped = app(WorkflowExecutionService::class)->cancel($run->id, 'Stop waehrend Start');
            $this->assertTrue($stopped['ok']);
            $this->assertSame('cancelled', $run->fresh()->status);

            return ['runId' => $externalId, 'state' => 'running'];
        });
        $this->app->instance(WorkflowTaskRunner::class, $runner);

        app(WorkflowExecutionService::class)->advance($stepRun->workflow_run_id);

        $this->assertSame('cancelled', $stepRun->fresh()->status);
        $this->assertSame('cancelled', $stepRun->workflowRun->fresh()->status);
        Queue::assertNotPushed(MonitorWorkflowStepRunJob::class);
    }

    public function test_cancelled_run_ignores_a_terminal_snapshot_read_by_an_older_monitor(): void
    {
        Queue::fake();
        $stepRun = $this->stepRun();
        $runner = Mockery::mock(WorkflowTaskRunner::class);
        $runner->shouldReceive('readRun')->once()->andReturnUsing(function () use ($stepRun): array {
            $stepRun->workflowRun->fresh()->forceFill(['status' => 'cancelled'])->save();
            $stepRun->fresh()->forceFill(['status' => 'cancelled'])->save();

            return ['state' => 'completed', 'workflow_variables' => ['late' => 'must-not-commit'], 'ok' => true];
        });
        $this->app->instance(WorkflowTaskRunner::class, $runner);

        app(WorkflowExecutionService::class)->monitorStepRun($stepRun->id);

        $this->assertSame('cancelled', $stepRun->fresh()->status);
        $this->assertSame('cancelled', $stepRun->workflowRun->fresh()->status);
        $this->assertNull(data_get($stepRun->workflowRun->fresh()->context_json, 'workflow_variables.late'));
        Queue::assertNothingPushed();
    }

    public function test_expired_claim_can_be_reconciled_without_an_old_owner_releasing_the_new_claim(): void
    {
        $stepRun = $this->stepRun();
        $coordination = app(WorkflowRunCoordinationService::class);
        $first = $coordination->acquire($stepRun->workflow_run_id, 'advance', 30);
        $this->assertNotNull($first);
        $this->assertNull($coordination->acquire($stepRun->workflow_run_id, 'monitor', 30));

        try {
            $this->travel(31)->seconds();
            $second = $coordination->acquire($stepRun->workflow_run_id, 'monitor', 30);
            $this->assertNotNull($second);
            $this->assertNotSame($first, $second);
            $coordination->release($stepRun->workflow_run_id, $first);
            $this->assertFalse($coordination->owns($stepRun->workflow_run_id, $first));
            $this->assertTrue($coordination->owns($stepRun->workflow_run_id, $second));
        } finally {
            $this->travelBack();
        }
    }

    public function test_reserved_runtime_id_and_terminal_status_survive_a_fast_spawn(): void
    {
        $stepRun = $this->stepRun();
        $reservedRunId = (string) str()->uuid();
        $mailSettings = Mockery::mock(MailAccountRegistrationRunner::class);
        $mailSettings->shouldReceive('settings')->once()->andReturn([]);
        $runner = new class($mailSettings) extends WorkflowTaskRunner
        {
            protected function resolveNodeBinary(): string
            {
                return 'node-fast-spawn-test-double';
            }

            protected function spawnDetachedProcess(
                array $command,
                string $workingDirectory,
                string $stdoutPath,
                string $stderrPath,
                array $environment = [],
            ): ?int {
                $runtime = json_decode(File::get($command[2]), true, 512, JSON_THROW_ON_ERROR);
                File::put($runtime['statusPath'], json_encode([
                    'runId' => $runtime['runId'],
                    'pid' => 4242,
                    'state' => 'completed',
                    'stage' => 'completed',
                    'message' => 'Sehr schneller Lauf abgeschlossen.',
                ], JSON_THROW_ON_ERROR));

                return 4242;
            }
        };

        $result = $runner->start(
            $stepRun->workflowRun,
            $stepRun->workflowStep,
            $stepRun,
            [],
            $reservedRunId,
        );

        $this->assertSame($reservedRunId, $result['runId']);
        $this->assertSame('completed', $result['state']);
        $this->assertSame(4242, $result['pid']);
    }

    public function test_fast_callback_cannot_be_overwritten_to_waiting_after_spawn(): void
    {
        Queue::fake();
        $stepRun = $this->stepRun();
        $runner = Mockery::mock(WorkflowTaskRunner::class);
        $runner->shouldReceive('start')
            ->once()
            ->andReturnUsing(function ($run, $step, WorkflowStepRun $startingStepRun, array $context, string $reservedRunId): array {
                $persisted = $startingStepRun->fresh();
                $this->assertSame('workflow-task', $persisted->external_run_type);
                $this->assertSame($reservedRunId, $persisted->external_run_id);

                $persisted->forceFill([
                    'status' => 'completed',
                    'finished_at' => now(),
                ])->save();

                return [
                    'runId' => $reservedRunId,
                    'state' => 'completed',
                    'message' => 'Callback war schneller als start().',
                ];
            });
        $this->app->instance(WorkflowTaskRunner::class, $runner);
        $service = app(WorkflowExecutionService::class);
        $method = new ReflectionMethod($service, 'startWorkflowTaskStep');
        $method->setAccessible(true);

        $method->invoke(
            $service,
            $stepRun->workflowRun,
            $stepRun->workflowStep,
            $stepRun,
        );

        $this->assertSame('completed', $stepRun->fresh()->status);
        Queue::assertNotPushed(MonitorWorkflowStepRunJob::class);
    }

    public function test_task_runner_generates_a_relative_hmac_signature_without_exposing_app_key(): void
    {
        config(['app.url' => 'https://factory.example.test']);
        $stepRun = $this->stepRun();
        $mailSettings = Mockery::mock(MailAccountRegistrationRunner::class);
        $runner = new WorkflowTaskRunner($mailSettings);
        $method = new ReflectionMethod($runner, 'completionCallbackConfig');
        $method->setAccessible(true);

        $callback = $method->invoke($runner, $stepRun, (string) $stepRun->external_run_id);

        $this->assertIsArray($callback);
        $this->assertSame(3000, $callback['timeoutMs']);
        $this->assertStringStartsWith('https://factory.example.test/api/workflow-runtime/', $callback['url']);
        $request = Request::create($callback['url'], 'POST');
        $this->assertTrue(URL::hasValidSignature($request, false));
        $this->assertStringNotContainsString((string) config('app.key'), $callback['url']);
    }

    public function test_debug_export_redacts_the_temporary_callback_signature(): void
    {
        $service = app(WorkflowRunDebugPackageService::class);
        $sanitize = new ReflectionMethod($service, 'sanitize');
        $sanitize->setAccessible(true);
        $secret = 'callback-signature-must-not-leak';

        $sanitized = $sanitize->invoke($service, [
            'completionCallback' => [
                'url' => 'https://factory.example.test/api/runtime?expires=123&signature='.$secret,
            ],
        ]);
        $serialized = json_encode($sanitized, JSON_UNESCAPED_SLASHES);

        $this->assertStringNotContainsString($secret, (string) $serialized);
        $this->assertStringContainsString('signature=[redacted]', (string) $serialized);
    }

    private function signedCallbackUrl(WorkflowStepRun $stepRun): string
    {
        return URL::temporarySignedRoute(
            'workflow-runtime.completed',
            now()->addMinute(),
            [
                'workflowStepRun' => $stepRun->id,
                'externalRunId' => $stepRun->external_run_id,
            ],
            false,
        );
    }

    /** @param array<string, mixed> $overrides */
    private function stepRun(array $overrides = []): WorkflowStepRun
    {
        $workflow = Workflow::query()->create([
            'name' => 'Runtime Callback '.str()->random(6),
            'slug' => 'runtime-callback-'.str()->random(10),
            'description' => '',
            'category' => 'test',
            'is_active' => true,
            'is_locked' => false,
            'trigger_type' => 'manual',
            'settings_json' => [],
        ]);
        $step = $workflow->steps()->create([
            'name' => 'Callback',
            'type' => WorkflowStep::TYPE_WAIT,
            'action_key' => 'callback',
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

        return WorkflowStepRun::query()->create(array_replace([
            'workflow_run_id' => $run->id,
            'workflow_step_id' => $step->id,
            'status' => 'waiting',
            'external_run_type' => 'workflow-task',
            'external_run_id' => (string) str()->uuid(),
            'result_json' => [],
        ], $overrides));
    }
}
