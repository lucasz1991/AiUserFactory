<?php

namespace Tests\Feature;

use App\Livewire\Admin\Network\WorkflowManager;
use App\Models\Workflow;
use App\Models\WorkflowStep;
use App\Services\Workflows\WorkflowTaskRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use ReflectionClass;
use Tests\TestCase;

class WorkflowCompositionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=']);
    }

    public function test_included_workflow_is_automatically_locked_and_unlocked_with_its_reference(): void
    {
        $parent = $this->workflow('parent');
        $child = $this->workflow('child');
        $childStep = $this->step($child, 'Child list', [
            $this->waitTask('child-wait'),
        ]);
        $parentStep = $this->step($parent, 'Parent list', [
            $this->workflowTask($child, 'child-workflow'),
        ]);

        $child->refresh();

        $this->assertTrue($child->is_included);
        $this->assertTrue($child->is_edit_locked);
        $this->assertTrue($child->includedByWorkflows()->whereKey($parent->id)->exists());

        $manager = app(WorkflowManager::class);
        $manager->mount($child);
        $manager->addStep();
        $this->assertSame(1, $child->steps()->count());

        Livewire::test(WorkflowManager::class, ['workflow' => $child])
            ->assertSee('Nur-Lese-Modus')
            ->assertSee('Testen')
            ->assertDontSee('Task-Bibliothek');

        $tasks = $this->runtimeTasks($parentStep);

        $this->assertCount(1, $tasks);
        $this->assertSame('node', $tasks[0]['runner']);
        $this->assertSame('child-workflow', $tasks[0]['parent_task_key']);
        $this->assertSame($child->id, $tasks[0]['embedded_workflow_id']);
        $this->assertStringContainsString((string) $childStep->id, $tasks[0]['key']);

        $parentStep->forceFill(['config_json' => ['tasks' => []]])->save();
        $child->refresh();

        $this->assertFalse($child->is_included);
        $this->assertFalse($child->is_edit_locked);
    }

    public function test_manual_lock_is_part_of_the_effective_edit_lock(): void
    {
        $workflow = $this->workflow('manual-lock');

        $this->assertFalse($workflow->is_edit_locked);

        $workflow->forceFill(['is_locked' => true])->save();
        $workflow->refresh();

        $this->assertTrue($workflow->is_edit_locked);
        $this->assertSame('Manuell gesperrt.', $workflow->lock_reason);
    }

    protected function workflow(string $slug): Workflow
    {
        return Workflow::query()->create([
            'name' => ucfirst(str_replace('-', ' ', $slug)),
            'slug' => $slug,
            'description' => '',
            'category' => 'test',
            'is_active' => true,
            'is_locked' => false,
            'trigger_type' => 'manual',
            'settings_json' => [],
        ]);
    }

    protected function step(Workflow $workflow, string $name, array $tasks): WorkflowStep
    {
        return $workflow->steps()->create([
            'name' => $name,
            'type' => WorkflowStep::TYPE_DATA_PROCESSING,
            'action_key' => str($name)->slug(),
            'position' => 10,
            'is_enabled' => true,
            'config_json' => ['tasks' => $tasks],
        ]);
    }

    protected function waitTask(string $key): array
    {
        return [
            'key' => $key,
            'task_key' => 'wait.seconds',
            'title' => 'Wait',
            'kind' => 'wait',
            'runner' => 'node',
            'node_script' => 'node/workflows/tasks/wait/seconds.cjs',
            'value' => 0,
        ];
    }

    protected function workflowTask(Workflow $workflow, string $key): array
    {
        return [
            'key' => $key,
            'task_key' => 'workflow.include.'.$workflow->id,
            'title' => $workflow->name,
            'kind' => 'workflow',
            'runner' => 'workflow',
            'workflow_id' => $workflow->id,
            'workflow_slug' => $workflow->slug,
        ];
    }

    protected function runtimeTasks(WorkflowStep $step): array
    {
        $reflection = new ReflectionClass(WorkflowTaskRunner::class);
        $runner = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('runtimeTasks');

        return $method->invoke($runner, $step);
    }
}
