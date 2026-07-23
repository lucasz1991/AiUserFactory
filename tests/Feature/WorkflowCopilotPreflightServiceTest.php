<?php

namespace Tests\Feature;

use App\Jobs\RunWorkflowJob;
use App\Models\Workflow;
use App\Models\WorkflowRevision;
use App\Models\WorkflowRevisionEvidence;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use App\Models\WorkflowStudioRevision;
use App\Services\Ai\AiConnectionService;
use App\Services\Workflows\WorkflowCopilotPreflightService;
use App\Services\Workflows\WorkflowCopilotPromptContextService;
use App\Services\Workflows\WorkflowCopilotSessionService;
use App\Services\Workflows\WorkflowCopilotSupervisorService;
use App\Services\Workflows\WorkflowRevisionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class WorkflowCopilotPreflightServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=']);
    }

    public function test_supervisor_restores_proven_task_configuration_before_starting_the_browser_run(): void
    {
        Queue::fake();
        [$workflow, $step] = $this->workflowWithTask('browser.click', [
            'selector' => '[data-testid="login"]',
            'element_selector' => '[data-testid="login"]',
            'on_error' => ['type' => 'step', 'step' => 'next'],
        ]);
        $workflow->forceFill(['copilot_revision' => 1])->save();
        $goodSnapshot = app(WorkflowRevisionService::class)->snapshot($workflow->fresh(['steps']));

        $this->updateTask($step, [
            'selector' => '#volatile-999',
            'element_selector' => '#volatile-999',
            'on_error' => ['type' => 'fail'],
        ]);
        $workflow->forceFill(['copilot_revision' => 2])->save();
        $failedSnapshot = app(WorkflowRevisionService::class)->snapshot($workflow->fresh(['steps']));
        $failedRun = WorkflowRun::query()->create([
            'run_uuid' => fake()->uuid(),
            'workflow_id' => $workflow->id,
            'workflow_revision' => 2,
            'status' => 'failed',
            'requested_by' => 'workflow-copilot',
            'queued_at' => now()->subMinute(),
            'started_at' => now()->subMinute(),
            'finished_at' => now()->subSeconds(30),
            'context_json' => [],
            'result_json' => [],
            'error_message' => 'Selector #volatile-999 wurde nach 30 Sekunden nicht gefunden.',
        ]);
        $session = app(WorkflowCopilotSessionService::class)->start($workflow->fresh(), [
            'goal' => 'Login sicher abschliessen.',
            'success_criteria' => ['Loginbereich ist sichtbar'],
        ]);
        WorkflowRevision::query()->create([
            'workflow_copilot_session_id' => $session->id,
            'workflow_id' => $workflow->id,
            'revision_number' => 1,
            'parent_revision_number' => null,
            'actor' => 'user',
            'reason' => 'Nachweislich funktionierender Selector.',
            'before_snapshot_json' => $goodSnapshot,
            'after_snapshot_json' => $goodSnapshot,
            'diff_json' => [],
            'is_verified' => true,
            'verified_at' => now()->subDay(),
        ]);
        WorkflowStudioRevision::query()->create([
            'workflow_id' => $workflow->id,
            'revision_number' => 2,
            'parent_revision_number' => 1,
            'actor' => 'copilot',
            'reason' => 'Nicht erfolgreiche Selector-Aenderung.',
            'before_snapshot_json' => $goodSnapshot,
            'after_snapshot_json' => $failedSnapshot,
            'diff_json' => app(WorkflowRevisionService::class)->diffSnapshots($goodSnapshot, $failedSnapshot),
            'is_verified' => false,
        ]);
        $this->evidence($workflow, $step, 1, true, 'success', 'continue', null, null);
        $this->evidence(
            $workflow,
            $step,
            2,
            false,
            'timeout',
            'fail',
            hash('sha256', 'selector-timeout'),
            $failedRun,
        );

        app(WorkflowCopilotSupervisorService::class)->supervise($session->id);

        $workflow->refresh();
        $session->refresh();
        $task = $workflow->steps()->firstOrFail()->task_cards[0];
        $this->assertSame(3, $workflow->copilot_revision);
        $this->assertSame('[data-testid="login"]', $task['selector']);
        $this->assertSame('[data-testid="login"]', $task['element_selector']);
        $this->assertSame(['type' => 'step', 'step' => 'next'], $task['on_error']);
        $this->assertNotNull($session->active_workflow_run_id);
        $this->assertSame(3, $session->activeRun()->firstOrFail()->workflow_revision);
        $this->assertSame(1, data_get($session->state_json, 'history_preflight.applied_repair_count'));
        $this->assertContains('selector', data_get($session->state_json, 'history_preflight.applied_repairs.0.change_fields'));
        $this->assertContains('on_error', data_get($session->state_json, 'history_preflight.applied_repairs.0.change_fields'));
        $this->assertDatabaseHas('workflow_revisions', [
            'workflow_id' => $workflow->id,
            'revision_number' => 3,
            'actor' => 'copilot-preflight',
        ]);
        $eventSequences = $session->events()
            ->whereIn('event_type', ['preflight.history_analyzed', 'preflight.repair_applied', 'run.started'])
            ->pluck('sequence', 'event_type');
        $this->assertLessThan($eventSequences['preflight.repair_applied'], $eventSequences['preflight.history_analyzed']);
        $this->assertLessThan($eventSequences['run.started'], $eventSequences['preflight.repair_applied']);
        Queue::assertPushed(RunWorkflowJob::class, 1);

        $context = app(WorkflowCopilotPromptContextService::class)->forWorkflow(
            $workflow->fresh(['steps']),
            $session->fresh(),
        );
        $this->assertSame(3, data_get($context, 'revision_learning.latest_preflight.revision_after'));
        $this->assertContains('studio', collect(data_get($context, 'revision_learning.revision_history'))->pluck('source'));
        $this->assertContains('copilot', collect(data_get($context, 'revision_learning.revision_history'))->pluck('source'));
    }

    public function test_unproven_offline_plan_cannot_turn_an_explicit_fail_route_into_success(): void
    {
        Queue::fake();
        [$workflow, $step] = $this->workflowWithTask('browser.click', [
            'selector' => '[data-testid="submit"]',
            'element_selector' => '[data-testid="submit"]',
            'on_error' => ['type' => 'fail'],
        ]);
        $workflow->forceFill(['copilot_revision' => 1])->save();
        $session = app(WorkflowCopilotSessionService::class)->start($workflow->fresh(), [
            'goal' => 'Formular ohne terminalen Retry-Fehler absenden.',
            'success_criteria' => ['Folgeseite ist sichtbar'],
        ]);
        $snapshot = app(WorkflowRevisionService::class)->snapshot($workflow->fresh(['steps']));
        WorkflowRevision::query()->create([
            'workflow_copilot_session_id' => $session->id,
            'workflow_id' => $workflow->id,
            'revision_number' => 1,
            'actor' => 'copilot',
            'reason' => 'Fehlgeschlagene terminale Route.',
            'before_snapshot_json' => $snapshot,
            'after_snapshot_json' => $snapshot,
            'diff_json' => [],
            'is_verified' => false,
        ]);
        $this->evidence(
            $workflow,
            $step,
            1,
            false,
            'failed',
            'fail',
            hash('sha256', 'terminal-route'),
            null,
        );
        $ai = Mockery::mock(AiConnectionService::class);
        $ai->shouldNotReceive('json');
        $this->app->instance(AiConnectionService::class, $ai);

        app(WorkflowCopilotSupervisorService::class)->supervise($session->id);

        $workflow->refresh();
        $session->refresh();
        $this->assertSame(1, $workflow->copilot_revision);
        $this->assertSame(['type' => 'fail'], data_get($workflow->steps()->firstOrFail()->task_cards, '0.on_error'));
        $this->assertSame(0, data_get($session->state_json, 'history_preflight.offline_plan.operation_count'));
        $this->assertSame(
            'historical_operation_not_proven',
            data_get($session->state_json, 'history_preflight.offline_plan.rejected_operations.0.reason_code'),
        );
        $this->assertSame(0, data_get($session->state_json, 'history_preflight.planned_repair_count'));
        $this->assertSame(1, $session->activeRun()->firstOrFail()->workflow_revision);
        $this->assertDatabaseMissing('workflow_copilot_events', [
            'workflow_copilot_session_id' => $session->id,
            'event_type' => 'preflight.repair_applied',
        ]);
        Queue::assertPushed(RunWorkflowJob::class, 1);
    }

    public function test_condition_false_branch_without_terminal_route_is_not_treated_as_failure_history(): void
    {
        [$workflow, $step] = $this->workflowWithTask('decision.element_exists', [
            'selector' => '[data-testid="optional-dialog"]',
            'element_selector' => '[data-testid="optional-dialog"]',
            'next' => ['type' => 'end'],
            'on_error' => ['type' => 'step', 'step' => 'next'],
        ]);
        $workflow->forceFill(['copilot_revision' => 1])->save();
        $session = app(WorkflowCopilotSessionService::class)->start($workflow->fresh(), [
            'goal' => 'Optionalen Dialog verzweigt behandeln.',
            'success_criteria' => ['Workflow erreicht das Ende'],
        ]);
        $this->evidence(
            $workflow,
            $step,
            1,
            false,
            'condition_false',
            'continue',
            hash('sha256', 'condition-false'),
            null,
        );

        $result = app(WorkflowCopilotPreflightService::class)->prepare($session);

        $workflow->refresh();
        $this->assertTrue($result['ready']);
        $this->assertNull($result['revision']);
        $this->assertSame(1, $workflow->copilot_revision);
        $this->assertSame(0, data_get($result, 'report.unresolved_error_pattern_count'));
        $this->assertSame(0, data_get($result, 'report.planned_repair_count'));
        $this->assertDatabaseMissing('workflow_copilot_events', [
            'workflow_copilot_session_id' => $session->id,
            'event_type' => 'preflight.repair_applied',
        ]);
    }

    /** @return array{Workflow, WorkflowStep} */
    protected function workflowWithTask(string $catalogKey, array $taskConfiguration): array
    {
        $workflow = Workflow::query()->create([
            'name' => 'Copilot Preflight '.fake()->unique()->numerify('###'),
            'slug' => fake()->unique()->slug(3),
            'description' => 'Historienbasierte Vorab-Reparatur testen.',
            'category' => 'test',
            'is_active' => true,
            'is_locked' => false,
            'trigger_type' => 'manual',
            'settings_json' => [],
        ]);
        $step = $workflow->steps()->create([
            'name' => 'Browser-Schritt',
            'type' => WorkflowStep::TYPE_BROWSER_TASK,
            'action_key' => 'browser-schritt',
            'position' => 10,
            'is_enabled' => true,
            'config_json' => [
                'tasks' => [[
                    'key' => 'target-task',
                    'task_key' => $catalogKey,
                    'title' => 'Ziel-Task',
                    ...$taskConfiguration,
                ]],
                'routes' => ['success' => ['type' => 'end']],
            ],
        ]);

        return [$workflow, $step];
    }

    protected function updateTask(WorkflowStep $step, array $changes): void
    {
        $config = is_array($step->config_json) ? $step->config_json : [];
        $config['tasks'][0] = array_replace($config['tasks'][0], $changes);
        $step->forceFill(['config_json' => $config])->save();
    }

    protected function evidence(
        Workflow $workflow,
        WorkflowStep $step,
        int $revision,
        bool $successful,
        string $logicalOutcome,
        string $routeDisposition,
        ?string $signature,
        ?WorkflowRun $run,
    ): WorkflowRevisionEvidence {
        return WorkflowRevisionEvidence::query()->create([
            'workflow_id' => $workflow->id,
            'workflow_run_id' => $run?->id,
            'workflow_step_id' => $step->id,
            'workflow_revision' => $revision,
            'task_key' => 'target-task',
            'logical_outcome' => $logicalOutcome,
            'route_disposition' => $routeDisposition,
            'successful' => $successful,
            'error_signature' => $signature,
            'evidence_json' => ['message' => $successful ? 'Task erfolgreich.' : 'Historischer Fehler.'],
            'created_at' => now(),
        ]);
    }
}
