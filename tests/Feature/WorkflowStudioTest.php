<?php

namespace Tests\Feature;

use App\Enums\WorkflowCopilotPermissionMode;
use App\Jobs\RunWorkflowJob;
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
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_fullscreen_studio_renders_the_shared_preview_controls_and_permission_select(): void
    {
        [$workflow] = $this->workflow();
        $admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $this->actingAs($admin);

        Livewire::test(WorkflowStudio::class, ['workflow' => $workflow])
            ->assertSee('Workflow-Vorschau', false)
            ->assertSee('Selector-Probe')
            ->assertSee('Kritisch nachfragen')
            ->assertSee('Checkpoint');
    }

    public function test_revision_history_is_opened_from_the_workflow_manager_actions(): void
    {
        [$workflow] = $this->workflow();
        $admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $this->actingAs($admin);

        Livewire::test(WorkflowManager::class, ['workflow' => $workflow])
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
            ->set('editingTaskTitle', 'Task im Studio bearbeitet')
            ->call('saveEditTaskCard')
            ->assertHasNoErrors()
            ->assertSet('showEditTaskModal', false);

        $this->assertSame('Task im Studio bearbeitet', data_get($step->fresh()->task_cards, '0.title'));
        $this->assertSame(1, $workflow->fresh()->copilot_revision);
        $this->assertSame(2, $workflow->studioRevisions()->count());
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
