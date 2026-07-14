<?php

namespace Tests\Feature;

use App\Jobs\WorkflowCopilotSupervisorJob;
use App\Livewire\Admin\Network\WorkflowManager;
use App\Livewire\Tools\Chatbot;
use App\Models\Setting;
use App\Models\Workflow;
use App\Models\WorkflowCopilotSession;
use App\Models\WorkflowRun;
use App\Services\Workflows\WorkflowCopilotSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            ->assertSet('showCopilotPreviewModal', true)
            ->assertDispatched('workflow-copilot-session-activated');

        $session = WorkflowCopilotSession::query()->sole();

        $this->assertSame('system', $session->execution_target);
        $this->assertSame(45, $session->budget_json['max_minutes']);
        $this->assertSame(['Finale URL enthaelt /success', 'Text Fertig ist sichtbar'], $session->success_criteria_json['assertions']);
        $this->assertSame($session->id, $workflow->fresh()->active_workflow_copilot_session_id);
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
        $service->appendEvent(
            $session,
            'model.reasoning',
            'Dieser interne Gedankengang darf nie sichtbar werden.',
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
            ->assertSet('copilotLastEventSequence', $milestone->sequence);

        $feed = $component->get('copilotEventFeed');
        $history = $component->get('chatHistory');

        $this->assertTrue(collect($feed)->contains(fn (array $event): bool => $event['message'] === 'Screenshot und DOM wurden erfasst.'));
        $this->assertFalse(collect($feed)->contains(fn (array $event): bool => str_contains($event['message'], 'interne Gedankengang')));
        $this->assertCount(1, collect($history)->where('copilot_event_id', $milestone->id));

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
        Queue::assertPushed(
            WorkflowCopilotSupervisorJob::class,
            fn (WorkflowCopilotSupervisorJob $job): bool => $job->workflowCopilotSessionId === $session->id,
        );
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
