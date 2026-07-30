<?php

namespace Tests\Feature;

use App\Models\Workflow;
use App\Models\WorkflowCopilotSession;
use App\Models\WorkflowRun;
use App\Models\WorkflowRunArtifact;
use App\Models\WorkflowStep;
use App\Models\WorkflowStepRun;
use App\Services\Ai\WorkflowCopilotVisionService;
use App\Services\Workflows\WorkflowCopilotObservationService;
use App\Services\Workflows\WorkflowCopilotRepairService;
use App\Services\Workflows\WorkflowCopilotSessionService;
use App\Services\Workflows\WorkflowCopilotSupervisorService;
use App\Services\Workflows\WorkflowExecutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class WorkflowCopilotProgressAndAssistanceTest extends TestCase
{
    use RefreshDatabase;

    private ?int $screenshotArtifactId = null;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=']);
        Queue::fake();
    }

    public function test_sinking_goal_progress_is_reported_as_regression_without_ending_the_session(): void
    {
        [$session, $run] = $this->failedCheckpointSession(
            ['max_progress_regressions' => 3],
            ['last_goal_progress' => 0.8],
        );
        $this->mockCheckpointServices($this->visionResult(0.3));

        app(WorkflowCopilotSupervisorService::class)->supervise($session->id);

        $session->refresh();
        $event = $session->events()->where('event_type', 'repair.progress_regressed')->firstOrFail();
        $this->assertSame(0.8, data_get($event->payload_json, 'previous_goal_progress'));
        $this->assertSame(0.3, data_get($event->payload_json, 'goal_progress'));
        $this->assertSame(1, data_get($event->payload_json, 'progress_regressions'));
        $this->assertSame('warning', $event->level);
        $this->assertStringContainsString('80 %', (string) $event->message);
        $this->assertStringContainsString('30 %', (string) $event->message);
        $this->assertSame(0.3, data_get($session->state_json, 'last_goal_progress'));
        $this->assertSame(1, data_get($session->state_json, 'progress_regressions'));
        $this->assertNotSame(WorkflowCopilotSession::STATUS_BUDGET_EXHAUSTED, $session->status);
        $this->assertSame(WorkflowCopilotSession::STATUS_PAUSED, $session->status);
        $this->assertSame((int) $run->id, (int) data_get($event->payload_json, 'workflow_run_id'));
    }

    public function test_repeated_progress_regressions_end_the_session_with_their_own_message(): void
    {
        [$session, $run] = $this->failedCheckpointSession(
            ['max_progress_regressions' => 3],
            ['last_goal_progress' => 0.9, 'progress_regressions' => 2],
        );
        $observations = Mockery::mock(WorkflowCopilotObservationService::class);
        $observations->shouldReceive('observe')->once()->andReturn($this->observation());
        $visionService = Mockery::mock(WorkflowCopilotVisionService::class);
        $visionService->shouldReceive('analyze')->once()->andReturn($this->visionResult(0.2));
        $repairs = Mockery::mock(WorkflowCopilotRepairService::class);
        $repairs->shouldNotReceive('plan');
        $execution = Mockery::mock(WorkflowExecutionService::class);
        $execution->shouldReceive('cancel')
            ->once()
            ->withArgs(fn (WorkflowRun $candidate, string $reason): bool => (int) $candidate->id === (int) $run->id)
            ->andReturn(['ok' => true]);
        $this->app->instance(WorkflowCopilotObservationService::class, $observations);
        $this->app->instance(WorkflowCopilotVisionService::class, $visionService);
        $this->app->instance(WorkflowCopilotRepairService::class, $repairs);
        $this->app->instance(WorkflowExecutionService::class, $execution);

        app(WorkflowCopilotSupervisorService::class)->supervise($session->id);

        $session->refresh();
        $this->assertSame(WorkflowCopilotSession::STATUS_BUDGET_EXHAUSTED, $session->status);
        $this->assertSame(3, data_get($session->state_json, 'progress_regressions'));
        $stop = $session->events()
            ->where('event_type', 'repair.no_progress')
            ->latest('sequence')
            ->firstOrFail();
        $this->assertSame('goal_progress_regressed', data_get($stop->payload_json, 'reason_code'));
        $this->assertSame(3, data_get($stop->payload_json, 'progress_regressions'));
        $this->assertStringContainsString('Zielfortschritt', (string) $stop->message);
        $this->assertStringNotContainsString('Checkpoint-Reparaturen', (string) $stop->message);
        $this->assertDatabaseHas('workflow_copilot_events', [
            'workflow_copilot_session_id' => $session->id,
            'event_type' => 'repair.progress_regressed',
        ]);
    }

    public function test_missing_goal_progress_changes_neither_counter_nor_stored_progress(): void
    {
        [$session] = $this->failedCheckpointSession(
            ['max_progress_regressions' => 3],
            ['last_goal_progress' => 0.8, 'progress_regressions' => 3],
        );
        $vision = $this->visionResult(0.2);
        unset($vision['goal_progress']);
        $this->mockCheckpointServices($vision);

        app(WorkflowCopilotSupervisorService::class)->supervise($session->id);

        $session->refresh();
        $this->assertSame(0.8, data_get($session->state_json, 'last_goal_progress'));
        $this->assertSame(3, data_get($session->state_json, 'progress_regressions'));
        $this->assertNotSame(WorkflowCopilotSession::STATUS_BUDGET_EXHAUSTED, $session->status);
        $this->assertDatabaseMissing('workflow_copilot_events', [
            'workflow_copilot_session_id' => $session->id,
            'event_type' => 'repair.progress_regressed',
        ]);
        $this->assertDatabaseMissing('workflow_copilot_events', [
            'workflow_copilot_session_id' => $session->id,
            'event_type' => 'repair.no_progress',
        ]);
    }

    public function test_non_numeric_goal_progress_changes_neither_counter_nor_stored_progress(): void
    {
        [$session] = $this->failedCheckpointSession(
            ['max_progress_regressions' => 3],
            ['last_goal_progress' => 0.8, 'progress_regressions' => 3],
        );
        $vision = $this->visionResult(0.2);
        $vision['goal_progress'] = 'unbekannt';
        $this->mockCheckpointServices($vision);

        app(WorkflowCopilotSupervisorService::class)->supervise($session->id);

        $session->refresh();
        $this->assertSame(0.8, data_get($session->state_json, 'last_goal_progress'));
        $this->assertSame(3, data_get($session->state_json, 'progress_regressions'));
        $this->assertNotSame(WorkflowCopilotSession::STATUS_BUDGET_EXHAUSTED, $session->status);
        $this->assertDatabaseMissing('workflow_copilot_events', [
            'workflow_copilot_session_id' => $session->id,
            'event_type' => 'repair.progress_regressed',
        ]);
    }

    public function test_rising_goal_progress_does_not_count_as_regression(): void
    {
        [$session] = $this->failedCheckpointSession(
            ['max_progress_regressions' => 3],
            ['last_goal_progress' => 0.4],
        );
        $this->mockCheckpointServices($this->visionResult(0.7));

        app(WorkflowCopilotSupervisorService::class)->supervise($session->id);

        $session->refresh();
        $this->assertSame(0.7, data_get($session->state_json, 'last_goal_progress'));
        $this->assertSame(0, data_get($session->state_json, 'progress_regressions'));
        $this->assertDatabaseMissing('workflow_copilot_events', [
            'workflow_copilot_session_id' => $session->id,
            'event_type' => 'repair.progress_regressed',
        ]);
    }

    public function test_progress_inside_tolerance_is_not_reported_as_regression(): void
    {
        [$session] = $this->failedCheckpointSession(
            ['max_progress_regressions' => 3],
            ['last_goal_progress' => 0.5],
        );
        $this->mockCheckpointServices($this->visionResult(0.46));

        app(WorkflowCopilotSupervisorService::class)->supervise($session->id);

        $session->refresh();
        $this->assertSame(0.46, data_get($session->state_json, 'last_goal_progress'));
        $this->assertSame(0, data_get($session->state_json, 'progress_regressions'));
        $this->assertDatabaseMissing('workflow_copilot_events', [
            'workflow_copilot_session_id' => $session->id,
            'event_type' => 'repair.progress_regressed',
        ]);
    }

    public function test_pause_without_trusted_element_reference_asks_a_question_with_candidates(): void
    {
        [$session, $run] = $this->failedCheckpointSession();
        $this->mockCheckpointServices($this->visionResult(0.4));

        app(WorkflowCopilotSupervisorService::class)->supervise($session->id);

        $session->refresh();
        $this->assertSame(WorkflowCopilotSession::STATUS_PAUSED, $session->status);
        $event = $session->events()->where('event_type', 'assistance.requested')->firstOrFail();
        $question = (string) data_get($event->payload_json, 'question');
        $this->assertSame($question, (string) $event->message);
        $this->assertStringNotContainsString("\n", $question);
        $this->assertStringContainsString('Login klicken', $question);
        $this->assertStringContainsString('Login', $question);
        $this->assertStringEndsWith('?', $question);
        $this->assertStringContainsString('Kein vertrauenswuerdiges Element', (string) data_get($event->payload_json, 'reason'));
        $this->assertSame((int) $run->id, (int) data_get($event->payload_json, 'workflow_run_id'));
        $this->assertSame('login-click', data_get($event->payload_json, 'task_key'));
        $this->assertSame('Login klicken', data_get($event->payload_json, 'task_title'));
        $this->assertSame('Login', data_get($event->payload_json, 'workflow_step_name'));
        $this->assertSame($this->screenshotArtifactId, data_get($event->payload_json, 'screenshot_artifact_id'));

        $candidates = data_get($event->payload_json, 'element_candidates');
        $this->assertIsArray($candidates);
        $this->assertCount(5, $candidates);
        $this->assertSame('el_submit', data_get($candidates, '0.element_ref'));
        $this->assertSame('Anmelden', data_get($candidates, '0.label'));
        $this->assertSame('button[type="submit"]', data_get($candidates, '0.selector_candidate'));
        $this->assertTrue((bool) data_get($candidates, '0.vision_relevant'));
        $this->assertFalse((bool) data_get($candidates, '1.vision_relevant'));

        // Die bestehende Pausenlogik bleibt unveraendert bestehen.
        $this->assertDatabaseHas('workflow_copilot_events', [
            'workflow_copilot_session_id' => $session->id,
            'event_type' => 'repair.paused',
        ]);
    }

    public function test_assistance_question_names_the_missing_candidates_when_the_dom_is_empty(): void
    {
        [$session] = $this->failedCheckpointSession();
        $observation = $this->observation();
        $observation['interaction_map'] = [];
        $observations = Mockery::mock(WorkflowCopilotObservationService::class);
        $observations->shouldReceive('observe')->once()->andReturn($observation);
        $visionService = Mockery::mock(WorkflowCopilotVisionService::class);
        $visionService->shouldReceive('analyze')->once()->andReturn($this->visionResult(0.4));
        $repairs = Mockery::mock(WorkflowCopilotRepairService::class);
        $repairs->shouldReceive('plan')->once()->andReturn($this->pausePlan());
        $execution = Mockery::mock(WorkflowExecutionService::class);
        $this->app->instance(WorkflowCopilotObservationService::class, $observations);
        $this->app->instance(WorkflowCopilotVisionService::class, $visionService);
        $this->app->instance(WorkflowCopilotRepairService::class, $repairs);
        $this->app->instance(WorkflowExecutionService::class, $execution);

        app(WorkflowCopilotSupervisorService::class)->supervise($session->id);

        $event = $session->fresh()->events()->where('event_type', 'assistance.requested')->firstOrFail();
        $this->assertSame([], data_get($event->payload_json, 'element_candidates'));
        $this->assertStringContainsString('kein verwertbarer Kandidat', (string) $event->message);
    }

    /**
     * @param  array<string, mixed>  $budget
     * @param  array<string, mixed>  $state
     * @return array{0: WorkflowCopilotSession, 1: WorkflowRun}
     */
    private function failedCheckpointSession(array $budget = [], array $state = []): array
    {
        $workflow = Workflow::query()->create([
            'name' => 'Copilot Fortschritt',
            'slug' => 'copilot-fortschritt-'.str()->random(8),
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
        $sessions = app(WorkflowCopilotSessionService::class);
        $session = $sessions->start($workflow, [
            'goal' => 'Login erfolgreich abschliessen.',
            'budget' => $budget,
        ]);
        $checkpoint = [
            'id' => 'runtime-progress-1',
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
        ];
        $run = WorkflowRun::query()->create([
            'run_uuid' => (string) str()->uuid(),
            'workflow_id' => $workflow->id,
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
        $this->screenshotArtifactId = (int) WorkflowRunArtifact::query()->create([
            'workflow_id' => $workflow->id,
            'workflow_run_id' => $run->id,
            'workflow_step_id' => $step->id,
            'workflow_step_run_id' => $stepRun->id,
            'phase' => 'after',
            'artifact_type' => 'screenshot',
            'browser_window' => 'main',
            'storage_disk' => 'local',
            'storage_path' => 'workflow-runs/1/screenshot.png',
            'status' => 'success',
            'metadata_json' => [],
        ])->getKey();
        $session = $sessions->attachRun($session, $run);

        if ($state !== []) {
            $session->forceFill([
                'state_json' => array_replace(is_array($session->state_json) ? $session->state_json : [], $state),
            ])->save();
        }

        return [$session->fresh() ?? $session, $run];
    }

    /** @param array<string, mixed> $vision */
    private function mockCheckpointServices(array $vision): void
    {
        $observations = Mockery::mock(WorkflowCopilotObservationService::class);
        $observations->shouldReceive('observe')->once()->andReturn($this->observation());
        $visionService = Mockery::mock(WorkflowCopilotVisionService::class);
        $visionService->shouldReceive('analyze')->once()->andReturn($vision);
        $repairs = Mockery::mock(WorkflowCopilotRepairService::class);
        $repairs->shouldReceive('plan')->once()->andReturn($this->pausePlan());
        $execution = Mockery::mock(WorkflowExecutionService::class);
        $this->app->instance(WorkflowCopilotObservationService::class, $observations);
        $this->app->instance(WorkflowCopilotVisionService::class, $visionService);
        $this->app->instance(WorkflowCopilotRepairService::class, $repairs);
        $this->app->instance(WorkflowExecutionService::class, $execution);
    }

    /** @return array<string, mixed> */
    private function pausePlan(): array
    {
        return [
            'action' => 'pause',
            'task_key' => 'login-click',
            'reason' => 'Kein vertrauenswuerdiges Element im DOM konnte der Task zugeordnet werden.',
        ];
    }

    /** @return array<string, mixed> */
    private function observation(): array
    {
        $extra = [];

        foreach (['abbrechen', 'hilfe', 'impressum', 'datenschutz', 'kontakt', 'suche'] as $index => $label) {
            $extra[] = [
                'element_ref' => 'el_'.$index,
                'tag' => 'a',
                'text' => ucfirst($label),
                'visible' => true,
                'enabled' => true,
                'selector_candidates' => ['a.'.$label],
                'window' => 'main',
            ];
        }

        return [
            'state_signature' => 'login-page-v1',
            'page' => ['url' => 'https://example.test/login', 'title' => 'Login', 'state' => 'login_form'],
            'dom' => ['ui_state' => 'login_form', 'visible_text_excerpt' => 'Anmelden'],
            'interaction_map' => array_merge($extra, [[
                'element_ref' => 'el_submit',
                'tag' => 'button',
                'text' => 'Anmelden',
                'aria' => 'Konto',
                'visible' => true,
                'enabled' => true,
                'selector_candidates' => ['button[type="submit"]'],
                'window' => 'main',
            ]]),
            'screenshot_url' => '/workflow-runs/1/artifacts/'.$this->screenshotArtifactId,
            'screenshot' => ['artifact_id' => $this->screenshotArtifactId, 'available_for_vision' => true],
            'screenshot_artifact_id' => $this->screenshotArtifactId,
            'sensitive_fields_removed' => 0,
            'evidence_sufficient' => true,
        ];
    }

    /** @return array<string, mixed> */
    private function visionResult(float $goalProgress): array
    {
        return [
            'page_type' => 'login',
            'ui_state' => 'login_form',
            'goal_progress' => $goalProgress,
            'blockers' => ['Konfigurierter Selektor findet den sichtbaren Button nicht.'],
            'relevant_elements' => [['element_ref' => 'el_submit', 'confidence' => 0.98]],
            'confidence' => 0.98,
            'suggested_task_actions' => [],
            'needs_screenshot' => false,
            'verdict' => 'pause',
            'model' => 'test/vision',
            'attempts' => [],
        ];
    }
}
