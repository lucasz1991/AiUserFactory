<?php

namespace Tests\Unit;

use App\Models\Workflow;
use App\Models\WorkflowStep;
use App\Services\Workflows\WorkflowTaskOrderingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkflowTaskOrderingServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_dragging_loop_start_moves_the_whole_contiguous_block_within_a_step(): void
    {
        $workflow = $this->workflow('same-step-loop-drag');
        $step = $this->step($workflow, 'Loop list', [
            $this->task('before'),
            ...$this->loopBlock(),
            $this->task('after'),
        ]);

        $moved = app(WorkflowTaskOrderingService::class)->moveTask(
            $workflow,
            $step,
            'loop-start',
            6,
            $step->id,
        );

        $this->assertTrue($moved);
        $this->assertSame(
            ['before', 'after', 'loop-start', 'body-one', 'body-two', 'loop-end'],
            collect($step->fresh()->task_cards)->pluck('key')->all(),
        );
    }

    public function test_dragging_loop_end_moves_the_whole_contiguous_block_across_steps(): void
    {
        $workflow = $this->workflow('cross-step-loop-drag');
        $source = $this->step($workflow, 'Source list', [
            $this->task('before'),
            ...$this->loopBlock(),
            $this->task('after'),
        ], 10);
        $target = $this->step($workflow, 'Target list', [
            $this->task('target-before'),
            $this->task('target-after'),
        ], 20);

        $moved = app(WorkflowTaskOrderingService::class)->moveTask(
            $workflow,
            $target,
            'loop-end',
            1,
            $source->id,
        );

        $this->assertTrue($moved);
        $this->assertSame(
            ['before', 'after'],
            collect($source->fresh()->task_cards)->pluck('key')->all(),
        );
        $this->assertSame(
            ['target-before', 'loop-start', 'body-one', 'body-two', 'loop-end', 'target-after'],
            collect($target->fresh()->task_cards)->pluck('key')->all(),
        );
    }

    /** @return list<array<string, mixed>> */
    private function loopBlock(): array
    {
        return [
            [
                ...$this->task('loop-start'),
                'task_key' => 'loop.for_each_element',
                'loop_pair_id' => 'loop-pair',
                'loop_pair_segment' => 'start',
                'loop_start_key' => 'loop-start',
                'loop_end_key' => 'loop-end',
            ],
            $this->task('body-one'),
            $this->task('body-two'),
            [
                ...$this->task('loop-end'),
                'task_key' => 'loop.end',
                'loop_pair_id' => 'loop-pair',
                'loop_pair_segment' => 'end',
                'loop_start_key' => 'loop-start',
                'loop_end_key' => 'loop-end',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function task(string $key): array
    {
        return [
            'key' => $key,
            'task_key' => 'wait.seconds',
            'title' => $key,
            'runner' => 'node',
            'node_script' => 'node/workflows/tasks/wait/seconds.cjs',
        ];
    }

    private function workflow(string $slug): Workflow
    {
        return Workflow::query()->create([
            'name' => $slug,
            'slug' => $slug,
            'description' => '',
            'category' => 'test',
            'is_active' => true,
            'is_locked' => false,
            'trigger_type' => 'manual',
            'settings_json' => [],
        ]);
    }

    private function step(Workflow $workflow, string $name, array $tasks, int $position = 10): WorkflowStep
    {
        return $workflow->steps()->create([
            'name' => $name,
            'type' => WorkflowStep::TYPE_DATA_PROCESSING,
            'action_key' => str($name)->slug(),
            'position' => $position,
            'is_enabled' => true,
            'config_json' => ['tasks' => $tasks],
        ]);
    }
}
