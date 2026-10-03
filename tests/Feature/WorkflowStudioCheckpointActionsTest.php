<?php

namespace Tests\Feature;

use App\Jobs\RunWorkflowJob;
use App\Livewire\Admin\Network\WorkflowStudio;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use App\Models\WorkflowStepRun;
use App\Services\Workflows\WorkflowStudioSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class WorkflowStudioCheckpointActionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_livewire_creates_a_checkpoint_only_for_a_safely_paused_run(): void
    {
        [$component, $session, $run] = $this->fixture();
        $component->set('checkpointName', 'Safe snapshot')->call('createCheckpoint')->assertHasNoErrors()->assertSet('lastActionResult.status', 'paused');
        $checkpoint = $session->checkpoints()->firstOrFail();
        $this->assertSame('Safe snapshot', $checkpoint->name);
        $this->assertSame($checkpoint->id, $component->get('lastActionResult.checkpoint_id'));

        $run->fresh()->update(['status' => 'running']);
        $component->call('createCheckpoint')->assertHasErrors('studio');
        $this->assertSame(1, $session->checkpoints()->count());
    }

    public function test_livewire_restore_confirmation_restores_and_consumes_the_exact_action(): void
    {
        [$component, $session, $run] = $this->fixture();
        $component->call('createCheckpoint')->assertHasNoErrors();
        $checkpoint = $session->checkpoints()->firstOrFail();
        $run->fresh()->update(['context_json' => ['value' => 'changed']]);
        $component->call('restoreCheckpoint', $checkpoint->id)->assertHasNoErrors()->assertSet('lastActionResult.status', 'confirmation_required');
        $confirmation = $component->get('pendingConfirmation.confirmation_id');
        $this->assertSame('changed', $run->fresh()->context_json['value']);

        $component->call('confirmPendingAction')->assertHasNoErrors()->assertSet('lastActionResult.status', 'paused');
        $this->assertSame('snapshot', $run->fresh()->context_json['value']);
        $this->assertNotContains($confirmation, $session->fresh()->state_json['confirmed_action_ids']);
        $this->assertSame(1, $session->events()->where('event_type', 'checkpoint.restored')->count());

        $component->call('restoreCheckpoint', $checkpoint->id, $confirmation, $run->id)->assertSet('lastActionResult.status', 'confirmation_required');
        $this->assertSame(1, $session->events()->where('event_type', 'checkpoint.restored')->count());
    }

    public function test_livewire_branch_confirmation_creates_one_linked_run_without_running_browser_tasks(): void
    {
        Queue::fake();
        [$component, $session, $run] = $this->fixture();
        $component->call('createCheckpoint')->assertHasNoErrors();
        $checkpoint = $session->checkpoints()->firstOrFail();
        $component->call('branchFromCheckpoint', $checkpoint->id)->assertHasNoErrors()->assertSet('lastActionResult.status', 'confirmation_required');
        $confirmation = $component->get('pendingConfirmation.confirmation_id');
        $this->assertSame(1, $session->runs()->count());

        $component->call('confirmPendingAction')->assertHasNoErrors();
        $branched = $session->fresh()->activeRun;
        $this->assertNotSame($run->id, $branched->id);
        $this->assertSame($session->id, $branched->workflow_studio_session_id);
        $this->assertSame($checkpoint->id, $branched->context_json['branched_from_checkpoint_id']);
        $this->assertTrue($branched->context_json['manual_pause_requested']);
        $this->assertSame(0, $branched->stepRuns()->count());
        $this->assertNotContains($confirmation, $session->fresh()->state_json['confirmed_action_ids']);
        $this->assertSame(1, $session->events()->where('event_type', 'checkpoint.branched')->count());
        Queue::assertPushed(RunWorkflowJob::class, 1);
    }

    public function test_changed_target_run_state_requires_a_new_restore_confirmation(): void
    {
        [$component, $session, $run] = $this->fixture();
        $component->call('createCheckpoint');
        $checkpoint = $session->checkpoints()->firstOrFail();
        $component->call('restoreCheckpoint', $checkpoint->id);
        $oldConfirmation = $component->get('pendingConfirmation.confirmation_id');
        $run->fresh()->update(['context_json' => ['value' => 'new-state']]);

        $component->call('confirmPendingAction')->assertHasNoErrors()->assertSet('lastActionResult.status', 'confirmation_required');
        $this->assertNotSame($oldConfirmation, $component->get('pendingConfirmation.confirmation_id'));
        $this->assertSame('new-state', $run->fresh()->context_json['value']);
        $this->assertSame(0, $session->events()->where('event_type', 'checkpoint.restored')->count());
        $this->assertNotContains($oldConfirmation, $session->fresh()->state_json['confirmed_action_ids']);
    }

    public function test_changed_checkpoint_cursor_requires_a_new_branch_confirmation(): void
    {
        Queue::fake();
        [$component, $session] = $this->fixture();
        $component->call('createCheckpoint');
        $checkpoint = $session->checkpoints()->firstOrFail();
        $component->call('branchFromCheckpoint', $checkpoint->id);
        $oldConfirmation = $component->get('pendingConfirmation.confirmation_id');
        $cursor = $checkpoint->cursor_json;
        $cursor['task_key'] = 'changed-task';
        $checkpoint->update(['cursor_json' => $cursor]);

        $component->call('confirmPendingAction')->assertHasNoErrors()->assertSet('lastActionResult.status', 'confirmation_required');
        $this->assertNotSame($oldConfirmation, $component->get('pendingConfirmation.confirmation_id'));
        $this->assertSame(1, $session->runs()->count());
        $this->assertNotContains($oldConfirmation, $session->fresh()->state_json['confirmed_action_ids']);
        Queue::assertNotPushed(RunWorkflowJob::class);
    }

    public function test_a_task_started_while_confirmation_was_open_blocks_the_restore(): void
    {
        [$component, $session, $run, $step] = $this->fixture();
        $component->call('createCheckpoint');
        $checkpoint = $session->checkpoints()->firstOrFail();
        $component->call('restoreCheckpoint', $checkpoint->id);
        $confirmation = $component->get('pendingConfirmation.confirmation_id');
        $stepRun = WorkflowStepRun::query()->create(['workflow_run_id' => $run->id, 'workflow_step_id' => $step->id, 'status' => 'waiting', 'external_run_id' => 'active-runtime']);

        $component->call('confirmPendingAction')->assertHasErrors('studio')->assertSet('lastActionResult.status', 'failed');
        $this->assertSame('active-runtime', $stepRun->fresh()->external_run_id);
        $this->assertSame('waiting', $stepRun->fresh()->status);
        $this->assertSame(0, $session->events()->where('event_type', 'checkpoint.restored')->count());
        $this->assertNotContains($confirmation, $session->fresh()->state_json['confirmed_action_ids']);
    }

    private function fixture(): array
    {
        config(['app.key' => 'base64:MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=']);
        $admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $workflow = Workflow::query()->create(['name' => 'Checkpoint UI', 'slug' => (string) str()->uuid(), 'trigger_type' => 'manual', 'is_active' => true, 'copilot_revision' => 0]);
        $step = $workflow->steps()->create([
            'name' => 'Safe wait', 'type' => WorkflowStep::TYPE_BROWSER_TASK, 'action_key' => 'safe-wait', 'position' => 1, 'is_enabled' => true,
            'config_json' => ['tasks' => [['key' => 'wait', 'task_key' => 'wait.seconds', 'title' => 'Wait', 'value' => 0]]],
        ]);
        $session = app(WorkflowStudioSessionService::class)->open($workflow, $admin, 'manual', 'ask_critical');
        $run = WorkflowRun::query()->create([
            'run_uuid' => (string) str()->uuid(), 'workflow_id' => $workflow->id, 'workflow_studio_session_id' => $session->id,
            'workflow_revision' => 0, 'current_workflow_step_id' => $step->id, 'status' => 'paused', 'context_json' => ['value' => 'snapshot'], 'result_json' => [],
        ]);
        app(WorkflowStudioSessionService::class)->attachRun($session, $run);
        $component = Livewire::actingAs($admin)->test(WorkflowStudio::class, ['workflow' => $workflow, 'hosted' => true, 'studioSessionId' => $session->id, 'runId' => $run->id]);

        return [$component, $session, $run, $step];
    }
}
