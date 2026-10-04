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
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportEvents\SupportEvents;
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

    public function test_studio_child_registers_its_run_listener_instead_of_the_inherited_manager_handler(): void
    {
        [$workflow, $step, $session] = $this->liveWorkspace();
        $editor = Livewire::test(WorkflowStudioTaskEditor::class, [
            'workflow' => $workflow, 'studioSessionId' => $session->id,
        ]);

        $this->assertSame('handleRunStatusChanged', SupportEvents::getListenerMethodName(
            $editor->instance(), 'workflow-studio-run-status-changed',
        ));
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
            $editor->dispatch('workflow-studio-run-status-changed', studioSessionId: $session->id, runId: $run->id, status: $status === 'paused' ? 'running' : 'paused')
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
        $editor->dispatch('workflow-studio-run-status-changed', studioSessionId: $session->id, runId: null, status: 'running')
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
        $editor->dispatch('workflow-studio-run-status-changed', studioSessionId: $session->id + 1, runId: $run->id, status: 'paused')
            ->assertSet('workspaceRunId', $run->id)->assertSet('workspaceRunPresentation', 'live');
        $editor->dispatch('workflow-studio-run-status-changed', studioSessionId: $session->id, runId: $run->id + 1, status: 'paused')
            ->assertSet('workspaceRunId', $run->id)->assertSet('workspaceRunPresentation', 'live');
        $editor->dispatch('workflow-studio-run-status-changed', studioSessionId: $session->id, runId: null, status: 'paused')
            ->assertSet('workspaceRunId', $run->id)->assertSet('workspaceRunPresentation', 'live');

        $run->update(['status' => 'completed']);
        $editor->call('refreshDefinitionAccess', $session->id)
            ->assertSet('workspaceRunPresentation', 'result');
        $newRun = $this->workspaceRun($workflow, $step, $session, 'queued');
        app(WorkflowStudioSessionService::class)->attachRun($session, $newRun);
        $editor->dispatch('workflow-studio-run-status-changed', studioSessionId: $session->id, runId: $newRun->id, status: 'completed')
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
        $this->assertSame('success', $observedEdge['outcome'], 'Display color must never overwrite the actual routing outcome.');
        $this->assertSame('runtime', $observedEdge['visualTone'], 'Only observed execution gets the live path tone.');
        $this->assertSame('success', $observedEdge['executionOutcome']);
        $this->assertSame(1, $observedEdge['runtimeCount']);
        $plannedEdges = collect($drawnEdges)->filter(fn (array $edge): bool => ! ($edge['runtime'] ?? false));
        $this->assertNotEmpty($plannedEdges);
        $this->assertTrue($plannedEdges->every(fn (array $edge): bool => $edge['visualTone'] === 'neutral'), 'Untaken configured routes must stay neutral, including unchosen error branches.');
        $editor->assertViewHas('routeMap', function (array $routeMap) use ($run): bool {
            $edge = collect($routeMap['edges'])->first(fn (array $edge): bool => $edge['source'] === 'main::first' && $edge['target'] === 'main::current' && ($edge['executed'] ?? false));

            return $routeMap['workflow_run_id'] === $run->id && $edge !== null;
        });
        $this->assertSame(0, $xpath->query('//*[@data-task-routes]')->count());
        $this->assertSame($step->config_json, $otherStep->fresh()->config_json);
    }

    #[DataProvider('liveRouteColorEvidence')]
    public function test_minimap_color_uses_execution_evidence_without_changing_routing_outcomes(bool $hasRun, bool $liveFlow, bool $runtime, bool $executed, int $runtimeCount, bool $pending, string $expectedTone): void
    {
        [$workflow, $step, $session] = $this->liveWorkspace();
        $run = $hasRun ? $this->workspaceRun($workflow, $step, $session, $liveFlow ? 'queued' : 'completed') : null;
        $routeMap = [
            'nodes' => [
                ['id' => 'main::first', 'kind' => 'task', 'title' => 'Erster Task'],
                ['id' => 'main::current', 'kind' => 'task', 'title' => 'Aktueller Task'],
            ],
            'edges' => [[
                'id' => 'evidence-test', 'source' => 'main::first', 'target' => 'main::current',
                'outcome' => 'failed', 'runtime' => $runtime, 'executed' => $executed,
                'runtime_count' => $runtimeCount, 'pending' => $pending, 'origins' => ['definition'],
            ]],
        ];
        $html = Blade::render('<x-workflows.minimap :workflow="$workflow" :workflow-run="$run" :route-map="$routeMap" :live-flow="$liveFlow" />', compact('workflow', 'run', 'routeMap', 'liveFlow'));
        $xpath = $this->xpath($html);
        $map = $xpath->query('//*[@data-workflow-route-evidence-mode]');
        $this->assertSame(1, $map->count());
        $this->assertSame($hasRun ? 'observed' : 'definition', $map->item(0)->getAttribute('data-workflow-route-evidence-mode'));
        $script = $xpath->query('//script[@*[name()="x-ref"]="routeMap"]');
        $this->assertSame(1, $script->count());
        $edges = json_decode($script->item(0)->textContent, true, flags: JSON_THROW_ON_ERROR)['edges'];
        $this->assertSame($hasRun, json_decode($script->item(0)->textContent, true, flags: JSON_THROW_ON_ERROR)['routeEvidenceMode']);
        $this->assertCount(1, $edges);
        $edge = $edges[0];
        $this->assertSame('failed', $edge['outcome']);
        $this->assertSame('failed', $edge['executionOutcome']);
        $this->assertSame($expectedTone, $edge['visualTone']);
        $this->assertSame($executed, $edge['executed']);
        $this->assertSame($runtimeCount, $edge['runtimeCount']);
        $this->assertSame($pending, $edge['pending']);
    }

    public static function liveRouteColorEvidence(): array
    {
        return [
            'queued definition before first task' => [true, true, false, false, 0, false, 'neutral'],
            'pending cursor is not an executed connection' => [true, true, true, false, 0, true, 'neutral'],
            'runtime flag alone is not evidence' => [true, true, true, false, 0, false, 'neutral'],
            'negative runtime count is not evidence' => [true, true, true, false, -1, false, 'neutral'],
            'recorded runtime count' => [true, true, true, false, 2, false, 'runtime'],
            'explicit executed runtime edge' => [true, true, true, true, 0, false, 'runtime'],
            'observed and pending remains observed' => [true, true, true, true, 2, true, 'runtime'],
            'configured edge cannot spoof runtime evidence' => [true, true, false, true, 2, false, 'neutral'],
            'definition without run retains error semantics' => [false, false, false, false, 0, false, 'failed'],
            'historical observed error retains error semantics' => [true, false, true, true, 1, false, 'failed'],
            'historical untaken branch stays neutral' => [true, false, false, false, 0, false, 'neutral'],
        ];
    }

    #[DataProvider('internalTransitionEvidenceSources')]
    public function test_actual_internal_task_transitions_are_visible_without_reinterpreting_a_later_definition(string $evidenceSource): void
    {
        [$workflow, $step, $session] = $this->liveWorkspace();
        $configuration = $step->config_json;
        // The definition was edited later; it is not a record of the old path.
        $configuration['tasks'][0]['next'] = ['type' => 'card', 'action_key' => 'main', 'card_key' => 'untouched'];
        $configuration['tasks'][1]['next'] = ['type' => 'end'];
        $step->update(['config_json' => $configuration]);
        $run = $this->workspaceRun($workflow, $step, $session, 'running');
        $tasks = [
            ['key' => 'first', 'status' => 'success'],
            ['key' => 'current', 'status' => 'success'],
            ['key' => 'untouched', 'status' => 'running'],
        ];
        if ($evidenceSource === 'timestamp-pairs') {
            $tasks[0] += ['startedAt' => '2026-10-04T08:00:00.000Z', 'finishedAt' => '2026-10-04T08:00:01.000Z', 'next' => ['type' => 'card', 'action_key' => 'main', 'card_key' => 'current']];
            $tasks[1] += ['startedAt' => '2026-10-04T08:00:02.000Z', 'finishedAt' => '2026-10-04T08:00:03.000Z', 'next' => ['type' => 'card', 'action_key' => 'main', 'card_key' => 'untouched']];
            $tasks[2] += ['startedAt' => '2026-10-04T08:00:04.000Z'];
        }
        $snapshot = ['tasks' => $tasks];
        if ($evidenceSource === 'actual-events') {
            $snapshot['events'] = [
                ['at' => '2026-10-04T08:00:00.000Z', 'stage' => 'task-started', 'taskKey' => 'first'],
                ['at' => '2026-10-04T08:00:01.000Z', 'stage' => 'task-completed', 'taskKey' => 'first', 'status' => 'success'],
                ['at' => '2026-10-04T08:00:02.000Z', 'stage' => 'task-started', 'taskKey' => 'current'],
                ['at' => '2026-10-04T08:00:03.000Z', 'stage' => 'task-completed', 'taskKey' => 'current', 'status' => 'success'],
                ['at' => '2026-10-04T08:00:04.000Z', 'stage' => 'task-started', 'taskKey' => 'untouched'],
            ];
        }
        $stepRun = $run->stepRuns()->create(['workflow_step_id' => $step->id, 'status' => 'running', 'result_json' => $snapshot]);
        $stepRun->update(['result_json' => $snapshot + [
            'workflowRunId' => $run->id, 'workflowStepId' => $step->id, 'workflowStepRunId' => $stepRun->id,
        ]]);
        app(WorkflowStudioSessionService::class)->attachRun($session, $run);
        $editor = Livewire::test(WorkflowStudioTaskEditor::class, ['workflow' => $workflow, 'studioSessionId' => $session->id]);
        $script = $this->xpath($editor->html())->query('//*[@data-workflow-live-preview]//script[@*[name()="x-ref"]="routeMap"]');
        $this->assertSame(1, $script->count());
        $edges = collect(json_decode($script->item(0)->textContent, true, flags: JSON_THROW_ON_ERROR)['edges']);
        foreach ([['main::first', 'main::current'], ['main::current', 'main::untouched']] as [$source, $target]) {
            $observed = $edges->first(fn (array $edge): bool => $edge['sourceNode'] === $source && $edge['targetNode'] === $target && $edge['executed']);
            $this->assertNotNull($observed, $evidenceSource.' must expose the actual internal task transition.');
            $this->assertTrue($observed['runtime']);
            $this->assertGreaterThan(0, $observed['runtimeCount']);
            $this->assertSame('success', $observed['outcome']);
            $this->assertSame('runtime', $observed['visualTone']);
        }
        $revisedPlannedEdge = $edges->first(fn (array $edge): bool => $edge['sourceNode'] === 'main::first' && $edge['targetNode'] === 'main::untouched');
        $this->assertNotNull($revisedPlannedEdge);
        $this->assertFalse($revisedPlannedEdge['executed']);
        $this->assertSame('neutral', $revisedPlannedEdge['visualTone']);
        $this->assertSame([], data_get($run->fresh()->context_json, 'route_history', []), 'The presenter must not persist inferred UI paths.');
        $this->assertSame($configuration, $step->fresh()->config_json);
        $this->assertSame($snapshot + ['workflowRunId' => $run->id, 'workflowStepId' => $step->id, 'workflowStepRunId' => $stepRun->id], $stepRun->fresh()->result_json);
    }

    public static function internalTransitionEvidenceSources(): array
    {
        return ['runtime event order' => ['actual-events'], 'recorded timestamp pair and snapshot target' => ['timestamp-pairs']];
    }

    #[DataProvider('unprovenInternalTransitions')]
    public function test_task_status_planned_routes_and_ambiguous_or_foreign_snapshots_never_color_internal_connections(string $scenario): void
    {
        [$workflow, $step, $session] = $this->liveWorkspace();
        $run = $this->workspaceRun($workflow, $step, $session, 'running');
        $tasks = [
            ['key' => 'first', 'status' => 'success', 'startedAt' => '2026-10-04T08:00:00.000Z', 'finishedAt' => '2026-10-04T08:00:01.000Z', 'next' => ['type' => 'card', 'action_key' => 'main', 'card_key' => 'current']],
            ['key' => 'current', 'status' => 'running', 'startedAt' => '2026-10-04T08:00:02.000Z'],
        ];
        $snapshot = [];
        if ($scenario === 'status-only') {
            $tasks = [['key' => 'first', 'status' => 'success'], ['key' => 'current', 'status' => 'running']];
            $run->update(['context_json' => ['next_task_key' => 'current', 'task_history' => [
                ['workflow_step_id' => $step->id, 'task_key' => 'first', 'status' => 'completed', 'seq' => 1],
            ]]]);
        } elseif ($scenario === 'future-target') {
            $tasks[1] = ['key' => 'current', 'status' => 'template'];
        } elseif ($scenario === 'target-without-start') {
            unset($tasks[1]['startedAt']);
        } elseif ($scenario === 'overlapping-execution') {
            $tasks[1]['startedAt'] = '2026-10-04T08:00:00.500Z';
        } elseif ($scenario === 'unfinished-source') {
            unset($tasks[0]['finishedAt']);
        } elseif ($scenario === 'different-snapshot-target') {
            $tasks[0]['next']['card_key'] = 'untouched';
        } elseif ($scenario === 'ambiguous-target-start') {
            $tasks[] = ['key' => 'untouched', 'status' => 'running', 'startedAt' => $tasks[1]['startedAt']];
        } elseif ($scenario === 'foreign-run') {
            $snapshot['workflowRunId'] = $run->id + 1;
        } elseif ($scenario === 'foreign-step') {
            $snapshot['workflowStepId'] = $step->id + 1;
        }
        $snapshot['tasks'] = $tasks;
        $run->stepRuns()->create(['workflow_step_id' => $step->id, 'status' => 'running', 'result_json' => $snapshot]);
        app(WorkflowStudioSessionService::class)->attachRun($session, $run);
        $editor = Livewire::test(WorkflowStudioTaskEditor::class, ['workflow' => $workflow, 'studioSessionId' => $session->id]);
        $script = $this->xpath($editor->html())->query('//*[@data-workflow-live-preview]//script[@*[name()="x-ref"]="routeMap"]');
        $this->assertSame(1, $script->count());
        $edges = collect(json_decode($script->item(0)->textContent, true, flags: JSON_THROW_ON_ERROR)['edges']);
        $this->assertNotEmpty($edges);
        $this->assertTrue($edges->every(fn (array $edge): bool => ! $edge['executed'] && $edge['runtimeCount'] === 0 && $edge['visualTone'] === 'neutral'), $scenario.' must not turn planned connections into observed paths.');
        $this->assertSame($snapshot, $run->stepRuns()->first()->result_json);
        $this->assertSame(1, $run->stepRuns()->count());
    }

    public static function unprovenInternalTransitions(): array
    {
        return array_combine(
            $scenarios = ['status-only', 'future-target', 'target-without-start', 'overlapping-execution', 'unfinished-source', 'different-snapshot-target', 'ambiguous-target-start', 'foreign-run', 'foreign-step'],
            array_map(fn (string $scenario): array => [$scenario], $scenarios),
        );
    }

    public function test_queued_and_pending_only_live_maps_never_color_unexecuted_success_or_error_branches(): void
    {
        [$workflow, $step, $session] = $this->liveWorkspace();
        $configuration = $step->config_json;
        $configuration['tasks'][0]['next'] = ['type' => 'card', 'action_key' => 'main', 'card_key' => 'current'];
        $configuration['tasks'][0]['on_error'] = ['type' => 'card', 'action_key' => 'main', 'card_key' => 'untouched'];
        $step->update(['config_json' => $configuration]);
        $run = $this->workspaceRun($workflow, $step, $session, 'queued');
        app(WorkflowStudioSessionService::class)->attachRun($session, $run);
        $editor = Livewire::test(WorkflowStudioTaskEditor::class, ['workflow' => $workflow, 'studioSessionId' => $session->id]);

        foreach ([false, true] as $pending) {
            if ($pending) {
                $run->update(['status' => 'running', 'context_json' => [
                    'next_task_key' => 'current', 'next_step_action_key' => 'main',
                    'next_task_route_source_key' => 'first', 'next_task_route_outcome' => 'success',
                ]]);
                $editor->dispatch('workflow-studio-run-status-changed', studioSessionId: $session->id, runId: $run->id, status: 'running');
            }
            $xpath = $this->xpath($editor->html());
            $script = $xpath->query('//*[@data-workflow-live-preview]//script[@*[name()="x-ref"]="routeMap"]');
            $this->assertSame(1, $script->count());
            $edges = collect(json_decode($script->item(0)->textContent, true, flags: JSON_THROW_ON_ERROR)['edges']);
            $this->assertNotEmpty($edges);
            $this->assertTrue($edges->every(fn (array $edge): bool => $edge['visualTone'] === 'neutral'));
            $this->assertTrue($edges->every(fn (array $edge): bool => ! $edge['executed'] && $edge['runtimeCount'] === 0));
            $branches = $edges->where('sourceNode', 'main::first');
            $this->assertContains('success', $branches->pluck('outcome'));
            $this->assertContains('failed', $branches->pluck('outcome'));
            if ($pending) {
                $this->assertTrue($branches->contains(fn (array $edge): bool => $edge['pending'] && $edge['targetNode'] === 'main::current'));
            }
        }
        $this->assertSame(0, $run->stepRuns()->count());
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
        $editor->dispatch('workflow-studio-run-status-changed', studioSessionId: $session->id, runId: $run->id, status: 'running')
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

    public function test_unloaded_live_cursor_queries_only_bounded_own_snapshots_without_hydrating_history(): void
    {
        [$workflow, $step, $session] = $this->liveWorkspace();
        $run = $this->workspaceRun($workflow, $step, $session, 'running');
        $run->update(['context_json' => ['next_task_key' => null]]);
        $currentSnapshot = $run->stepRuns()->create([
            'workflow_step_id' => $step->id, 'status' => 'running',
            'result_json' => ['tasks' => [['key' => 'current', 'status' => 'running']]],
        ]);
        // Production still stores one attempt per run/step. Other historical
        // steps make the query bound observable without changing that schema.
        for ($index = 0; $index < 40; $index++) {
            $historicalStep = $workflow->steps()->create([
                'name' => 'Historischer Schritt '.$index, 'action_key' => 'history-'.$index,
                'type' => 'browser_task', 'position' => 20 + $index, 'is_enabled' => true,
                'config_json' => ['tasks' => [['key' => 'first', 'task_key' => 'wait.seconds', 'value' => 0]]],
            ]);
            $run->stepRuns()->create([
                'workflow_step_id' => $historicalStep->id, 'status' => 'completed',
                'result_json' => ['tasks' => [['key' => 'first', 'status' => 'completed']]],
            ]);
        }
        $foreignRun = $this->workspaceRun($workflow, $step, $session, 'running');
        $foreignRun->stepRuns()->create([
            'workflow_step_id' => $step->id, 'status' => 'running',
            'result_json' => ['tasks' => [['key' => 'first', 'status' => 'running']]],
        ]);
        $workflow->load('steps');
        $unloadedRun = $run->fresh();
        $this->assertFalse($unloadedRun->relationLoaded('stepRuns'));

        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $cursor = app(WorkflowLiveTaskPresenter::class)->present($workflow, $unloadedRun);
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }
        $this->assertCount(1, $queries);
        $this->assertStringContainsString('workflow_step_runs', $queries[0]['query']);
        $this->assertStringContainsString('limit 32', strtolower($queries[0]['query']));
        $this->assertMatchesRegularExpression('/order by\s+"?id"?\s+desc/i', $queries[0]['query']);
        $this->assertSame([$run->id, $step->id], $queries[0]['bindings']);
        $this->assertSame($currentSnapshot->id, $cursor['step_run_id']);
        $this->assertSame('main::current', $cursor['node']);
        $this->assertFalse($unloadedRun->relationLoaded('stepRuns'));

        // The no-current-step branch is bounded too and cannot pick the newer
        // foreign runner or revive an older own snapshot behind terminal data.
        $unloadedRun->current_workflow_step_id = null;
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $withoutCurrentStep = app(WorkflowLiveTaskPresenter::class)->present($workflow, $unloadedRun);
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }
        $this->assertNull($withoutCurrentStep);
        $this->assertCount(1, $queries);
        $this->assertStringContainsString('limit 32', strtolower($queries[0]['query']));
        $this->assertSame([$run->id], $queries[0]['bindings']);
        $this->assertFalse($unloadedRun->relationLoaded('stepRuns'));
        $this->assertSame($step->id, $run->fresh()->current_workflow_step_id);
        $this->assertSame(41, $run->stepRuns()->count());
        $this->assertSame(1, $foreignRun->stepRuns()->count());
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
        $run->stepRuns()->create([
            'workflow_step_id' => $step->id, 'status' => 'running',
            'result_json' => ['browserWindows' => [
                'main' => ['name' => 'main', 'targetId' => 'main-target', 'screenshotUrl' => 'https://example.test/main-shot.png'],
                'popup' => ['name' => 'popup', 'targetId' => 'popup-target', 'screenshotUrl' => 'https://example.test/popup-shot.png'],
            ]],
        ]);
        app(WorkflowStudioSessionService::class)->attachRun($session, $run);
        $studio = Livewire::test(WorkflowStudio::class, [
            'workflow' => $workflow, 'studioSessionId' => $session->id,
        ]);
        $xpath = $this->xpath($studio->html());
        $this->assertSame('popup', trim($xpath->query('//*[@data-studio-active-browser-name]')->item(0)->textContent));
        $this->assertSame(1, $xpath->query('//*[@data-studio-active-browser-status and @data-connected="true"]')->count());
        $this->assertSame(2, $xpath->query('//*[@data-studio-browser-mini-list]/article[@data-studio-browser-mini-window]')->count());
        $this->assertSame(0, $xpath->query('//details[@data-studio-additional-browser-windows]')->count());
        $this->assertSame(1, $xpath->query('//*[@data-studio-browser-preview-trigger]')->count());
        $activePreview = $xpath->query('//button[@data-studio-browser-mini-preview="popup" and @data-studio-browser-preview-trigger]');
        $this->assertSame(1, $activePreview->count());
        $this->assertSame("openToolModal('browser')", $activePreview->item(0)->getAttribute('wire:click'));
        $this->assertSame('https://example.test/popup-shot.png', $xpath->query('//*[@data-studio-browser-mini-preview="popup"]//img')->item(0)->getAttribute('src'));
        $this->assertSame('https://example.test/main-shot.png', $xpath->query('//*[@data-studio-browser-mini-preview="main"]//img')->item(0)->getAttribute('src'));
        $studio->call('openToolModal', 'browser')->assertSet('activeToolModal', 'browser')->assertSeeHtml('data-workflow-browser-tool');
        $studio->call('closeToolModal')->assertSet('activeToolModal', '');

        $run->update(['status' => 'completed']);
        $studio->call('refreshStudio');
        $xpath = $this->xpath($studio->html());
        $this->assertSame(1, $xpath->query('//*[@data-studio-active-browser-status and @data-connected="false"]')->count());
        $this->assertSame('Letzte Vorschau', trim($xpath->query('//*[@data-studio-active-browser-status]')->item(0)->textContent));
        $this->assertSame(1, $xpath->query('//*[@data-studio-browser-preview-trigger]')->count(), 'The last observable browser preview remains accessible.');
    }

    #[DataProvider('browserMiniConnectionStates')]
    public function test_browser_mini_never_invents_a_live_connection_and_preserves_empty_image_fallback(bool $active, bool $historical, bool $runtime, bool $connected, string $expectedLabel): void
    {
        $html = view('livewire.admin.network.workflow-studio.browser-windows', [
            'session' => (object) ['id' => 100], 'isActive' => $active, 'isPaused' => false,
            'historicalRunView' => $historical, 'autonomousMode' => false,
            'browserWindows' => [[
                'name' => 'main', 'title' => '', 'url' => '', 'active' => true,
                'runtime' => $runtime, 'connected' => $connected, 'screenshot_url' => null,
            ]],
        ])->render();
        $xpath = $this->xpath($html);
        $status = $xpath->query('//*[@data-studio-active-browser-status]');
        $this->assertSame(1, $status->count());
        $this->assertSame($expectedLabel, trim($status->item(0)->textContent));
        $this->assertSame($expectedLabel === 'Verbunden' ? 'true' : 'false', $status->item(0)->getAttribute('data-connected'));
        $this->assertSame(1, $xpath->query('//button[@data-studio-browser-mini-preview="main" and @type="button"]')->count());
        $this->assertSame(0, $xpath->query('//*[@data-studio-browser-mini-image]')->count());
        $this->assertSame(1, $xpath->query('//*[@data-studio-browser-mini-fallback]')->count());
        $this->assertSame(0, $xpath->query('//*[@data-studio-browser-mini-list]/ancestor::details')->count());
    }

    public static function browserMiniConnectionStates(): array
    {
        return [
            'configured and idle' => [false, false, false, false, 'Noch nicht geöffnet'],
            'active before browser capture' => [true, false, false, false, 'Wartet auf Browser'],
            'configured connected flag is not runtime evidence' => [true, false, false, true, 'Wartet auf Browser'],
            'runtime without target' => [true, false, true, false, 'Wartet auf Browser'],
            'own active runtime target' => [true, false, true, true, 'Verbunden'],
            'paused or completed runtime' => [false, false, true, true, 'Letzte Vorschau'],
            'historical view cannot show another active run' => [true, true, true, true, 'Letzte Vorschau'],
        ];
    }

    public function test_browser_mini_escapes_screenshot_and_title_attributes_and_keeps_a_local_image_failure_fallback(): void
    {
        $screenshot = 'https://example.test/shot.png?state="quoted"&next=one';
        $html = view('livewire.admin.network.workflow-studio.browser-windows', [
            'session' => (object) ['id' => 100], 'isActive' => false, 'isPaused' => true,
            'historicalRunView' => false, 'autonomousMode' => false,
            'browserWindows' => [[
                'name' => 'main', 'title' => '<img onerror="bad()">', 'url' => '', 'active' => true,
                'runtime' => true, 'connected' => true, 'screenshot_url' => $screenshot,
            ]],
        ])->render();
        $xpath = $this->xpath($html);
        $image = $xpath->query('//*[@data-studio-browser-mini-image]');
        $this->assertSame(1, $image->count());
        $this->assertSame($screenshot, $image->item(0)->getAttribute('src'));
        $this->assertSame('lazy', $image->item(0)->getAttribute('loading'));
        $this->assertSame('async', $image->item(0)->getAttribute('decoding'));
        $this->assertSame('imageFailed = true; imageLoaded = false', $image->item(0)->getAttribute('x-on:error'));
        $this->assertSame(1, $xpath->query('//*[@data-studio-browser-mini-fallback]')->count());
        $this->assertSame(0, $xpath->query('//img[@onerror]')->count(), 'Window metadata must never create executable HTML.');
        $this->assertStringNotContainsString('<img onerror="bad()">', $html);
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
