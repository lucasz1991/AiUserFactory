<?php

namespace Tests\Feature;

use App\Models\Workflow;
use App\Models\WorkflowCopilotSession;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use App\Models\WorkflowStepRun;
use App\Services\Ai\WorkflowCopilotVisionService;
use App\Services\Workflows\WorkflowCopilotObservationService;
use App\Services\Workflows\WorkflowCopilotSessionService;
use App\Services\Workflows\WorkflowCopilotSupervisorService;
use App\Services\Workflows\WorkflowExecutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_failed_task_is_observed_probed_versioned_and_retried(): void
    {
        [$workflow, $step] = $this->workflowWithBrokenSelector();
        $sessions = app(WorkflowCopilotSessionService::class);
        $session = $sessions->start($workflow, ['goal' => 'Login erfolgreich abschliessen.']);
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
        $this->assertDatabaseCount('workflow_task_attempts', 1);
        $this->assertDatabaseCount('workflow_run_checkpoints', 1);
        $this->assertDatabaseCount('workflow_revisions', 0);

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
        $run->forceFill(['context_json' => $context])->save();

        $observations = Mockery::mock(WorkflowCopilotObservationService::class);
        $observations->shouldReceive('observe')->once()->andReturn($observation);
        $visionService = Mockery::mock(WorkflowCopilotVisionService::class);
        $visionService->shouldReceive('analyze')->once()->andReturn($vision);
        $execution = Mockery::mock(WorkflowExecutionService::class);
        $execution->shouldReceive('retryCopilotTask')
            ->once()
            ->withArgs(fn (mixed $runArgument, string $taskKey, ?array $transient, array $repairPlan): bool => $runArgument instanceof WorkflowRun
                && $taskKey === 'login-click'
                && $transient === null
                && $repairPlan === []);
        $this->app->instance(WorkflowCopilotObservationService::class, $observations);
        $this->app->instance(WorkflowCopilotVisionService::class, $visionService);
        $this->app->instance(WorkflowExecutionService::class, $execution);

        app(WorkflowCopilotSupervisorService::class)->supervise($session->id);

        $this->assertSame('button[type="submit"]', data_get($step->fresh()->config_json, 'tasks.0.selector'));
        $this->assertSame(1, $workflow->fresh()->copilot_revision);
        $this->assertSame(1, $session->fresh()->current_revision);
        $this->assertSame(1, $run->fresh()->workflow_revision);
        $this->assertDatabaseCount('workflow_task_attempts', 2);
        $this->assertDatabaseCount('workflow_run_checkpoints', 2);
        $this->assertDatabaseCount('workflow_revisions', 1);
        $this->assertDatabaseHas('workflow_copilot_events', [
            'workflow_copilot_session_id' => $session->id,
            'event_type' => 'revision.saved',
        ]);
    }

    public function test_completed_verification_marks_revision_verified_and_unlocks_workflow(): void
    {
        [$workflow, $step] = $this->workflowWithBrokenSelector();
        $sessions = app(WorkflowCopilotSessionService::class);
        $session = $sessions->start($workflow, ['goal' => 'Login erfolgreich abschliessen.']);
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

    private function observation(): array
    {
        return [
            'state_signature' => 'login-page-v1',
            'page' => ['url' => 'https://example.test/login', 'title' => 'Login'],
            'dom' => ['ui_state' => 'login_form', 'visible_text_excerpt' => 'Anmelden'],
            'interaction_map' => [[
                'element_ref' => 'el_submit',
                'tag' => 'button',
                'text' => 'Anmelden',
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
