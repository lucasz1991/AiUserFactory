<?php

namespace Tests\Feature;

use App\Livewire\Admin\Network\WorkflowManager;
use App\Livewire\Admin\Network\WorkflowStudio;
use App\Livewire\Admin\Network\WorkflowStudioTaskEditor;
use App\Models\Person;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use App\Models\WorkflowStudioSession;
use App\Services\Workflows\WorkflowStudioSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class WorkflowWorkbenchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=']);
    }

    public function test_workbench_reuses_session_run_and_component_key_across_surfaces_close_and_reopen(): void
    {
        [$workflow, $step] = $this->workflow();
        $this->actingAs($this->admin());

        $manager = Livewire::test(WorkflowManager::class, ['workflow' => $workflow])
            ->call('openDefinitionWorkbench')
            ->assertSet('workbenchOpen', true)
            ->assertSet('workbenchBooted', true)
            ->assertSet('workbenchSurface', 'definition');

        $sessionId = (int) $manager->get('workbenchStudioSessionId');
        $stableKey = (int) $manager->get('testWorkbenchKey');
        $this->assertGreaterThan(0, $sessionId);
        $this->assertGreaterThan(0, $stableKey);

        $session = WorkflowStudioSession::query()->findOrFail($sessionId);
        $run = $this->workflowRun($workflow, $step, $session, 'running');
        app(WorkflowStudioSessionService::class)->attachRun($session, $run);

        $manager
            ->call('openTestWorkbench', 'interactive', $run->id)
            ->assertSet('workbenchSurface', 'test')
            ->assertSet('workbenchStudioSessionId', $sessionId)
            ->assertSet('workbenchRunId', $run->id)
            ->assertSet('testWorkbenchRunId', $run->id)
            ->assertSet('testWorkbenchKey', $stableKey)
            ->call('switchWorkbenchSurface', 'definition')
            ->assertSet('workbenchStudioSessionId', $sessionId)
            ->assertSet('workbenchRunId', $run->id)
            ->assertSet('testWorkbenchKey', $stableKey)
            ->call('switchWorkbenchSurface', 'test')
            ->assertSet('workbenchStudioSessionId', $sessionId)
            ->assertSet('workbenchRunId', $run->id)
            ->assertSet('testWorkbenchKey', $stableKey)
            ->call('closeWorkflowWorkbench')
            ->assertSet('workbenchOpen', false)
            ->assertSet('showTestWorkbenchModal', false)
            ->assertSet('workbenchStudioSessionId', $sessionId)
            ->assertSet('workbenchRunId', $run->id)
            ->assertSet('testWorkbenchRunId', $run->id)
            ->assertSet('testWorkbenchKey', $stableKey)
            ->call('openTestWorkbench', 'interactive')
            ->assertSet('workbenchOpen', true)
            ->assertSet('workbenchSurface', 'test')
            ->assertSet('workbenchStudioSessionId', $sessionId)
            ->assertSet('workbenchRunId', $run->id)
            ->assertSet('testWorkbenchKey', $stableKey);

        $this->assertSame($run->id, $session->fresh()->active_workflow_run_id);
        $this->assertSame('running', $run->fresh()->status);
    }

    public function test_pause_and_edit_unlocks_only_after_the_database_run_reaches_a_safe_pause(): void
    {
        [$workflow, $step] = $this->workflow();
        $admin = $this->admin();
        $session = app(WorkflowStudioSessionService::class)->open($workflow, $admin, 'manual', 'ask_critical');
        $run = $this->workflowRun($workflow, $step, $session, 'running');
        app(WorkflowStudioSessionService::class)->attachRun($session, $run);
        $this->actingAs($admin);

        $manager = Livewire::test(WorkflowManager::class, ['workflow' => $workflow])
            ->call('openTestWorkbench', 'interactive', $run->id)
            ->call('requestPauseAndEdit')
            ->assertSet('workbenchPauseRequested', true)
            ->assertDispatched('workflow-studio-pause-for-edit-requested');

        $manager
            ->call('handleWorkbenchRunStatusChanged', $session->id, $run->id, 'paused')
            ->assertSet('workbenchPauseRequested', true)
            ->assertSet('workbenchRunStatus', 'running');

        Livewire::test(WorkflowStudioTaskEditor::class, [
            'workflow' => $workflow,
            'studioSessionId' => $session->id,
        ])
            ->call('toggleStep', $step->id)
            ->assertHasErrors('studioBuilder');
        $this->assertTrue($step->fresh()->is_enabled);

        $run->forceFill(['status' => 'paused'])->save();

        $manager
            ->call('handleWorkbenchRunStatusChanged', $session->id, $run->id, 'paused')
            ->assertSet('workbenchPauseRequested', false)
            ->assertSet('workbenchSurface', 'definition')
            ->assertSet('workbenchRunStatus', 'paused');

        Livewire::test(WorkflowStudioTaskEditor::class, [
            'workflow' => $workflow,
            'studioSessionId' => $session->id,
        ])
            ->call('toggleStep', $step->id)
            ->assertHasNoErrors();
        $this->assertFalse($step->fresh()->is_enabled);
        $this->assertSame($run->id, $session->fresh()->active_workflow_run_id);
    }

    public function test_active_autonomous_context_remains_read_only_for_direct_server_calls(): void
    {
        [$workflow, $step] = $this->workflow();
        $admin = $this->admin();
        $session = app(WorkflowStudioSessionService::class)->open($workflow, $admin, 'autonomous', 'ask_critical');
        $run = $this->workflowRun($workflow, $step, $session, 'running');
        app(WorkflowStudioSessionService::class)->attachRun($session, $run);
        $this->actingAs($admin);

        Livewire::test(WorkflowManager::class, ['workflow' => $workflow])
            ->call('openTestWorkbench', 'autonomous', $run->id)
            ->assertSet('workbenchSessionMode', 'autonomous')
            ->call('switchWorkbenchSurface', 'definition')
            ->call('toggleStep', $step->id);
        $this->assertTrue($step->fresh()->is_enabled);

        Livewire::test(WorkflowStudioTaskEditor::class, [
            'workflow' => $workflow,
            'studioSessionId' => $session->id,
        ])
            ->call('toggleStep', $step->id)
            ->assertHasErrors('studioBuilder');

        $this->assertTrue($step->fresh()->is_enabled);
        $this->assertSame($run->id, $session->fresh()->active_workflow_run_id);
        $this->assertSame('running', $run->fresh()->status);
    }

    public function test_opening_a_historical_run_never_replaces_the_sessions_active_run(): void
    {
        [$workflow, $step] = $this->workflow();
        $admin = $this->admin();
        $session = app(WorkflowStudioSessionService::class)->open($workflow, $admin, 'manual', 'ask_critical');
        $activeRun = $this->workflowRun($workflow, $step, $session, 'running');
        app(WorkflowStudioSessionService::class)->attachRun($session, $activeRun);
        $historicalRun = $this->workflowRun($workflow, $step, $session, 'completed');
        $this->actingAs($admin);

        $manager = Livewire::test(WorkflowManager::class, ['workflow' => $workflow])
            ->call('openTestWorkbench', 'interactive', $historicalRun->id)
            ->assertSet('workbenchStudioSessionId', $session->id)
            ->assertSet('workbenchRunId', $historicalRun->id)
            ->assertSet('workbenchHistoricalRun', true)
            ->call('switchWorkbenchSurface', 'definition')
            ->assertSet('workbenchRunId', $historicalRun->id)
            ->call('switchWorkbenchSurface', 'test')
            ->assertSet('workbenchRunId', $historicalRun->id)
            ->call('closeWorkflowWorkbench')
            ->assertSet('workbenchRunId', $historicalRun->id);

        $this->assertSame($activeRun->id, $session->fresh()->active_workflow_run_id);
        $this->assertSame('running', $activeRun->fresh()->status);
        $this->assertSame('completed', $historicalRun->fresh()->status);

        $manager
            ->call('openTestWorkbench', 'interactive', $historicalRun->id)
            ->assertSet('workbenchRunId', $historicalRun->id)
            ->assertSet('workbenchHistoricalRun', true)
            ->call('handleWorkbenchRunStatusChanged', $session->id, $activeRun->id, 'running')
            ->assertSet('workbenchRunId', $activeRun->id)
            ->assertSet('workbenchHistoricalRun', false);
        $this->assertSame($activeRun->id, $session->fresh()->active_workflow_run_id);
    }

    public function test_hosted_historical_run_is_read_only_and_never_synchronizes_its_status_into_the_session(): void
    {
        [$workflow, $step] = $this->workflow();
        $admin = $this->admin();
        $session = app(WorkflowStudioSessionService::class)->open($workflow, $admin, 'manual', 'ask_critical');
        $activeRun = $this->workflowRun($workflow, $step, $session, 'running');
        app(WorkflowStudioSessionService::class)->attachRun($session, $activeRun);
        $session->forceFill(['status' => 'running', 'finished_at' => null])->save();
        $historicalRun = $this->workflowRun($workflow, $step, $session, 'completed');
        $this->actingAs($admin);

        Livewire::test(WorkflowStudio::class, [
            'workflow' => $workflow,
            'hosted' => true,
            'studioSessionId' => $session->id,
            'runId' => $historicalRun->id,
        ])
            ->assertSet('activeRunId', $historicalRun->id)
            ->assertSee('Historischer Testlauf')
            ->call('refreshStudio')
            ->call('restartRun')
            ->assertHasErrors('studio')
            ->call('stopRun')
            ->assertHasErrors('studio');

        $this->assertSame($activeRun->id, $session->fresh()->active_workflow_run_id);
        $this->assertSame('running', $session->fresh()->status);
        $this->assertSame('running', $activeRun->fresh()->status);
        $this->assertSame('completed', $historicalRun->fresh()->status);
    }

    public function test_unassigned_legacy_history_never_replaces_a_real_active_session_run(): void
    {
        [$workflow, $step] = $this->workflow();
        $admin = $this->admin();
        $session = app(WorkflowStudioSessionService::class)->open($workflow, $admin, 'manual', 'ask_critical');
        $activeRun = $this->workflowRun($workflow, $step, $session, 'running');
        app(WorkflowStudioSessionService::class)->attachRun($session, $activeRun);
        $legacyRun = WorkflowRun::query()->create([
            'run_uuid' => (string) str()->uuid(),
            'workflow_id' => $workflow->id,
            'workflow_revision' => 0,
            'current_workflow_step_id' => $step->id,
            'status' => 'completed',
            'requested_by' => 'legacy-test',
            'queued_at' => now()->subMinute(),
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
            'context_json' => ['next_task_key' => 'first-task'],
            'result_json' => [],
        ]);
        $this->actingAs($admin);

        Livewire::test(WorkflowManager::class, ['workflow' => $workflow])
            ->call('openTestWorkbench', 'interactive', $legacyRun->id)
            ->assertSet('workbenchStudioSessionId', $session->id)
            ->assertSet('workbenchRunId', $legacyRun->id)
            ->assertSet('workbenchHistoricalRun', true)
            ->assertSet('workbenchDefinitionCanEdit', false)
            ->call('refreshWorkbenchContext')
            ->assertSet('workbenchRunId', $legacyRun->id)
            ->assertSet('workbenchDefinitionCanEdit', false);

        Livewire::test(WorkflowStudio::class, [
            'workflow' => $workflow,
            'hosted' => true,
            'studioSessionId' => $session->id,
            'runId' => $legacyRun->id,
        ])
            ->assertSet('activeRunId', $legacyRun->id)
            ->call('restartRun')
            ->assertHasErrors('studio');

        $this->assertNull($legacyRun->fresh()->workflow_studio_session_id);
        $this->assertSame($activeRun->id, $session->fresh()->active_workflow_run_id);
        $this->assertSame('running', $activeRun->fresh()->status);
        $this->assertSame('completed', $legacyRun->fresh()->status);
    }

    public function test_hosted_context_can_explicitly_leave_history_for_the_sessions_current_run(): void
    {
        [$workflow, $step] = $this->workflow();
        $admin = $this->admin();
        $session = app(WorkflowStudioSessionService::class)->open($workflow, $admin, 'manual', 'ask_critical');
        $activeRun = $this->workflowRun($workflow, $step, $session, 'paused');
        app(WorkflowStudioSessionService::class)->attachRun($session, $activeRun);
        $historicalRun = $this->workflowRun($workflow, $step, $session, 'completed');
        $this->actingAs($admin);

        Livewire::test(WorkflowStudio::class, [
            'workflow' => $workflow,
            'hosted' => true,
            'studioSessionId' => $session->id,
            'runId' => $historicalRun->id,
        ])
            ->assertSet('activeRunId', $historicalRun->id)
            ->call('synchronizeHostedContext', $session->id, 'interactive', null)
            ->assertSet('activeRunId', $activeRun->id)
            ->assertHasNoErrors();
    }

    public function test_definition_child_refreshes_its_access_after_the_active_run_reaches_pause(): void
    {
        [$workflow, $step] = $this->workflow();
        $admin = $this->admin();
        $session = app(WorkflowStudioSessionService::class)->open($workflow, $admin, 'manual', 'ask_critical');
        $run = $this->workflowRun($workflow, $step, $session, 'running');
        app(WorkflowStudioSessionService::class)->attachRun($session, $run);
        $this->actingAs($admin);

        $editor = Livewire::test(WorkflowStudioTaskEditor::class, [
            'workflow' => $workflow,
            'studioSessionId' => $session->id,
        ])->assertSet('taskEditReadOnly', true);

        $run->forceFill(['status' => 'paused'])->save();

        $editor
            ->call('refreshDefinitionAccess', $session->id)
            ->assertSet('taskEditReadOnly', false)
            ->call('toggleStep', $step->id)
            ->assertHasNoErrors();

        $this->assertFalse($step->fresh()->is_enabled);
    }

    public function test_terminal_autonomous_run_releases_definition_when_no_copilot_lock_remains(): void
    {
        [$workflow, $step] = $this->workflow();
        $admin = $this->admin();
        $session = app(WorkflowStudioSessionService::class)->open($workflow, $admin, 'autonomous', 'ask_critical');
        $run = $this->workflowRun($workflow, $step, $session, 'completed');
        app(WorkflowStudioSessionService::class)->attachRun($session, $run);
        $this->actingAs($admin);

        Livewire::test(WorkflowStudioTaskEditor::class, [
            'workflow' => $workflow,
            'studioSessionId' => $session->id,
        ])
            ->assertSet('taskEditReadOnly', false)
            ->call('toggleStep', $step->id)
            ->assertHasNoErrors();

        $this->assertFalse($step->fresh()->is_enabled);
        $this->assertSame($run->id, $session->fresh()->active_workflow_run_id);
    }

    public function test_unlocked_autonomous_draft_without_a_run_keeps_the_definition_editable(): void
    {
        [$workflow, $step] = $this->workflow();
        $admin = $this->admin();
        $this->actingAs($admin);

        $manager = Livewire::test(WorkflowManager::class, ['workflow' => $workflow])
            ->call('openTestWorkbench', 'autonomous')
            ->assertSet('workbenchSessionMode', 'autonomous')
            ->assertSet('workbenchDefinitionCanEdit', true)
            ->call('switchWorkbenchSurface', 'definition');

        $sessionId = (int) $manager->get('workbenchStudioSessionId');
        Livewire::test(WorkflowStudioTaskEditor::class, [
            'workflow' => $workflow,
            'studioSessionId' => $sessionId,
        ])
            ->assertSet('taskEditReadOnly', false)
            ->call('toggleStep', $step->id)
            ->assertHasNoErrors();

        $this->assertFalse($step->fresh()->is_enabled);
    }

    public function test_overview_action_library_records_a_revision_after_the_workbench_was_mounted(): void
    {
        [$workflow] = $this->workflow();
        $admin = $this->admin();
        $person = Person::query()->create([
            'platform' => 'instagram',
            'profile_key' => 'workbench-action-person',
            'profile_label' => 'Workbench Action Person',
            'metadata' => [
                'internal_activity_simulation' => [
                    'status' => 'draft',
                    'days_plan' => [[
                        'date' => '2026-08-03',
                        'weekday' => 'Montag',
                        'content_items' => [[
                            'planned_time_local' => '12:00',
                            'type' => 'post',
                            'theme' => 'Revisionssichere Aktion',
                            'prompt' => 'Testaktion',
                        ]],
                        'sessions' => [],
                    ]],
                ],
            ],
        ]);
        $this->actingAs($admin);

        $manager = Livewire::test(WorkflowManager::class, ['workflow' => $workflow])
            ->call('openDefinitionWorkbench');
        $sessionId = (int) $manager->get('workbenchStudioSessionId');
        $stepCount = $workflow->steps()->count();

        $manager
            ->call('addActionStep', 'content-'.$person->id.'-0-0')
            ->assertHasNoErrors();

        $session = WorkflowStudioSession::query()->findOrFail($sessionId);
        $this->assertSame($stepCount + 1, $workflow->steps()->count());
        $this->assertSame(1, (int) $workflow->fresh()->copilot_revision);
        $this->assertSame(1, (int) $session->fresh()->current_revision);
        $this->assertSame(2, $session->revisions()->count());
    }

    public function test_markup_contains_one_shared_fullscreen_workbench_and_a_separate_static_overview_map(): void
    {
        $root = dirname(__DIR__, 2);
        $manager = file_get_contents($root.'/resources/views/livewire/admin/network/workflow-manager.blade.php');
        $definition = file_get_contents($root.'/resources/views/livewire/admin/network/partials/workflow-definition-editor.blade.php');
        $studio = file_get_contents($root.'/resources/views/livewire/admin/network/workflow-studio.blade.php');
        $minimap = file_get_contents($root.'/resources/views/components/workflows/minimap.blade.php');

        $this->assertSame(1, preg_match_all('/^\s*data-workflow-workbench\s*$/m', $manager));
        $this->assertSame(1, substr_count($manager, '<x-workflows.minimap'));
        $this->assertStringContainsString('data-workflow-overview-card', $manager);
        $this->assertStringContainsString(':selectable-tasks="false"', $manager);
        $this->assertStringContainsString('data-workflow-edit-cta', $manager);
        $this->assertMatchesRegularExpression('/data-workflow-edit-cta[\s\S]{0,500}min-h-11/', $manager);

        $this->assertStringNotContainsString('<x-workflows.minimap', $definition);
        $this->assertStringNotContainsString('overviewOpen', $definition);
        $this->assertStringNotContainsString('x-collapse', $definition);
        $this->assertStringContainsString('data-studio-workflow-canvas', $definition);

        $this->assertStringContainsString('data-workflow-workbench-definition', $manager);
        $this->assertStringContainsString('data-workflow-workbench-test', $manager);
        $this->assertStringContainsString('x-show.important="workbenchSurface === \'definition\'"', $manager);
        $this->assertStringContainsString('x-show.important="workbenchSurface === \'test\'"', $manager);
        $this->assertStringContainsString('x-bind:inert="workbenchSurface !== \'definition\'"', $manager);
        $this->assertStringContainsString('x-bind:inert="workbenchSurface !== \'test\'"', $manager);
        $this->assertStringContainsString('<livewire:admin.network.workflow-studio-task-editor', $manager);
        $this->assertStringContainsString(':hosted="true"', $manager);
        $this->assertStringContainsString(':studio-session-id="$workbenchStudioSessionId"', $manager);
        $this->assertStringContainsString('workflow-workbench-test-', $manager);
        $this->assertStringContainsString("\$hosted ? 'relative h-full'", $studio);

        $this->assertStringContainsString('data-workflow-minimap-zoom', $minimap);
        $this->assertStringContainsString('class="inline-flex min-h-11', $minimap);
        $this->assertStringContainsString('h-[100dvh]', $manager);
    }

    private function admin(): User
    {
        return User::factory()->create([
            'role' => 'admin',
            'status' => true,
        ]);
    }

    /** @return array{0: Workflow, 1: WorkflowStep} */
    private function workflow(): array
    {
        $workflow = Workflow::query()->create([
            'name' => 'Workbench '.str()->random(6),
            'slug' => 'workbench-'.str()->random(10),
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

    private function workflowRun(
        Workflow $workflow,
        WorkflowStep $step,
        WorkflowStudioSession $session,
        string $status,
    ): WorkflowRun {
        return WorkflowRun::query()->create([
            'run_uuid' => (string) str()->uuid(),
            'workflow_id' => $workflow->id,
            'workflow_studio_session_id' => $session->id,
            'workflow_revision' => (int) $workflow->copilot_revision,
            'current_workflow_step_id' => $step->id,
            'status' => $status,
            'requested_by' => 'workflow-workbench-test',
            'queued_at' => now(),
            'started_at' => $status === 'running' ? now() : null,
            'finished_at' => $status === 'completed' ? now() : null,
            'context_json' => ['next_task_key' => 'first-task'],
            'result_json' => [],
        ]);
    }
}
