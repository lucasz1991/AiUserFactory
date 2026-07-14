<?php

namespace Tests\Feature;

use App\Jobs\WorkflowCopilotSupervisorJob;
use App\Models\Setting;
use App\Models\Workflow;
use App\Models\WorkflowCopilotSession;
use App\Models\WorkflowStep;
use App\Services\Ai\WorkflowAssistantToolService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WorkflowCopilotToolServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=']);
    }

    public function test_assistant_exposes_and_starts_all_system_optimization_tools(): void
    {
        Queue::fake();
        Setting::setValue('ai_assistant', 'workflow_copilot', [
            'optimization_defaults' => [
                'auto_execute_workflow_actions' => true,
                'max_minutes' => 45,
                'max_repair_iterations' => 8,
                'max_probe_actions' => 24,
                'max_same_state_repeats' => 3,
            ],
        ]);
        $workflow = $this->workflow();
        $service = app(WorkflowAssistantToolService::class);
        $toolNames = collect($service->tools())->pluck('function.name');

        $this->assertTrue($toolNames->contains('workflow_optimize_start'));
        $this->assertTrue($toolNames->contains('workflow_optimize_status'));
        $this->assertTrue($toolNames->contains('workflow_optimize_instruction'));
        $this->assertTrue($toolNames->contains('workflow_optimize_pause'));
        $this->assertTrue($toolNames->contains('workflow_optimize_resume'));
        $this->assertTrue($toolNames->contains('workflow_optimize_rewind'));
        $this->assertTrue($toolNames->contains('workflow_optimize_stop'));

        $result = $service->execute('workflow_optimize_start', [
            'workflow_id' => $workflow->id,
            'goal' => 'Workflow erreicht die Erfolgsseite.',
            'success_criteria' => ['URL endet mit /success'],
            'workflow_inputs' => ['tenant' => 'demo'],
        ], (object) ['id' => 7]);

        $this->assertTrue($result['ok']);
        $this->assertSame('system', data_get($result, 'session.execution_target'));
        $session = WorkflowCopilotSession::query()->firstOrFail();
        $this->assertSame('system', $session->execution_target);
        $this->assertSame(45, data_get($session->budget_json, 'max_minutes'));
        $this->assertSame(8, data_get($session->budget_json, 'max_repair_iterations'));
        $this->assertSame(24, data_get($session->budget_json, 'max_probe_actions'));
        $this->assertSame(3, data_get($session->budget_json, 'max_same_state_repeats'));
        $this->assertSame($session->id, $workflow->fresh()->active_workflow_copilot_session_id);
        Queue::assertPushed(WorkflowCopilotSupervisorJob::class, fn (WorkflowCopilotSupervisorJob $job): bool => $job->workflowCopilotSessionId === $session->id);

        $status = $service->execute('workflow_optimize_status', ['session_id' => $session->id], (object) ['id' => 7]);
        $this->assertTrue($status['ok']);
        $this->assertSame($session->id, data_get($status, 'session.id'));
        $this->assertSame('system', data_get($status, 'session.execution_target'));
    }

    public function test_assistant_blocks_autonomous_start_when_global_permission_is_disabled(): void
    {
        Queue::fake();
        Setting::setValue('ai_assistant', 'workflow_copilot', [
            'optimization_defaults' => ['auto_execute_workflow_actions' => false],
        ]);
        $workflow = $this->workflow();

        $result = app(WorkflowAssistantToolService::class)->execute('workflow_optimize_start', [
            'workflow_id' => $workflow->id,
            'goal' => 'Workflow reparieren.',
        ], (object) ['id' => 7]);

        $this->assertFalse($result['ok']);
        $this->assertSame('WORKFLOW_OPTIMIZE_AUTO_EXECUTE_DISABLED', $result['error']);
        $this->assertDatabaseCount('workflow_copilot_sessions', 0);
        Queue::assertNotPushed(WorkflowCopilotSupervisorJob::class);
    }

    private function workflow(): Workflow
    {
        $workflow = Workflow::query()->create([
            'name' => 'Tool Workflow '.str()->random(6),
            'slug' => 'tool-workflow-'.str()->random(10),
            'description' => '',
            'category' => 'test',
            'is_active' => true,
            'is_locked' => false,
            'trigger_type' => 'manual',
            'settings_json' => [],
        ]);
        $workflow->steps()->create([
            'name' => 'Start',
            'type' => WorkflowStep::TYPE_BROWSER_TASK,
            'action_key' => 'start',
            'position' => 10,
            'is_enabled' => true,
            'config_json' => [
                'tasks' => [[
                    'key' => 'wait',
                    'task_key' => 'wait.seconds',
                    'title' => 'Kurz warten',
                    'value' => 0,
                ]],
            ],
        ]);

        return $workflow;
    }
}
