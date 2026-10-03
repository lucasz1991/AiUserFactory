<?php

namespace Tests\Feature;

use App\Exceptions\WorkflowSupervisorInterruptedException;
use App\Jobs\RunWorkflowJob;
use App\Jobs\WorkflowCopilotSupervisorJob;
use App\Models\Workflow;
use App\Models\WorkflowCopilotSession;
use App\Models\WorkflowRun;
use App\Services\Ai\AiConnectionService;
use App\Services\Ai\WorkflowCopilotAiUsageTracker;
use App\Services\Workflows\WorkflowCopilotDecisionGuard;
use App\Services\Workflows\WorkflowCopilotSessionService;
use App\Services\Workflows\WorkflowCopilotSupervisorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WorkflowCopilotDecisionConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        config(['app.key' => 'base64:MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=']);
    }

    #[DataProvider('userControls')]
    public function test_initial_provider_result_cannot_overwrite_newer_user_control(string $control, string $expectedStatus, bool $recheck): void
    {
        [$workflow, $session] = $this->emptySession();
        $sessions = app(WorkflowCopilotSessionService::class);
        $ai = Mockery::mock(AiConnectionService::class);
        $ai->shouldReceive('json')->once()->andReturnUsing(function () use ($sessions, $session, $control): array {
            $this->recordProviderUsage();

            match ($control) {
                'pause' => $sessions->pause($session->fresh(), 'Pause waehrend der Provider-Anfrage.'),
                'stop' => $sessions->stop($session->fresh(), 'Stopp waehrend der Provider-Anfrage.'),
                'instruction' => $sessions->instruction($session->fresh(), 'Neue Anweisung: noch keine Tasks starten.'),
                'pause_resume' => $sessions->resume($sessions->pause($session->fresh(), 'Kurze Benutzerpause.')),
                'budget' => $session->fresh()->forceFill(['budget_json' => array_replace($session->fresh()->budget_json, ['max_cost_usd' => 0.001])])->save(),
                'auto_permission' => $session->fresh()->forceFill(['budget_json' => array_replace($session->fresh()->budget_json, ['auto_execute_workflow_actions' => false])])->save(),
            };

            return $this->initialPlan();
        });
        $this->app->instance(AiConnectionService::class, $ai);

        app(WorkflowCopilotSupervisorService::class)->supervise($session->id);

        $session->refresh();
        $this->assertSame($expectedStatus, $session->status);
        $this->assertSame(0, $workflow->fresh()->copilot_revision);
        $this->assertSame(0, $workflow->steps()->count());
        $this->assertNull($session->active_workflow_run_id);
        $this->assertDatabaseCount('workflow_revisions', 0);
        $this->assertDatabaseCount('workflow_runs', 0);
        $this->assertSame(1, $session->events()->where('event_type', 'supervisor.result_discarded')->count());
        $this->assertSame(0, $session->events()->where('event_type', 'planning.completed')->count());
        $this->assertSame(0, $session->events()->where('event_type', 'session.succeeded')->count());
        $this->assertNull(data_get($session->state_json, 'supervisor_lease.token'));
        $this->assertSame(1, data_get($session->usage_json, 'ai_requests'));
        $this->assertSame(120, data_get($session->usage_json, 'input_tokens'));
        $this->assertSame(30, data_get($session->usage_json, 'output_tokens'));
        $this->assertSame(150, data_get($session->usage_json, 'total_tokens'));
        $this->assertSame(0.005, data_get($session->usage_json, 'cost_usd'));
        $this->assertSame(1, $session->events()->where('event_type', 'ai.usage_recorded')->count());
        Queue::assertNotPushed(RunWorkflowJob::class);

        if ($recheck) {
            Queue::assertPushed(WorkflowCopilotSupervisorJob::class, 1);
            Queue::assertPushed(WorkflowCopilotSupervisorJob::class, fn (WorkflowCopilotSupervisorJob $job): bool => $job->workflowCopilotSessionId === $session->id && $job->delay !== null);
        } else {
            Queue::assertNotPushed(WorkflowCopilotSupervisorJob::class);
        }

        if ($control === 'instruction') {
            $this->assertSame('Neue Anweisung: noch keine Tasks starten.', data_get($session->events()->where('event_type', 'instruction.received')->firstOrFail()->payload_json, 'instruction'));
        }

        if ($control === 'pause_resume') {
            $this->assertTrue($session->events()->where('event_type', 'session.status_changed')->get()->contains(fn ($event): bool => data_get($event->payload_json, 'to') === WorkflowCopilotSession::STATUS_PAUSED));
        }

        if (in_array($control, ['budget', 'auto_permission'], true)) {
            app(WorkflowCopilotSupervisorService::class)->supervise($session->id);
            $session->refresh();
            $this->assertSame($control === 'budget' ? WorkflowCopilotSession::STATUS_BUDGET_EXHAUSTED : WorkflowCopilotSession::STATUS_PAUSED, $session->status);
            $this->assertSame(0, $workflow->steps()->count());
            $this->assertDatabaseCount('workflow_runs', 0);
            $this->assertSame(0.005, data_get($session->usage_json, 'cost_usd'));
            Queue::assertNotPushed(RunWorkflowJob::class);
        }
    }

    public static function userControls(): array
    {
        return [
            'pause' => ['pause', WorkflowCopilotSession::STATUS_PAUSED, false],
            'stop' => ['stop', WorkflowCopilotSession::STATUS_STOPPED, false],
            'new instruction' => ['instruction', WorkflowCopilotSession::STATUS_RUNNING, true],
            'pause and resume ABA' => ['pause_resume', WorkflowCopilotSession::STATUS_RUNNING, true],
            'budget lowered during request' => ['budget', WorkflowCopilotSession::STATUS_RUNNING, true],
            'autonomous execution revoked' => ['auto_permission', WorkflowCopilotSession::STATUS_RUNNING, true],
        ];
    }

    #[DataProvider('decisionChanges')]
    public function test_guard_rejects_provider_result_when_its_durable_decision_input_changes(string $change): void
    {
        [$workflow, $session] = $this->emptySession();
        $run = $this->waitingRun($workflow, $session, 'checkpoint-original');
        $session = app(WorkflowCopilotSessionService::class)->attachRun($session, $run);
        $session = app(WorkflowCopilotSessionService::class)->updateState($session, [
            'supervisor_lease' => ['token' => 'original-owner', 'expires_at' => now()->addMinute()->toIso8601String()],
        ]);
        $guard = app(WorkflowCopilotDecisionGuard::class);
        $expected = $guard->snapshot($session);

        match ($change) {
            'workflow_owner' => $workflow->fresh()->forceFill(['active_workflow_copilot_session_id' => null])->save(),
            'active_run' => $session->fresh()->forceFill(['active_workflow_run_id' => $this->waitingRun($workflow, $session, 'checkpoint-other')->id])->save(),
            'run_status' => $run->refresh()->forceFill(['status' => 'completed'])->save(),
            'checkpoint' => $run->refresh()->forceFill(['context_json' => array_replace($run->context_json, ['copilot_checkpoint' => ['id' => 'checkpoint-new']])])->save(),
            'revision' => $session->fresh()->forceFill(['current_revision' => 1])->save(),
            'goal' => $session->fresh()->forceFill(['goal' => 'Neues, noch nicht geplantes Ziel.'])->save(),
            'workflow_inputs' => $session->fresh()->forceFill(['workflow_inputs_json' => ['browser_window' => 'other']])->save(),
            'success_criteria' => $session->fresh()->forceFill(['success_criteria_json' => ['assertions' => [['type' => 'url', 'value' => 'new']]]])->save(),
            'budget' => $session->fresh()->forceFill(['budget_json' => array_replace($session->fresh()->budget_json, ['max_cost_usd' => 0.001])])->save(),
            'auto_permission' => $session->fresh()->forceFill(['budget_json' => array_replace($session->fresh()->budget_json, ['auto_execute_workflow_actions' => false])])->save(),
            'execution_target' => WorkflowCopilotSession::query()->whereKey($session->id)->update(['execution_target' => 'device']),
            'lease_owner' => app(WorkflowCopilotSessionService::class)->updateState($session, ['supervisor_lease' => ['token' => 'new-owner', 'expires_at' => now()->addMinute()->toIso8601String()]]),
            'invalid_lease' => app(WorkflowCopilotSessionService::class)->updateState($session, ['supervisor_lease' => ['token' => 'original-owner', 'expires_at' => 'invalid-expiry']]),
            'missing_lease_expiry' => $session->fresh()->forceFill(['state_json' => array_replace($session->fresh()->state_json, ['supervisor_lease' => ['token' => 'original-owner']])])->save(),
            'expired_lease' => $this->travel(61)->seconds(),
        };

        try {
            $this->expectException(WorkflowSupervisorInterruptedException::class);
            $guard->assertCurrent($session, $expected);
        } finally {
            $this->travelBack();
        }
    }

    public static function decisionChanges(): array
    {
        return array_map(fn (string $change): array => [$change], [
            'workflow_owner', 'active_run', 'run_status', 'checkpoint', 'revision', 'goal', 'workflow_inputs', 'success_criteria',
            'budget', 'auto_permission', 'execution_target', 'lease_owner', 'invalid_lease', 'missing_lease_expiry', 'expired_lease',
        ]);
    }

    public function test_internal_phase_and_json_object_order_changes_do_not_discard_a_current_decision(): void
    {
        [, $session] = $this->emptySession();
        $session->forceFill(['workflow_inputs_json' => ['browser_window' => 'main', 'nested' => ['a' => 1, 'b' => 2]]])->save();
        $guard = app(WorkflowCopilotDecisionGuard::class);
        $expected = $guard->snapshot($session);
        app(WorkflowCopilotSessionService::class)->transition($session, WorkflowCopilotSession::STATUS_REPAIRING, 'planning');
        $session->fresh()->forceFill(['workflow_inputs_json' => ['nested' => ['b' => 2, 'a' => 1], 'browser_window' => 'main']])->save();

        $guard->assertCurrent($session, $expected);

        $this->assertSame(WorkflowCopilotSession::STATUS_REPAIRING, $session->fresh()->status);
    }

    public function test_old_provider_response_cannot_release_a_replacement_supervisor_lease(): void
    {
        [$workflow, $session] = $this->emptySession();
        $ai = Mockery::mock(AiConnectionService::class);
        $ai->shouldReceive('json')->once()->andReturnUsing(function () use ($session): array {
            $this->recordProviderUsage();
            app(WorkflowCopilotSessionService::class)->updateState($session->fresh(), [
                'supervisor_lease' => ['token' => 'replacement-owner', 'expires_at' => now()->addMinute()->toIso8601String()],
            ]);

            return $this->initialPlan();
        });
        $this->app->instance(AiConnectionService::class, $ai);

        app(WorkflowCopilotSupervisorService::class)->supervise($session->id);

        $session->refresh();
        $this->assertSame('replacement-owner', data_get($session->state_json, 'supervisor_lease.token'));
        $this->assertTrue(data_get($session->state_json, 'supervisor_recheck_requested'));
        $this->assertSame(1, $session->events()->where('event_type', 'supervisor.result_discarded')->count());
        $this->assertSame(0, $workflow->steps()->count());
        $this->assertDatabaseCount('workflow_runs', 0);
        $this->assertSame(0.005, data_get($session->usage_json, 'cost_usd'));
        Queue::assertNotPushed(RunWorkflowJob::class);
    }

    private function emptySession(): array
    {
        $workflow = Workflow::query()->create([
            'name' => 'Concurrency test', 'slug' => 'concurrency-'.Str::uuid(),
            'is_active' => false, 'is_locked' => false, 'trigger_type' => 'manual', 'settings_json' => [],
        ]);
        $session = app(WorkflowCopilotSessionService::class)->start($workflow, [
            'goal' => 'Einen sicheren Testworkflow vorbereiten.',
            'workflow_inputs' => ['browser_window' => 'main'],
        ]);

        return [$workflow, $session];
    }

    private function waitingRun(Workflow $workflow, WorkflowCopilotSession $session, string $checkpointId): WorkflowRun
    {
        return WorkflowRun::query()->create([
            'run_uuid' => (string) Str::uuid(), 'workflow_id' => $workflow->id,
            'workflow_copilot_session_id' => $session->id, 'status' => 'waiting',
            'context_json' => [
                'workflow_copilot_session_id' => $session->id, 'copilot_supervised' => true,
                'execution_target' => WorkflowCopilotSession::EXECUTION_TARGET_SYSTEM,
                'copilot_checkpoint' => ['id' => $checkpointId],
            ],
        ]);
    }

    private function recordProviderUsage(): void
    {
        app(WorkflowCopilotAiUsageTracker::class)->recordResponse(
            ['model' => 'test/planner'],
            ['id' => 'synthetic-concurrent-response', 'model' => 'test/planner', 'usage' => [
                'prompt_tokens' => 120, 'completion_tokens' => 30, 'total_tokens' => 150, 'cost' => 0.005,
            ]],
            'data_analysis',
        );
    }

    private function initialPlan(): array
    {
        return [
            'summary' => 'Ein ausfuehrbarer Warteschritt.', 'assumptions' => [],
            'steps' => [[
                'name' => 'Vorbereitung', 'action_key' => 'preparation', 'type' => 'preparation',
                'description' => '', 'routes' => ['success' => ['type' => 'end']],
                'tasks' => [['key' => 'wait', 'task_key' => 'wait.seconds', 'title' => 'Warten', 'parameters' => ['value' => 1]]],
            ]],
        ];
    }
}
