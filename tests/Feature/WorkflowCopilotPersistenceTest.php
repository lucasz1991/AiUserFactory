<?php

namespace Tests\Feature;

use App\Exceptions\WorkflowRevisionConflictException;
use App\Models\Workflow;
use App\Models\WorkflowCopilotSession;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use App\Services\Workflows\WorkflowCopilotSessionService;
use App\Services\Workflows\WorkflowRevisionService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class WorkflowCopilotPersistenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=']);
    }

    public function test_session_starts_with_system_target_defaults_lock_and_initial_event(): void
    {
        $workflow = $this->workflow('copilot-session');
        $service = app(WorkflowCopilotSessionService::class);
        $session = $service->start($workflow, [
            'goal' => 'Login-Workflow erfolgreich abschliessen.',
            'success_criteria' => [['type' => 'url', 'value' => '/dashboard']],
            'workflow_inputs' => ['email' => 'test@example.test'],
        ]);

        $this->assertSame(WorkflowCopilotSession::STATUS_RUNNING, $session->status);
        $this->assertSame(WorkflowCopilotSession::EXECUTION_TARGET_SYSTEM, $session->execution_target);
        $this->assertSame(90, data_get($session->budget_json, 'max_minutes'));
        $this->assertSame(15, data_get($session->budget_json, 'max_repair_iterations'));
        $this->assertSame(1, $session->last_event_sequence);
        $this->assertSame('session.started', $session->events()->firstOrFail()->event_type);

        $workflow->refresh();
        $this->assertSame($session->id, $workflow->active_workflow_copilot_session_id);
        $this->assertTrue($workflow->has_active_copilot_lock);
        $this->assertTrue($workflow->is_edit_locked);
        $this->assertSame('unverified', $workflow->copilot_verification_status);

        $this->expectException(DomainException::class);
        $service->start($workflow);
    }

    public function test_client_controller_target_is_rejected_before_a_session_is_written(): void
    {
        $workflow = $this->workflow('client-target-rejected');

        try {
            app(WorkflowCopilotSessionService::class)->start($workflow, [
                'execution_target' => 'client_controller',
            ]);
            $this->fail('client_controller must not be accepted for a Copilot repair session.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('execution_target=system', $exception->getMessage());
        }

        $this->assertDatabaseCount('workflow_copilot_sessions', 0);
        $this->assertNull($workflow->fresh()->active_workflow_copilot_session_id);
    }

    public function test_events_are_sequenced_append_only_and_status_transitions_control_the_lock(): void
    {
        $workflow = $this->workflow('copilot-events');
        $service = app(WorkflowCopilotSessionService::class);
        $session = $service->start($workflow);
        $event = $service->appendEvent($session, 'observation.captured', 'Screenshot und DOM wurden erfasst.');

        $this->assertSame(2, $event->sequence);
        $this->assertSame([1, 2], $session->events()->pluck('sequence')->all());
        $this->assertSame([2], $service->eventsAfter($session, 1)->pluck('sequence')->all());

        try {
            $event->forceFill(['message' => 'Manipuliert'])->save();
            $this->fail('Copilot events must be immutable.');
        } catch (LogicException) {
            $this->assertSame('Screenshot und DOM wurden erfasst.', $event->fresh()->message);
        }

        $session = $service->updateState($session, [
            'active_repair_plan' => ['selector' => '#old'],
            'active_instructions' => ['one', 'two'],
        ]);
        $session = $service->updateState($session, [
            'active_repair_plan' => [],
            'active_instructions' => ['replacement'],
        ]);
        $this->assertSame([], data_get($session->state_json, 'active_repair_plan'));
        $this->assertSame(['replacement'], data_get($session->state_json, 'active_instructions'));

        $session = $service->pause($session);
        $this->assertSame(WorkflowCopilotSession::STATUS_PAUSED, $session->status);
        $session = $service->resume($session);
        $session = $service->transition(
            $session,
            WorkflowCopilotSession::STATUS_BUDGET_EXHAUSTED,
            'budget_exhausted',
        );
        $this->assertSame($session->id, $workflow->fresh()->active_workflow_copilot_session_id);
        $this->assertSame('unverified', $workflow->fresh()->copilot_verification_status);

        $session = $service->stop($session);
        $this->assertSame(WorkflowCopilotSession::STATUS_STOPPED, $session->status);
        $this->assertNull($workflow->fresh()->active_workflow_copilot_session_id);

        try {
            $service->instruction($session, 'Darf nach dem Stop nicht mehr angenommen werden.');
            $this->fail('A stopped Copilot session must reject new instructions.');
        } catch (DomainException) {
            $this->assertFalse($session->fresh()->events()->where('event_type', 'instruction.received')->exists());
        }
    }

    public function test_runs_attempts_checkpoints_and_rewind_are_persisted_without_changing_normal_runs(): void
    {
        $workflow = $this->workflow('copilot-checkpoints');
        $step = $this->step($workflow, 'Login', '.old-login');
        $normalRun = WorkflowRun::query()->create([
            'run_uuid' => (string) str()->uuid(),
            'workflow_id' => $workflow->id,
            'status' => 'queued',
            'context_json' => [],
        ]);
        $this->assertNull($normalRun->workflow_copilot_session_id);
        $this->assertNull($normalRun->workflow_revision);

        $service = app(WorkflowCopilotSessionService::class);
        $session = $service->start($workflow);
        $session = $service->attachRun($session, $normalRun);
        $attempt = $service->beginTaskAttempt($session, [
            'workflow_run_id' => $normalRun->id,
            'workflow_step_id' => $step->id,
            'task_key' => 'login-click',
            'task_title' => 'Login klicken',
            'task_definition_json' => ['task_key' => 'browser.click', 'selector' => '.old-login'],
        ]);
        $attempt = $service->finishTaskAttempt(
            $attempt,
            'failed',
            ['ok' => false],
            'Element nicht gefunden.',
        );
        $checkpoint = $service->createCheckpoint($session, [
            'workflow_run_id' => $normalRun->id,
            'workflow_step_id' => $step->id,
            'workflow_task_attempt_id' => $attempt->id,
            'task_key' => 'login-click',
            'cursor_json' => ['step_id' => $step->id, 'task_key' => 'login-click'],
            'context_json' => ['workflow_variables' => ['email' => 'redacted']],
            'state_signature' => 'login-screen-v1',
            'side_effect_ledger_json' => [['type' => 'form_submit', 'reversible' => false]],
        ]);
        $session = $service->rewind($session, $checkpoint->id, 'Selektor repariert; Task erneut testen.');

        $this->assertSame(1, $attempt->attempt_number);
        $this->assertSame('failed', $attempt->status);
        $this->assertSame(1, $checkpoint->sequence);
        $this->assertTrue($checkpoint->is_reproducible);
        $this->assertSame(WorkflowCopilotSession::STATUS_REPAIRING, $session->status);
        $this->assertSame($checkpoint->id, data_get($session->state_json, 'pending_control.checkpoint_id'));
        $this->assertSame($session->id, $normalRun->fresh()->workflow_copilot_session_id);
        $this->assertSame(0, $normalRun->fresh()->workflow_revision);
        $this->assertTrue($session->events()->where('event_type', 'rewind.requested')->exists());
    }

    public function test_revision_service_snapshots_diffs_restores_checks_expected_revision_and_verifies(): void
    {
        $workflow = $this->workflow('copilot-revisions');
        $step = $this->step($workflow, 'Login', '.old-login');
        $sessions = app(WorkflowCopilotSessionService::class);
        $revisions = app(WorkflowRevisionService::class);
        $session = $sessions->start($workflow);

        $first = $revisions->apply(
            $session,
            0,
            'Login-Selektor an den sichtbaren Button anpassen.',
            function (Workflow $lockedWorkflow): void {
                $step = $lockedWorkflow->steps()->firstOrFail();
                $config = $step->config_json;
                data_set($config, 'tasks.0.selector', 'button[type="submit"]');
                $step->forceFill(['config_json' => $config])->save();
            },
        );

        $this->assertSame(1, $first->revision_number);
        $this->assertSame('.old-login', data_get($first->before_snapshot_json, 'steps.0.config_json.tasks.0.selector'));
        $this->assertSame('button[type="submit"]', data_get($first->after_snapshot_json, 'steps.0.config_json.tasks.0.selector'));
        $this->assertNotEmpty($first->diff_json);
        $this->assertSame(1, $workflow->fresh()->copilot_revision);
        $this->assertSame(1, $session->fresh()->current_revision);

        $second = $revisions->apply(
            $session->fresh(),
            1,
            'Selektor fuer den zweiten Test variieren.',
            function (Workflow $lockedWorkflow): void {
                $step = $lockedWorkflow->steps()->firstOrFail();
                $config = $step->config_json;
                data_set($config, 'tasks.0.selector', '#login-submit');
                $step->forceFill(['config_json' => $config])->save();
            },
        );
        $restored = $revisions->restore(
            $session->fresh(),
            $first,
            $second->revision_number,
            'Auf den nachgewiesen funktionierenden Stand zurueckspringen.',
        );

        $this->assertSame(3, $restored->revision_number);
        $this->assertSame(
            'button[type="submit"]',
            data_get($step->fresh()->config_json, 'tasks.0.selector'),
        );

        try {
            $revisions->apply($session->fresh(), 2, 'Veralteter Schreibversuch.', static function (): void {});
            $this->fail('A stale expected revision must be rejected.');
        } catch (WorkflowRevisionConflictException $exception) {
            $this->assertSame(2, $exception->expectedRevision);
            $this->assertSame(3, $exception->actualRevision);
        }

        $verified = $revisions->markVerified($session->fresh(), 3);
        $this->assertTrue($verified->is_verified);
        $this->assertSame(WorkflowCopilotSession::STATUS_SUCCEEDED, $session->fresh()->status);
        $this->assertNull($workflow->fresh()->active_workflow_copilot_session_id);
        $this->assertSame('verified', $workflow->fresh()->copilot_verification_status);
    }

    public function test_unchanged_workflow_can_be_verified_as_revision_zero(): void
    {
        $workflow = $this->workflow('copilot-baseline-verification');
        $this->step($workflow, 'Only task', '.ready');
        $session = app(WorkflowCopilotSessionService::class)->start($workflow);
        $revision = app(WorkflowRevisionService::class)->markVerified($session, 0);

        $this->assertSame(0, $revision->revision_number);
        $this->assertSame([], $revision->diff_json);
        $this->assertTrue($revision->is_verified);
        $this->assertSame(WorkflowCopilotSession::STATUS_SUCCEEDED, $session->fresh()->status);
        $this->assertNull($workflow->fresh()->active_workflow_copilot_session_id);
    }

    public function test_workflow_mutations_are_frozen_during_the_verification_run(): void
    {
        $workflow = $this->workflow('copilot-frozen-verification');
        $this->step($workflow, 'Only task', '.ready');
        $sessions = app(WorkflowCopilotSessionService::class);
        $session = $sessions->start($workflow);
        $session = $sessions->transition(
            $session,
            WorkflowCopilotSession::STATUS_VERIFYING,
            'verifying',
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Kontrolllaufs');

        app(WorkflowRevisionService::class)->apply(
            $session,
            0,
            'Darf waehrend des Kontrolllaufs nicht gespeichert werden.',
            static function (): void {},
        );
    }

    private function workflow(string $slug): Workflow
    {
        return Workflow::query()->create([
            'name' => str($slug)->replace('-', ' ')->title(),
            'slug' => $slug,
            'description' => '',
            'category' => 'test',
            'is_active' => true,
            'is_locked' => false,
            'trigger_type' => 'manual',
            'settings_json' => [],
        ]);
    }

    private function step(Workflow $workflow, string $name, string $selector): WorkflowStep
    {
        return $workflow->steps()->create([
            'name' => $name,
            'type' => WorkflowStep::TYPE_BROWSER_TASK,
            'action_key' => str($name)->slug(),
          