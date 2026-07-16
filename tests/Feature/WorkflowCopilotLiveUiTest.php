<?php

namespace Tests\Feature;

use App\Jobs\WorkflowCopilotSupervisorJob;
use App\Livewire\Admin\Network\WorkflowManager;
use App\Livewire\Tools\Chatbot;
use App\Models\Setting;
use App\Models\Workflow;
use App\Models\WorkflowCopilotSession;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use App\Services\Ai\AiConnectionService;
use App\Services\Ai\WorkflowCopilotAiUsageTracker;
use App\Services\Workflows\WorkflowCopilotSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class WorkflowCopilotLiveUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=']);
    }

    public function test_manager_starts_an_exclusively_system_bound_optimization_session(): void
    {
        Queue::fake();
        $workflow = $this->workflow('copilot-system-start');
        $ai = \Mockery::mock(AiConnectionService::class);
        $ai->shouldReceive('json')->once()->andReturnUsing(function (): array {
            app(WorkflowCopilotAiUsageTracker::class)->recordResponse(
                ['model' => 'test/planner'],
                [
                    'id' => 'initial-plan-generation',
                    'model' => 'test/planner',
                    'usage' => [
                        'prompt_tokens' => 200,
                        'completion_tokens' => 50,
                        'total_tokens' => 250,
                        'cost' => 0.0025,
                    ],
                ],
                'data_analysis',
            );

            return [
                'summary' => 'Ein kurzer, ausfuehrbarer Startplan.',
                'assumptions' => ['Der Test darf kurz warten.'],
                'steps' => [[
                    'name' => 'Start vorbereiten',
                    'type' => WorkflowStep::TYPE_WAIT,
                    'description' => 'Initialer, katalogkonformer Testschritt.',
                    'tasks' => [[
                        'task_key' => 'wait.seconds',
                        'title' => 'Kurz warten',
                        'parameters' => ['value' => 1],
                    ]],
                ]],
            ];
        });
        $this->app->instance(AiConnectionService::class, $ai);

        $component = Livewire::test(WorkflowManager::class, ['workflow' => $workflow])
            ->call('openCopilotOptimization')
            ->assertSet('showCopilotModal', true)
            ->set('copilotGoal', 'Der Workflow erreicht vollstaendig die Erfolgsseite.')
            ->set('copilotSuccessCriteria', "Finale URL enthaelt /success\nText Fertig ist sichtbar")
            ->set('copilotWorkflowInputs', '{"browser_window":"main"}')
            ->set('copilotMaxMinutes', 45)
            ->set('copilotMaxRepairIterations', 8)
            ->set('copilotMaxProbeActions', 25)
            ->set('copilotMaxSameStateRepeats', 2)
            ->call('startCopilotOptimization')
            ->assertHasNoErrors()
            ->assertSet('showCopilotModal', false)
            ->assertSet('showCopilotPreviewModal', false)
            ->assertSet('showRunPreviewModal', true)
            ->assertDispatched('workflow-copilot-session-activated');

        $session = WorkflowCopilotSession::query()->sole();

        $this->assertSame('system', $session->execution_target);
        $this->assertSame(45, $session->budget_json['max_minutes']);
        $this->assertSame(0.0025, $session->usage_json['cost_usd']);
        $this->assertSame(250, $session->usage_json['total_tokens']);
        $this->assertSame(['Finale URL enthaelt /success', 'Text Fertig ist sichtbar'], $session->success_criteria_json['assertions']);
        $this->assertSame($session->id, $workflow->fresh()->active_workflow_copilot_session_id);
        $this->assertSame('wait.seconds', data_get($workflow->fresh()->steps()->first()?->task_cards, '0.task_key'));
        $this->assertDatabaseHas('workflow_copilot_events', [
            'workflow_copilot_session_id' => $session->id,
            'event_type' => 'plan.applied',
        ]);
        Queue::assertPushed(
            WorkflowCopilotSupervisorJob::class,
            fn (WorkflowCopilotSupervisorJob $job): bool => $job->workflowCopilotSessionId === $session->id,
        );

        $component->call('pauseCopilotOptimization')->call('resumeCopilotOptimization');
        Queue::assertPushed(WorkflowCopilotSupervisorJob::class, 2);

        $checkpoint = app(WorkflowCopilotSessionService::class)->createCheckpoint($session->fresh(), [
            'phase' => 'observing',
            'task_key' => 'login-click',
            'cursor_json' => ['task_key' => 'login-click'],
            'is_reproducible' => true,
        ]);
        $component
            ->set('copilotRewindCheckpoint', (string) $checkpoint->id)
            ->call('rewindCopilotOptimization')
            ->assertHasNoErrors();
        Queue::assertPushed(WorkflowCopilotSupervisorJob::class, 3);
    }

    public function test_manager_blocks_start_when_autonomous_actions_are_disabled(): void
    {
        Queue::fake();
        Setting::setValue('ai_assistant', 'workflow_copilot', [
            'optimization_defaults' => [
                'auto_execute_workflow_actions' => false,
            ],
        ]);
        $workflow = $this->workflow('copilot-disabled');

        Livewire::test(WorkflowManager::class, ['workflow' => $workflow])
            ->call('openCopilotOptimization')
            ->set('copilotGoal', 'Der Workflow soll funktionieren.')
            ->set('copilotSuccessCriteria', 'Erfolgsseite sichtbar')
            ->call('startCopilotOptimization')
            ->assertHasErrors(['copilotAutoExecute'])
            ->assertSet('showCopilotModal', true);

        $this->assertDatabaseCount('workflow_copilot_sessions', 0);
        Queue::assertNothingPushed();
    }

    public function test_chat_polls_only_new_visible_events_and_routes_messages_to_the_active_session(): void
    {
        Queue::fake();
        $workflow = $this->workflow('copilot-live-chat');
        $service = app(WorkflowCopilotSessionService::class);
        $session = $service->start($workflow, [
            'goal' => 'Workflow erfolgreich abschliessen.',
            'success_criteria' => ['assertions' => ['Erfolgsseite sichtbar']],
        ]);
        $session->forceFill(['state_json' => [
            'vision' => [
                'page_type' => 'search',
                'ui_state' => 'search_input',
                'goal_progress' => 0.5,
                'confidence' => 0.91,
                'verdict' => 'continue',
                'blockers' => ['Suchbegriff fehlt noch.'],
                'relevant_elements' => [[
                    'element_ref' => 'el_search',
                    'reason' => 'Sichtbares Suchfeld',
                    'confidence' => 0.95,
                ]],
                'suggested_task_actions' => [[
                    'task_key' => 'input.fill_field',
                    'element_ref' => 'el_search',
                    'reason' => 'Suchbegriff eingeben',
                    'confidence' => 0.94,
                ]],
                'model' => 'test/vision',
                'analysis_source' => 'vision',
                'duration_ms' => 900,
            ],
        ]])->save();
        $service->appendEvent(
            $session,
            'model.reasoning',
            'Dieser interne Gedankengang darf nie sichtbar werden.',
        );
        $service->appendEvent(
            $session,
            'vision.analysis_started',
            'Die Bildanalyse des aktuellen Browserzustands laeuft.',
        );
        $analysis = $service->appendEvent(
            $session,
            'vision.analysis_completed',
            'Bildanalyse abgeschlossen: search / search_input (91 %). Entscheidung: Workflow fortsetzen.',
            ['ui_state' => 'search_input'],
            'visual_analysis',
            'success',
            true,
        );
        $milestone = $service->appendEvent(
            $session,
            'observation.completed',
            'Screenshot und DOM wurden erfasst.',
            [],
            'observing',
            'info',
            true,
        );

        $component = Livewire::test(Chatbot::class)
            ->call('attachCopilotSession', $session->id)
            ->assertSet('activeCopilotSessionId', $session->id)
            ->assertSet('copilotLastEventSequence', $milestone->sequence)
            ->assertSet('copilotStatus.vision_analysis.ui_state', 'search_input')
            ->assertSet('copilotStatus.vision_analysis.suggested_task_actions.0.task_key', 'input.fill_field');

        $feed = $component->get('copilotEventFeed');
        $history = $component->get('chatHistory');

        $this->assertTrue(collect($feed)->contains(fn (array $event): bool => $event['message'] === 'Screenshot und DOM wurden erfasst.'));
        $this->assertTrue(collect($feed)->contains(fn (array $event): bool => str_contains($event['message'], 'Bildanalyse abgeschlossen')));
        $this->assertFalse(collect($feed)->contains(fn (array $event): bool => str_contains($event['message'], 'Bildanalyse des aktuellen Browserzustands laeuft')));
        $this->assertFalse(collect($feed)->contains(fn (array $event): bool => str_contains($event['message'], 'interne Gedankengang')));
        $this->assertCount(1, collect($history)->where('copilot_event_id', $milestone->id));
        $this->assertCount(1, collect($history)->where('copilot_event_id', $analysis->id));

        $component
            ->call('pollCopilotSession')
            ->set('message', 'Halte nach dem aktuellen Task an.')
            ->call('sendMessage')
            ->assertSet('isLoading', false);

        $this->assertCount(1, collect($component->get('chatHistory'))->where('copilot_event_id', $milestone->id));
        $this->assertDatabaseHas('workflow_copilot_events', [
            'workflow_copilot_session_id' => $session->id,
            'event_type' => 'instruction.received',
        ]);
        $this->assertDatabaseHas('workflow_copilot_events', [
            'workflow_copilot_session_id' => $session->id,
            'event_type' => 'chat.user',
        ]);
        $this->assertDatabaseHas('workflow_copilot_events', [
            'workflow_copilot_session_id' => $session->id,
            'event_type' => 'chat.assistant',
        ]);
        Queue::assertPushed(
            WorkflowCopilotSupervisorJob::class,
            fn (WorkflowCopilotSupervisorJob $job): bool => $job->workflowCopilotSessionId === $session->id,
        );
    }

    public function test_manager_shows_completed_vision_details_but_hides_operational_analysis_noise(): void
    {
        $workflow = $this->workflow('copilot-manager-vision');
        $service = app(WorkflowCopilotSessionService::class);
        $session = $service->start($workflow, ['goal' => 'Suchseite bedienen.']);
        $session->forceFill(['state_json' => [
            'vision' => [
                'page_type' => 'search',
                'ui_state' => 'search_input',
                'goal_progress' => 0.5,
                'confidence' => 0.9,
                'verdict' => 'continue',
                'blockers' => [],
                'relevant_elements' => [[
                    'element_ref' => 'el_search',
                    'reason' => 'Suchfeld',
                    'confidence' => 0.95,
                ]],
                'suggested_task_actions' => [[
                    'task_key' => 'input.fill_field',
                    'element_ref' => 'el_search',
                    'reason' => 'Suchbegriff eingeben',
                    'confidence' => 0.93,
                ]],
            ],
        ]])->save();
        $service->appendEvent(
            $session,
            'vision.analysis_started',
            'Die Bildanalyse des aktuellen Browserzustands laeuft.',
        );
        $service->appendEvent(
            $session,
            'vision.analysis_completed',
            'Bildanalyse abgeschlossen: search / search_input (90 %). Entscheidung: Workflow fortsetzen.',
            ['ui_state' => 'search_input'],
            'visual_analysis',
            'success',
            true,
        );

        $component = Livewire::test(WorkflowManager::class, ['workflow' => $workflow])
            ->assertSet('activeCopilotSessionId', $session->id)
            ->assertSet('copilotStatus.vision_analysis.ui_state', 'search_input')
            ->assertSet('copilotStatus.vision_analysis.relevant_elements.0.element_ref', 'el_search');
        $events = $component->get('copilotEvents');

        $this->assertTrue(collect($events)->contains(
            fn (array $event): bool => str_contains($event['message'], 'Bildanalyse abgeschlossen'),
        ));
        $this->assertFalse(collect($events)->contains(
            fn (array $event): bool => str_contains($event['message'], 'Bildanalyse des aktuellen Browserzustands laeuft'),
        ));
    }

    public function test_chat_rebuilds_canonical_milestones_and_final_report_after_session_loss(): void
    {
        $workflow = $this->workflow('copilot-terminal-restore');
        $service = app(WorkflowCopilotSessionService::class);
        $session = $service->start($workflow, [
            'goal' => 'Workflow vollstaendig abschliessen.',
            'success_criteria' => ['assertions' => ['Erfolgsseite sichtbar']],
        ]);
        $observation = $service->appendEvent(
            $session,
            'observation.completed',
            'Screenshot und DOM wurden dauerhaft erfasst.',
            [],
            'observing',
            'info',
            true,
        );
        $verification = $service->appendEvent(
            $session,
            'verification.passed',
            'Workflow vollstaendig erfolgreich und automatisch verifiziert.',
            [
                'workflow_run_id' => 4711,
                'revision' => 7,
                'criteria_evaluation' => ['pass' => true, 'passed' => 2, 'total' => 2],
                'vision_verdict' => 'pass',
                'vision_confidence' => 0.94,
                'technical_status' => 'success',
                'business_status' => 'success',
            ],
            'verifying',
            'success',
            true,
        );
        $session = $service->transition(
            $session,
            WorkflowCopilotSession::STATUS_SUCCEEDED,
            'completed',
            [
                'verification_run_id' => 4711,
                'verification' => [
                    'pass' => true,
                    'criteria' => ['pass' => true, 'passed' => 2, 'total' => 2],
                    'vision' => ['verdict' => 'pass', 'confidence' => 0.94],
                ],
            ],
        );

        session()->flush();

        $chat = Livewire::test(Chatbot::class)
            ->call('updatePageContext', ['workflow_id' => $workflow->id])
            ->assertSet('activeCopilotSessionId', $session->id)
            ->assertSet('copilotStatus.active', false)
            ->assertSet('copilotStatus.verification_report.pass', true)
            ->assertSee('Finaler Verifikationsbericht')
            ->assertSee('Workflow vollstaendig erfolgreich und automatisch verifiziert.')
            ->assertDontSee('wire:poll.2s="pollCopilotSession"', false);

        $history = collect($chat->get('chatHistory'));
        $this->assertCount(1, $history->where('copilot_event_id', $observation->id));
        $this->assertCount(1, $history->where('copilot_event_id', $verification->id));

        $chat->call('pollCopilotSession')->call('pollCopilotSession');
        $this->assertCount(1, collect($chat->get('chatHistory'))->where('copilot_event_id', $verification->id));

        session()->flush();

        $manager = Livewire::test(WorkflowManager::class, ['workflow' => $workflow])
            ->assertSet('activeCopilotSessionId', $session->id)
            ->assertSet('copilotStatus.verification_report.pass', true)
            ->call('openCopilotOptimization')
            ->assertSet('showCopilotPreviewModal', false)
            ->assertSet('showRunPreviewModal', true)
            ->assertSee('Finaler Verifikationsbericht')
            ->assertDontSee('wire:poll.2s="refreshCopilotSession"', false)
            ->call('closeCopilotPreview')
            ->assertSet('activeCopilotSessionId', null)
            ->call('openCopilotOptimization')
            ->assertSet('showCopilotModal', true);
    }

    public function test_clear_chat_hides_old_copilot_events_locally_without_deleting_the_audit_log(): void
    {
        $workflow = $this->workflow('copilot-clear-chat');
        $service = app(WorkflowCopilotSessionService::class);
        $session = $service->start($workflow);
        $oldMilestone = $service->appendEvent(
            $session,
            'observation.completed',
            'Alter sichtbarer Meilenstein.',
            [],
            'observing',
            'info',
            true,
        );

        $chat = Livewire::test(Chatbot::class)
            ->call('updatePageContext', ['workflow_id' => $workflow->id]);

        $this->assertCount(1, collect($chat->get('chatHistory'))->where('copilot_event_id', $oldMilestone->id));

        $chat
            ->call('clearChat')
            ->assertSet('chatHistory', [])
            ->call('pollCopilotSession')
            ->assertSet('chatHistory', []);

        $this->assertDatabaseHas('workflow_copilot_events', ['id' => $oldMilestone->id]);

        $newMilestone = $service->appendEvent(
            $session->fresh(),
            'task.started',
            'Neuer Meilenstein nach dem Leeren.',
            [],
            'executing',
            'info',
            true,
        );
        $chat->call('pollCopilotSession');
        $history = collect($chat->get('chatHistory'));
        $this->assertCount(0, $history->where('copilot_event_id', $oldMilestone->id));
        $this->assertCount(1, $history->where('copilot_event_id', $newMilestone->id));

        session()->flush();

        $restored = Livewire::test(Chatbot::class)
            ->call('updatePageContext', ['workflow_id' => $workflow->id]);
        $restoredHistory = collect($restored->get('chatHistory'));
        $this->assertCount(1, $restoredHistory->where('copilot_event_id', $oldMilestone->id));
        $this->assertCount(1, $restoredHistory->where('copilot_event_id', $newMilestone->id));
    }

    public function test_workflow_page_prefers_an_active_session_over_a_newer_terminal_session(): void
    {
        $workflow = $this->workflow('copilot-active-priority');
        $active = app(WorkflowCopilotSessionService::class)->start($workflow);
        WorkflowCopilotSession::query()->create([
            'session_uuid' => (string) str()->uuid(),
            'workflow_id' => $workflow->id,
            'status' => WorkflowCopilotSession::STATUS_STOPPED,
            'phase' => 'stopped',
            'execution_target' => 'system',
            'success_criteria_json' => [],
            'workflow_inputs_json' => [],
            'budget_json' => [],
            'usage_json' => [],
            'state_json' => [],
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        session()->flush();

        Livewire::test(Chatbot::class)
            ->call('updatePageContext', ['workflow_id' => $workflow->id])
            ->assertSet('activeCopilotSessionId', $active->id)
            ->assertSet('copilotStatus.active', true);

        Livewire::test(WorkflowManager::class, ['workflow' => $workflow])
            ->assertSet('activeCopilotSessionId', $active->id)
            ->assertSet('copilotStatus.active', true);
    }

    public function test_chatbot_projects_a_stale_running_probe_with_elapsed_activity_metadata(): void
    {
        $workflow = $this->workflow('copilot-stale-probe-activity');
        $service = app(WorkflowCopilotSessionService::class);
        $session = $service->start($workflow);
        $event = $service->appendEvent(
            $session,
            'probe.started',
            'Browser-Probe wurde gestartet.',
            ['task_catalog_key' => 'browser.click'],
            'probing',
            'info',
            true,
        );
        DB::table('workflow_copilot_events')
            ->where('id', $event->id)
            ->update([
                'occurred_at' => now()->subMinutes(4),
                'created_at' => now()->subMinutes(4),
            ]);
        $state = $session->fresh()->state_json;
        $state['current_task_key'] = 'search-submit';
        $state['continuation_applied_action'] = 'probe';
        $state['active_repair_plan'] = [
            'action' => 'probe_update',
            'task_key' => 'search-submit',
            'task_catalog_key' => 'browser.click',
            'probe_task' => [
                'key' => 'search-submit--copilot-probe',
                'task_key' => 'browser.click',
            ],
        ];
        $session->forceFill([
            'status' => WorkflowCopilotSession::STATUS_REPAIRING,
            'phase' => 'probing',
            'state_json' => $state,
            'last_activity_at' => now()->subMinutes(4),
        ])->save();

        session()->flush();

        $component = Livewire::test(Chatbot::class)
            ->call('updatePageContext', ['workflow_id' => $workflow->id])
            ->assertSet('activeCopilotSessionId', $session->id)
            ->assertSet('copilotStatus.activity.active', true)
            ->assertSet('copilotStatus.activity.kind', 'browser_probe')
            ->assertSet('copilotStatus.activity.label', 'Browser-Probe wird ausgefuehrt')
            ->assertSet('copilotStatus.activity.detail', 'Probe-Tool: browser.click');
        $activity = $component->get('copilotStatus')['activity'];

        $this->assertTrue((bool) $activity['stale'], json_encode($activity, JSON_PRETTY_PRINT));
        $this->assertGreaterThanOrEqual(240, (int) $activity['stale_seconds']);
    }

    public function test_manager_cancels_the_active_run_before_stopping_the_session(): void
    {
        Queue::fake();
        $workflow = $this->workflow('copilot-stop-run');
        $service = app(WorkflowCopilotSessionService::class);
        $session = $service->start($workflow);
        $run = WorkflowRun::query()->create([
            'run_uuid' => (string) str()->uuid(),
            'workflow_id' => $workflow->id,
            'status' => 'queued',
            'context_json' => [
                'workflow_copilot_session_id' => $session->id,
                'execution_target' => 'system',
            ],
        ]);
        $service->attachRun($session, $run);

        Livewire::test(WorkflowManager::class, ['workflow' => $workflow])
            ->call('stopCopilotOptimization');

        $this->assertSame('cancelled', $run->fresh()->status);
        $this->assertSame(WorkflowCopilotSession::STATUS_STOPPED, $session->fresh()->status);
        $this->assertNull($workflow->fresh()->active_workflow_copilot_session_id);
    }

    public function test_manager_restart_cancels_the_run_and_preserves_session_inputs_with_fresh_budgets(): void
    {
        Queue::fake();
        $workflow = $this->workflow('copilot-manager-restart');
        $service = app(WorkflowCopilotSessionService::class);
        $session = $service->start($workflow, [
            'goal' => 'Workflow erfolgreich abschliessen.',
            'success_criteria' => ['assertions' => ['Erfolgsseite sichtbar']],
            'workflow_inputs' => ['query' => 'OpenAI'],
            'budget' => [
                'max_minutes' => 45,
                'max_repair_iterations' => 8,
                'max_probe_actions' => 25,
                'max_same_state_repeats' => 3,
                'auto_execute_workflow_actions' => true,
            ],
        ]);
        $session->forceFill([
            'usage_json' => ['repair_iterations' => 5, 'probe_actions' => 7, 'same_state_repeats' => 2],
            'state_json' => ['current_task_key' => 'stale-task'],
        ])->save();
        $run = WorkflowRun::query()->create([
            'run_uuid' => (string) str()->uuid(),
            'workflow_id' => $workflow->id,
            'status' => 'queued',
            'context_json' => [
                'workflow_copilot_session_id' => $session->id,
                'execution_target' => 'system',
            ],
        ]);
        $service->attachRun($session->fresh(), $run);

        $manager = Livewire::test(WorkflowManager::class, ['workflow' => $workflow])
            ->call('restartCopilotOptimization')
            ->assertSet('showRunPreviewModal', true)
            ->assertSet('previewWorkflowRunId', null)
            ->assertDispatched('workflow-copilot-session-activated');

        $restartedId = (int) $manager->get('activeCopilotSessionId');
        $restarted = WorkflowCopilotSession::query()->findOrFail($restartedId);

        $this->assertNotSame($session->id, $restarted->id);
        $this->assertSame('cancelled', $run->fresh()->status);
        $this->assertSame(WorkflowCopilotSession::STATUS_STOPPED, $session->fresh()->status);
        $this->assertSame(WorkflowCopilotSession::STATUS_RUNNING, $restarted->status);
        $this->assertSame($session->goal, $restarted->goal);
        $this->assertSame($session->success_criteria_json, $restarted->success_criteria_json);
        $this->assertSame($session->workflow_inputs_json, $restarted->workflow_inputs_json);
        $this->assertSame(45, $restarted->budget_json['max_minutes']);
        $this->assertSame(0, $restarted->usage_json['repair_iterations']);
        $this->assertSame(0, $restarted->usage_json['probe_actions']);
        $this->assertSame($session->id, data_get($restarted->state_json, 'restarted_from_session_id'));
        $this->assertNull(data_get($restarted->state_json, 'current_task_key'));
        $this->assertSame($restarted->id, $workflow->fresh()->active_workflow_copilot_session_id);
        $this->assertDatabaseHas('workflow_copilot_events', [
            'workflow_copilot_session_id' => $session->id,
            'event_type' => 'session.restart_requested',
        ]);
        $this->assertDatabaseHas('workflow_copilot_events', [
            'workflow_copilot_session_id' => $restarted->id,
            'event_type' => 'session.restarted',
        ]);
        Queue::assertPushed(
            WorkflowCopilotSupervisorJob::class,
            fn (WorkflowCopilotSupervisorJob $job): bool => $job->workflowCopilotSessionId === $restarted->id,
        );
    }

    public function test_sidebar_restart_attaches_the_new_session_and_reopens_the_shared_run_preview(): void
    {
        Queue::fake();
        $workflow = $this->workflow('copilot-sidebar-restart');
        $session = app(WorkflowCopilotSessionService::class)->start($workflow, [
            'goal' => 'Workflow vollstaendig testen.',
        ]);

        $chat = Livewire::test(Chatbot::class)
            ->call('attachCopilotSession', $session->id)
            ->call('restartCopilotSession')
            ->assertDispatched('workflow-copilot-session-activated')
            ->assertDispatched('assistant-open-workflow-run-preview');

        $restartedId = (int) $chat->get('activeCopilotSessionId');

        $this->assertNotSame($session->id, $restartedId);
        $this->assertSame(WorkflowCopilotSession::STATUS_STOPPED, $session->fresh()->status);
        $this->assertSame(WorkflowCopilotSession::STATUS_RUNNING, WorkflowCopilotSession::query()->findOrFail($restartedId)->status);
        Queue::assertPushed(
            WorkflowCopilotSupervisorJob::class,
            fn (WorkflowCopilotSupervisorJob $job): bool => $job->workflowCopilotSessionId === $restartedId,
        );
    }

    public function test_active_copilot_lock_blocks_manual_livewire_mutations(): void
    {
        $workflow = $this->workflow('copilot-manual-edit-lock');
        $step = $workflow->steps()->create([
            'name' => 'Locked step',
            'type' => WorkflowStep::TYPE_BROWSER_TASK,
            'action_key' => 'locked-step',
            'position' => 10,
            'is_enabled' => true,
            'config_json' => ['tasks' => []],
        ]);
        app(WorkflowCopilotSessionService::class)->start($workflow, ['goal' => 'Workflow pruefen.']);

        Livewire::test(WorkflowManager::class, ['workflow' => $workflow])
            ->call('toggleStep', $step->id);

        $this->assertTrue($step->fresh()->is_enabled);
        $this->assertNotNull($workflow->fresh()->active_workflow_copilot_session_id);
    }

    private function workflow(string $slug): Workflow
    {
        return Workflow::query()->create([
            'name' => str($slug)->headline()->toString(),
            'slug' => $slug,
            'description' => '',
            'category' => 'custom',
            'subcategory' => '',
            'is_active' => true,
            'is_locked' => false,
            'trigger_type' => 'manual',
            'settings_json' => [],
        ]);
    }
}
