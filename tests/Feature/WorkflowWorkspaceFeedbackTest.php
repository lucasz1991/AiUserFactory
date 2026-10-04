<?php

namespace Tests\Feature;

use App\Livewire\Admin\Network\WorkflowStudio;
use App\Livewire\Admin\Network\WorkflowStudioTaskEditor;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Services\Workflows\WorkflowRunTaskFeedback;
use App\Services\Workflows\WorkflowStudioSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class WorkflowWorkspaceFeedbackTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $this->actingAs($admin = User::factory()->create(['role' => 'admin', 'status' => true]));
        $workflow = Workflow::create(['name' => 'Workspace QA', 'slug' => 'workspace-qa', 'trigger_type' => 'manual', 'is_active' => true]);
        $step = $workflow->steps()->create([
            'name' => 'Login', 'action_key' => 'login', 'type' => 'browser_task', 'position' => 10, 'is_enabled' => true,
            'config_json' => ['tasks' => [
                ['key' => 'shared-key', 'title' => 'Login prüfen', 'task_key' => 'browser.find_element'],
                ['key' => 'cleanup', 'title' => 'Aufräumen', 'task_key' => 'wait.seconds'],
            ]],
        ]);
        $session = app(WorkflowStudioSessionService::class)->open($workflow, $admin, 'manual', 'ask_critical');
        $run = WorkflowRun::create([
            'workflow_id' => $workflow->id, 'workflow_studio_session_id' => $session->id,
            'run_uuid' => (string) str()->uuid(), 'status' => 'failed', 'current_workflow_step_id' => $step->id,
            'context_json' => ['next_task_key' => 'cleanup'], 'result_json' => [],
        ]);
        $run->stepRuns()->create([
            'workflow_step_id' => $step->id, 'status' => 'failed',
            'result_json' => ['tasks' => [['key' => 'shared-key', 'status' => 'failed', 'message' => 'Eingabefeld nicht gefunden.']]],
        ]);
        app(WorkflowStudioSessionService::class)->attachRun($session, $run);

        return [$workflow, $step, $session, $run];
    }

    public function test_failed_run_focuses_the_responsible_task_not_the_advanced_cursor_and_does_not_steal_focus_on_poll(): void
    {
        [$workflow, $step, $session, $run] = $this->fixture();
        $studio = Livewire::test(WorkflowStudio::class, [
            'workflow' => $workflow, 'hosted' => true, 'studioSessionId' => $session->id, 'runId' => $run->id,
        ])->assertSet('selectedStepId', (string) $step->id)->assertSet('selectedTaskKey', 'shared-key');

        $studio->call('selectTask', $step->id, 'cleanup')->call('refreshStudio')->assertSet('selectedTaskKey', 'cleanup');
        $editor = Livewire::test(WorkflowStudioTaskEditor::class, ['workflow' => $workflow, 'studioSessionId' => $session->id]);
        $editor->assertSee('Eingabefeld nicht gefunden.')->assertSeeHtml('data-task-failed="true"');
    }

    public function test_duplicate_task_keys_resolve_to_the_failed_step_and_not_the_first_matching_list(): void
    {
        [$workflow, $step, $session, $run] = $this->fixture();
        $other = $workflow->steps()->create([
            'name' => 'Andere Liste', 'action_key' => 'other', 'type' => 'browser_task', 'position' => 20,
            'config_json' => $step->config_json, 'is_enabled' => true,
        ]);
        $run->stepRuns()->create([
            'workflow_step_id' => $other->id, 'status' => 'failed',
            'result_json' => ['tasks' => [['key' => 'shared-key', 'status' => 'failed', 'message' => 'Letzter Fehler']]],
        ]);
        $failure = app(WorkflowRunTaskFeedback::class)->failure($workflow, $run);
        $this->assertSame($other->id, $failure['step_id']);
        $this->assertSame('Letzter Fehler', $failure['message']);
    }

    public function test_a_running_test_transition_to_timeout_focuses_the_failed_task_and_uses_the_runner_message(): void
    {
        [$workflow, $step, $session, $run] = $this->fixture();
        $run->update(['status' => 'running']);
        $studio = Livewire::test(WorkflowStudio::class, ['workflow' => $workflow, 'studioSessionId' => $session->id])
            ->assertSet('selectedTaskKey', 'cleanup');
        $run->stepRuns()->first()->update([
            'status' => 'timed_out',
            'result_json' => ['tasks' => [['key' => 'shared-key', 'status' => 'timeout', 'statusMessage' => 'Ziel nicht rechtzeitig geladen.']]],
        ]);
        $run->update(['status' => 'timed_out']);
        $studio->call('refreshStudio')->assertSet('selectedTaskKey', 'shared-key')
            ->assertDispatched('workflow-standard-editor-focused', stepId: $step->id, taskKey: 'shared-key', editorInstance: 'studio-'.$session->id);
        $this->assertSame('Ziel nicht rechtzeitig geladen.', app(WorkflowRunTaskFeedback::class)->failure($workflow, $run)['message']);
    }

    public function test_completed_error_branch_and_recovered_task_are_not_execution_errors(): void
    {
        [$workflow, $step, $session, $run] = $this->fixture();
        $run->stepRuns()->first()->update([
            'status' => 'completed',
            'result_json' => ['tasks' => [['key' => 'shared-key', 'status' => 'completed', 'logical_outcome' => 'failed']]],
        ]);
        $feedback = app(WorkflowRunTaskFeedback::class)->tasks($workflow, $run);
        $this->assertFalse($feedback[$step->id]['shared-key']['failed']);
        $this->assertNull(app(WorkflowRunTaskFeedback::class)->failure($workflow, $run));
        $run->update(['status' => 'completed']);
        $this->assertNull(app(WorkflowRunTaskFeedback::class)->failure($workflow, $run));
    }

    public function test_infrastructure_failure_without_task_evidence_does_not_guess_a_task(): void
    {
        [$workflow, $step, $session, $run] = $this->fixture();
        $run->stepRuns()->update(['result_json' => json_encode(['tasks' => []])]);
        $this->assertNull(app(WorkflowRunTaskFeedback::class)->failure($workflow, $run));
    }

    public function test_workspace_selection_is_session_scoped_and_does_not_execute_tasks(): void
    {
        [$workflow, $step, $session, $run] = $this->fixture();
        $studio = Livewire::test(WorkflowStudio::class, ['workflow' => $workflow, 'studioSessionId' => $session->id]);
        $studio->call('selectWorkspaceTask', $session->id + 1, $step->id, 'cleanup')->assertSet('selectedTaskKey', 'shared-key');
        $studio->call('selectWorkspaceTask', $session->id, $step->id, 'cleanup')->assertSet('selectedTaskKey', 'cleanup');
        Livewire::test(WorkflowStudioTaskEditor::class, ['workflow' => $workflow, 'studioSessionId' => $session->id])
            ->call('selectTaskForTest', $step->id, 'cleanup')
            ->assertDispatched('workflow-workspace-task-selected', studioSessionId: $session->id, stepId: $step->id, taskKey: 'cleanup');
        $this->assertSame('failed', $run->fresh()->status);
        $this->assertSame(1, $workflow->runs()->count());
    }
}
