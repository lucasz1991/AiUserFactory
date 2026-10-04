<?php

namespace Tests\Feature;

use App\Livewire\Admin\Network\WorkflowStudio;
use App\Livewire\Admin\Network\WorkflowStudioTaskEditor;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use App\Models\WorkflowStudioSession;
use App\Services\Workflows\WorkflowLiveTaskPresenter;
use App\Services\Workflows\WorkflowRunTaskFeedback;
use App\Services\Workflows\WorkflowStudioSessionService;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WorkflowWorkspaceFeedbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=']);
    }

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

    public function test_workspace_presentation_tracks_database_lifecycle_instead_of_event_status(): void
    {
        [$workflow, $step, $session] = $this->liveWorkspace();
        $definition = $step->config_json;
        $editor = Livewire::test(WorkflowStudioTaskEditor::class, [
            'workflow' => $workflow, 'studioSessionId' => $session->id,
        ])->assertSet('workspaceRunId', null)->assertSet('workspaceRunPresentation', 'edit');
        $run = $this->workspaceRun($workflow, $step, $session, 'queued');
        app(WorkflowStudioSessionService::class)->attachRun($session, $run);

        foreach ([
            'queued' => 'live', 'running' => 'live', 'waiting' => 'live',
            'stop_requested' => 'live', 'unreachable' => 'live', 'paused' => 'edit',
            'completed' => 'result', 'failed' => 'result', 'timed_out' => 'result',
            'cancelled' => 'result', 'stopped' => 'result', 'lost' => 'result', 'budget_exhausted' => 'result',
        ] as $status => $presentation) {
            $run->update(['status' => $status]);
            $editor->call('handleRunStatusChanged', $session->id, $run->id, $status === 'paused' ? 'running' : 'paused')
                ->assertSet('workspaceRunId', $run->id)
                ->assertSet('taskEditRunStatus', $status)
                ->assertSet('workspaceRunPresentation', $presentation)
                ->assertSeeHtml('data-workflow-run-presentation="'.$presentation.'"');
            if ($presentation === 'live') {
                $this->assertSame(1, $this->xpath($editor->html())->query('//*[@data-studio-task-layout and @data-library-expanded="false"]')->count());
            }
            $this->assertSame($status, $run->fresh()->status, 'Changing presentation must not mutate execution status.');
        }

        $run->update(['status' => 'paused']);
        $editor->call('refreshDefinitionAccess', $session->id)->assertSet('workspaceRunPresentation', 'edit');
        $run->update(['status' => 'running']);
        $editor->call('refreshDefinitionAccess', $session->id)->assertSet('workspaceRunPresentation', 'live');

        $session->update(['active_workflow_run_id' => null]);
        $editor->call('handleRunStatusChanged', $session->id, null, 'running')
            ->assertSet('workspaceRunId', null)->assertSet('workspaceRunPresentation', 'edit');
        $this->assertSame($definition, $step->fresh()->config_json);
        $this->assertSame(1, $workflow->runs()->count());
        $this->assertSame(0, (int) $workflow->fresh()->copilot_revision);
    }

    public function test_initial_active_workspace_uses_compact_live_presentation_and_keeps_mutation_locked(): void
    {
        [$workflow, $step, $session] = $this->liveWorkspace();
        $definition = $step->config_json;

        foreach (['queued', 'running', 'waiting', 'stop_requested', 'unreachable'] as $status) {
            $run = $this->workspaceRun($workflow, $step, $session, $status);
            app(WorkflowStudioSessionService::class)->attachRun($session, $run);
            Livewire::test(WorkflowStudioTaskEditor::class, [
                'workflow' => $workflow, 'studioSessionId' => $session->id,
            ])->assertSet('workspaceRunId', $run->id)
                ->assertSet('workspaceRunPresentation', 'live')
                ->assertSet('taskEditReadOnly', true)
                ->assertSeeHtml('data-workflow-run-presentation="live"')
                ->call('toggleStep', $step->id)->assertHasErrors('studioBuilder');
            $this->assertTrue($step->fresh()->is_enabled);
            $this->assertSame($definition, $step->fresh()->config_json);
        }
    }

    public function test_workspace_ignores_foreign_run_events_and_replaces_evidence_after_a_new_run(): void
    {
        [$workflow, $step, $session] = $this->liveWorkspace();
        $run = $this->workspaceRun($workflow, $step, $session, 'running');
        app(WorkflowStudioSessionService::class)->attachRun($session, $run);
        $editor = Livewire::test(WorkflowStudioTaskEditor::class, [
            'workflow' => $workflow, 'studioSessionId' => $session->id,
        ]);
        $editor->call('handleRunStatusChanged', $session->id + 1, $run->id, 'paused')
            ->assertSet('workspaceRunId', $run->id)->assertSet('workspaceRunPresentation', 'live');
        $editor->call('handleRunStatusChanged', $session->id, $run->id + 1, 'paused')
            ->assertSet('workspaceRunId', $run->id)->assertSet('workspaceRunPresentation', 'live');
        $editor->call('handleRunStatusChanged', $session->id, null, 'paused')
            ->assertSet('workspaceRunId', $run->id)->assertSet('workspaceRunPresentation', 'live');

        $run->update(['status' => 'completed']);
        $editor->call('refreshDefinitionAccess', $session->id)
            ->assertSet('workspaceRunPresentation', 'result');
        $newRun = $this->workspaceRun($workflow, $step, $session, 'queued');
        app(WorkflowStudioSessionService::class)->attachRun($session, $newRun);
        $editor->call('handleRunStatusChanged', $session->id, $newRun->id, 'completed')
            ->assertSet('workspaceRunId', $newRun->id)->assertSet('workspaceRunPresentation', 'live')
            ->assertViewHas('activeRun', fn (WorkflowRun $activeRun): bool => $activeRun->id === $newRun->id);
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertSame('queued', $newRun->fresh()->status);
    }

    public function test_live_minimap_shows_recorded_history_current_task_and_actual_route_without_guessing_duplicate_keys(): void
    {
        [$workflow, $step, $session] = $this->liveWorkspace();
        $otherStep = $workflow->steps()->create([
            'name' => 'Andere Liste', 'action_key' => 'other', 'type' => 'browser_task', 'position' => 20,
            'is_enabled' => true, 'config_json' => $step->config_json,
        ]);
        $run = $this->workspaceRun($workflow, $step, $session, 'running');
        $run->update(['context_json' => [
            'next_task_key' => 'current',
            'task_history' => [[
                'workflow_step_id' => $step->id, 'task_key' => 'first', 'status' => 'completed', 'seq' => 1,
            ]],
            'route_history' => [[
                'workflow_step_id' => $step->id, 'outcome' => 'success', 'logical_outcome' => 'success',
                'route_disposition' => 'continue',
                'route' => ['type' => 'card', 'action_key' => 'main', 'card_key' => 'current', '_source_card_key' => 'first'],
            ]],
        ]]);
        $run->stepRuns()->create([
            'workflow_step_id' => $step->id, 'status' => 'running',
            'result_json' => ['tasks' => [['key' => 'current', 'status' => 'running']]],
        ]);
        app(WorkflowStudioSessionService::class)->attachRun($session, $run);
        $editor = Livewire::test(WorkflowStudioTaskEditor::class, [
            'workflow' => $workflow, 'studioSessionId' => $session->id,
        ])->assertSet('workspaceRunPresentation', 'live');
        $xpath = $this->xpath($editor->html());

        $this->assertSame(1, $xpath->query('//*[@data-minimap-node="main::first" and @data-workflow-task-status="completed"]')->count());
        $this->assertSame(1, $xpath->query('//*[@data-minimap-node="main::current" and @data-workflow-task-status="running" and @data-workflow-minimap-active-target="true"]')->count());
        $this->assertSame(1, $xpath->query('//*[@data-minimap-node="main::current" and @aria-current="step"]')->count());
        $untouched = $xpath->query('//*[@data-minimap-node="main::untouched" and @data-workflow-minimap-active-target="false"]');
        $this->assertSame(1, $untouched->count());
        $this->assertNotContains($untouched->item(0)->getAttribute('data-workflow-task-status'), ['running', 'completed', 'success', 'failed', 'timeout']);
        $this->assertSame(1, $xpath->query('//*[@data-minimap-node="other::current" and @data-workflow-minimap-active-target="false"]')->count());
        $routeScript = $xpath->query('//*[@data-workflow-live-preview]//script[@*[name()="x-ref"]="routeMap"]');
        $this->assertSame(1, $routeScript->count());
        $drawnEdges = json_decode($routeScript->item(0)->textContent, true, flags: JSON_THROW_ON_ERROR)['edges'];
        $observedEdge = collect($drawnEdges)->first(fn (array $edge): bool => $edge['sourceNode'] === 'main::first' && $edge['targetNode'] === 'main::current');
        $this->assertNotNull($observedEdge);
        $this->assertSame('runtime', $observedEdge['outcome'], 'The observed execution path gets its own tone, independent of branch configuration.');
        $this->assertSame('success', $observedEdge['executionOutcome']);
        $this->assertSame(1, $observedEdge['runtimeCount']);
        $plannedEdges = collect($drawnEdges)->filter(fn (array $edge): bool => ! ($edge['runtime'] ?? false));
        $this->assertNotEmpty($plannedEdges);
        $this->assertFalse($plannedEdges->contains(fn (array $edge): bool => $edge['outcome'] === 'runtime'), 'Untaken configured routes must not masquerade as an observed path.');
        $editor->assertViewHas('routeMap', function (array $routeMap) use ($run): bool {
            $edge = collect($routeMap['edges'])->first(fn (array $edge): bool => $edge['source'] === 'main::first' && $edge['target'] === 'main::current' && ($edge['executed'] ?? false));

            return $routeMap['workflow_run_id'] === $run->id && $edge !== null;
        });
        $this->assertSame(0, $xpath->query('//*[@data-task-routes]')->count());
        $this->assertSame($step->config_json, $otherStep->fresh()->config_json);
    }

    public function test_historical_parent_does_not_mount_the_live_editor_of_another_active_run(): void
    {
        [$workflow, $step, $session] = $this->liveWorkspace();
        $activeRun = $this->workspaceRun($workflow, $step, $session, 'running');
        app(WorkflowStudioSessionService::class)->attachRun($session, $activeRun);
        $historicalRun = $this->workspaceRun($workflow, $step, $session, 'completed');
        $studio = Livewire::test(WorkflowStudio::class, [
            'workflow' => $workflow, 'hosted' => true, 'studioSessionId' => $session->id, 'runId' => $historicalRun->id,
        ])->assertViewHas('historicalRunView', true)
            ->call('refreshStudio')->assertSet('activeRunId', $historicalRun->id)
            ->call('pauseRun')->assertHasErrors('studio')
            ->call('stopRun')->assertHasErrors('studio');
        // A hidden modal-only definition editor may stay mounted. The visible
        // historical diagram must never include the current run's live surface.
        $this->assertSame(0, $this->xpath($studio->html())->query('//*[@data-workflow-live-preview]')->count());
        $this->assertSame($activeRun->id, $session->fresh()->active_workflow_run_id);
        $this->assertSame('running', $activeRun->fresh()->status);
        $this->assertSame('completed', $historicalRun->fresh()->status);
    }

    #[DataProvider('clientPresentationOverrides')]
    public function test_client_cannot_forge_server_owned_run_presentation(string $property, mixed $value): void
    {
        [$workflow, $step, $session] = $this->liveWorkspace();
        $run = $this->workspaceRun($workflow, $step, $session, 'running');
        app(WorkflowStudioSessionService::class)->attachRun($session, $run);
        $editor = Livewire::test(WorkflowStudioTaskEditor::class, [
            'workflow' => $workflow, 'studioSessionId' => $session->id,
        ]);
        $this->expectException(CannotUpdateLockedPropertyException::class);

        try {
            $editor->set($property, $value);
        } finally {
            $this->assertSame('running', $run->fresh()->status);
            $this->assertSame($run->id, $session->fresh()->active_workflow_run_id);
            $this->assertSame(1, $workflow->runs()->count());
        }
    }

    public static function clientPresentationOverrides(): array
    {
        return [
            'show edit while running' => ['workspaceRunPresentation', 'edit'],
            'select another run' => ['workspaceRunId', 999999],
            'select an invented cursor' => ['workspaceCursorNode', 'foreign::task'],
        ];
    }

    public function test_pending_cursor_is_not_reported_as_running_and_terminal_results_have_no_active_task(): void
    {
        [$workflow, $step, $session] = $this->liveWorkspace();
        $run = $this->workspaceRun($workflow, $step, $session, 'queued');
        app(WorkflowStudioSessionService::class)->attachRun($session, $run);
        $editor = Livewire::test(WorkflowStudioTaskEditor::class, [
            'workflow' => $workflow, 'studioSessionId' => $session->id,
        ]);
        $xpath = $this->xpath($editor->html());
        $summary = $xpath->query('//*[@data-workflow-live-task-node="main::first"]');
        $this->assertSame(1, $summary->count());
        $this->assertStringContainsString('Nächster Task', $summary->item(0)->textContent);
        $this->assertStringNotContainsString('Läuft:', $summary->item(0)->textContent);
        $this->assertSame(0, $xpath->query('//*[@data-workflow-live-preview]//*[@data-workflow-task-status="running"]')->count());

        // The cursor may deliberately remain at its last location after finish.
        // That is historical position, not evidence of continuing execution.
        $run->update(['status' => 'completed']);
        $editor->call('refreshDefinitionAccess', $session->id)->assertSet('workspaceRunPresentation', 'result');
        $xpath = $this->xpath($editor->html());
        $this->assertSame(0, $xpath->query('//*[@data-workflow-live-preview]//*[@data-workflow-minimap-active-target="true"]')->count());
        $this->assertSame(0, $xpath->query('//*[@data-workflow-live-preview]//*[@aria-current="step"]')->count());
        $this->assertSame(0, $xpath->query('//*[@data-workflow-live-task-node]')->count());
        $this->assertSame('first', data_get($run->fresh()->context_json, 'next_task_key'));
    }

    public function test_continuous_step_task_changes_invalidate_live_evidence_but_browser_preview_ticks_do_not(): void
    {
        [$workflow, $step, $session] = $this->liveWorkspace();
        $step->update(['config_json' => ['tasks' => [
            ['key' => 'qa-wait-first', 'title' => 'Erstes Warten', 'task_key' => 'wait.seconds', 'value' => 0],
            ['key' => 'qa-wait-second', 'title' => 'Zweites Warten', 'task_key' => 'wait.seconds', 'value' => 0],
        ]]]);
        $run = $this->workspaceRun($workflow, $step, $session, 'running');
        $run->update(['context_json' => ['next_task_key' => null, 'workflow_studio_session_id' => $session->id]]);
        $snapshot = [
            'stage' => 'browser-preview', 'taskKey' => null,
            'tasks' => [
                ['key' => 'qa-wait-first', 'title' => 'Erstes Warten', 'status' => 'running'],
                ['key' => 'qa-wait-second', 'title' => 'Zweites Warten'],
            ],
            'browserWindows' => [['key' => 'main', 'targetId' => 'qa-target', 'title' => 'QA', 'currentUrl' => 'https://example.test/one']],
        ];
        $stepRun = $run->stepRuns()->create([
            'workflow_step_id' => $step->id, 'status' => 'running', 'result_json' => $snapshot,
        ]);
        app(WorkflowStudioSessionService::class)->attachRun($session, $run);
        $studio = Livewire::test(WorkflowStudio::class, [
            'workflow' => $workflow, 'studioSessionId' => $session->id, 'hosted' => true, 'runId' => $run->id,
        ]);
        $editor = Livewire::test(WorkflowStudioTaskEditor::class, [
            'workflow' => $workflow, 'studioSessionId' => $session->id,
        ])->assertSet('workspaceCursorNode', 'main::qa-wait-first');
        $this->assertSame(1, $this->xpath($editor->html())->query('//*[@data-minimap-node="main::qa-wait-first" and @aria-current="step"]')->count());
        $studio->call('refreshStudio')->assertNotDispatched('workflow-studio-run-status-changed');

        // The runtime's lightweight browser ticker intentionally omits taskKey.
        // A new screenshot/stage timestamp alone must not invalidate the child.
        $previewTick = $snapshot;
        $previewTick['stage'] = 'debug-screenshot';
        $previewTick['updatedAt'] = '2026-10-04T06:10:01+00:00';
        $previewTick['screenshotUrl'] = 'https://example.test/preview-two.png';
        $previewTick['browserWindows'][0]['currentUrl'] = 'https://example.test/two';
        $stepRun->update(['result_json' => $previewTick]);
        $studio->call('refreshStudio')->assertNotDispatched('workflow-studio-run-status-changed');

        $secondTaskSnapshot = $snapshot;
        $secondTaskSnapshot['tasks'][0]['status'] = 'completed';
        $secondTaskSnapshot['tasks'][1]['status'] = 'running';
        $stepRun->update(['result_json' => $secondTaskSnapshot]);
        $studio->call('refreshStudio')->assertDispatched('workflow-studio-run-status-changed',
            studioSessionId: $session->id, runId: $run->id, status: 'running',
        );
        $editor->call('handleRunStatusChanged', $session->id, $run->id, 'running')
            ->assertSet('workspaceCursorNode', 'main::qa-wait-second');
        $xpath = $this->xpath($editor->html());
        $this->assertSame(1, $xpath->query('//*[@data-minimap-node="main::qa-wait-second" and @data-workflow-task-status="running" and @aria-current="step"]')->count());
        $this->assertSame(0, $xpath->query('//*[@data-minimap-node="main::qa-wait-first" and @aria-current="step"]')->count());
        $this->assertSame(1, $xpath->query('//*[@data-minimap-node="main::qa-wait-first" and @data-workflow-task-status="completed"]')->count());
        $summary = $xpath->query('//*[@data-workflow-live-task-node="main::qa-wait-second"]');
        $this->assertSame(1, $summary->count());
        $this->assertStringContainsString('Läuft', $summary->item(0)->textContent);
        $this->assertStringContainsString('Zweites Warten', $summary->item(0)->textContent);
        $studio->call('refreshStudio')->assertNotDispatched('workflow-studio-run-status-changed');
        $this->assertNull(data_get($run->fresh()->context_json, 'next_task_key'));
        $this->assertSame('running', $run->fresh()->status);
        $this->assertSame(1, $run->stepRuns()->count(), 'A visible task change must not create another execution.');
    }

    public function test_live_view_never_guesses_the_first_task_from_a_running_list_without_task_evidence(): void
    {
        [$workflow, $step, $session] = $this->liveWorkspace();
        $run = $this->workspaceRun($workflow, $step, $session, 'running');
        $run->update(['context_json' => [
            'next_task_key' => null,
            'task_history' => [['workflow_step_id' => $step->id, 'task_key' => 'first', 'status' => 'running', 'seq' => 1]],
        ]]);
        $run->stepRuns()->create([
            'workflow_step_id' => $step->id, 'status' => 'running',
            'result_json' => [
                'stage' => 'browser-preview', 'taskKey' => 'not-a-defined-task',
                'tasks' => [['key' => 'first', 'status' => 'completed'], ['key' => 'current', 'status' => 'completed']],
            ],
        ]);
        app(WorkflowStudioSessionService::class)->attachRun($session, $run);
        $editor = Livewire::test(WorkflowStudioTaskEditor::class, [
            'workflow' => $workflow, 'studioSessionId' => $session->id,
        ])->assertSet('workspaceRunPresentation', 'live')->assertSet('workspaceCursorNode', '');
        $xpath = $this->xpath($editor->html());
        $this->assertSame(0, $xpath->query('//*[@data-workflow-live-preview]//*[@data-workflow-minimap-active-target="true"]')->count());
        $this->assertSame(0, $xpath->query('//*[@data-workflow-live-preview]//*[@aria-current="step"]')->count());
        $this->assertSame(0, $xpath->query('//*[@data-workflow-live-task-node]')->count());
        $this->assertSame(1, $xpath->query('//*[@data-minimap-node="main::first" and @data-workflow-task-status="completed"]')->count());
    }

    #[DataProvider('publicSnapshotTaskEvidence')]
    public function test_live_cursor_rejects_ambiguous_or_terminal_snapshots_and_only_accepts_known_task_evidence(array $snapshot, ?string $expectedTask): void
    {
        [$workflow, $step, $session] = $this->liveWorkspace();
        $run = $this->workspaceRun($workflow, $step, $session, 'running');
        $run->update(['context_json' => ['next_task_key' => null]]);
        $run->stepRuns()->create([
            'workflow_step_id' => $step->id, 'status' => 'running', 'result_json' => $snapshot,
        ]);
        $cursor = app(WorkflowLiveTaskPresenter::class)->present($workflow, $run->fresh());
        $this->assertSame($expectedTask, $cursor['task_key'] ?? null);
        if ($expectedTask !== null) {
            $this->assertSame($step->id, $cursor['step_id']);
            $this->assertSame('runtime', $cursor['source']);
        }
    }

    public static function publicSnapshotTaskEvidence(): array
    {
        return [
            'ambiguous without a valid hint' => [
                ['tasks' => [['key' => 'first', 'status' => 'running'], ['key' => 'current', 'status' => 'running']]], null,
            ],
            'hint identifies one evidenced active task' => [
                ['taskKey' => 'current', 'tasks' => [['key' => 'first', 'status' => 'running'], ['key' => 'current', 'status' => 'running']]], 'current',
            ],
            'terminal snapshot overrides stale task markers' => [
                ['state' => 'completed', 'tasks' => [['key' => 'first', 'status' => 'running']]], null,
            ],
            'unknown key is not a template task' => [
                ['taskKey' => 'unknown', 'tasks' => [['key' => 'unknown', 'status' => 'running']]], null,
            ],
            'malformed hint cannot replace unambiguous evidence' => [
                ['taskKey' => ['invented'], 'tasks' => [['key' => 'first', 'status' => 'running']]], 'first',
            ],
            'generated task maps to its known parent card' => [
                ['tasks' => [['key' => 'generated-child', 'parent_task_key' => 'current', 'status' => 'running']]], 'current',
            ],
        ];
    }

    public function test_compact_browser_strip_reports_the_active_window_and_never_claims_a_finished_run_is_connected(): void
    {
        [$workflow, $step, $session] = $this->liveWorkspace();
        $run = $this->workspaceRun($workflow, $step, $session, 'running');
        $run->update(['context_json' => [
            'next_task_key' => 'first', 'active_browser_window' => 'popup', 'workflow_studio_session_id' => $session->id,
            'browser_windows' => [
                'main' => ['name' => 'main', 'title' => 'Startseite', 'url' => 'https://example.test/main', 'targetId' => 'main-target'],
                'popup' => ['name' => 'popup', 'title' => 'Aktives Fenster', 'url' => 'https://example.test/popup', 'targetId' => 'popup-target'],
            ],
        ]]);
        app(WorkflowStudioSessionService::class)->attachRun($session, $run);
        $studio = Livewire::test(WorkflowStudio::class, [
            'workflow' => $workflow, 'studioSessionId' => $session->id,
        ]);
        $xpath = $this->xpath($studio->html());
        $this->assertSame('popup', trim($xpath->query('//*[@data-studio-active-browser-name]')->item(0)->textContent));
        $this->assertSame(1, $xpath->query('//*[@data-studio-active-browser-status and @data-connected="true"]')->count());
        $this->assertSame(1, $xpath->query('//details[@data-studio-additional-browser-windows]/summary')->count());
        $this->assertSame(1, $xpath->query('//*[@data-studio-browser-preview-trigger]')->count());

        $run->update(['status' => 'completed']);
        $studio->call('refreshStudio');
        $xpath = $this->xpath($studio->html());
        $this->assertSame(1, $xpath->query('//*[@data-studio-active-browser-status and @data-connected="false"]')->count());
        $this->assertSame('Letzte Vorschau', trim($xpath->query('//*[@data-studio-active-browser-status]')->item(0)->textContent));
        $this->assertSame(1, $xpath->query('//*[@data-studio-browser-preview-trigger]')->count(), 'The last observable browser preview remains accessible.');
    }

    /** Synthetic task definitions: no portal, browser process or external I/O. */
    private function liveWorkspace(): array
    {
        $this->actingAs($admin = User::factory()->create(['role' => 'admin', 'status' => true]));
        $workflow = Workflow::create([
            'name' => 'Live Workspace QA', 'slug' => 'live-workspace-qa', 'trigger_type' => 'manual', 'is_active' => true,
        ]);
        $step = $workflow->steps()->create([
            'name' => 'Hauptliste', 'action_key' => 'main', 'type' => 'browser_task', 'position' => 10, 'is_enabled' => true,
            'config_json' => ['tasks' => [
                ['key' => 'first', 'title' => 'Erster Task', 'task_key' => 'wait.seconds', 'value' => 0],
                ['key' => 'current', 'title' => 'Aktueller Task', 'task_key' => 'wait.seconds', 'value' => 0],
                ['key' => 'untouched', 'title' => 'Noch nicht gestartet', 'task_key' => 'wait.seconds', 'value' => 0],
            ]],
        ]);
        $session = app(WorkflowStudioSessionService::class)->open($workflow, $admin, 'manual', 'ask_critical');

        return [$workflow, $step, $session];
    }

    private function workspaceRun(Workflow $workflow, WorkflowStep $step, WorkflowStudioSession $session, string $status): WorkflowRun
    {
        return WorkflowRun::create([
            'workflow_id' => $workflow->id, 'workflow_studio_session_id' => $session->id,
            'run_uuid' => (string) str()->uuid(), 'status' => $status, 'current_workflow_step_id' => $step->id,
            'context_json' => ['next_task_key' => 'first'], 'result_json' => [],
        ]);
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument;
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();

        return new DOMXPath($document);
    }
}
