<?php

namespace Tests\Feature;

use App\Exceptions\WorkflowRunConflictException;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use App\Models\WorkflowStepRun;
use App\Models\WorkflowStudioCheckpoint;
use App\Models\WorkflowStudioSession;
use App\Services\Workflows\WorkflowRunContextStore;
use App\Services\Workflows\WorkflowRunCoordinationService;
use App\Services\Workflows\WorkflowStudioCheckpointService;
use App\Services\Workflows\WorkflowStudioSessionService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class WorkflowStudioRestoreConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_restore_uses_fresh_rows_and_commits_steps_run_session_and_event_together(): void
    {
        [$session, $run, $stepRun, $checkpoint] = $this->fixture();
        $staleRun = $run->fresh();
        $run->update(['context_json' => ['value' => 'changed'], 'result_json' => ['new' => true]]);

        $restored = app(WorkflowStudioCheckpointService::class)->restore($session, $checkpoint, $staleRun);

        $this->assertSame('paused', $restored->status);
        $this->assertSame('snapshot', $restored->context_json['value']);
        $this->assertTrue($restored->context_json['manual_pause_requested']);
        $this->assertSame([], $restored->result_json);
        $this->assertSame('queued', $stepRun->fresh()->status);
        $this->assertSame('paused', $session->fresh()->status);
        $this->assertSame(1, $session->events()->where('event_type', 'checkpoint.restored')->count());
    }

    public function test_stale_session_cannot_restore_a_run_after_the_active_run_was_replaced(): void
    {
        [$session, $run, $stepRun, $checkpoint] = $this->fixture();
        $replacement = WorkflowRun::query()->create([
            'run_uuid' => (string) str()->uuid(), 'workflow_id' => $session->workflow_id,
            'workflow_studio_session_id' => $session->id, 'status' => 'paused', 'context_json' => ['replacement' => true],
        ]);
        $session->fresh()->update(['active_workflow_run_id' => $replacement->id]);

        try {
            app(WorkflowStudioCheckpointService::class)->restore($session, $checkpoint);
            $this->fail('A stale active run must not be restored.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('aktive Lauf', $exception->getMessage());
        }

        $this->assertSame('waiting', $stepRun->fresh()->status);
        $this->assertSame($replacement->id, $session->fresh()->active_workflow_run_id);
        $this->assertSame(['replacement' => true], $replacement->fresh()->context_json);
        $this->assertSame(0, $session->events()->where('event_type', 'checkpoint.restored')->count());
    }

    public function test_restore_rejects_a_step_that_became_active_after_the_caller_loaded_the_run(): void
    {
        [$session, $run, $stepRun, $checkpoint] = $this->fixture();
        $stepRun->update(['status' => 'running', 'external_run_type' => 'workflow-task', 'external_run_id' => 'new-runtime']);

        try {
            app(WorkflowStudioCheckpointService::class)->restore($session, $checkpoint, $run);
            $this->fail('A fresh active task must prevent restore.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('laufender Task', $exception->getMessage());
        }

        $this->assertSame('running', $stepRun->fresh()->status);
        $this->assertSame('new-runtime', $stepRun->fresh()->external_run_id);
        $this->assertSame('before-restore', $run->fresh()->context_json['value']);
        $this->assertSame(0, $session->events()->where('event_type', 'checkpoint.restored')->count());
    }

    public function test_live_orchestration_claim_prevents_restore_before_a_step_has_started(): void
    {
        [$session, $run, $stepRun, $checkpoint] = $this->fixture();
        $coordination = app(WorkflowRunCoordinationService::class);
        $token = $coordination->acquire($run->id, 'advance');

        try {
            app(WorkflowStudioCheckpointService::class)->restore($session, $checkpoint, $run);
            $this->fail('An in-flight orchestration claim must prevent restore.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('Laufsteuerung', $exception->getMessage());
        }

        $this->assertTrue($coordination->owns($run->id, $token));
        $this->assertSame('waiting', $stepRun->fresh()->status);
        $this->assertSame('before-restore', $run->fresh()->context_json['value']);
    }

    public function test_run_context_conflict_rolls_back_the_prior_step_reset(): void
    {
        [$session, $run, $stepRun, $checkpoint] = $this->fixture();
        $store = Mockery::mock(WorkflowRunContextStore::class)->makePartial();
        $store->shouldReceive('merge')->once()->andThrow(new WorkflowRunConflictException('context_json.value'));
        $this->app->instance(WorkflowRunContextStore::class, $store);

        try {
            app(WorkflowStudioCheckpointService::class)->restore($session, $checkpoint, $run);
            $this->fail('A context conflict must reject the whole restore.');
        } catch (WorkflowRunConflictException) {
            $this->assertSame('waiting', $stepRun->fresh()->status);
        }

        $this->assertSame('before-restore', $run->fresh()->context_json['value']);
        $this->assertSame('waiting', $session->fresh()->status);
        $this->assertSame(0, $session->events()->where('event_type', 'checkpoint.restored')->count());
    }

    public function test_event_failure_rolls_back_steps_run_session_and_inserted_event(): void
    {
        [$session, $run, $stepRun, $checkpoint] = $this->fixture();
        $real = app(WorkflowStudioSessionService::class);
        $events = Mockery::mock(WorkflowStudioSessionService::class);
        $events->shouldReceive('appendEvent')->once()->andReturnUsing(function (...$arguments) use ($real): void {
            $real->appendEvent(...$arguments);
            throw new RuntimeException('Synthetic event failure');
        });
        $this->app->instance(WorkflowStudioSessionService::class, $events);

        try {
            app(WorkflowStudioCheckpointService::class)->restore($session, $checkpoint, $run);
            $this->fail('An event failure must reject the whole restore.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Synthetic event failure', $exception->getMessage());
        }

        $this->assertSame('waiting', $stepRun->fresh()->status);
        $this->assertSame('before-restore', $run->fresh()->context_json['value']);
        $this->assertSame('waiting', $session->fresh()->status);
        $this->assertSame(0, $session->events()->where('event_type', 'checkpoint.restored')->count());
    }

    public function test_foreign_studio_run_is_rejected_even_with_the_same_workflow(): void
    {
        [$session, $run, $stepRun, $checkpoint] = $this->fixture();
        $foreign = app(WorkflowStudioSessionService::class)->open($session->workflow);
        $run->update(['workflow_studio_session_id' => $foreign->id]);

        $this->expectException(DomainException::class);
        app(WorkflowStudioCheckpointService::class)->restore($session, $checkpoint, $run);
    }

    public function test_stale_checkpoint_object_cannot_bypass_fresh_reproducibility_or_cursor_scope(): void
    {
        [$session, $run, $stepRun, $checkpoint] = $this->fixture();
        $checkpoint->fresh()->update(['is_reproducible' => false]);

        try {
            app(WorkflowStudioCheckpointService::class)->restore($session, $checkpoint, $run);
            $this->fail('Fresh checkpoint state must be authoritative.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('nicht reproduzierbar', $exception->getMessage());
        }

        $foreignWorkflow = Workflow::query()->create(['name' => 'Foreign', 'slug' => (string) str()->uuid(), 'trigger_type' => 'manual']);
        $foreignStep = WorkflowStep::query()->create(['workflow_id' => $foreignWorkflow->id, 'name' => 'Foreign', 'type' => 'browser_task', 'action_key' => 'foreign', 'position' => 1]);
        $checkpoint->fresh()->update(['is_reproducible' => true, 'cursor_json' => ['workflow_revision' => 0, 'workflow_step_id' => $foreignStep->id]]);

        try {
            app(WorkflowStudioCheckpointService::class)->restore($session, $checkpoint, $run);
            $this->fail('A foreign workflow cursor must not be restored.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('Cursor', $exception->getMessage());
        }

        $this->assertSame('waiting', $stepRun->fresh()->status);
        $this->assertSame('before-restore', $run->fresh()->context_json['value']);
    }

    /** @return array{WorkflowStudioSession, WorkflowRun, WorkflowStepRun, WorkflowStudioCheckpoint} */
    private function fixture(): array
    {
        config(['app.key' => 'base64:MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=']);
        $workflow = Workflow::query()->create(['name' => 'Restore', 'slug' => (string) str()->uuid(), 'trigger_type' => 'manual', 'copilot_revision' => 0]);
        $step = WorkflowStep::query()->create(['workflow_id' => $workflow->id, 'name' => 'Snapshot', 'type' => 'browser_task', 'action_key' => 'snapshot', 'position' => 1]);
        $session = app(WorkflowStudioSessionService::class)->open($workflow);
        $run = WorkflowRun::query()->create([
            'run_uuid' => (string) str()->uuid(), 'workflow_id' => $workflow->id, 'workflow_studio_session_id' => $session->id,
            'workflow_revision' => 0, 'current_workflow_step_id' => $step->id, 'status' => 'paused', 'context_json' => ['value' => 'snapshot'], 'result_json' => [],
        ]);
        app(WorkflowStudioSessionService::class)->attachRun($session, $run);
        $checkpoint = app(WorkflowStudioCheckpointService::class)->create($session, $run);
        $run->update(['context_json' => ['value' => 'before-restore']]);
        $session->update(['status' => 'waiting']);
        $stepRun = WorkflowStepRun::query()->create(['workflow_run_id' => $run->id, 'workflow_step_id' => $step->id, 'status' => 'waiting', 'external_run_id' => null]);

        return [$session, $run, $stepRun, $checkpoint];
    }
}
