<?php

namespace Tests\Unit;

use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use App\Models\WorkflowStepRun;
use App\Services\Workflows\WorkflowRouteMapPresenter;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Tests\TestCase;

class WorkflowRouteMapPresenterTest extends TestCase
{
    public function test_definition_contains_stable_task_step_and_terminal_nodes_with_implicit_routes(): void
    {
        $collect = $this->step(11, 'collect', 10, [
            $this->task('second', 20),
            $this->task('first', 10),
        ]);
        $empty = $this->step(12, 'empty', 20, []);
        $workflow = $this->workflow($collect, $empty);

        $map = (new WorkflowRouteMapPresenter)->present($workflow);

        $this->assertSame('definition', $map['mode']);
        $this->assertSame([
            'collect::*',
            'collect::first',
            'collect::second',
            'empty::*',
            'terminal::end',
            'terminal::fail',
        ], collect($map['nodes'])->pluck('id')->all());
        $this->assertSame('step', $this->node($map, 'empty::*')['kind']);
        $this->assertSame('terminal', $this->node($map, 'terminal::end')['kind']);
        $this->assertSame('end', $this->node($map, 'terminal::end')['terminal']);

        $entry = $this->edge($map, 'collect::*', 'collect::first', 'enter');
        $this->assertFalse($entry['explicit']);
        $this->assertSame('entry', $entry['type']);

        $implicitTask = $this->edge($map, 'collect::first', 'collect::second', 'success');
        $this->assertFalse($implicitTask['explicit']);
        $this->assertSame('card', $implicitTask['type']);

        $implicitStep = $this->edge($map, 'collect::*', 'empty::*', 'success');
        $this->assertFalse($implicitStep['explicit']);
        $this->assertSame('forward', $implicitStep['direction']);

        $workflowEnd = $this->edge($map, 'empty::*', 'terminal::end', 'success');
        $this->assertFalse($workflowEnd['explicit']);
        $this->assertSame('end', $workflowEnd['type']);

        $unhandledFailure = $this->edge($map, 'collect::*', 'terminal::fail', 'failed');
        $this->assertFalse($unhandledFailure['explicit']);
        $this->assertSame('fail', $unhandledFailure['direction']);
        $this->assertSame($map, (new WorkflowRouteMapPresenter)->present($workflow));
    }

    public function test_task_routes_expose_runtime_precedence_without_hiding_shadowed_status_routes(): void
    {
        $branch = $this->task('branch', 10, [
            'next' => ['type' => 'card', 'card_key' => 'done', 'label' => 'Weiter'],
            'on_partial' => ['type' => 'card', 'card_key' => 'partial'],
            'on_error' => ['type' => 'fail', 'label' => 'Abbruch'],
            'status_routes' => [
                'waiting' => ['type' => 'card', 'card_key' => 'wait'],
                'success' => ['type' => 'card', 'card_key' => 'alternate'],
                'partial' => ['type' => 'card', 'card_key' => 'alternate'],
                'timeout' => ['type' => 'card', 'card_key' => 'retry'],
                'failed' => ['type' => 'card', 'card_key' => 'retry'],
            ],
        ]);
        $workflow = $this->workflow($this->step(21, 'decision', 10, [
            $branch,
            $this->task('done', 20),
            $this->task('partial', 30),
            $this->task('wait', 40),
            $this->task('alternate', 50),
            $this->task('retry', 60),
        ]));

        $map = (new WorkflowRouteMapPresenter)->present($workflow);
        $branchEdges = collect($map['edges'])->where('source', 'decision::branch');

        $success = $branchEdges->firstWhere('field', 'next');
        $this->assertSame('decision::done', $success['target']);
        $this->assertSame('success', $success['outcome']);
        $this->assertTrue($success['reachable']);

        $partial = $branchEdges->firstWhere('field', 'on_partial');
        $this->assertSame('partial', $partial['outcome']);
        $this->assertSame('decision::partial', $partial['target']);

        $onErrorEdges = $branchEdges->where('field', 'on_error')->values();
        $this->assertSame(['failed', 'timeout'], $onErrorEdges->pluck('outcome')->all());
        $this->assertTrue($onErrorEdges->every(fn (array $edge): bool => $edge['target'] === 'terminal::fail'));

        foreach ([
            'status_routes.success' => 'next',
            'status_routes.partial' => 'on_partial',
            'status_routes.failed' => 'on_error',
            'status_routes.timeout' => 'on_error',
        ] as $field => $shadowedBy) {
            $shadowed = $branchEdges->firstWhere('field', $field);
            $this->assertNotNull($shadowed);
            $this->assertFalse($shadowed['reachable']);
            $this->assertSame($shadowedBy, $shadowed['shadowed_by']);
        }

        $waiting = $branchEdges->firstWhere('field', 'status_routes.waiting');
        $this->assertTrue($waiting['reachable']);
        $this->assertSame('decision::wait', $waiting['target']);
        $this->assertFalse($branchEdges->contains(
            fn (array $edge): bool => ! $edge['explicit'] && $edge['outcome'] === 'success',
        ));
    }

    public function test_step_default_and_next_routes_are_resolved_to_real_or_terminal_nodes(): void
    {
        $first = $this->step(31, 'first', 10, [], [
            'success' => ['type' => 'step', 'step' => 'next'],
            'failed' => ['step' => 'fail'],
            'default' => ['type' => 'end'],
        ]);
        $second = $this->step(32, 'second', 20, []);
        $workflow = $this->workflow($first, $second);

        $map = (new WorkflowRouteMapPresenter)->present($workflow);

        $success = $this->edge($map, 'first::*', 'second::*', 'success');
        $this->assertTrue($success['explicit']);
        $this->assertSame('routes.success', $success['field']);
        $this->assertSame('step', $success['type']);

        $failed = $this->edge($map, 'first::*', 'terminal::fail', 'failed');
        $this->assertTrue($failed['explicit']);
        $this->assertSame('routes.failed', $failed['field']);

        foreach (['partial', 'timeout'] as $outcome) {
            $fallback = $this->edge($map, 'first::*', 'terminal::end', $outcome);
            $this->assertTrue($fallback['explicit']);
            $this->assertTrue($fallback['fallback']);
            $this->assertSame('routes.default', $fallback['field']);
        }
    }

    public function test_combined_mode_overlays_runtime_path_and_status_without_removing_configured_edges(): void
    {
        $sourceStep = $this->step(41, 'source', 10, [
            $this->task('start', 10, [
                'next' => ['type' => 'card', 'step' => 'target', 'card' => 'finish'],
                'on_error' => ['type' => 'fail'],
            ]),
        ]);
        $targetStep = $this->step(42, 'target', 20, [
            $this->task('finish', 10),
        ]);
        $workflow = $this->workflow($sourceStep, $targetStep);
        $run = $this->workflowRun($workflow, $targetStep, [
            'route_history' => [
                [
                    'at' => '2026-07-30T08:00:00+00:00',
                    'workflow_step_id' => 41,
                    'workflow_step_run_id' => 501,
                    'outcome' => 'success',
                    'logical_outcome' => 'success',
                    'route_disposition' => 'continue',
                    'route' => [
                        'type' => 'card',
                        'action_key' => 'target',
                        'card_key' => 'finish',
                        '_source_card_key' => 'start',
                    ],
                ],
                [
                    'at' => '2026-07-30T08:01:00+00:00',
                    'workflow_step_id' => 42,
                    'workflow_step_run_id' => 502,
                    'outcome' => 'success',
                    'logical_outcome' => 'success',
                    'route_disposition' => 'continue',
                    'route' => [
                        'type' => 'card',
                        'action_key' => 'source',
                        'card_key' => 'start',
                        '_source_card_key' => 'finish',
                    ],
                ],
            ],
            'next_task_key' => 'finish',
            'next_task_route_outcome' => 'success',
            'next_task_route_source_key' => 'start',
            'next_task_logical_outcome' => 'success',
            'next_task_route_disposition' => 'continue',
            'next_step_action_key' => 'target',
        ], [
            $this->stepRun(501, 41, 'completed', [
                ['key' => 'start', 'status' => 'completed', 'logical_outcome' => 'success'],
            ]),
            $this->stepRun(502, 42, 'running', [
                ['key' => 'finish', 'status' => 'running'],
            ]),
        ]);

        $presenter = new WorkflowRouteMapPresenter;
        $combined = $presenter->present($workflow, $run, WorkflowRouteMapPresenter::MODE_COMBINED);

        $executedConfigured = $this->edge($combined, 'source::start', 'target::finish', 'success');
        $this->assertSame(['definition', 'runtime'], $executedConfigured['origins']);
        $this->assertTrue($executedConfigured['configured']);
        $this->assertTrue($executedConfigured['runtime']);
        $this->assertTrue($executedConfigured['executed']);
        $this->assertTrue($executedConfigured['pending']);
        $this->assertSame(1, $executedConfigured['runtime_count']);

        $unexecutedFailure = $this->edge($combined, 'source::start', 'terminal::fail', 'failed');
        $this->assertTrue($unexecutedFailure['configured']);
        $this->assertFalse($unexecutedFailure['runtime']);
        $this->assertFalse($unexecutedFailure['executed']);

        $dynamicBackRoute = $this->edge($combined, 'target::finish', 'source::start', 'success');
        $this->assertSame('runtime', $dynamicBackRoute['origin']);
        $this->assertFalse($dynamicBackRoute['configured']);
        $this->assertSame('back', $dynamicBackRoute['direction']);

        $this->assertSame('completed', $this->node($combined, 'source::start')['status']);
        $this->assertSame('running', $this->node($combined, 'target::finish')['status']);
        $this->assertTrue($this->node($combined, 'target::finish')['active']);
        $this->assertSame('running', $this->node($combined, 'target::*')['status']);
        $this->assertTrue($this->node($combined, 'target::*')['active']);

        $runtime = $presenter->present($workflow, $run, WorkflowRouteMapPresenter::MODE_RUNTIME);
        $this->assertCount(2, $runtime['edges']);
        $this->assertTrue(collect($runtime['edges'])->every(
            fn (array $edge): bool => $edge['origin'] === 'runtime',
        ));

        $definition = $presenter->present($workflow, $run, WorkflowRouteMapPresenter::MODE_DEFINITION);
        $this->assertNull($this->node($definition, 'source::start')['status']);
        $this->assertFalse($this->node($definition, 'target::finish')['active']);
        $this->assertSame($combined, $presenter->present(
            $workflow,
            $run,
            WorkflowRouteMapPresenter::MODE_COMBINED,
        ));
    }

    public function test_runtime_repeats_are_aggregated_and_terminal_status_is_exposed(): void
    {
        $step = $this->step(51, 'only', 10, []);
        $workflow = $this->workflow($step);
        $event = [
            'workflow_step_id' => 51,
            'workflow_step_run_id' => 601,
            'outcome' => 'failed',
            'logical_outcome' => 'technical_error',
            'route_disposition' => 'fail',
            'route' => ['type' => 'fail'],
        ];
        $run = $this->workflowRun($workflow, null, [
            'route_history' => [
                array_replace($event, ['at' => '2026-07-30T09:00:00+00:00']),
                array_replace($event, ['at' => '2026-07-30T09:01:00+00:00']),
            ],
        ], [], 'failed');

        $map = (new WorkflowRouteMapPresenter)->present(
            $workflow,
            $run,
            WorkflowRouteMapPresenter::MODE_RUNTIME,
        );

        $route = $this->edge($map, 'only::*', 'terminal::fail', 'failed');
        $this->assertSame(2, $route['runtime_count']);
        $this->assertCount(2, $route['runtime_events']);
        $this->assertSame('2026-07-30T09:01:00+00:00', $route['latest_at']);
        $this->assertSame('failed', $route['line_tone']);
        $this->assertSame('failed', $this->node($map, 'terminal::fail')['status']);
    }

    public function test_pending_runtime_focus_uses_current_step_when_task_keys_repeat(): void
    {
        $first = $this->step(61, 'first', 10, [
            $this->task('shared', 10),
        ]);
        $second = $this->step(62, 'second', 20, [
            $this->task('origin', 10),
            $this->task('shared', 20),
        ]);
        $workflow = $this->workflow($first, $second);
        $run = $this->workflowRun($workflow, $second, [
            'next_task_key' => 'shared',
            'next_task_route_outcome' => 'success',
            'next_task_route_source_key' => 'origin',
            'next_task_logical_outcome' => 'success',
            'next_task_route_disposition' => 'continue',
        ], [
            $this->stepRun(701, 62, 'running', [
                ['key' => 'origin', 'status' => 'completed'],
            ]),
        ]);

        $map = (new WorkflowRouteMapPresenter)->present(
            $workflow,
            $run,
            WorkflowRouteMapPresenter::MODE_COMBINED,
        );

        $this->assertFalse($this->node($map, 'first::shared')['active']);
        $this->assertTrue($this->node($map, 'second::shared')['active']);

        $pending = $this->edge($map, 'second::origin', 'second::shared', 'success');
        $this->assertTrue($pending['pending']);
        $this->assertFalse($pending['executed']);
    }

    public function test_unknown_mode_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown workflow route-map mode');

        (new WorkflowRouteMapPresenter)->present(
            $this->workflow($this->step(61, 'only', 10, [])),
            null,
            'invalid',
        );
    }

    private function workflow(WorkflowStep ...$steps): Workflow
    {
        $workflow = new Workflow([
            'name' => 'Route map test',
            'slug' => 'route-map-test',
            'is_active' => true,
        ]);
        $workflow->setAttribute('id', 7);
        $workflow->exists = true;
        $workflow->setRelation('steps', new Collection($steps));

        return $workflow;
    }

    /**
     * @param  list<array<string, mixed>>  $tasks
     * @param  array<string, mixed>  $routes
     */
    private function step(
        int $id,
        string $action,
        int $position,
        array $tasks,
        array $routes = [],
        bool $enabled = true,
    ): WorkflowStep {
        $step = new WorkflowStep([
            'name' => ucfirst($action),
            'type' => WorkflowStep::TYPE_BROWSER_TASK,
            'action_key' => $action,
            'position' => $position,
            'is_enabled' => $enabled,
            'config_json' => [
                'tasks' => $tasks,
                'routes' => $routes,
            ],
        ]);
        $step->setAttribute('id', $id);
        $step->exists = true;

        return $step;
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function task(string $key, int $order, array $extra = []): array
    {
        return array_replace([
            'key' => $key,
            'title' => ucfirst($key),
            'task_key' => 'browser.'.$key,
            'order_id' => $order,
        ], $extra);
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  list<WorkflowStepRun>  $stepRuns
     */
    private function workflowRun(
        Workflow $workflow,
        ?WorkflowStep $currentStep,
        array $context,
        array $stepRuns,
        string $status = 'running',
    ): WorkflowRun {
        $run = new WorkflowRun([
            'workflow_id' => $workflow->getKey(),
            'run_uuid' => 'route-map-run',
            'current_workflow_step_id' => $currentStep?->getKey(),
            'status' => $status,
            'context_json' => $context,
        ]);
        $run->setAttribute('id', 70);
        $run->exists = true;
        $run->setRelation('workflow', $workflow);
        $run->setRelation('stepRuns', new Collection($stepRuns));

        return $run;
    }

    /**
     * @param  list<array<string, mixed>>  $tasks
     */
    private function stepRun(int $id, int $stepId, string $status, array $tasks): WorkflowStepRun
    {
        $stepRun = new WorkflowStepRun([
            'workflow_run_id' => 70,
            'workflow_step_id' => $stepId,
            'status' => $status,
            'result_json' => ['tasks' => $tasks],
        ]);
        $stepRun->setAttribute('id', $id);
        $stepRun->exists = true;

        return $stepRun;
    }

    /**
     * @param  array<string, mixed>  $map
     * @return array<string, mixed>
     */
    private function node(array $map, string $id): array
    {
        $node = collect($map['nodes'])->firstWhere('id', $id);
        $this->assertNotNull($node, 'Node not found: '.$id);

        return $node;
    }

    /**
     * @param  array<string, mixed>  $map
     * @return array<string, mixed>
     */
    private function edge(array $map, string $source, string $target, string $outcome): array
    {
        $edge = collect($map['edges'])->first(
            fn (array $edge): bool => $edge['source'] === $source
                && $edge['target'] === $target
                && $edge['outcome'] === $outcome,
        );
        $this->assertNotNull(
            $edge,
            sprintf('Edge not found: %s -> %s (%s)', $source, $target, $outcome),
        );

        return $edge;
    }
}
