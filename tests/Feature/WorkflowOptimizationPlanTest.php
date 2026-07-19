<?php

namespace Tests\Feature;

use App\Livewire\Admin\Network\WorkflowStudio;
use App\Livewire\Admin\Network\WorkflowStudioTaskEditor;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowOptimizationPlan;
use App\Models\WorkflowStep;
use App\Services\Ai\AiConnectionService;
use App\Services\Workflows\WorkflowCopilotLaunchRequest;
use App\Services\Workflows\WorkflowCopilotLaunchService;
use App\Services\Workflows\WorkflowCopilotSupervisorService;
use App\Services\Workflows\WorkflowStudioControlService;
use App\Services\Workflows\WorkflowStudioSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class WorkflowOptimizationPlanTest extends TestCase
{
    use RefreshDatabase;

    public function test_autonomous_mode_lock_blocks_user_run_and_definition_mutations_server_side(): void
    {
        Queue::fake();
        $workflow = $this->workflowWithTasks();
        $admin = User::factory()->create(['role' => 'admin', 'status' => true]);
        $studio = app(WorkflowStudioSessionService::class)->open($workflow, $admin, 'autonomous');
        app(WorkflowStudioControlService::class)->lock($studio, 'autonomous', $admin);
        $this->actingAs($admin);

        Livewire::test(WorkflowStudio::class, [
            'workflow' => $workflow,
            'embedded' => true,
            'initialMode' => 'autonomous',
        ])
            ->assertSet('mode', 'autonomous')
            ->call('startRun')
            ->assertHasErrors('studio');

        Livewire::test(WorkflowStudioTaskEditor::class, [
            'workflow' => $workflow,
            'studioSessionId' => $studio->id,
        ])
            ->call('removeTaskCard', $workflow->steps()->firstOrFail()->id, 'first-task')
            ->assertHasErrors('studioBuilder');

        $this->assertSame(0, $workflow->runs()->count());
        $this->assertCount(1, $workflow->steps()->firstOrFail()->task_cards);
    }

    public function test_empty_workflow_materializes_and_verifies_one_planned_task_before_the_next(): void
    {
        Queue::fake();
        $workflow = Workflow::query()->create([
            'name' => 'Inkrementeller Plan',
            'slug' => 'inkrementeller-plan',
            'description' => '',
            'category' => 'test',
            'is_active' => true,
            'is_locked' => false,
            'trigger_type' => 'manual',
            'settings_json' => [],
        ]);
        $ai = Mockery::mock(AiConnectionService::class);
        $ai->shouldReceive('json')->once()->andReturn([
            'summary' => 'Zwei Tasks nacheinander testen.',
            'assumptions' => [],
            'steps' => [[
                'name' => 'Start',
                'action_key' => 'start',
                'type' => WorkflowStep::TYPE_WAIT,
                'routes' => ['success' => ['type' => 'end']],
                'tasks' => [[
                    'key' => 'erste-task',
                    'task_key' => 'wait.seconds',
                    'title' => 'Erste Task',
                    'parameters' => ['value' => 0],
                ], [
                    'key' => 'zweite-task',
                    'task_key' => 'wait.seconds',
                    'title' => 'Zweite Task',
                    'parameters' => ['value' => 0],
                ]],
            ]],
        ]);
        $this->app->instance(AiConnectionService::class, $ai);

        $launch = app(WorkflowCopilotLaunchService::class)->start(
            $workflow,
            WorkflowCopilotLaunchRequest::fromArray([
                'goal' => 'Beide Tasks erfolgreich ausfuehren.',
                'success_criteria' => ['Workflow wird erfolgreich beendet'],
                'workflow_inputs' => [],
                'permission_mode' => 'ask_critical',
            ]),
        );
        $session = $launch['session'];
        $this->assertSame(0, $workflow->steps()->count());

        app(WorkflowCopilotSupervisorService::class)->supervise($session->id);

        $workflow->refresh();
        $plan = WorkflowOptimizationPlan::query()->where('workflow_copilot_session_id', $session->id)->firstOrFail();
        $this->assertSame(['erste-task'], collect($workflow->steps()->firstOrFail()->task_cards)->pluck('key')->all());
        $this->assertSame(['testing', 'planned'], $plan->items()->orderBy('sequence')->pluck('status')->all());
        $firstRun = $session->fresh()->activeRun()->firstOrFail();
        $this->assertSame('erste-task', data_get($firstRun->context_json, 'next_task_key'));

        $firstRun->forceFill(['status' => 'completed', 'finished_at' => now(), 'result_json' => ['ok' => true]])->save();
        app(WorkflowCopilotSupervisorService::class)->supervise($session->id);

        $workflow->refresh();
        $plan->refresh();
        $this->assertSame(['erste-task', 'zweite-task'], collect($workflow->steps()->firstOrFail()->task_cards)->pluck('key')->all());
        $this->assertSame(['verified', 'testing'], $plan->items()->orderBy('sequence')->pluck('status')->all());
        $secondRun = $session->fresh()->activeRun()->firstOrFail();
        $this->assertNotSame($firstRun->id, $secondRun->id);
        $this->assertSame('zweite-task', data_get($secondRun->context_json, 'next_task_key'));

        $secondRun->forceFill(['status' => 'completed', 'finished_at' => now(), 'result_json' => ['ok' => true]])->save();
        app(WorkflowCopilotSupervisorService::class)->supervise($session->id);

        $plan->refresh();
        $session->refresh();
        $this->assertSame('finalized', $plan->status);
        $this->assertSame(2, $plan->verified_items);
        $this->assertSame('verifying', $session->status);
        $this->assertNotSame($secondRun->id, $session->active_workflow_run_id);
        $this->assertSame(['erste-task', 'zweite-task'], collect($workflow->fresh()->steps()->firstOrFail()->task_cards)->pluck('key')->all());
    }

    private function workflowWithTasks(): Workflow
    {
        $workflow = Workflow::query()->create([
            'name' => 'Gesperrter Workflow',
            'slug' => 'gesperrter-workflow',
            'description' => '',
            'category' => 'test',
            'is_active' => true,
            'is_locked' => false,
            'trigger_type' => 'manual',
            'settings_json' => [],
        ]);
        $workflow->steps()->create([
            'name' => 'Start',
            'type' => WorkflowStep::TYPE_WAIT,
            'action_key' => 'start',
            'position' => 10,
            'is_enabled' => true,
            'config_json' => ['tasks' => [[
                'key' => 'first-task',
                'task_key' => 'wait.seconds',
                'title' => 'Erste Task',
                'value' => 0,
            ]]],
        ]);

        return $workflow->fresh('steps');
    }
}
