<?php

namespace Tests\Feature;

use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use App\Services\Workflows\WorkflowExecutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WorkflowRunConcurrencyCapTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_run_is_refused_when_too_many_runs_are_already_active(): void
    {
        Queue::fake();

        $workflow = $this->runnableWorkflow('cap-target');

        // Default-Cap ist 5 aktive Laeufe. Fuenf aktive Laeufe vorbelegen.
        foreach (range(1, 5) as $index) {
            $this->activeRun($workflow, $index % 2 === 0 ? 'running' : 'waiting');
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Workflow-Laeufe gleichzeitig aktiv');

        app(WorkflowExecutionService::class)->start($workflow, [], 'workflow-studio');
    }

    public function test_new_run_starts_when_below_the_cap(): void
    {
        Queue::fake();

        $workflow = $this->runnableWorkflow('cap-below');

        // Nur vier aktive Laeufe – der fuenfte darf starten. Finale Laeufe
        // zaehlen nicht mit.
        foreach (range(1, 4) as $index) {
            $this->activeRun($workflow, 'running');
        }
        $this->activeRun($workflow, 'completed');
        $this->activeRun($workflow, 'failed');

        $run = app(WorkflowExecutionService::class)->start($workflow, [], 'workflow-studio');

        $this->assertNotNull($run->id);
        $this->assertSame('queued', $run->status);
    }

    private function runnableWorkflow(string $slug): Workflow
    {
        $workflow = Workflow::query()->create([
            'name' => ucfirst(str_replace('-', ' ', $slug)),
            'slug' => $slug,
            'description' => '',
            'category' => 'test',
            'is_active' => true,
            'is_locked' => false,
            'trigger_type' => 'manual',
            'settings_json' => [],
        ]);

        $workflow->steps()->create([
            'name' => 'Warteliste',
            'type' => WorkflowStep::TYPE_WAIT,
            'action_key' => 'warteliste',
            'position' => 10,
            'is_enabled' => true,
            'config_json' => ['tasks' => [[
                'key' => 'warten',
                'task_key' => 'wait.seconds',
                'value' => 1,
            ]]],
        ]);

        return $workflow->fresh(['steps']);
    }

    private function activeRun(Workflow $workflow, string $status): WorkflowRun
    {
        return WorkflowRun::query()->create([
            'run_uuid' => (string) str()->uuid(),
            'workflow_id' => $workflow->id,
            'status' => $status,
            'context_json' => [],
            'result_json' => [],
        ]);
    }
}
