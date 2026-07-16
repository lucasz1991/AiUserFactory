<?php

namespace Tests\Feature;

use App\Jobs\RunWorkflowJob;
use App\Jobs\WorkflowCopilotSupervisorJob;
use App\Models\Workflow;
use App\Models\WorkflowCopilotSession;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use App\Models\WorkflowStepRun;
use App\Services\Ai\WorkflowCopilotAiUsageTracker;
use App\Services\Ai\WorkflowCopilotVisionService;
use App\Services\Workflows\WorkflowCopilotObservationService;
use App\Services\Workflows\WorkflowCopilotRepairService;
use App\Services\Workflows\WorkflowCopilotSessionService;
use App\Services\Workflows\WorkflowCopilotSupervisorService;
use App\Services\Workflows\WorkflowExecutionService;
use App\Services\Workflows\WorkflowRevisionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class WorkflowCopilotSupervisorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=']);
    }

    public function test_failed_task_is_observed_probed_versioned_and_continued_without_duplicate_retry(): void
    {
        [$workflow, $step] = $this->workflowWithBrokenSelector();
        $sessions = app(WorkflowCopilotSessionService::class);
        $session = $sessions->start($workflow, [
            'goal' => 'Login erfolgreich abschliessen.',
            'budget' => [
                'max_repair_iterations' => 1,
                'max_probe_actions' => 1,
            ],
        ]);
        [$run, $stepRun] = $this->waitingRun($session, $step, [
            'id' => 'runtime-failure-1',
            'kind' => 'regular',
            'workflow_step_id' => $step->id,
            'workflow_step_name' => $step->name,
            'task_key' => 'login-click',
            'task_title' => 'Login klicken',
            'successful' => false,
            'outcome' => 'failed',
            'next_action' => 'repair',
            'result' => ['ok' => false, 'statusMessage' => 'Element nicht gefunden.'],
            'started_at' => now()->subSecond()->toIso8601String(),
            'finished_at' => now()->toIso8601String(),
        ]);
        $runContext = $run->context_json;
        $runContext['workflow_variables'] = ['email' => 'checkpoint@example.test', 'attempt' => 1];
        $run->forceFill(['context_json' => $runContext])->save();
        $session = $sessions->attachRun($session, $run);

        $observation = $this->observation();
        $vision = $this->visionResult('continue');
        $observations = Mockery::mock(WorkflowCopilotObservationService::class);
        $observations->shouldReceive('observe')->once()->andReturn($observation);
        $visionService = Mockery::mock(WorkflowCopilotVisionService::class);
        $visionService->shouldReceive('analyze')->once()->andReturn($vision);
        $execution = Mockery::mock(WorkflowExecutionService::class);
        $execution->shouldReceive('retryCopilotTask')
            ->once()
            ->withArgs(function (mixed $runArgument, string $taskKey, ?array $transient, array $plan): bool {
                return $runArgument instanceof WorkflowRun
                    && $taskKey === 'login-click'
                    && data_get($transient, 'task_key') === 'browser.click'
                    && data_get($transient, 'selector') === 'button[type="submit"]'
                    && ($plan['action'] ?? null) === 'probe_update';
            });
        $this->app->instance(WorkflowCopilotObservationService::class, $observations);
        $this->app->instance(WorkflowCopilotVisionService::class, $visionService);
        $this->app->instance(WorkflowExecutionService::class, $execution);

        app(WorkflowCopilotSupervisorService::class)->supervise($session->id);

        $session->refresh();
        $this->assertSame(WorkflowCopilotSession::STATUS_REPAIRING, $session->status);
        $this->assertSame(1, data_get($session->usage_json, 'repair_iterations'));
        $this->assertSame(1, data_get($session->usage_json, 'probe_actions'));
        $this->assertSame('probe_update', data_get($session->state_json, 'active_repair_plan.action'));
        $this->assertSame('runtime-failure-1', data_get($session->state_json, 'observed_checkpoint_id'));
        $this->assertSame('runtime-failure-1', data_get($session->state_json, 'continuation_applied_checkpoint_id'));
        $this->assertDatabaseCount('workflow_task_attempts', 1);
        $this->assertDatabaseCount('workflow_run_checkpoints', 1);
        $this->assertDatabaseCount('workflow_revisions', 0);
        $this->assertDatabaseHas('workflow_copilot_events', [
            'workflow_copilot_session_id' => $session->id,
            'event_type' => 'observation.started',
        ]);
        $this->assertDatabaseHas('workflow_copilot_events', [
            'workflow_copilot_session_id' => $session->id,
            'event_type' => 'vision.analysis_started',
        ]);
        $evidenceEvent = $session->events()->where('event_type', 'repair.evidence_evaluated')->firstOrFail();
        $decisionEvent = $session->events()->where('event_type', 'repair.decision_planned')->firstOrFail();
        $this->assertSame('login_form', data_get($evidenceEvent->payload_json, 'vision.ui_state'));
        $this->assertSame('el_submit', data_get($evidenceEvent->payload_json, 'dom.relevant_elements.0.element_ref'));
        $this->assertSame('probe_update', data_get($decisionEvent->payload_json, 'action'));
        $this->assertStringContainsString('sichtbares DOM-Element', (string) data_get($decisionEvent->payload_json, 'reason'));
        $storedContext = $session->checkpoints()->firstOrFail()->context_json;
        $this->assertNotEmpty(data_get($storedContext, 'encrypted_runtime_context'));
        $this->assertStringNotContainsString('checkpoint@example.test', json_encode($storedContext));
        $run->refresh();
        $dispatchedContext = $run->context_json;
        unset($dispatchedContext['copilot_checkpoint']);
        $run->forceFill([
            'status' => 'running',
            'context_json' => $dispatchedContext,
        ])->save();

        $observations = Mockery::mock(WorkflowCopilotObservationService::class);
        $observations->shouldNotReceive('observe');
        $visionService = Mockery::mock(WorkflowCopilotVisionService::class);
        $visionService->shouldNotReceive('analyze');
        $execution = Mockery::mock(WorkflowExecutionService::class);
        $execution->shouldNotReceive('retryCopilotTask');
        $execution->shouldNotReceive('resumeCopilotCheckpoint');
        $this->app->instance(WorkflowCopilotObservationService::class, $observations);
        $this->app->instance(WorkflowCopilotVisionService::class, $visionService);
        $this->app->instance(WorkflowExecutionService::class, $execution);

        app(WorkflowCopilotSupervisorService::class)->supervise($session->id);

        $this->assertDatabaseCount('workflow_task_attempts', 1);
        $this->assertDatabaseCount('workflow_run_checkpoints', 1);

        app(WorkflowRevisionService::class)->apply(
            $session->fresh(),
            0,
            'Bereits vorhandene Revision vor dem erfolgreichen Probe-Ergebnis.',
            function () use ($step): void {
                $freshStep = WorkflowStep::query()->findOrFail($step->id);
                $config = $freshStep->config_json;
                $config['description'] = 'Vorhandene Basisrevision';
                $freshStep->forceFill(['config_json' => $config])->save();
            },
        );
        $session->refresh();
        $plan = data_get($session->state_json, 'active_repair_plan');
        $run->refresh();
        $context = $run->context_json;
        $context['copilot_repair_plan'] = $plan;
        $context['copilot_checkpoint'] = [
            'id' => 'runtime-probe-1',
            'kind' => 'probe',
            'workflow_step_id' => $step->id,
            'workflow_step_name' => $step->name,
            'task_key' => 'login-click--copilot-probe',
            'task_title' => 'Login klicken (Copilot-Probe)',
            'successful' => true,
            'outcome' => 'success',
            'next_action' => 'repair',
            'result' => ['ok' => true, 'statusMessage' => 'Button wurde sichtbar geklickt.'],
            'started_at' => now()->subSecond()->toIso8601String(),
            'finished_at' => now()->toIso8601String(),
        ];
        $run->forceFill([
            'status' => 'waiting',
            'context_json' => $context,
        ])->save();

        $observations = Mockery::mock(WorkflowCopilotObservationService::class);
        $observations->shouldReceive('observe')->once()->andReturn($observation);
        $visionService = Mockery::mock(WorkflowCopilotVisionService::class);
        $visionService->shouldReceive('analyze')->once()->andReturn($vision);
        $execution = Mockery::mock(WorkflowExecutionService::class);
        $execution->shouldNotReceive('retryCopilotTask');
        $execution->shouldReceive('resumeCopilotCheckpoint')
            ->once()
            ->withArgs(fn (mixed $runArgument, ?string $originalTaskKey): bool => $runArgument instanceof WorkflowRun
                && (int) $runArgument->getKey() === (int) $run->getKey()
                && $originalTaskKey === 'login-click')
            ->andReturn(true);
        $this->app->instance(WorkflowCopilotObservationService::class, $observations);
        $this->app->instance(WorkflowCopilotVisionService::class, $visionService);
        $this->app->instance(WorkflowExecutionService::class, $execution);

        app(WorkflowCopilotSupervisorService::class)->supervise($session->id);

        $this->assertSame('button[type="submit"]', data_get($step->fresh()->config_json, 'tasks.0.selector'));
        $this->assertSame(2, $workflow->fresh()->copilot_revision);
        $this->assertSame(2, $session->fresh()->current_revision);
        $this->assertSame(2, $run->fresh()->workflow_revision);
        $this->assertSame(2, data_get($run->fresh()->context_json, 'workflow_revision'));
        $this->assertSame('runtime-probe-1', data_get($session->fresh()->state_json, 'continuation_applied_checkpoint_id'));
        $this->assertDatabaseCount('workflow_task_attempts', 2);
        $this->assertDatabaseCount('workflow_run_checkpoints', 2);
        $this->assertDatabaseCount('workflow_revisions', 2);
        $this->assertDatabaseHas('workflow_copilot_events', [
            'workflow_copilot_session_id' => $session->id,
            'event_type' => 'revision.saved',
        ]);
    }

    public function test_processed_waiting_probe_checkpoint_is_recovered_instead_of_ignored(): void
    {
        [$workflow, $step] = $this->workflowWithBrokenSelector();
        $sessions = app(WorkflowCopilotSessionService::class);
        $session = $sessions->start($workflow, ['goal' => 'Workflow fortsetzen.']);
        [$run] = $this->waitingRun($session, $step, [
            'id' => 'processed-waiting-probe',
            'kind' => 'probe',
            'workflow_step_id' => $step->id,
            'workflow_step_name' => $step->name,
            'task_key' => 'login-click--copilot-probe',
            'task_title' => 'Login klicken (Copilot-Probe)',
            'successful' => true,
            'outcome' => 'success',
            'next_action' => 'repair',
            'result' => ['ok' => true, 'statusMessage' => 'Probe erfolgreich.'],
        ]);
        $context = $run->context_json;
        $context['copilot_repair_plan'] = [
            'action' => 'probe_update',
            'task_key' => 'login-click',
            'original_task_key' => 'login-click',
        ];
        $run->forceFill(['context_json' => $context])->save();
        $session = $sessions->attachRun($session, $run);
        $state = $session->state_json;
        $state['continuation_applied_checkpoint_id'] = 'processed-waiting-probe';
        $state['continuation_applied_action'] = 'revision_continue_after_probe';
        $session->forceFill(['state_json' => $state])->save();

        $observations = Mockery::mock(WorkflowCopilotObservationService::class);
        $observations->shouldNotReceive('observe');
        $visionService = Mockery::mock(WorkflowCopilotVisionService::class);
        $visionService->shouldNotReceive('analyze');
        $execution = Mockery::mock(WorkflowExecutionService::class);
        $execution->shouldReceive('resumeCopilotCheckpoint')
            ->once()
            ->withArgs(fn (mixed $runArgument, ?string $originalTaskKey): bool => $runArgument instanceof WorkflowRun
                && (int) $runArgument->id === (int) $run->id
                && $originalTaskKey === 'login-click')
            ->andReturn(true);
        $this->app->instance(WorkflowCopilotObservationService::class, $observations);
        $this->app->instance(WorkflowCopilotVisionService::class, $visionService);
        $this->app->instance(WorkflowExecutionService::class, $execution);

        app(WorkflowCopilotSupervisorService::class)->supervise($session->id);

        $this->assertDatabaseHas('workflow_copilot_events', [
            'workflow_copilot_session_id' => $session->id,
            'event_type' => 'checkpoint.continuation_recovered',
        ]);
    }

    public function test_processed_waiting_failure_checkpoint_restarts_an_orphaned_probe(): void
    {
        [$workflow, $step] = $this->workflowWithBrokenSelector();
        $sessions = app(WorkflowCopilotSessionService::class);
        $session = $sessions->start($workflow, ['goal' => 'Workflow fortsetzen.']);
        $checkpoint = [
            'id' => 'orphaned-probe-source-checkpoint',
            'kind' => 'regular',
            'workflow_step_id' => $step->id,
            'workflow_step_name' => $step->name,
            'task_key' => 'login-click',
            'task_title' => 'Login klicken',
            'successful' => false,
            'outcome' => 'failed',
            'next_action' => 'repair',
            'result' => ['ok' => false, 'statusMessage' => 'Externer Lauf konnte nicht gelesen werden.'],
        ];
        [$run] = $this->waitingRun($session, $step, $checkpoint);
        $session = $sessions->attachRun($session, $run);
        $probeTask = [
            'key' => 'login-click--copilot-probe',
            'task_key' => 'browser.click',
            'title' => 'Login klicken (Copilot-Probe)',
            'selector' => 'button[type="submit"]',
        ];
        $plan = [
            'action' => 'probe_update',
            'task_key' => 'login-click',
            'original_task_key' => 'login-click',
            'task_catalog_key' => 'browser.click',
            'probe_task' => $probeTask,
        ];
        $state = $session->state_json;
        $state['continuation_applied_checkpoint_id'] = $checkpoint['id'];
        $state['continuation_applied_action'] = 'probe';
        $state['active_repair_plan'] = $plan;
        $session->forceFill(['state_json' => $state])->save();

        $observations = Mockery::mock(WorkflowCopilotObservationService::class);
        $observations->shouldNotReceive('observe');
        $visionService = Mockery::mock(WorkflowCopilotVisionService::class);
        $visionService->shouldNotReceive('analyze');
        $execution = Mockery::mock(WorkflowExecutionService::class);
        $execution->shouldReceive('retryCopilotTask')
            ->once()
            ->withArgs(fn (mixed $runArgument, string $taskKey, ?array $transientTask, array $repairPlan): bool => $runArgument instanceof WorkflowRun
                && (int) $runArgument->id === (int) $run->id
                && $taskKey === 'login-click'
                && $transientTask === $probeTask
                && $repairPlan === $plan);
        $execution->shouldNotReceive('resumeCopilotCheckpoint');
        $this->app->instance(WorkflowCopilotObservationService::class, $observations);
        $this->app->instance(WorkflowCopilotVisionService::class, $visionService);
        $this->app->instance(WorkflowExecutionService::class, $execution);

        app(WorkflowCopilotSupervisorService::class)->supervise($session->id);

        $this->assertDatabaseHas('workflow_copilot_events', [
            'workflow_copilot_session_id' => $session->id,
            'event_type' => 'checkpoint.probe_recovered',
        ]);
        $this->assertDatabaseMissing('workflow_copilot_events', [
            'workflow_copilot_session_id' => $session->id,
            'event_type' => 'checkpoint.continuation_recovered',
        ]);
    }

    public function test_structural_repair_is_versioned_and_restarts_the_workflow_from_the_beginning(): void
    {
        [$workflow, $step] = $this->workflowWithBrokenSelector();
        $sessions = app(WorkflowCopilotSessionService::class);
        $session = $sessions->start($workflow, [
            'goal' => 'Login erfolgreich abschliessen.',
            'budget' => ['max_repair_iterations' => 3],
        ]);
        [$run] = $this->waitingRun($session, $step, [
            'id' => 'runtime-structure-failure-1',
            'kind' => 'regular',
            'workflow_step_id' => $step->id,
            'workflow_step_name' => $step->name,
            'task_key' => 'login-click',
            'task_title' => 'Login klicken',
            'successful' => false,
            'outcome' => 'failed',
            'next_action' => 'repair',
            'result' => ['ok' => false, 'statusMessage' => 'Vorausgehende Navigation fehlt.'],
        ]);
        $session = $sessions->attachRun($session, $run);
        $newRun = WorkflowRun::query()->create([
            'run_uuid' => (string) str()->uuid(),
            'workflow_id' => $workflow->id,
            'workflow_copilot_session_id' => $session->id,
            'workflow_revision' => 1,
            'status' => 'queued',
            'context_json' => ['execution_target' => 'system', 'workflow_revision' => 1],
            'result_json' => [],
        ]);
        $observation = $this->observation();
        $vision = $this->visionResult('pause');
        $observations = Mockery::mock(WorkflowCopilotObservationService::class);
        $observations->shouldReceive('observe')->once()->andReturn($observation);
        $visionService = Mockery::mock(WorkflowCopilotVisionService::class);
        $visionService->shouldReceive('analyze')->once()->andReturn($vision);
        $repairs = Mockery::mock(WorkflowCopilotRepairService::class);
        $repairs->shouldReceive('plan')->once()->andReturn([
            'action' => 'restart_with_workflow_changes',
            'task_key' => 'login-click',
            'reason' => 'Die Fehlerroute muss die vorhandene Navigation erreichen.',
            'operations' => [[
                'type' => 'update_step_routes',
                'step_action_key' => 'login',
                'routes' => [
                    'failed' => ['type' => 'step', 'step' => 'login', 'action_key' => 'login'],
                ],
            ]],
        ]);
        $repairs->shouldReceive('applyStructuralOperations')
            ->once()
            ->withArgs(function (Workflow $lockedWorkflow, array $operations, WorkflowCopilotSession $owningSession, array $structuralObservation): bool {
                $lockedStep = $lockedWorkflow->steps()->where('action_key', 'login')->firstOrFail();
                $config = is_array($lockedStep->config_json) ? $lockedStep->config_json : [];
                $config['routes']['failed'] = data_get($operations, '0.routes.failed');
                $lockedStep->forceFill(['config_json' => $config])->save();

                return (int) $owningSession->workflow_id === (int) $lockedWorkflow->id
                    && data_get($structuralObservation, 'page.url') === 'https://example.test/login';
            });
        $execution = Mockery::mock(WorkflowExecutionService::class);
        $execution->shouldReceive('cancel')
            ->once()
            ->withArgs(fn (mixed $runArgument, string $message): bool => $runArgument instanceof WorkflowRun
                && (int) $runArgument->id === (int) $run->id
                && str_contains($message, 'strukturellen Workflow-Reparatur'))
            ->andReturn(['ok' => true]);
        $execution->shouldReceive('start')
            ->once()
            ->withArgs(fn (Workflow $startedWorkflow, array $context, string $source): bool => (int) $startedWorkflow->id === (int) $workflow->id
                && data_get($context, 'workflow_revision') === 1
                && data_get($context, 'execution_target') === 'system'
                && $source === 'workflow-copilot')
            ->andReturn($newRun);
        $this->app->instance(WorkflowCopilotObservationService::class, $observations);
        $this->app->instance(WorkflowCopilotVisionService::class, $visionService);
        $this->app->instance(WorkflowCopilotRepairService::class, $repairs);
        $this->app->instance(WorkflowExecutionService::class, $execution);

        app(WorkflowCopilotSupervisorService::class)->supervise($session->id);

        $session->refresh();
        $this->assertSame(WorkflowCopilotSession::STATUS_RUNNING, $session->status);
        $this->assertSame(1, $session->current_revision);
        $this->assertSame($newRun->id, (int) $session->active_workflow_run_id);
        $this->assertSame('login', data_get($step->fresh()->config_json, 'routes.failed.step'));
        $this->assertDatabaseCount('workflow_revisions', 1);
        $this->assertDatabaseHas('workflow_copilot_events', [
            'workflow_copilot_session_id' => $session->id,
            'event_type' => 'repair.structural_update_applied',
        ]);
        $this->assertSame(1, data_get($session->usage_json, 'repair_iterations'));
        $structuralEvent = $session->events()->where('event_type', 'repair.structural_update_applied')->firstOrFail();
        $this->assertSame('image_understanding', data_get($structuralEvent->payload_json, 'planning_handoff.vision_profile'));
        $this->assertSame('data_analysis', data_get($structuralEvent->payload_json, 'planning_handoff.planner_profile'));
    }

    public function test_completed_verification_marks_revision_verified_and_unlocks_workflow(): void
    {
        [$workflow, $step] = $this->workflowWithBrokenSelector();
        $sessions = app(WorkflowCopilotSessionService::class);
        $criteria = ['assertions' => [
            'Finale URL enthält /login',
            'Text Anmelden ist sichtbar',
            'Text Konto ist sichtbar',
            'workflow_return ist success',
        ]];
        $session = $sessions->start($workflow, [
            'goal' => 'Login erfolgreich abschliessen.',
            'success_criteria' => $criteria,
        ]);
        $session = $sessions->transition($session, WorkflowCopilotSession::STATUS_VERIFYING, 'verifying');
        $run = WorkflowRun::query()->create([
            'run_uuid' => (string) str()->uuid(),
            'workflow_id' => $workflow->id,
            'workflow_copilot_session_id' => $session->id,
            'workflow_revision' => 0,
            'status' => 'completed',
            'context_json' => [
                'workflow_copilot_session_id' => $session->id,
                'copilot_verification_run' => true,
                'copilot_supervised' => true,
                'copilot_mutations_allowed' => false,
                'copilot_frozen_success_criteria' => $criteria,
                'copilot_frozen_workflow_hash' => $this->workflowSnapshotHash($workflow),
                'workflow_revision' => 0,
                'execution_target' => 'system',
            ],
            'result_json' => [
                'ok' => true,
                'technical_status' => 'success',
                'business_status' => 'success',
                'workflow_return' => 'success',
            ],
            'started_at' => now()->subSecond(),
            'finished_at' => now(),
        ]);
        WorkflowStepRun::query()->create([
            'workflow_run_id' => $run->id,
            'workflow_step_id' => $step->id,
            'status' => 'completed',
            'result_json' => ['ok' => true],
        ]);
        $sessions->attachRun($session, $run);

        $observations = Mockery::mock(WorkflowCopilotObservationService::class);
        $observations->shouldReceive('observe')->once()->andReturn($this->observation());
        $visionService = Mockery::mock(WorkflowCopilotVisionService::class);
        $visionService->shouldReceive('analyze')->once()->andReturn($this->visionResult('pass'));
        $this->app->instance(WorkflowCopilotObservationService::class, $observations);
        $this->app->instance(WorkflowCopilotVisionService::class, $visionService);

        app(WorkflowCopilotSupervisorService::class)->supervise($session->id);

        $this->assertSame(WorkflowCopilotSession::STATUS_SUCCEEDED, $session->fresh()->status);
        $this->assertNull($workflow->fresh()->active_workflow_copilot_session_id);
        $this->assertSame('verified', $workflow->fresh()->copilot_verification_status);
        $this->assertDatabaseHas('workflow_revisions', [
            'workflow_copilot_session_id' => $session->id,
            'revision_number' => 0,
            'is_verified' => true,
        ]);
    }

    public function test_final_control_run_stays_task_segmented_but_disables_mutations(): void
    {
        [$workflow] = $this->workflowWithBrokenSelector();
        $sessions = app(WorkflowCopilotSessionService::class);
        $criteria = ['assertions' => ['Finale URL enthält /login']];
        $session = $sessions->start($workflow, ['success_criteria' => $criteria]);
        $repairRun = WorkflowRun::query()->create([
            'run_uuid' => (string) str()->uuid(),
            'workflow_id' => $workflow->id,
            'workflow_copilot_session_id' => $session->id,
            'workflow_revision' => 0,
            'status' => 'completed',
            'context_json' => [
                'workflow_copilot_session_id' => $session->id,
                'workflow_revision' => 0,
                'copilot_supervised' => true,
                'copilot_verification_run' => false,
                'execution_target' => 'system',
            ],
            'result_json' => ['ok' => true, 'technical_status' => 'success', 'business_status' => 'success'],
        ]);
        $session = $sessions->attachRun($session, $repairRun);
        $verificationRun = WorkflowRun::query()->create([
            'run_uuid' => (string) str()->uuid(),
            'workflow_id' => $workflow->id,
            'workflow_copilot_session_id' => $session->id,
            'workflow_revision' => 0,
            'status' => 'queued',
            'context_json' => ['execution_target' => 'system'],
            'result_json' => [],
        ]);
        $execution = Mockery::mock(WorkflowExecutionService::class);
        $execution->shouldReceive('start')
            ->once()
            ->withArgs(fn (Workflow $candidate, array $context, string $requestedBy): bool => $candidate->is($workflow)
                && data_get($context, 'copilot_supervised') === true
                && data_get($context, 'copilot_mutations_allowed') === false
                && data_get($context, 'copilot_verification_run') === true
                && filled(data_get($context, 'copilot_frozen_workflow_hash'))
                && data_get($context, 'copilot_frozen_success_criteria') === $criteria
                && data_get($context, 'execution_target') === 'system'
                && $requestedBy === 'workflow-copilot-verification')
            ->andReturn($verificationRun);
        $this->app->instance(WorkflowExecutionService::class, $execution);

        app(WorkflowCopilotSupervisorService::class)->supervise($session->id);

        $this->assertSame(WorkflowCopilotSession::STATUS_VERIFYING, $session->fresh()->status);
        $this->assertSame($verificationRun->id, $session->fresh()->active_workflow_run_id);
    }

    public function test_low_confidence_vision_pass_never_verifies_the_workflow(): void
    {
        [$workflow, $step] = $this->workflowWithBrokenSelector();
        $sessions = app(WorkflowCopilotSessionService::class);
        $criteria = ['assertions' => [['type' => 'url', 'operator' => 'contains', 'value' => '/login']]];
        $session = $sessions->start($workflow, [
            'goal' => 'Login erfolgreich abschliessen.',
            'success_criteria' => $criteria,
        ]);
        $session = $sessions->transition($session, WorkflowCopilotSession::STATUS_VERIFYING, 'verifying');
        $run = $this->verificationRun($session, $step, $criteria);
        $sessions->attachRun($session, $run);
        $replacement = WorkflowRun::query()->create([
            'run_uuid' => (string) str()->uuid(),
            'workflow_id' => $workflow->id,
            'workflow_copilot_session_id' => $session->id,
            'workflow_revision' => 0,
            'status' => 'queued',
            'context_json' => ['execution_target' => 'system'],
            'result_json' => [],
        ]);

        $observations = Mockery::mock(WorkflowCopilotObservationService::class);
        $observations->shouldReceive('observe')->once()->andReturn($this->observation());
        $vision = $this->visionResult('pass');
        $vision['confidence'] = 0.2;
        $visionService = Mockery::mock(WorkflowCopilotVisionService::class);
        $visionService->shouldReceive('analyze')->once()->andReturn($vision);
        $execution = Mockery::mock(WorkflowExecutionService::class);
        $execution->shouldReceive('start')
            ->once()
            ->withArgs(fn (Workflow $candidate, array $context, string $requestedBy): bool => $candidate->is($workflow)
                && data_get($context, 'execution_target') === 'system'
                && $requestedBy === 'workflow-copilot')
            ->andReturn($replacement);
        $this->app->instance(WorkflowCopilotObservationService::class, $observations);
        $this->app->instance(WorkflowCopilotVisionService::class, $visionService);
        $this->app->instance(WorkflowExecutionService::class, $execution);

        app(WorkflowCopilotSupervisorService::class)->supervise($session->id);

        $this->assertSame(WorkflowCopilotSession::STATUS_RUNNING, $session->fresh()->status);
        $this->assertSame($replacement->id, $session->fresh()->active_workflow_run_id);
        $this->assertSame($session->id, $workflow->fresh()->active_workflow_copilot_session_id);
        $this->assertSame('unverified', $workflow->fresh()->copilot_verification_status);
        $this->assertDatabaseHas('workflow_copilot_events', [
            'workflow_copilot_session_id' => $session->id,
            'event_type' => 'verification.failed',
        ]);
    }

    public function test_stale_verification_workflow_snapshot_is_rejected_before_vision_analysis(): void
    {
        [$workflow, $step] = $this->workflowWithBrokenSelector();
        $sessions = app(WorkflowCopilotSessionService::class);
        $criteria = ['assertions' => [['type' => 'url', 'value' => '/login']]];
        $session = $sessions->start($workflow, ['success_criteria' => $criteria]);
        $session = $sessions->transition($session, WorkflowCopilotSession::STATUS_VERIFYING, 'verifying');
        $run = $this->verificationRun($session, $step, $criteria);
        $sessions->attachRun($session, $run);
        $config = $step->config_json;
        data_set($config, 'tasks.0.selector', '.manually-changed-without-revision');
        $step->forceFill(['config_json' => $config])->save();
        $replacement = WorkflowRun::query()->create([
            'run_uuid' => (string) str()->uuid(),
            'workflow_id' => $workflow->id,
            'workflow_copilot_session_id' => $session->id,
            'workflow_revision' => 0,
            'status' => 'queued',
            'context_json' => ['execution_target' => 'system'],
            'result_json' => [],
        ]);

        $observations = Mockery::mock(WorkflowCopilotObservationService::class);
        $observations->shouldNotReceive('observe');
        $visionService = Mockery::mock(WorkflowCopilotVisionService::class);
        $visionService->shouldNotReceive('analyze');
        $execution = Mockery::mock(WorkflowExecutionService::class);
        $execution->shouldReceive('start')->once()->andReturn($replacement);
        $this->app->instance(WorkflowCopilotObservationService::class, $observations);
        $this->app->instance(WorkflowCopilotVisionService::class, $visionService);
        $this->app->instance(WorkflowExecutionService::class, $execution);

        app(WorkflowCopilotSupervisorService::class)->supervise($session->id);

        $this->assertNotSame(WorkflowCopilotSession::STATUS_SUCCEEDED, $session->fresh()->status);
        $this->assertSame('unverified', $workflow->fresh()->copilot_verification_status);
        $this->assertDatabaseMissing('workflow_revisions', [
            'workflow_id' => $workflow->id,
            'revision_number' => 0,
            'is_verified' => true,
        ]);
        $event = $session->events()->where('event_type', 'verification.failed')->firstOrFail();
        $this->assertContains('workflow_snapshot_mismatch', data_get($event->payload_json, 'binding_errors', []));
    }

    public function test_rewind_instruction_uses_previous_reproducible_checkpoint_logically(): void
    {
        [$workflow, $step] = $this->workflowWithBrokenSelector();
        $sessions = app(WorkflowCopilotSessionService::class);
        $session = $sessions->start($workflow);
        [$run] = $this->waitingRun($session, $step, [
            'id' => 'runtime-registration',
            'kind' => 'regular',
            'workflow_step_id' => $step->id,
            'workflow_step_name' => 'Registrierung',
            'task_key' => 'registration-submit',
            'task_title' => 'Registrierung absenden',
            'successful' => false,
            'result' => ['ok' => false],
        ]);
        $session = $sessions->attachRun($session, $run);
        $checkpoint = $sessions->createCheckpoint($session, [
            'workflow_run_id' => $run->id,
            'workflow_step_id' => $step->id,
            'phase' => 'observing',
            'task_key' => 'login-click',
            'cursor_json' => [
                'step_action_key' => $step->action_key,
                'step_name' => 'Login',
                'task_key' => 'login-click',
            ],
            'browser_state_json' => ['windows' => [['name' => 'main']]],
            'context_json' => [
                'version' => 1,
                'execution_target' => 'system',
                'encrypted_runtime_context' => Crypt::encryptString((string) json_encode([
                    'execution_target' => 'system',
                    'workflow_variables' => [
                        'email' => 'checkpoint@example.test',
                        'attempt' => 2,
                    ],
                ])),
            ],
            'side_effect_ledger_json' => [['type' => 'external_request', 'reversible' => false]],
            'is_reproducible' => true,
        ]);
        $sessions->instruction($session, 'Springe noch einmal vor die Registrierung zurück.');
        $replacement = WorkflowRun::query()->create([
            'run_uuid' => (string) str()->uuid(),
            'workflow_id' => $workflow->id,
            'workflow_copilot_session_id' => $session->id,
            'workflow_revision' => 0,
            'status' => 'queued',
            'context_json' => ['execution_target' => 'system'],
            'result_json' => [],
        ]);

        $observations = Mockery::mock(WorkflowCopilotObservationService::class);
        $observations->shouldNotReceive('observe');
        $visionService = Mockery::mock(WorkflowCopilotVisionService::class);
        $visionService->shouldNotReceive('analyze');
        $execution = Mockery::mock(WorkflowExecutionService::class);
        $execution->shouldReceive('cancel')->once()->withArgs(fn (WorkflowRun $candidate, string $message): bool => $candidate->is($run)
            && str_contains($message, 'logisch'))->andReturn(['ok' => true]);
        $execution->shouldReceive('start')
            ->once()
            ->withArgs(fn (Workflow $candidate, array $context, string $requestedBy): bool => $candidate->is($workflow)
                && data_get($context, 'next_task_key') === 'login-click'
                && data_get($context, 'execution_target') === 'system'
                && data_get($context, 'workflow_variables.email') === 'checkpoint@example.test'
                && data_get($context, 'workflow_variables.attempt') === 2
                && $requestedBy === 'workflow-copilot')
            ->andReturn($replacement);
        $this->app->instance(WorkflowCopilotObservationService::class, $observations);
        $this->app->instance(WorkflowCopilotVisionService::class, $visionService);
        $this->app->instance(WorkflowExecutionService::class, $execution);

        app(WorkflowCopilotSupervisorService::class)->supervise($session->id);

        $this->assertSame($replacement->id, $session->fresh()->active_workflow_run_id);
        $this->assertDatabaseHas('workflow_copilot_events', [
            'workflow_copilot_session_id' => $session->id,
            'event_type' => 'instruction.rewind_applied',
        ]);
        $event = $session->events()->where('event_type', 'instruction.rewind_applied')->firstOrFail();
        $this->assertSame($checkpoint->id, data_get($event->payload_json, 'checkpoint_id'));
        $this->assertTrue((bool) data_get($event->payload_json, 'logical_only'));
        $this->assertFalse((bool) data_get($event->payload_json, 'external_side_effects_reverted'));
    }

    public function test_live_supervisor_lease_serializes_duplicate_calls(): void
    {
        Queue::fake();
        [$workflow] = $this->workflowWithBrokenSelector();
        $session = app(WorkflowCopilotSessionService::class)->start($workflow);
        $state = is_array($session->state_json) ? $session->state_json : [];
        $state['supervisor_lease'] = [
            'token' => 'other-supervisor',
            'acquired_at' => now()->toIso8601String(),
            'expires_at' => now()->addMinute()->toIso8601String(),
        ];
        $session->forceFill(['state_json' => $state])->save();

        app(WorkflowCopilotSupervisorService::class)->supervise($session->id);

        $session->refresh();
        $this->assertNull($session->active_workflow_run_id);
        $this->assertSame('other-supervisor', data_get($session->state_json, 'supervisor_lease.token'));
        $this->assertTrue((bool) data_get($session->state_json, 'supervisor_recheck_requested'));
        Queue::assertPushed(WorkflowCopilotSupervisorJob::class, 1);

        app(WorkflowCopilotSupervisorService::class)->supervise($session->id);

        Queue::assertPushed(WorkflowCopilotSupervisorJob::class, 1);
    }

    public function test_queued_system_run_is_redispatched_once_after_resume(): void
    {
        Queue::fake();
        [$workflow] = $this->workflowWithBrokenSelector();
        $sessions = app(WorkflowCopilotSessionService::class);
        $session = $sessions->start($workflow);
        $run = WorkflowRun::query()->create([
            'run_uuid' => (string) str()->uuid(),
            'workflow_id' => $workflow->id,
            'workflow_copilot_session_id' => $session->id,
            'workflow_revision' => 0,
            'status' => 'queued',
            'context_json' => [
                'workflow_copilot_session_id' => $session->id,
                'copilot_supervised' => true,
                'execution_target' => 'system',
            ],
            'result_json' => [],
        ]);
        $session = $sessions->attachRun($session, $run);
        $session = $sessions->pause($session, 'Testpause vor dem ersten Task.');
        $session = $sessions->resume($session);

        app(WorkflowCopilotSupervisorService::class)->supervise($session->id);
        app(WorkflowCopilotSupervisorService::class)->supervise($session->id);

        Queue::assertPushed(RunWorkflowJob::class, 1);
        $this->assertSame($run->id, data_get($session->fresh()->state_json, 'queued_run_redispatched_run_id'));
        $this->assertDatabaseHas('workflow_copilot_events', [
            'workflow_copilot_session_id' => $session->id,
            'event_type' => 'run.redispatched_after_resume',
        ]);
    }

    public function test_repeated_technical_run_failure_aborts_with_diagnosis_instead_of_looping(): void
    {
        Queue::fake();
        [$workflow, $step] = $this->workflowWithBrokenSelector();
        $sessions = app(WorkflowCopilotSessionService::class);
        $session = $sessions->start($workflow, ['budget' => ['max_same_state_repeats' => 1]]);

        $error = 'Die Ziel-Task fuer den Ruecksprung wurde nicht gefunden: if-eingabevariable-pruefen';
        $run = $this->failedRun($session, $step, $error);
        $session = $sessions->attachRun($session, $run);

        // Ein vorheriger, identischer technischer Fehllauf ist bereits vermerkt.
        $state = is_array($session->state_json) ? $session->state_json : [];
        $state['last_technical_failure_signature'] = $error;
        $state['technical_failure_repeats'] = 0;
        $session->forceFill([
            'status' => WorkflowCopilotSession::STATUS_RUNNING,
            'state_json' => $state,
        ])->save();

        $execution = Mockery::mock(WorkflowExecutionService::class);
        $execution->shouldNotReceive('start');
        $execution->shouldNotReceive('cancel');
        $this->app->instance(WorkflowExecutionService::class, $execution);

        app(WorkflowCopilotSupervisorService::class)->supervise($session->id);

        $session->refresh();
        $this->assertSame(WorkflowCopilotSession::STATUS_BUDGET_EXHAUSTED, $session->status);
        $this->assertSame(1, data_get($session->usage_json, 'repair_iterations'));

        $event = $session->events()->where('event_type', 'run.unrepairable')->firstOrFail();
        $this->assertSame('if-eingabevariable-pruefen', data_get($event->payload_json, 'unresolved_route_target'));
        $this->assertSame(1, data_get($event->payload_json, 'repeats'));
    }

    public function test_first_technical_run_failure_restarts_once_and_records_signature(): void
    {
        [$workflow, $step] = $this->workflowWithBrokenSelector();
        $sessions = app(WorkflowCopilotSessionService::class);
        $session = $sessions->start($workflow);

        $error = 'Die Ziel-Task fuer den Ruecksprung wurde nicht gefunden: if-eingabevariable-pruefen';
        $run = $this->failedRun($session, $step, $error);
        $session = $sessions->attachRun($session, $run);
        $session->forceFill(['status' => WorkflowCopilotSession::STATUS_RUNNING])->save();

        $newRun = WorkflowRun::query()->create([
            'run_uuid' => (string) str()->uuid(),
            'workflow_id' => $workflow->id,
            'workflow_revision' => 0,
            'status' => 'queued',
            'context_json' => ['execution_target' => 'system'],
            'result_json' => [],
        ]);
        $execution = Mockery::mock(WorkflowExecutionService::class);
        $execution->shouldReceive('start')->once()->andReturn($newRun);
        $this->app->instance(WorkflowExecutionService::class, $execution);

        app(WorkflowCopilotSupervisorService::class)->supervise($session->id);

        $session->refresh();
        $this->assertSame(WorkflowCopilotSession::STATUS_RUNNING, $session->status);
        $this->assertSame(1, data_get($session->usage_json, 'repair_iterations'));
        $this->assertSame(0, data_get($session->state_json, 'technical_failure_repeats'));
        $this->assertSame($error, data_get($session->state_json, 'last_technical_failure_signature'));
        $this->assertSame($newRun->id, (int) $session->active_workflow_run_id);
    }

    public function test_repeated_checkpoint_failure_stops_route_churn_without_calling_the_repair_planner_again(): void
    {
        [$workflow, $step] = $this->workflowWithBrokenSelector();
        $sessions = app(WorkflowCopilotSessionService::class);
        $session = $sessions->start($workflow, ['budget' => ['max_same_state_repeats' => 1]]);
        $checkpoint = [
            'id' => 'repeated-checkpoint-failure',
            'kind' => 'regular',
            'workflow_step_id' => $step->id,
            'workflow_step_name' => $step->name,
            'task_key' => 'login-click',
            'task_title' => 'Login klicken',
            'successful' => false,
            'outcome' => 'failed',
            'next_action' => 'repair',
            'result' => ['ok' => false, 'statusMessage' => 'Element nicht gefunden.'],
        ];
        [$run] = $this->waitingRun($session, $step, $checkpoint);
        $session = $sessions->attachRun($session, $run);
        $observation = $this->observation();
        $signature = hash('sha256', (string) json_encode([
            'workflow_step_id' => (int) $step->id,
            'task_key' => 'login-click',
            'state_signature' => 'login-page-v1',
            'page_url' => 'https://example.test/login',
            'error' => 'Element nicht gefunden.',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
        $state = is_array($session->state_json) ? $session->state_json : [];
        $state['last_repair_failure_signature'] = $signature;
        $state['repair_failure_repeats'] = 0;
        $session->forceFill(['state_json' => $state])->save();

        $observations = Mockery::mock(WorkflowCopilotObservationService::class);
        $observations->shouldReceive('observe')->once()->andReturn($observation);
        $visionService = Mockery::mock(WorkflowCopilotVisionService::class);
        $visionService->shouldReceive('analyze')->once()->andReturn($this->visionResult('pause'));
        $repairs = Mockery::mock(WorkflowCopilotRepairService::class);
        $repairs->shouldNotReceive('plan');
        $execution = Mockery::mock(WorkflowExecutionService::class);
        $execution->shouldReceive('cancel')->once()->withArgs(
            fn (WorkflowRun $candidate, string $message): bool => $candidate->is($run)
                && str_contains($message, 'Budget'),
        )->andReturn(['ok' => true]);
        $execution->shouldNotReceive('start');
        $this->app->instance(WorkflowCopilotObservationService::class, $observations);
        $this->app->instance(WorkflowCopilotVisionService::class, $visionService);
        $this->app->instance(WorkflowCopilotRepairService::class, $repairs);
        $this->app->instance(WorkflowExecutionService::class, $execution);

        app(WorkflowCopilotSupervisorService::class)->supervise($session->id);

        $session->refresh();
        $this->assertSame(WorkflowCopilotSession::STATUS_BUDGET_EXHAUSTED, $session->status);
        $this->assertSame(1, data_get($session->state_json, 'repair_failure_repeats'));
        $this->assertSame(1, data_get($session->usage_json, 'same_state_repeats'));
        $this->assertDatabaseHas('workflow_copilot_events', [
            'workflow_copilot_session_id' => $session->id,
            'event_type' => 'repair.no_progress',
        ]);
    }

    public function test_same_page_signature_does_not_consume_retry_budget_for_a_different_task(): void
    {
        [$workflow, $step] = $this->workflowWithBrokenSelector();
        $sessions = app(WorkflowCopilotSessionService::class);
        $session = $sessions->start($workflow);
        [$run] = $this->waitingRun($session, $step, [
            'id' => 'runtime-email',
            'kind' => 'regular',
            'workflow_step_id' => $step->id,
            'workflow_step_name' => $step->name,
            'task_key' => 'fill-email',
            'task_title' => 'E-Mail fuellen',
            'successful' => true,
            'next_action' => 'next_task',
            'next_task_key' => 'fill-password',
            'result' => ['ok' => true],
        ]);
        $session = $sessions->attachRun($session, $run);

        $observations = Mockery::mock(WorkflowCopilotObservationService::class);
        $observations->shouldReceive('observe')->once()->andReturn($this->observation());
        $visionService = Mockery::mock(WorkflowCopilotVisionService::class);
        $visionService->shouldNotReceive('analyze');
        $execution = Mockery::mock(WorkflowExecutionService::class);
        $execution->shouldReceive('resumeCopilotCheckpoint')->once()->andReturn(true);
        $this->app->instance(WorkflowCopilotObservationService::class, $observations);
        $this->app->instance(WorkflowCopilotVisionService::class, $visionService);
        $this->app->instance(WorkflowExecutionService::class, $execution);
        app(WorkflowCopilotSupervisorService::class)->supervise($session->id);

        $context = $run->fresh()->context_json;
        $context['copilot_checkpoint'] = [
            'id' => 'runtime-password',
            'kind' => 'regular',
            'workflow_step_id' => $step->id,
            'workflow_step_name' => $step->name,
            'task_key' => 'fill-password',
            'task_title' => 'Passwort fuellen',
            'successful' => true,
            'next_action' => 'complete_step',
            'result' => ['ok' => true],
        ];
        $run->forceFill(['context_json' => $context, 'status' => 'waiting'])->save();

        $observations = Mockery::mock(WorkflowCopilotObservationService::class);
        $observations->shouldReceive('observe')->once()->andReturn($this->observation());
        $visionService = Mockery::mock(WorkflowCopilotVisionService::class);
        $visionService->shouldReceive('analyze')->once()->andReturn($this->visionResult('continue'));
        $execution = Mockery::mock(WorkflowExecutionService::class);
        $execution->shouldReceive('resumeCopilotCheckpoint')->once()->andReturn(true);
        $this->app->instance(WorkflowCopilotObservationService::class, $observations);
        $this->app->instance(WorkflowCopilotVisionService::class, $visionService);
        $this->app->instance(WorkflowExecutionService::class, $execution);
        app(WorkflowCopilotSupervisorService::class)->supervise($session->id);

        $this->assertSame(0, data_get($session->fresh()->usage_json, 'same_state_repeats'));
    }

    public function test_ai_cost_budget_is_recorded_and_stops_before_repair(): void
    {
        [$workflow, $step] = $this->workflowWithBrokenSelector();
        $sessions = app(WorkflowCopilotSessionService::class);
        $session = $sessions->start($workflow, [
            'goal' => 'Login erfolgreich abschliessen.',
            'budget' => ['max_cost_usd' => 0.005],
        ]);
        [$run] = $this->waitingRun($session, $step, [
            'id' => 'runtime-cost-budget-1',
            'kind' => 'regular',
            'workflow_step_id' => $step->id,
            'workflow_step_name' => $step->name,
            'task_key' => 'login-click',
            'task_title' => 'Login klicken',
            'successful' => false,
            'outcome' => 'failed',
            'next_action' => 'repair',
            'result' => ['ok' => false, 'statusMessage' => 'Element nicht gefunden.'],
        ]);
        $session = $sessions->attachRun($session, $run);
        $observations = Mockery::mock(WorkflowCopilotObservationService::class);
        $observations->shouldReceive('observe')->once()->andReturn($this->observation());
        $visionService = Mockery::mock(WorkflowCopilotVisionService::class);
        $visionService->shouldReceive('analyze')->once()->andReturnUsing(function (): array {
            app(WorkflowCopilotAiUsageTracker::class)->recordResponse(
                ['model' => 'test/vision'],
                [
                    'id' => 'cost-budget-generation',
                    'model' => 'test/vision',
                    'usage' => [
                        'prompt_tokens' => 100,
                        'completion_tokens' => 25,
                        'total_tokens' => 125,
                        'cost' => 0.006,
                    ],
                ],
                'image_understanding',
            );

            return $this->visionResult('continue');
        });
        $repairs = Mockery::mock(WorkflowCopilotRepairService::class);
        $repairs->shouldNotReceive('plan');
        $execution = Mockery::mock(WorkflowExecutionService::class);
        $execution->shouldReceive('cancel')
            ->once()
            ->withArgs(fn (WorkflowRun $candidate, string $reason): bool => (int) $candidate->id === (int) $run->id
                && str_contains($reason, 'Budget'))
            ->andReturn(['ok' => true]);
        $this->app->instance(WorkflowCopilotObservationService::class, $observations);
        $this->app->instance(WorkflowCopilotVisionService::class, $visionService);
        $this->app->instance(WorkflowCopilotRepairService::class, $repairs);
        $this->app->instance(WorkflowExecutionService::class, $execution);

        app(WorkflowCopilotSupervisorService::class)->supervise($session->id);

        $session->refresh();
        $this->assertSame(WorkflowCopilotSession::STATUS_BUDGET_EXHAUSTED, $session->status);
        $this->assertSame(0.006, data_get($session->usage_json, 'cost_usd'));
        $this->assertSame(125, data_get($session->usage_json, 'total_tokens'));
        $this->assertDatabaseHas('workflow_copilot_events', [
            'workflow_copilot_session_id' => $session->id,
            'event_type' => 'ai.usage_recorded',
        ]);
    }

    public function test_resolved_consent_obstacle_is_logged_and_continued_without_selector_repair(): void
    {
        [$workflow, $step] = $this->workflowWithBrokenSelector();
        $config = $step->config_json;
        $config['tasks'][0] = array_replace($config['tasks'][0], [
            'key' => 'consent-ablehnen',
            'task_key' => 'browser.click',
            'title' => 'Consent: Alle ablehnen',
            'selector' => '#W0wltc',
        ]);
        $step->forceFill(['config_json' => $config])->save();
        $sessions = app(WorkflowCopilotSessionService::class);
        $session = $sessions->start($workflow, [
            'goal' => 'Google-Suche ohne Consent-Blockade ausfuehren.',
        ]);
        [$run] = $this->waitingRun($session, $step->fresh(), [
            'id' => 'runtime-consent-gone-1',
            'kind' => 'regular',
            'workflow_step_id' => $step->id,
            'workflow_step_name' => $step->name,
            'task_key' => 'consent-ablehnen',
            'task_title' => 'Consent: Alle ablehnen',
            'successful' => false,
            'outcome' => 'failed',
            'next_action' => 'repair',
            'result' => ['ok' => false, 'statusMessage' => 'Element nicht gefunden.'],
        ]);
        $session = $sessions->attachRun($session, $run);
        $observation = [
            'state_signature' => 'google-search-input-v1',
            'page' => ['url' => 'https://www.google.com', 'title' => 'Google', 'state' => 'search_input'],
            'dom' => ['ui_state' => 'search_input', 'visible_text_excerpt' => 'Google Suche'],
            'interaction_map' => [[
                'element_ref' => 'el_search',
                'tag' => 'textarea',
                'aria' => 'Suche',
                'visible' => true,
                'enabled' => true,
                'selector_candidates' => ['#APjFqb'],
            ]],
            'screenshot' => ['available_for_vision' => true],
            'evidence_sufficient' => true,
        ];
        $vision = [
            'page_type' => 'search_page',
            'ui_state' => 'search_input',
            'confidence' => 0.9,
            'verdict' => 'continue',
            'safe_pause' => false,
            'model' => 'test/vision',
            'relevant_elements' => [['element_ref' => 'el_search', 'confidence' => 0.9]],
            'suggested_task_actions' => [],
        ];
        $observations = Mockery::mock(WorkflowCopilotObservationService::class);
        $observations->shouldReceive('observe')->once()->andReturn($observation);
        $visionService = Mockery::mock(WorkflowCopilotVisionService::class);
        $visionService->shouldReceive('analyze')->once()->andReturn($vision);
        $repairs = Mockery::mock(WorkflowCopilotRepairService::class);
        $repairs->shouldReceive('plan')->once()->andReturn([
            'action' => 'skip_resolved_obstacle',
            'task_key' => 'consent-ablehnen',
            'reason' => 'Der Consent-Dialog ist nicht mehr vorhanden.',
            'evidence' => ['vision_state' => 'search_input'],
        ]);
        $execution = Mockery::mock(WorkflowExecutionService::class);
        $execution->shouldReceive('skipResolvedCopilotTask')
            ->once()
            ->withArgs(fn (WorkflowRun $candidateRun, string $taskKey): bool => $candidateRun->is($run)
                && $taskKey === 'consent-ablehnen')
            ->andReturn(true);
        $execution->shouldNotReceive('retryCopilotTask');
        $execution->shouldNotReceive('resumeCopilotCheckpoint');
        $this->app->instance(WorkflowCopilotObservationService::class, $observations);
        $this->app->instance(WorkflowCopilotVisionService::class, $visionService);
        $this->app->instance(WorkflowCopilotRepairService::class, $repairs);
        $this->app->instance(WorkflowExecutionService::class, $execution);

        app(WorkflowCopilotSupervisorService::class)->supervise($session->id);

        $session->refresh();
        $this->assertSame(WorkflowCopilotSession::STATUS_RUNNING, $session->status);
        $this->assertSame('skip_resolved_obstacle', data_get($session->state_json, 'continuation_applied_action'));
        $this->assertDatabaseHas('workflow_copilot_events', [
            'workflow_copilot_session_id' => $session->id,
            'event_type' => 'repair.obstacle_resolved',
        ]);
        $this->assertDatabaseHas('workflow_copilot_events', [
            'workflow_copilot_session_id' => $session->id,
            'event_type' => 'repair.decision_planned',
        ]);
    }

    public function test_successful_task_with_visible_consent_dialog_enters_repair_instead_of_continuing(): void
    {
        [$workflow, $step] = $this->workflowWithBrokenSelector();
        $config = $step->config_json;
        $config['tasks'] = [[
            'key' => 'check-consent',
            'task_key' => 'decision.element_exists',
            'title' => 'Consent pruefen',
            'selector' => 'button:has-text("Alle ablehnen")',
        ]];
        $step->forceFill(['config_json' => $config])->save();
        $sessions = app(WorkflowCopilotSessionService::class);
        $session = $sessions->start($workflow, [
            'goal' => 'Google-Suche ohne blockierenden Consent-Dialog ausfuehren.',
        ]);
        [$run] = $this->waitingRun($session, $step->fresh(), [
            'id' => 'runtime-consent-success-1',
            'kind' => 'regular',
            'workflow_step_id' => $step->id,
            'workflow_step_name' => $step->name,
            'task_key' => 'check-consent',
            'task_title' => 'Consent pruefen',
            'successful' => true,
            'outcome' => 'success',
            'next_action' => 'complete_step',
            'result' => [
                'ok' => true,
                'matchedCandidate' => 'button:has-text("Alle ablehnen")',
                'element' => ['text' => 'Alle ablehnen'],
                'statusMessage' => 'Alle ablehnen wurde gefunden.',
            ],
        ]);
        $session = $sessions->attachRun($session, $run);
        $observation = [
            'state_signature' => 'google-consent-v1',
            'page' => ['url' => 'https://www.google.com', 'title' => 'Google', 'state' => 'consent_blocked', 'window' => 'main'],
            'page_state' => 'consent_blocked',
            'dom' => ['ui_state' => 'consent_blocked', 'visible_text_excerpt' => 'Alle ablehnen Alle akzeptieren'],
            'interaction_map' => [[
                'element_ref' => 'el_reject',
                'tag' => 'button',
                'text' => 'Alle ablehnen',
                'visible' => true,
                'enabled' => true,
                'selector_candidates' => ['button:has-text("Alle ablehnen")'],
                'window' => 'main',
            ]],
            'screenshot_changed' => false,
            'screenshot' => ['available_for_vision' => true],
            'evidence_sufficient' => true,
        ];
        $vision = [
            'ui_state' => 'consent_blocked',
            'verdict' => 'blocked',
            'confidence' => 0.99,
            'model' => 'test/vision',
        ];
        $observations = Mockery::mock(WorkflowCopilotObservationService::class);
        $observations->shouldReceive('observe')->once()->andReturn($observation);
        $visionService = Mockery::mock(WorkflowCopilotVisionService::class);
        $visionService->shouldReceive('analyze')->once()->andReturn($vision);
        $repairs = Mockery::mock(WorkflowCopilotRepairService::class);
        $repairs->shouldReceive('plan')
            ->once()
            ->withArgs(fn (WorkflowCopilotSession $candidateSession, WorkflowStep $candidateStep, array $checkpoint): bool => (int) $candidateSession->id === (int) $session->id
                && (int) $candidateStep->id === (int) $step->id
                && ($checkpoint['successful'] ?? true) === false
                && ($checkpoint['outcome'] ?? null) === 'blocked'
                && data_get($checkpoint, 'result.technicalSuccess') === true)
            ->andReturn([
                'action' => 'pause',
                'task_key' => 'check-consent',
                'reason' => 'Testpause nach erkannter Consent-Blockade.',
            ]);
        $execution = Mockery::mock(WorkflowExecutionService::class);
        $execution->shouldNotReceive('resumeCopilotCheckpoint');
        $execution->shouldNotReceive('retryCopilotTask');
        $this->app->instance(WorkflowCopilotObservationService::class, $observations);
        $this->app->instance(WorkflowCopilotVisionService::class, $visionService);
        $this->app->instance(WorkflowCopilotRepairService::class, $repairs);
        $this->app->instance(WorkflowExecutionService::class, $execution);

        app(WorkflowCopilotSupervisorService::class)->supervise($session->id);

        $this->assertSame(WorkflowCopilotSession::STATUS_PAUSED, $session->fresh()->status);
        $this->assertDatabaseHas('workflow_copilot_events', [
            'workflow_copilot_session_id' => $session->id,
            'event_type' => 'checkpoint.consent_blocked',
        ]);
        $this->assertDatabaseMissing('workflow_copilot_events', [
            'workflow_copilot_session_id' => $session->id,
            'event_type' => 'checkpoint.continue',
        ]);
    }

    private function workflowWithBrokenSelector(): array
    {
        $workflow = Workflow::query()->create([
            'name' => 'Copilot Login',
            'slug' => 'copilot-login-'.str()->random(8),
            'description' => '',
            'category' => 'test',
            'is_active' => true,
            'is_locked' => false,
            'trigger_type' => 'manual',
            'settings_json' => [],
        ]);
        $step = $workflow->steps()->create([
            'name' => 'Login',
            'type' => WorkflowStep::TYPE_BROWSER_TASK,
            'action_key' => 'login',
            'position' => 10,
            'is_enabled' => true,
            'config_json' => [
                'tasks' => [[
                    'key' => 'login-click',
                    'task_key' => 'browser.click',
                    'title' => 'Login klicken',
                    'selector' => '.missing-login',
                ]],
            ],
        ]);

        return [$workflow, $step];
    }

    private function waitingRun(WorkflowCopilotSession $session, WorkflowStep $step, array $checkpoint): array
    {
        $run = WorkflowRun::query()->create([
            'run_uuid' => (string) str()->uuid(),
            'workflow_id' => $session->workflow_id,
            'workflow_copilot_session_id' => $session->id,
            'workflow_revision' => 0,
            'current_workflow_step_id' => $step->id,
            'status' => 'waiting',
            'context_json' => [
                'workflow_copilot_session_id' => $session->id,
                'copilot_supervised' => true,
                'execution_target' => 'system',
                'copilot_checkpoint' => $checkpoint,
            ],
            'result_json' => [],
        ]);
        $stepRun = WorkflowStepRun::query()->create([
            'workflow_run_id' => $run->id,
            'workflow_step_id' => $step->id,
            'status' => 'waiting',
            'external_run_type' => 'workflow-task',
            'external_run_id' => 'fake-node-run',
            'result_json' => $checkpoint['result'],
        ]);

        return [$run, $stepRun];
    }

    private function failedRun(WorkflowCopilotSession $session, WorkflowStep $step, string $error): WorkflowRun
    {
        return WorkflowRun::query()->create([
            'run_uuid' => (string) str()->uuid(),
            'workflow_id' => $session->workflow_id,
            'workflow_copilot_session_id' => $session->id,
            'workflow_revision' => 0,
            'current_workflow_step_id' => $step->id,
            'status' => 'failed',
            'error_message' => $error,
            'context_json' => [
                'workflow_copilot_session_id' => $session->id,
                'copilot_supervised' => true,
                'execution_target' => 'system',
            ],
            'result_json' => ['ok' => false],
        ]);
    }

    private function verificationRun(WorkflowCopilotSession $session, WorkflowStep $step, array $criteria): WorkflowRun
    {
        $run = WorkflowRun::query()->create([
            'run_uuid' => (string) str()->uuid(),
            'workflow_id' => $session->workflow_id,
            'workflow_copilot_session_id' => $session->id,
            'workflow_revision' => $session->current_revision,
            'status' => 'completed',
            'context_json' => [
                'workflow_copilot_session_id' => $session->id,
                'copilot_verification_run' => true,
                'copilot_supervised' => true,
                'copilot_mutations_allowed' => false,
                'copilot_frozen_success_criteria' => $criteria,
                'copilot_frozen_workflow_hash' => $this->workflowSnapshotHash($session->workflow),
                'workflow_revision' => $session->current_revision,
                'execution_target' => 'system',
            ],
            'result_json' => [
                'ok' => true,
                'technical_status' => 'success',
                'business_status' => 'success',
            ],
            'started_at' => now()->subSecond(),
            'finished_at' => now(),
        ]);
        WorkflowStepRun::query()->create([
            'workflow_run_id' => $run->id,
            'workflow_step_id' => $step->id,
            'status' => 'completed',
            'result_json' => ['ok' => true],
        ]);

        return $run;
    }

    private function workflowSnapshotHash(Workflow $workflow): string
    {
        $snapshot = $this->canonicalValue(app(WorkflowRevisionService::class)->snapshot($workflow));

        return hash('sha256', (string) json_encode(
            $snapshot,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE,
        ));
    }

    private function canonicalValue(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn (mixed $item): mixed => $this->canonicalValue($item), $value);
    }

    private function observation(): array
    {
        return [
            'state_signature' => 'login-page-v1',
            'page' => ['url' => 'https://example.test/login', 'title' => 'Login', 'state' => 'login_form'],
            'dom' => ['ui_state' => 'login_form', 'visible_text_excerpt' => 'Anmelden'],
            'interaction_map' => [[
                'element_ref' => 'el_submit',
                'tag' => 'button',
                'text' => 'Anmelden',
                'aria' => 'Konto',
                'visible' => true,
                'enabled' => true,
                'selector_candidates' => ['button[type="submit"]'],
                'window' => 'main',
            ]],
            'screenshot_url' => '/workflow-runs/1/artifacts/1',
            'screenshot' => ['artifact_id' => null, 'available_for_vision' => true],
            'sensitive_fields_removed' => 3,
            'evidence_sufficient' => true,
        ];
    }

    private function visionResult(string $verdict): array
    {
        return [
            'page_type' => 'login',
            'ui_state' => 'login_form',
            'goal_progress' => $verdict === 'pass' ? 1.0 : 0.4,
            'blockers' => $verdict === 'pass' ? [] : ['Konfigurierter Selektor findet den sichtbaren Button nicht.'],
            'relevant_elements' => [['element_ref' => 'el_submit', 'confidence' => 0.98]],
            'confidence' => 0.98,
            'suggested_task_actions' => [],
            'needs_screenshot' => false,
            'verdict' => $verdict,
            'model' => 'test/vision',
            'attempts' => [],
        ];
    }
}
