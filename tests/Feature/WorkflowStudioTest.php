<?php

namespace Tests\Feature;

use App\Enums\WorkflowCopilotPermissionMode;
use App\Jobs\RunWorkflowJob;
use App\Jobs\WorkflowCopilotSupervisorJob;
use App\Livewire\Admin\Network\WorkflowManager;
use App\Livewire\Admin\Network\WorkflowsIndex;
use App\Livewire\Admin\Network\WorkflowStudio;
use App\Livewire\Admin\Network\WorkflowStudioTaskEditor;
use App\Models\Setting;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use App\Models\WorkflowStepRun;
use App\Services\Ai\AiConnectionService;
use App\Services\Workflows\WorkflowCopilotSessionService;
use App\Services\Workflows\WorkflowExecutionService;
use App\Services\Workflows\WorkflowStudioAuthorizationService;
use App\Services\Workflows\WorkflowStudioCheckpointService;
use App\Services\Workflows\WorkflowStudioRevisionService;
use App\Services\Workflows\WorkflowStudioSessionService;
use App\Services\Workflows\WorkflowTaskRunner;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Mockery;
use ReflectionClass;
use Tests\TestCase;

class WorkflowStudioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=']);
    }

    public function test_permission_modes_are_enforced_server_side_and_confirmation_ids_are_parameter_bound(): void
    {
        [$workflow] = $this->workflow();
        $admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $sessions = app(WorkflowStudioSessionService::class);
        $authorization = app(WorkflowStudioAuthorizationService::class);

        $askAll = $sessions->open($workflow, $admin, 'assisted', 'ask_all');
        $this->assertTrue($authorization->decide($askAll, 'selector.search', ['selector' => '#login'])['requires_confirmation']);

        $critical = $sessions->open($workflow, $admin, 'assisted', 'ask_critical');
        $this->assertTrue($authorization->decide($critical, 'selector.search', ['selector' => '#login'])['allowed']);
        $this->assertTrue($authorization->decide($critical, 'task.add', ['task_key' => 'browser.find_element'])['allowed']);
        $this->assertTrue($authorization->decide($critical, 'workflow.replace', ['operations' => [1, 2]])['requires_confirmation']);
        $external = $authorization->decide($critical, 'probe.click', ['selector' => '#submit']);
        $this->assertTrue($external['requires_confirmation']);
        $authorization->confirm($critical, $external['confirmation_id']);
        $this->assertTrue($authorization->decide($critical->fresh(), 'probe.click', ['selector' => '#submit'], $external['confirmation_id'])['allowed']);
        $this->assertFalse($authorization->decide($critical->fresh(), 'probe.click', ['selector' => '#delete'], $external['confirmation_id'])['allowed']);

        $authorization->setPermissionMode($critical, WorkflowCopilotPermissionMode::UNRESTRICTED, $admin, true);
        $this->assertTrue($authorization->decide($critical->fresh(), 'external.delete', ['id' => 12])['allowed']);
        $this->assertDatabaseHas('workflow_studio_events', ['workflow_studio_session_id' => $critical->id, 'event_type' => 'permission.changed', 'level' => 'warning']);
    }

    public function test_unrestricted_requires_an_admin_and_explicit_session_warning(): void
    {
        [$workflow] = $this->workflow();
        $user = User::factory()->create(['role' => 'user', 'status' => true]);
        $session = app(WorkflowStudioSessionService::class)->open($workflow, $user, 'assisted', 'ask_critical');

        $this->expectException(DomainException::class);
        app(WorkflowStudioAuthorizationService::class)->setPermissionMode($session, 'unrestricted', $user, true);
    }

    public function test_unrestricted_admin_still_has_to_acknowledge_the_session_warning(): void
    {
        [$workflow] = $this->workflow();
        $admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $session = app(WorkflowStudioSessionService::class)->open($workflow, $admin, 'assisted', 'ask_critical');

        $this->expectException(DomainException::class);
        app(WorkflowStudioAuthorizationService::class)->setPermissionMode($session, 'unrestricted', $admin, false);
    }

    public function test_legacy_auto_execute_boolean_maps_to_the_new_global_permission_default(): void
    {
        [$workflow] = $this->workflow();
        Setting::setValue('ai_assistant', 'workflow_copilot', [
            'optimization_defaults' => ['auto_execute_workflow_actions' => false],
        ]);
        $askAll = app(WorkflowStudioSessionService::class)->open($workflow, null, 'manual');
        $this->assertSame('ask_all', $askAll->permission_mode);

        Setting::setValue('ai_assistant', 'workflow_copilot', [
            'optimization_defaults' => ['auto_execute_workflow_actions' => true],
        ]);
        $askCritical = app(WorkflowStudioSessionService::class)->open($workflow, null, 'manual');
        $this->assertSame('ask_critical', $askCritical->permission_mode);
    }

    public function test_restoring_a_revision_creates_a_new_revision_without_deleting_history(): void
    {
        [$workflow] = $this->workflow();
        $admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $session = app(WorkflowStudioSessionService::class)->open($workflow, $admin, 'manual', 'ask_critical');
        $revisions = app(WorkflowStudioRevisionService::class);
        $baseline = $revisions->ensureBaseline($session);
        $revisions->apply($session, 0, 'Beschreibung aendern', function (Workflow $locked): void {
            $locked->forceFill(['description' => 'Revision eins'])->save();
        });

        $restored = $revisions->restore($session->fresh(), $baseline, 1, 'Ausgangsstand wiederherstellen');

        $this->assertSame(2, $restored->revision_number);
        $this->assertSame('', (string) $workflow->fresh()->description);
        $this->assertSame([0, 1, 2], $workflow->studioRevisions()->pluck('revision_number')->all());
        $this->assertFalse($restored->is_verified);
    }

    public function test_checkpoint_persists_runtime_context_and_restores_a_paused_run(): void
    {
        [$workflow, $step] = $this->workflow();
        $admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $session = app(WorkflowStudioSessionService::class)->open($workflow, $admin, 'manual', 'ask_critical');
        app(WorkflowStudioRevisionService::class)->ensureBaseline($session);
        $run = WorkflowRun::query()->create([
            'run_uuid' => (string) str()->uuid(),
            'workflow_id' => $workflow->id,
            'workflow_studio_session_id' => $session->id,
            'workflow_revision' => 0,
            'current_workflow_step_id' => $step->id,
            'status' => 'paused',
            'requested_by' => 'test',
            'queued_at' => now(),
            'context_json' => [
                'next_task_key' => 'first-task',
                'workflow_variables' => ['items' => ['one', 'two']],
                'loop_state' => ['active' => ['index' => 1]],
                'browser_windows' => ['main' => ['currentUrl' => 'https://example.test']],
                'token' => 'secret-value',
            ],
            'result_json' => [],
        ]);
        app(WorkflowStudioSessionService::class)->attachRun($session, $run);
        $checkpoints = app(WorkflowStudioCheckpointService::class);
        $checkpoint = $checkpoints->create($session->fresh(), $run, 'Nach Suche');
        $run->forceFill(['context_json' => ['workflow_variables' => ['items' => []]], 'current_workflow_step_id' => null])->save();

        $restored = $checkpoints->restore($session->fresh(), $checkpoint, $run->fresh());

        $this->assertSame('paused', $restored->status);
        $this->assertSame(['one', 'two'], data_get($restored->context_json, 'workflow_variables.items'));
        $this->assertSame(1, data_get($restored->context_json, 'loop_state.active.index'));
        $this->assertSame('[geschuetzt]', data_get($checkpoint->context_json, 'token'));
        $this->assertNotSame('secret-value', $checkpoint->encrypted_runtime_context);
    }

    public function test_worker_blocks_external_copilot_task_until_server_side_confirmation(): void
    {
        Queue::fake();
        [$workflow, $step] = $this->workflow();
        $config = $step->config_json;
        $config['tasks'] = [[
            'key' => 'submit-form',
            'task_key' => 'input.submit',
            'title' => 'Formular absenden',
            'runner' => 'node',
            'node_script' => 'node/workflows/tasks/input/submit.cjs',
            'selector' => 'button[type=submit]',
        ]];
        $step->forceFill(['config_json' => $config])->save();
        $admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $studio = app(WorkflowStudioSessionService::class)->open($workflow, $admin, 'autonomous', 'ask_critical');
        $copilot = app(WorkflowCopilotSessionService::class)->start($workflow->fresh());
        $studio->forceFill(['workflow_copilot_session_id' => $copilot->id])->save();
        $run = app(WorkflowExecutionService::class)->start($workflow->fresh(), [
            'workflow_copilot_session_id' => $copilot->id,
            'workflow_studio_session_id' => $studio->id,
            'copilot_supervised' => true,
            'execution_target' => 'system',
        ], 'workflow-copilot');

        app(WorkflowExecutionService::class)->advance($run);

        $pending = data_get($studio->fresh()->state_json, 'pending_copilot_confirmation');
        $this->assertSame('paused', $run->fresh()->status);
        $this->assertSame('paused', $copilot->fresh()->status);
        $this->assertSame('external.send', $pending['action']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $pending['confirmation_id']);
        $this->assertDatabaseMissing('workflow_step_runs', ['workflow_run_id' => $run->id, 'status' => 'running']);
    }

    public function test_committing_a_probe_creates_exactly_one_revision_while_the_probe_itself_creates_none(): void
    {
        [$workflow, $step] = $this->workflow();
        $admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $studio = app(WorkflowStudioSessionService::class)->open($workflow, $admin, 'assisted', 'ask_critical');
        app(WorkflowStudioRevisionService::class)->ensureBaseline($studio);
        $run = WorkflowRun::query()->create([
            'run_uuid' => (string) str()->uuid(),
            'workflow_id' => $workflow->id,
            'workflow_studio_session_id' => $studio->id,
            'workflow_revision' => 0,
            'current_workflow_step_id' => $step->id,
            'status' => 'paused',
            'requested_by' => 'test',
            'queued_at' => now(),
            'context_json' => [
                'next_task_key' => 'first-task',
                'studio_probe_result' => [
                    'successful' => true,
                    'task' => [
                        'key' => 'studio-probe-search',
                        'task_key' => 'browser.find_element',
                        'title' => 'Suchfeld pruefen',
                        'runner' => 'node',
                        'node_script' => 'node/workflows/tasks/browser/find_element.cjs',
                        'selector' => '#search',
                    ],
                    'result' => ['ok' => true],
                ],
            ],
            'result_json' => [],
        ]);
        app(WorkflowStudioSessionService::class)->attachRun($studio, $run);
        $this->assertSame(1, $workflow->studioRevisions()->count());

        $this->actingAs($admin);
        Livewire::test(WorkflowStudio::class, ['workflow' => $workflow])
            ->call('commitProbeAsTask')
            ->assertHasNoErrors();

        $this->assertSame(1, $workflow->fresh()->copilot_revision);
        $this->assertSame(2, $workflow->studioRevisions()->count());
        $this->assertCount(2, $step->fresh()->task_cards);
    }

    public function test_unified_studio_renders_the_known_preview_tabs_and_permanent_copilot(): void
    {
        [$workflow] = $this->workflow();
        $admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $this->actingAs($admin);

        Livewire::test(WorkflowStudio::class, ['workflow' => $workflow])
            ->assertSee('Workflow-Vorschau & Live-Ausführung', false)
            ->assertSee('Bis Ende starten')
            ->assertSee('Eine Task')
            ->assertSeeHtml('wire:click="stopRun"')
            ->assertDontSeeHtml('wire:click="terminateRun"')
            ->assertSee('Workflow-Vorschau')
            ->assertSee('Browser & Selector')
            ->assertSee('Daten & Checkpoints')
            ->assertSee('Browserfenster')
            ->assertSee('Selector prüfen')
            ->assertSee('Immer bereit')
            ->assertSee('Eigenes Testen')
            ->assertSee('Autonomer Copilot')
            ->assertSee('Kritisch nachfragen')
            ->assertDontSee('Workflow-Outline')
            ->assertDontSee('Checkpoints verwalten')
            ->assertDontSeeHtml('wire:model="showCheckpointsModal"');

        Livewire::test(WorkflowStudio::class, ['workflow' => $workflow])
            ->call('openSelectorProbe', 'main')
            ->assertSet('probeBrowserWindow', 'main')
            ->assertSet('showSelectorProbeModal', true)
            ->assertSee('Selector-Prüfung');
    }

    public function test_browser_tools_are_a_workspace_tab_and_only_builder_uses_a_modal(): void
    {
        [$workflow] = $this->workflow();
        $admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $this->actingAs($admin);

        Livewire::test(WorkflowStudio::class, ['workflow' => $workflow])
            ->assertSet('activeStudioPanel', '')
            ->assertSet('activeWorkspaceTab', 'test')
            ->set('activeWorkspaceTab', 'tools')
            ->assertSee('Browserwerkzeuge')
            ->call('openStudioPanel', 'tools')
            ->assertSet('activeStudioPanel', '')
            ->call('openStudioPanel', 'builder')
            ->assertSet('activeStudioPanel', 'builder')
            ->assertSee('Workflow und Task bearbeiten')
            ->call('closeStudioPanel')
            ->assertSet('activeStudioPanel', '')
            ->call('openStudioPanel', 'invalid-panel')
            ->assertSet('activeStudioPanel', '');
    }

    public function test_revision_history_is_opened_from_the_workflow_manager_actions(): void
    {
        [$workflow] = $this->workflow();
        $admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $this->actingAs($admin);

        Livewire::test(WorkflowManager::class, ['workflow' => $workflow])
            ->assertSee('Interaktiv testen')
            ->assertSee('Autonom optimieren')
            ->assertSee('Revisionen')
            ->call('openRevisionHistory')
            ->assertSet('showRevisionHistoryModal', true)
            ->assertSet('revisionStudioSessionId', fn ($value): bool => is_int($value) && $value > 0)
            ->assertSee('Workflow-Revisionen');

        $this->assertSame(1, $workflow->studioRevisions()->count());
    }

    public function test_normal_resume_runs_continuously_while_single_task_resume_sets_a_one_shot_pause(): void
    {
        Queue::fake();
        [$workflow, $step] = $this->workflow();
        $run = WorkflowRun::query()->create([
            'run_uuid' => (string) str()->uuid(),
            'workflow_id' => $workflow->id,
            'workflow_revision' => 0,
            'current_workflow_step_id' => $step->id,
            'status' => 'paused',
            'requested_by' => 'test',
            'queued_at' => now(),
            'context_json' => [
                'next_task_key' => 'first-task',
                'manual_pause_requested' => false,
                'studio_single_task' => true,
            ],
            'result_json' => [],
        ]);
        $execution = app(WorkflowExecutionService::class);

        $execution->resumeManualPause($run);

        $this->assertSame('running', $run->fresh()->status);
        $this->assertArrayNotHasKey('studio_single_task', $run->fresh()->context_json);

        $run->refresh()->forceFill(['status' => 'paused'])->save();
        $execution->resumeManualPause($run->fresh(), $step->id, 'first-task', true);

        $this->assertTrue((bool) data_get($run->fresh()->context_json, 'studio_single_task'));
        Queue::assertPushed(RunWorkflowJob::class, 2);
    }

    public function test_single_task_button_starts_at_the_selected_task_without_an_existing_run(): void
    {
        Queue::fake();
        [$workflow, $step] = $this->workflow();
        $admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $this->actingAs($admin);

        Livewire::test(WorkflowStudio::class, ['workflow' => $workflow])
            ->assertSet('selectedStepId', (string) $step->id)
            ->assertSet('selectedTaskKey', 'first-task')
            ->call('runSingleTask')
            ->assertHasNoErrors();

        $run = $workflow->runs()->latest('id')->firstOrFail();
        $this->assertSame('queued', $run->status);
        $this->assertTrue((bool) data_get($run->context_json, 'studio_single_task'));
        $this->assertTrue((bool) data_get($run->context_json, 'interactive_debug'));
        $this->assertSame($step->action_key, data_get($run->context_json, 'next_step_action_key'));
        $this->assertSame('first-task', data_get($run->context_json, 'next_task_key'));
        $this->assertSame($run->id, $workflow->studioSessions()->latest('id')->firstOrFail()->active_workflow_run_id);
        Queue::assertPushed(RunWorkflowJob::class, 1);
    }

    public function test_restart_force_terminates_the_old_run_and_records_the_new_run_result_and_event(): void
    {
        Queue::fake();
        [$workflow, $step] = $this->workflow();
        $admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $session = app(WorkflowStudioSessionService::class)->open($workflow, $admin, 'manual', 'ask_critical');
        $oldRun = WorkflowRun::query()->create([
            'run_uuid' => (string) str()->uuid(),
            'workflow_id' => $workflow->id,
            'workflow_revision' => 0,
            'current_workflow_step_id' => $step->id,
            'status' => 'waiting',
            'requested_by' => 'workflow-studio',
            'queued_at' => now()->subMinute(),
            'started_at' => now()->subMinute(),
            'context_json' => ['next_task_key' => 'first-task'],
            'result_json' => [],
        ]);
        app(WorkflowStudioSessionService::class)->attachRun($session, $oldRun);
        $this->actingAs($admin);

        $component = Livewire::test(WorkflowStudio::class, ['workflow' => $workflow->fresh()])
            ->call('restartRun')
            ->assertHasNoErrors()
            ->assertSet('lastActionResult.status', 'queued')
            ->assertSet('lastActionResult.message', 'Workflow-Test wurde neu gestartet.');

        $newRun = $workflow->runs()->latest('id')->firstOrFail();
        $this->assertNotSame($oldRun->id, $newRun->id);
        $this->assertSame('cancelled', $oldRun->fresh()->status);
        $this->assertNotNull(data_get($oldRun->fresh()->result_json, 'process_termination.at'));
        $this->assertSame($newRun->id, $session->fresh()->active_workflow_run_id);
        $this->assertNotNull($session->fresh()->mode_locked_at);
        $this->assertDatabaseHas('workflow_studio_events', [
            'workflow_studio_session_id' => $session->id,
            'event_type' => 'run.restarted',
            'message' => 'Workflow-Test wurde neu gestartet.',
        ]);
        $component->assertSee('Workflow-Test wurde neu gestartet.');
        Queue::assertPushed(RunWorkflowJob::class, 1);
    }

    public function test_task_navigation_moves_the_selection_across_lists_without_executing_tasks(): void
    {
        Queue::fake();
        [$workflow, $step] = $this->workflow();
        $config = $step->config_json;
        $config['tasks'][] = [
            'key' => 'second-task',
            'task_key' => 'wait.seconds',
            'title' => 'Zweiter Task',
            'value' => 0,
        ];
        $step->forceFill(['config_json' => $config])->save();
        $secondStep = $workflow->steps()->create([
            'name' => 'Abschluss',
            'type' => WorkflowStep::TYPE_CLEANUP,
            'action_key' => 'abschluss',
            'position' => 20,
            'is_enabled' => true,
            'config_json' => ['tasks' => [[
                'key' => 'third-task',
                'task_key' => 'wait.seconds',
                'title' => 'Dritter Task',
                'value' => 0,
            ]]],
        ]);
        $admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $this->actingAs($admin);

        Livewire::test(WorkflowStudio::class, ['workflow' => $workflow->fresh()])
            ->assertSet('selectedTaskKey', 'first-task')
            ->call('selectNextTask')
            ->assertSet('selectedTaskKey', 'second-task')
            ->call('selectNextTask')
            ->assertSet('selectedStepId', (string) $secondStep->id)
            ->assertSet('selectedTaskKey', 'third-task')
            ->call('selectPreviousTask')
            ->assertSet('selectedStepId', (string) $step->id)
            ->assertSet('selectedTaskKey', 'second-task');

        Queue::assertNothingPushed();
        $this->assertSame(0, $workflow->runs()->count());
    }

    public function test_interactive_run_continues_after_a_failed_task_when_an_executable_error_route_exists(): void
    {
        Queue::fake();
        [$workflow, $step] = $this->workflow();
        $tasks = $step->task_cards;
        $tasks[0]['on_error'] = [
            'type' => 'card',
            'action_key' => $step->action_key,
            'step' => $step->action_key,
            'card_key' => 'alternative-task',
            'card' => 'alternative-task',
        ];
        $tasks[] = [
            'key' => 'alternative-task',
            'task_key' => 'wait.seconds',
            'title' => 'Alternative Task',
            'value' => 0,
        ];
        $config = $step->config_json;
        $config['tasks'] = $tasks;
        $step->forceFill(['config_json' => $config])->save();
        $run = WorkflowRun::query()->create([
            'run_uuid' => (string) str()->uuid(),
            'workflow_id' => $workflow->id,
            'current_workflow_step_id' => $step->id,
            'status' => 'running',
            'requested_by' => 'test',
            'queued_at' => now(),
            'context_json' => [
                'interactive_debug' => true,
                'next_task_key' => 'first-task',
            ],
            'result_json' => [],
        ]);
        $stepRun = WorkflowStepRun::query()->create([
            'workflow_run_id' => $run->id,
            'workflow_step_id' => $step->id,
            'status' => 'running',
            'started_at' => now(),
            'result_json' => [],
        ])->load(['workflowRun', 'workflowStep']);

        $execution = app(WorkflowExecutionService::class);
        $method = (new ReflectionClass($execution))->getMethod('continueInteractiveDebugTask');
        $method->invoke($execution, $stepRun, [
            'ok' => true,
            'status' => 'success',
            'statusMessage' => 'Erste Variante nicht gefunden.',
            'routeRequested' => true,
            'routeOutcome' => 'failed',
            'completedTaskKey' => 'first-task',
            'tasks' => [['key' => 'first-task', 'status' => 'failed']],
        ], true);

        $run->refresh();
        $stepRun->refresh();
        $this->assertSame('running', $run->status);
        $this->assertSame('alternative-task', data_get($run->context_json, 'next_task_key'));
        $this->assertTrue((bool) data_get($run->context_json, 'manual_debug_last_task.routed_failure'));
        $this->assertFalse((bool) data_get($run->context_json, 'manual_debug_last_task.successful'));
        $this->assertSame('queued', $stepRun->status);
        $this->assertNull($stepRun->error_message);
        Queue::assertPushed(RunWorkflowJob::class, 1);
    }

    public function test_copilot_checkpoint_treats_a_configured_error_branch_as_continuable(): void
    {
        [$workflow, $step] = $this->workflow();
        $tasks = $step->task_cards;
        $tasks[0]['on_error'] = [
            'type' => 'card',
            'action_key' => $step->action_key,
            'step' => $step->action_key,
            'card_key' => 'alternative-task',
            'card' => 'alternative-task',
        ];
        $tasks[] = [
            'key' => 'alternative-task',
            'task_key' => 'wait.seconds',
            'title' => 'Alternative Task',
            'value' => 0,
        ];
        $config = $step->config_json;
        $config['tasks'] = $tasks;
        $step->forceFill(['config_json' => $config])->save();
        $run = WorkflowRun::query()->create([
            'run_uuid' => (string) str()->uuid(),
            'workflow_id' => $workflow->id,
            'current_workflow_step_id' => $step->id,
            'status' => 'running',
            'requested_by' => 'test',
            'queued_at' => now(),
            'context_json' => [
                'copilot_current_task_key' => 'first-task',
            ],
            'result_json' => [],
        ]);
        $stepRun = WorkflowStepRun::query()->create([
            'workflow_run_id' => $run->id,
            'workflow_step_id' => $step->id,
            'status' => 'running',
            'started_at' => now(),
            'result_json' => [],
        ]);

        $execution = app(WorkflowExecutionService::class);
        $method = (new ReflectionClass($execution))->getMethod('persistCopilotTaskCheckpoint');
        $method->invoke($execution, $stepRun, [], [
            'ok' => true,
            'status' => 'success',
            'statusMessage' => 'Alternative Route verwenden.',
            'routeRequested' => true,
            'routeOutcome' => 'failed',
            'completedTaskKey' => 'first-task',
            'tasks' => [['key' => 'first-task', 'status' => 'failed']],
        ], true);

        $checkpoint = $run->fresh()->context_json['copilot_checkpoint'];
        $this->assertTrue($checkpoint['successful']);
        $this->assertFalse($checkpoint['task_successful']);
        $this->assertTrue($checkpoint['routed_failure']);
        $this->assertSame('next_task', $checkpoint['next_action']);
        $this->assertSame('alternative-task', $checkpoint['next_task_key']);
    }

    public function test_paused_studio_run_can_continue_after_a_task_revision_without_losing_runtime_state(): void
    {
        Queue::fake();
        [$workflow, $step] = $this->workflow();
        $admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $session = app(WorkflowStudioSessionService::class)->open($workflow, $admin, 'manual', 'ask_critical');
        app(WorkflowStudioRevisionService::class)->ensureBaseline($session);
        $run = WorkflowRun::query()->create([
            'run_uuid' => (string) str()->uuid(),
            'workflow_id' => $workflow->id,
            'workflow_studio_session_id' => $session->id,
            'workflow_revision' => 0,
            'current_workflow_step_id' => $step->id,
            'status' => 'paused',
            'requested_by' => 'test',
            'queued_at' => now(),
            'context_json' => [
                'next_task_key' => 'first-task',
                'workflow_variables' => ['query' => 'bleibt-erhalten'],
                'browser_windows' => ['main' => ['currentUrl' => 'https://example.test']],
            ],
            'result_json' => [],
        ]);
        app(WorkflowStudioSessionService::class)->attachRun($session, $run);
        app(WorkflowStudioRevisionService::class)->apply(
            $session->fresh(),
            0,
            'Task-Titel korrigiert',
            function () use ($step): void {
                $config = $step->fresh()->config_json;
                $config['tasks'][0]['title'] = 'Korrigierter Task';
                $step->fresh()->forceFill(['config_json' => $config])->save();
            },
        );

        $this->actingAs($admin);
        Livewire::test(WorkflowStudio::class, ['workflow' => $workflow->fresh()])
            ->call('resumeRun')
            ->assertHasNoErrors();

        $run->refresh();
        $this->assertSame(1, $run->workflow_revision);
        $this->assertSame('running', $run->status);
        $this->assertSame('bleibt-erhalten', data_get($run->context_json, 'workflow_variables.query'));
        $this->assertSame('https://example.test', data_get($run->context_json, 'browser_windows.main.currentUrl'));
        $this->assertSame(1, data_get($run->context_json, 'studio_revision_rebases.0.to_revision'));
    }

    public function test_studio_task_editor_reuses_the_manager_form_and_saves_a_revision(): void
    {
        [$workflow, $step] = $this->workflow();
        $admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $session = app(WorkflowStudioSessionService::class)->open($workflow, $admin, 'manual', 'ask_critical');
        app(WorkflowStudioRevisionService::class)->ensureBaseline($session);
        $this->actingAs($admin);

        Livewire::test(WorkflowStudioTaskEditor::class, [
            'workflow' => $workflow,
            'studioSessionId' => $session->id,
        ])
            ->call('openFromStudio', $step->id, 'first-task')
            ->assertSet('showEditTaskModal', true)
            ->assertSee('Funktion / Node-Skript')
            ->assertSee('Rolle im Workflow')
            ->set('editingTaskTitle', 'Task im Studio bearbeitet')
            ->call('saveEditTaskCard')
            ->assertHasNoErrors()
            ->assertSet('showEditTaskModal', false);

        $this->assertSame('Task im Studio bearbeitet', data_get($step->fresh()->task_cards, '0.title'));
        $this->assertSame(1, $workflow->fresh()->copilot_revision);
        $this->assertSame(2, $workflow->studioRevisions()->count());
    }

    public function test_studio_task_editor_has_explicit_livewire_close_actions_for_each_child_modal(): void
    {
        [$workflow] = $this->workflow();
        $admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $session = app(WorkflowStudioSessionService::class)->open($workflow, $admin, 'manual', 'ask_critical');
        $this->actingAs($admin);

        Livewire::test(WorkflowStudioTaskEditor::class, [
            'workflow' => $workflow,
            'studioSessionId' => $session->id,
        ])
            ->assertSeeHtml('wire:click="closeAddStepModal"')
            ->assertSeeHtml('wire:click="closeEditStepModal"')
            ->assertSeeHtml('wire:click="closeAddTaskModal"')
            ->assertSeeHtml('wire:click="closeEditTaskModal"')
            ->set('showAddStepModal', true)
            ->call('closeAddStepModal')
            ->assertSet('showAddStepModal', false)
            ->set('showEditStepModal', true)
            ->call('closeEditStepModal')
            ->assertSet('showEditStepModal', false)
            ->set('showAddTaskModal', true)
            ->call('closeAddTaskModal')
            ->assertSet('showAddTaskModal', false)
            ->set('showEditTaskModal', true)
            ->call('closeEditTaskModal')
            ->assertSet('showEditTaskModal', false);

        $studioView = File::get(resource_path('views/livewire/admin/network/workflow-studio.blade.php'));
        $this->assertStringNotContainsString('x-on:keydown.escape.window="$wire.closeStudioPanel()"', $studioView);
    }

    public function test_paused_studio_builder_can_insert_a_catalog_task_and_records_a_revision(): void
    {
        [$workflow, $step] = $this->workflow();
        $admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $session = app(WorkflowStudioSessionService::class)->open($workflow, $admin, 'manual', 'ask_critical');
        app(WorkflowStudioRevisionService::class)->ensureBaseline($session);
        $run = WorkflowRun::query()->create([
            'run_uuid' => (string) str()->uuid(),
            'workflow_id' => $workflow->id,
            'workflow_studio_session_id' => $session->id,
            'workflow_revision' => 0,
            'current_workflow_step_id' => $step->id,
            'status' => 'paused',
            'requested_by' => 'test',
            'queued_at' => now(),
            'context_json' => ['next_task_key' => 'first-task'],
            'result_json' => [],
        ]);
        app(WorkflowStudioSessionService::class)->attachRun($session, $run);
        $this->actingAs($admin);

        Livewire::test(WorkflowStudioTaskEditor::class, [
            'workflow' => $workflow,
            'studioSessionId' => $session->id,
        ])
            ->assertSee('Task-Katalog')
            ->assertSee('Workflow aufbauen')
            ->call('prepareCatalogTask', 'wait.seconds')
            ->assertSet('showAddTaskModal', true)
            ->set('newTaskTitle', 'Im Studio eingefügt')
            ->set('newTaskInputValue', '1')
            ->call('addTaskCard')
            ->assertHasNoErrors()
            ->assertSet('showAddTaskModal', false);

        $this->assertCount(2, $step->fresh()->task_cards);
        $this->assertSame('Im Studio eingefügt', data_get($step->fresh()->task_cards, '1.title'));
        $this->assertSame(1, $workflow->fresh()->copilot_revision);
        $this->assertSame(2, $workflow->studioRevisions()->count());
        $this->assertSame('paused', $run->fresh()->status);
    }

    public function test_creation_with_copilot_builds_a_draft_revision_without_executing_it(): void
    {
        $ai = Mockery::mock(AiConnectionService::class);
        $ai->shouldReceive('json')->once()->andReturn([
            'summary' => 'Suchseite oeffnen und auf das Feld warten.',
            'assumptions' => [],
            'steps' => [[
                'name' => 'Suche vorbereiten',
                'action_key' => 'suche-vorbereiten',
                'type' => 'browser_task',
                'description' => 'Oeffnet die Suchseite.',
                'routes' => ['success' => ['type' => 'end']],
                'tasks' => [[
                    'key' => 'suche-oeffnen',
                    'task_key' => 'browser.open_url',
                    'title' => 'Suchseite oeffnen',
                    'parameters' => ['url' => 'https://example.test'],
                ]],
            ]],
        ]);
        $this->app->instance(AiConnectionService::class, $ai);

        Livewire::test(WorkflowsIndex::class)
            ->set('newWorkflowName', 'Copilot Entwurf')
            ->set('newWorkflowDescription', 'Wird im Studio geprueft.')
            ->set('newWorkflowGoal', 'Eine Suchseite fuer einen spaeteren Test vorbereiten.')
            ->set('newWorkflowSuccessCriteria', 'Suchseite wurde geoeffnet')
            ->set('newWorkflowInputs', '{"query":""}')
            ->set('newWorkflowPlanWithCopilot', true)
            ->call('createWorkflow')
            ->assertHasNoErrors();

        $workflow = Workflow::query()->where('name', 'Copilot Entwurf')->firstOrFail();
        $studio = $workflow->studioSessions()->firstOrFail();
        $this->assertSame('draft_ready', $studio->status);
        $this->assertSame(1, $workflow->copilot_revision);
        $this->assertSame(1, $workflow->steps()->count());
        $this->assertSame(0, $workflow->runs()->count());
        $this->assertSame([0, 1], $workflow->studioRevisions()->pluck('revision_number')->all());
    }

    public function test_empty_workflow_saves_a_blueprint_before_materializing_the_first_task(): void
    {
        Queue::fake();
        $workflow = Workflow::query()->create([
            'name' => 'Leerer Studio Workflow',
            'slug' => 'leerer-studio-workflow',
            'description' => '',
            'category' => 'test',
            'is_active' => true,
            'is_locked' => false,
            'trigger_type' => 'manual',
            'settings_json' => [],
        ]);
        $ai = Mockery::mock(AiConnectionService::class);
        $ai->shouldReceive('json')->once()->andReturn([
            'summary' => 'Katalogkonformen Startschritt erstellen.',
            'assumptions' => [],
            'steps' => [[
                'name' => 'Start',
                'action_key' => 'start',
                'type' => WorkflowStep::TYPE_WAIT,
                'routes' => ['success' => ['type' => 'end']],
                'tasks' => [[
                    'key' => 'kurz-warten',
                    'task_key' => 'wait.seconds',
                    'title' => 'Kurz warten',
                    'parameters' => ['value' => 1],
                ]],
            ]],
        ]);
        $this->app->instance(AiConnectionService::class, $ai);

        Livewire::test(WorkflowStudio::class, ['workflow' => $workflow])
            ->set('goal', 'Einen gueltigen Testlauf vorbereiten.')
            ->set('successCriteria', 'Der Workflow wird erfolgreich beendet')
            ->set('workflowInputs', '{"browser_window":"main"}')
            ->call('startCopilot')
            ->assertHasNoErrors();

        $copilot = $workflow->fresh()->copilotSessions()->sole();
        $this->assertSame(0, $workflow->fresh()->steps()->count());
        $this->assertDatabaseHas('workflow_optimization_plans', [
            'workflow_copilot_session_id' => $copilot->id,
            'status' => 'planned',
            'total_items' => 1,
        ]);
        $this->assertDatabaseHas('workflow_optimization_plan_items', [
            'task_key' => 'kurz-warten',
            'status' => 'planned',
        ]);
        $this->assertNotEmpty(data_get($copilot->state_json, 'definition_validation'));
        $this->assertSame('workflow-studio', data_get($copilot->state_json, 'launch_source'));
        Queue::assertPushed(
            WorkflowCopilotSupervisorJob::class,
            fn (WorkflowCopilotSupervisorJob $job): bool => $job->workflowCopilotSessionId === $copilot->id,
        );
    }

    public function test_force_termination_cleans_up_a_finished_runs_node_process_without_rewriting_its_result_status(): void
    {
        [$workflow, $step] = $this->workflow();
        $run = WorkflowRun::query()->create([
            'run_uuid' => (string) str()->uuid(),
            'workflow_id' => $workflow->id,
            'status' => 'completed',
            'requested_by' => 'test',
            'queued_at' => now()->subMinute(),
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
            'context_json' => [],
            'result_json' => ['ok' => true],
        ]);
        WorkflowStepRun::query()->create([
            'workflow_run_id' => $run->id,
            'workflow_step_id' => $step->id,
            'status' => 'completed',
            'external_run_type' => 'workflow-task',
            'external_run_id' => 'node-run-123',
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
            'logs_json' => [],
            'result_json' => ['ok' => true],
        ]);
        $message = 'Testlauf samt Node-Prozessbaum beenden.';
        $runner = Mockery::mock(WorkflowTaskRunner::class);
        $runner->shouldReceive('cancelRun')
            ->once()
            ->with('node-run-123', true, $message)
            ->andReturn(['ok' => true, 'processFamilyTerminated' => true]);
        $this->app->instance(WorkflowTaskRunner::class, $runner);

        $result = app(WorkflowExecutionService::class)->terminate($run, $message);

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['alreadyFinal']);
        $this->assertSame(1, $result['terminatedExternalRuns']);
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertSame(1, data_get($run->fresh()->result_json, 'process_termination.external_runs'));
    }

    public function test_force_cancelling_a_node_runner_terminates_its_exact_windows_process_tree(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('Windows taskkill contract test.');
        }

        $runId = 'force-stop-'.str()->uuid();
        $directory = storage_path('app/workflow-task-runs/'.$runId);
        File::ensureDirectoryExists($directory);
        File::put($directory.'/status.json', json_encode([
            'runId' => $runId,
            'pid' => 4242,
            'state' => 'running',
            'isRunning' => true,
        ], JSON_THROW_ON_ERROR));
        Process::fake();

        try {
            $result = app(WorkflowTaskRunner::class)->cancelRun($runId, true, 'Erzwungen beendet.');

            $this->assertTrue($result['ok']);
            Process::assertRan(fn ($process): bool => $process->command === [
                'taskkill',
                '/PID',
                '4242',
                '/T',
                '/F',
            ]);
        } finally {
            File::deleteDirectory($directory);
        }
    }

    private function workflow(): array
    {
        $workflow = Workflow::query()->create([
            'name' => 'Studio '.str()->random(6),
            'slug' => 'studio-'.str()->random(10),
            'description' => '',
            'category' => 'test',
            'is_active' => true,
            'is_locked' => false,
            'trigger_type' => 'manual',
            'settings_json' => [],
        ]);
        $step = $workflow->steps()->create([
            'name' => 'Browser Tasks',
            'type' => WorkflowStep::TYPE_BROWSER_TASK,
            'action_key' => 'browser-tasks',
            'position' => 10,
            'is_enabled' => true,
            'config_json' => ['tasks' => [[
                'key' => 'first-task',
                'task_key' => 'wait.seconds',
                'title' => 'Erster Task',
                'value' => 0,
            ]]],
        ]);

        return [$workflow, $step];
    }
}
