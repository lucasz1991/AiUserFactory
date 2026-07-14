<?php

namespace Tests\Feature;

use App\Jobs\MonitorWorkflowStepRunJob;
use App\Jobs\ReconcileWorkflowCopilotSessionsJob;
use App\Jobs\RunWorkflowJob;
use App\Jobs\WorkflowCopilotSupervisorJob;
use App\Models\Workflow;
use App\Models\WorkflowCopilotSession;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use App\Models\WorkflowStepRun;
use App\Services\Workflows\WorkflowCopilotQueueRecoveryService;
use App\Services\Workflows\WorkflowCopilotSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class WorkflowCopilotQueueRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=']);
        Carbon::setTestNow('2026-07-14 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_final_supervisor_failure_is_sanitized_persisted_and_pauses_without_unlocking(): void
    {
        [$workflow, $session] = $this->workflowAndSession();
        $job = new WorkflowCopilotSupervisorJob($session->id);

        $job->failed(new RuntimeException(
            'Provider failed: Bearer top-secret password=supersecret person@example.test +49 170 12345678 https://example.test/callback?token=querysecret',
            503,
        ));

        $session->refresh();
        $workflow->refresh();
        $event = $session->events()->where('event_type', 'queue.supervisor_failed')->firstOrFail();
        $encodedPayload = json_encode($event->payload_json, JSON_THROW_ON_ERROR);

        $this->assertSame(WorkflowCopilotSession::STATUS_PAUSED, $session->status);
        $this->assertSame('queue_failed', $session->phase);
        $this->assertSame($session->id, $workflow->active_workflow_copilot_session_id);
        $this->assertSame('system', $event->payload_json['execution_target']);
        $this->assertTrue($event->is_milestone);
        $this->assertStringNotContainsString('top-secret', $encodedPayload);
        $this->assertStringNotContainsString('supersecret', $encodedPayload);
        $this->assertStringNotContainsString('querysecret', $encodedPayload);
        $this->assertStringNotContainsString('person@example.test', $encodedPayload);
        $this->assertStringNotContainsString('170 12345678', $encodedPayload);
        $this->assertStringContainsString('[redacted]', $event->payload_json['error_summary']);
        $this->assertTrue((bool) data_get($session->state_json, 'queue_recovery.requires_manual_resume'));
    }

    public function test_reconciler_redispatches_an_orphaned_session_and_applies_a_persistent_cooldown(): void
    {
        Queue::fake();
        [, $session] = $this->workflowAndSession();
        $this->makeSessionStale($session);

        $first = app(WorkflowCopilotQueueRecoveryService::class)->reconcile();

        $this->assertSame(1, $first['dispatched']);
        Queue::assertPushed(WorkflowCopilotSupervisorJob::class, function ($job) use ($session): bool {
            return $job->workflowCopilotSessionId === $session->id
                && $job->connection === 'database';
        });
        $this->assertDatabaseHas('workflow_copilot_events', [
            'workflow_copilot_session_id' => $session->id,
            'event_type' => 'queue.recovery_dispatched',
        ]);

        $this->makeSessionStale($session->fresh());
        $second = app(WorkflowCopilotQueueRecoveryService::class)->reconcile();

        $this->assertSame(1, $second['cooldown']);
        Queue::assertPushed(WorkflowCopilotSupervisorJob::class, 1);
    }

    public function test_reconciler_restores_queued_running_and_waiting_system_run_jobs(): void
    {
        Queue::fake();

        [$queuedSession, $queuedRun] = $this->sessionWithRun('queued');
        [$runningSession, $runningRun] = $this->sessionWithRun('running');
        [$waitingSession, $waitingRun, $waitingStepRun] = $this->sessionWithRun('waiting', true);

        $result = app(WorkflowCopilotQueueRecoveryService::class)->reconcile();

        $this->assertSame(3, $result['dispatched']);
        Queue::assertPushed(RunWorkflowJob::class, function ($job) use ($queuedRun): bool {
            return $job->workflowRunId === $queuedRun->id && $job->connection === 'database';
        });
        Queue::assertPushed(RunWorkflowJob::class, function ($job) use ($runningRun): bool {
            return $job->workflowRunId === $runningRun->id && $job->connection === 'database';
        });
        Queue::assertPushed(MonitorWorkflowStepRunJob::class, function ($job) use ($waitingStepRun): bool {
            return $job->workflowStepRunId === $waitingStepRun->id && $job->connection === 'database';
        });
        Queue::assertNotPushed(WorkflowCopilotSupervisorJob::class);
        $this->assertSame($queuedRun->id, $queuedSession->fresh()->active_workflow_run_id);
        $this->assertSame($waitingRun->id, $waitingSession->fresh()->active_workflow_run_id);
        $this->assertSame($runningRun->id, $runningSession->fresh()->active_workflow_run_id);
    }

    public function test_reconciler_skips_live_leases_and_blocks_non_system_run_identity(): void
    {
        Queue::fake();
        [, $leasedSession] = $this->workflowAndSession();
        $this->makeSessionStale($leasedSession, [
            'supervisor_lease' => [
                'token' => 'live-lease',
                'expires_at' => now()->addMinutes(5)->toIso8601String(),
            ],
        ]);

        [, $unsafeRun] = $this->sessionWithRun('queued');
        $unsafeContext = $unsafeRun->context_json;
        $unsafeContext['execution_target'] = 'client';
        $unsafeContext['network_node_id'] = 123;
        $unsafeRun->forceFill(['context_json' => $unsafeContext])->save();
        $this->makeRunStale($unsafeRun);

        $result = app(WorkflowCopilotQueueRecoveryService::class)->reconcile();

        $this->assertSame(1, $result['leased']);
        $this->assertSame(0, $result['dispatched']);
        Queue::assertNothingPushed();
        $unsafeSession = $unsafeRun->copilotSession()->firstOrFail();
        $this->assertSame(WorkflowCopilotSession::STATUS_PAUSED, $unsafeSession->status);
        $this->assertSame('queue_recovery_blocked', $unsafeSession->phase);
        $this->assertDatabaseHas('workflow_copilot_events', [
            'workflow_copilot_session_id' => $unsafeSession->id,
            'event_type' => 'queue.recovery_blocked',
        ]);
    }

    public function test_scheduled_reconciler_job_is_unique_and_database_backed(): void
    {
        $job = new ReconcileWorkflowCopilotSessionsJob;

        $this->assertSame('database', $job->connection);
        $this->assertSame(120, $job->uniqueFor);
        $this->assertSame('workflow-copilot-queue-reconciliation', $job->uniqueId());
    }

    /**
     * @return array{Workflow, WorkflowCopilotSession}
     */
    private function workflowAndSession(): array
    {
        $workflow = Workflow::query()->create([
            'name' => 'Queue recovery '.uniqid('', true),
            'slug' => 'queue-recovery-'.str()->uuid(),
            'category' => 'automation',
            'is_active' => true,
            'trigger_type' => 'manual',
        ]);
        $session = app(WorkflowCopilotSessionService::class)->start($workflow, [
            'goal' => 'Workflow im System vollstaendig ausfuehren.',
        ]);

        return [$workflow, $session];
    }

    /**
     * @return array{WorkflowCopilotSession, WorkflowRun, 2?: WorkflowStepRun}
     */
    private function sessionWithRun(string $status, bool $withActiveStep = false): array
    {
        [$workflow, $session] = $this->workflowAndSession();
        $step = WorkflowStep::query()->create([
            'workflow_id' => $workflow->id,
            'name' => 'System Task',
            'type' => WorkflowStep::TYPE_BROWSER_TASK,
            'action_key' => 'system-task',
            'position' => 1,
            'is_enabled' => true,
            'config_json' => ['tasks' => []],
        ]);
        $run = WorkflowRun::query()->create([
            'run_uuid' => (string) str()->uuid(),
            'workflow_id' => $workflow->id,
            'workflow_copilot_session_id' => $session->id,
            'workflow_revision' => 0,
            'current_workflow_step_id' => $step->id,
            'status' => $status,
            'requested_by' => 'workflow-copilot',
            'queued_at' => now()->subMinutes(10),
            'started_at' => $status === 'queued' ? null : now()->subMinutes(9),
            'context_json' => [
                'workflow_copilot_session_id' => $session->id,
                'execution_target' => 'system',
                'network_node_id' => null,
                'device_id' => null,
                'copilot_supervised' => true,
            ],
            'result_json' => [],
        ]);
        $session->forceFill(['active_workflow_run_id' => $run->id])->save();
        $stepRun = null;

        if ($withActiveStep) {
            $stepRun = WorkflowStepRun::query()->create([
                'workflow_run_id' => $run->id,
                'workflow_step_id' => $step->id,
                'status' => 'waiting',
                'external_run_type' => 'workflow-task',
                'external_run_id' => 'system-run-'.$run->id,
                'started_at' => now()->subMinutes(9),
            ]);
            $this->makeStepRunStale($stepRun);
        }

        $this->makeRunStale($run);
        $this->makeSessionStale($session);

        return $stepRun ? [$session, $run, $stepRun] : [$session, $run];
    }

    private function makeSessionStale(WorkflowCopilotSession $session, ?array $state = null): void
    {
        $session->timestamps = false;
        $session->forceFill([
            'state_json' => $state ?? $session->state_json,
            'started_at' => now()->subMinutes(10),
            'last_activity_at' => now()->subMinutes(5),
            'updated_at' => now()->subMinutes(5),
        ])->save();
        $session->timestamps = true;
    }

    private function makeRunStale(WorkflowRun $run): void
    {
        $run->timestamps = false;
        $run->forceFill(['updated_at' => now()->subMinutes(5)])->save();
        $run->timestamps = true;
    }

    private function makeStepRunStale(WorkflowStepRun $stepRun): void
    {
        $stepRun->timestamps = false;
        $stepRun->forceFill(['updated_at' => now()->subMinutes(5)])->save();
        $stepRun->timestamps = true;
    }
}
