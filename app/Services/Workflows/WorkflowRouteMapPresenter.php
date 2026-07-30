<?php

namespace App\Services\Workflows;

use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use App\Models\WorkflowStepRun;
use InvalidArgumentException;

/**
 * Produces the stable, presentation-only route graph shared by the workflow
 * editor and the runtime minimap.
 *
 * Node ids deliberately follow the existing DOM contract (`action::task` and
 * `action::*`). Edges are returned as ordered lists because one source/target
 * pair may represent several outcomes. No workflow definition is changed.
 */
final class WorkflowRouteMapPresenter
{
    public const MODE_DEFINITION = 'definition';

    public const MODE_RUNTIME = 'runtime';

    public const MODE_COMBINED = 'combined';

    private const TERMINAL_END = 'terminal::end';

    private const TERMINAL_FAIL = 'terminal::fail';

    /**
     * @return array{
     *     mode: string,
     *     workflow_id: int|null,
     *     workflow_run_id: int|null,
     *     nodes: list<array<string, mixed>>,
     *     edges: list<array<string, mixed>>,
     *     meta: array<string, mixed>
     * }
     */
    public function present(
        Workflow $workflow,
        ?WorkflowRun $run = null,
        string $mode = self::MODE_DEFINITION,
    ): array {
        $mode = strtolower(trim($mode));

        if (! in_array($mode, [self::MODE_DEFINITION, self::MODE_RUNTIME, self::MODE_COMBINED], true)) {
            throw new InvalidArgumentException('Unknown workflow route-map mode: '.$mode);
        }

        $graph = $this->graphContext($workflow);
        $definitionEdges = $this->definitionEdges($graph);
        $runtimeEdges = $run ? $this->runtimeEdges($run, $graph) : [];

        $nodes = $graph['nodes'];
        if ($run && $mode !== self::MODE_DEFINITION) {
            $nodes = $this->applyRuntimeNodeState($nodes, $run, $graph);
        }

        $edges = match ($mode) {
            self::MODE_RUNTIME => $runtimeEdges,
            self::MODE_COMBINED => $this->combineEdges($definitionEdges, $runtimeEdges),
            default => $definitionEdges,
        };

        return [
            'mode' => $mode,
            'workflow_id' => $workflow->getKey() !== null ? (int) $workflow->getKey() : null,
            'workflow_run_id' => $run?->getKey() !== null ? (int) $run->getKey() : null,
            'nodes' => array_values($nodes),
            'edges' => array_values($edges),
            'meta' => [
                'has_runtime' => $run !== null,
                'definition_edge_count' => count($definitionEdges),
                'runtime_edge_count' => count($runtimeEdges),
                'visible_edge_count' => count($edges),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function graphContext(Workflow $workflow): array
    {
        $steps = $this->orderedSteps($workflow);
        $nodes = [];
        $nodePositions = [];
        $stepNodeById = [];
        $stepNodeByAction = [];
        $stepByAction = [];
        $tasksByAction = [];
        $taskActionsByKey = [];
        $enabledActions = [];

        foreach ($steps as $stepIndex => $step) {
            $action = $this->stepAction($step, $stepIndex);
            $stepNode = $action.'::*';
            $stepNodeById[(int) $step->getKey()] = $stepNode;
            $stepNodeByAction[$action] = $stepNode;
            $stepByAction[$action] = $step;

            if ((bool) $step->is_enabled) {
                $enabledActions[] = $action;
            }

            $nodes[$stepNode] = [
                'id' => $stepNode,
                'kind' => 'step',
                'step' => $action,
                'task' => null,
                'title' => trim((string) $step->name) ?: $action,
                'step_id' => $step->getKey() !== null ? (int) $step->getKey() : null,
                'step_action_key' => $action,
                'task_key' => null,
                'enabled' => (bool) $step->is_enabled,
                'position' => ['step' => $stepIndex, 'task' => -1],
                'status' => null,
                'active' => false,
                'runtime' => null,
            ];
            $nodePositions[$stepNode] = ['step' => $stepIndex, 'task' => -1];

            $tasks = $this->orderedTasks($step);
            $tasksByAction[$action] = $tasks;

            foreach ($tasks as $taskIndex => $task) {
                $taskKey = $this->taskKey($task, $step, $taskIndex);
                $taskNode = $action.'::'.$taskKey;
                $taskActionsByKey[$taskKey] ??= [];
                if (! in_array($action, $taskActionsByKey[$taskKey], true)) {
                    $taskActionsByKey[$taskKey][] = $action;
                }

                $nodes[$taskNode] = [
                    'id' => $taskNode,
                    'kind' => 'task',
                    'step' => $action,
                    'task' => $taskKey,
                    'title' => trim((string) ($task['title'] ?? $task['label'] ?? '')) ?: $taskKey,
                    'step_id' => $step->getKey() !== null ? (int) $step->getKey() : null,
                    'step_action_key' => $action,
                    'task_key' => $taskKey,
                    'task_type' => trim((string) ($task['task_key'] ?? '')),
                    'enabled' => (bool) $step->is_enabled,
                    'position' => ['step' => $stepIndex, 'task' => $taskIndex],
                    'status' => null,
                    'active' => false,
                    'runtime' => null,
                ];
                $nodePositions[$taskNode] = ['step' => $stepIndex, 'task' => $taskIndex];
            }
        }

        $terminalPosition = count($steps);
        $nodes[self::TERMINAL_END] = [
            'id' => self::TERMINAL_END,
            'kind' => 'terminal',
            'step' => null,
            'task' => null,
            'terminal' => 'end',
            'title' => 'Workflow-Ende',
            'step_id' => null,
            'step_action_key' => null,
            'task_key' => null,
            'enabled' => true,
            'position' => ['step' => $terminalPosition, 'task' => 0],
            'status' => null,
            'active' => false,
            'runtime' => null,
        ];
        $nodes[self::TERMINAL_FAIL] = [
            'id' => self::TERMINAL_FAIL,
            'kind' => 'terminal',
            'step' => null,
            'task' => null,
            'terminal' => 'fail',
            'title' => 'Workflow-Abbruch',
            'step_id' => null,
            'step_action_key' => null,
            'task_key' => null,
            'enabled' => true,
            'position' => ['step' => $terminalPosition, 'task' => 1],
            'status' => null,
            'active' => false,
            'runtime' => null,
        ];
        $nodePositions[self::TERMINAL_END] = ['step' => $terminalPosition, 'task' => 0];
        $nodePositions[self::TERMINAL_FAIL] = ['step' => $terminalPosition, 'task' => 1];

        return [
            'workflow' => $workflow,
            'steps' => $steps,
            'nodes' => $nodes,
            'node_positions' => $nodePositions,
            'step_node_by_id' => $stepNodeById,
            'step_node_by_action' => $stepNodeByAction,
            'step_by_action' => $stepByAction,
            'tasks_by_action' => $tasksByAction,
            'task_actions_by_key' => $taskActionsByKey,
            'enabled_actions' => $enabledActions,
        ];
    }

    /**
     * @param  array<string, mixed>  $graph
     * @return list<array<string, mixed>>
     */
    private function definitionEdges(array $graph): array
    {
        $edges = [];

        foreach ($graph['steps'] as $stepIndex => $step) {
            $action = $this->stepAction($step, $stepIndex);
            $sourceStepNode = $action.'::*';
            $tasks = $graph['tasks_by_action'][$action] ?? [];

            if ($tasks !== []) {
                $firstTaskKey = $this->taskKey($tasks[0], $step, 0);
                $edges[] = $this->makeDefinitionEdge(
                    $sourceStepNode,
                    $action.'::'.$firstTaskKey,
                    'enter',
                    'entry',
                    false,
                    null,
                    'Listeneinstieg',
                    $graph,
                );
            }

            foreach (['success', 'failed', 'partial', 'timeout'] as $outcome) {
                $routes = $step->routes;
                $field = is_array($routes[$outcome] ?? null)
                    ? 'routes.'.$outcome
                    : (is_array($routes['default'] ?? null) ? 'routes.default' : null);
                $route = $field === null
                    ? null
                    : ($field === 'routes.default' ? $routes['default'] : $routes[$outcome]);

                if (is_array($route)) {
                    $target = $this->routeTarget($route, $action, $graph);
                    $edges[] = $this->makeDefinitionEdge(
                        $sourceStepNode,
                        $target['node'],
                        $outcome,
                        $target['type'],
                        true,
                        $field,
                        trim((string) ($route['label'] ?? '')),
                        $graph,
                        [
                            'fallback' => $field === 'routes.default',
                            'target_exists' => $target['exists'],
                            'route' => $route,
                        ],
                    );

                    continue;
                }

                $targetNode = $outcome === 'failed'
                    ? self::TERMINAL_FAIL
                    : $this->linearTargetAfterStep($action, $graph);
                $edges[] = $this->makeDefinitionEdge(
                    $sourceStepNode,
                    $targetNode,
                    $outcome,
                    $targetNode === self::TERMINAL_END
                        ? 'end'
                        : ($targetNode === self::TERMINAL_FAIL ? 'fail' : 'step'),
                    false,
                    null,
                    $outcome === 'failed' ? 'Fehler ohne explizite Route' : 'Naechste Liste',
                    $graph,
                );
            }

            foreach ($tasks as $taskIndex => $task) {
                $taskKey = $this->taskKey($task, $step, $taskIndex);
                $sourceTaskNode = $action.'::'.$taskKey;
                $nextTask = $tasks[$taskIndex + 1] ?? null;
                $statusRoutes = is_array($task['status_routes'] ?? null) ? $task['status_routes'] : [];

                $generalRoutes = [
                    'success' => ['field' => 'next', 'route' => $task['next'] ?? null],
                    'partial' => ['field' => 'on_partial', 'route' => $task['on_partial'] ?? null],
                    'failed' => ['field' => 'on_error', 'route' => $task['on_error'] ?? null],
                    'timeout' => ['field' => 'on_error', 'route' => $task['on_error'] ?? null],
                ];

                foreach ($generalRoutes as $outcome => $definition) {
                    if (! is_array($definition['route'])) {
                        continue;
                    }

                    $target = $this->routeTarget($definition['route'], $action, $graph);
                    $edges[] = $this->makeDefinitionEdge(
                        $sourceTaskNode,
                        $target['node'],
                        $outcome,
                        $target['type'],
                        true,
                        $definition['field'],
                        trim((string) ($definition['route']['label'] ?? '')),
                        $graph,
                        [
                            'target_exists' => $target['exists'],
                            'route' => $definition['route'],
                        ],
                    );
                }

                foreach ($this->orderedStatusRoutes($statusRoutes) as $status => $route) {
                    $outcome = strtolower(trim((string) $status));
                    if ($outcome === '' || ! is_array($route)) {
                        continue;
                    }

                    $shadowedBy = match (true) {
                        $outcome === 'success' && is_array($task['next'] ?? null) => 'next',
                        $outcome === 'partial' && is_array($task['on_partial'] ?? null) => 'on_partial',
                        in_array($outcome, ['failed', 'timeout'], true) && is_array($task['on_error'] ?? null) => 'on_error',
                        default => null,
                    };
                    $target = $this->routeTarget($route, $action, $graph);
                    $edges[] = $this->makeDefinitionEdge(
                        $sourceTaskNode,
                        $target['node'],
                        $outcome,
                        $target['type'],
                        true,
                        'status_routes.'.$status,
                        trim((string) ($route['label'] ?? '')),
                        $graph,
                        [
                            'reachable' => $shadowedBy === null,
                            'shadowed_by' => $shadowedBy,
                            'target_exists' => $target['exists'],
                            'route' => $route,
                        ],
                    );
                }

                if (
                    ! is_array($task['next'] ?? null)
                    && ! $this->hasStatusRoute($statusRoutes, 'success')
                    && is_array($nextTask)
                ) {
                    $nextTaskKey = $this->taskKey($nextTask, $step, $taskIndex + 1);
                    $edges[] = $this->makeDefinitionEdge(
                        $sourceTaskNode,
                        $action.'::'.$nextTaskKey,
                        'success',
                        'card',
                        false,
                        null,
                        'Naechste Task',
                        $graph,
                    );
                }
            }
        }

        return $this->uniqueDefinitionEdges($edges);
    }

    /**
     * @param  array<string, mixed>  $graph
     * @return list<array<string, mixed>>
     */
    private function runtimeEdges(WorkflowRun $run, array $graph): array
    {
        $grouped = [];
        $context = is_array($run->context_json) ? $run->context_json : [];
        $history = is_array($context['route_history'] ?? null) ? $context['route_history'] : [];

        foreach ($history as $eventIndex => $event) {
            if (! is_array($event) || ! is_array($event['route'] ?? null)) {
                continue;
            }

            $route = $event['route'];
            $sourceAction = $this->sourceActionForRuntimeEvent($event, $route, $graph);
            if ($sourceAction === '') {
                continue;
            }

            $sourceTask = trim((string) ($route['_source_card_key'] ?? ''));
            $sourceNode = $sourceAction.'::'.($sourceTask !== '' ? $sourceTask : '*');
            $target = $this->routeTarget($route, $sourceAction, $graph);
            $outcome = strtolower(trim((string) ($event['outcome'] ?? ''))) ?: 'success';
            $key = $this->edgeMatchKey($sourceNode, $target['node'], $outcome);
            $runtimeEvent = [
                'index' => $eventIndex,
                'at' => trim((string) ($event['at'] ?? '')),
                'workflow_step_id' => isset($event['workflow_step_id']) ? (int) $event['workflow_step_id'] : null,
                'workflow_step_run_id' => isset($event['workflow_step_run_id']) ? (int) $event['workflow_step_run_id'] : null,
                'outcome' => $outcome,
                'logical_outcome' => trim((string) ($event['logical_outcome'] ?? '')),
                'route_disposition' => trim((string) ($event['route_disposition'] ?? '')),
                'route' => $route,
            ];

            if (! isset($grouped[$key])) {
                $grouped[$key] = $this->makeRuntimeEdge(
                    $sourceNode,
                    $target['node'],
                    $outcome,
                    $target['type'],
                    $graph,
                    $runtimeEvent,
                    [
                        'target_exists' => $target['exists'],
                        'label' => trim((string) ($route['label'] ?? '')),
                    ],
                );
            } else {
                $grouped[$key] = $this->appendRuntimeEvent($grouped[$key], $runtimeEvent);
            }
        }

        $pending = $this->pendingRuntimeEdge($context, $graph, $run);
        if ($pending !== null) {
            $key = $this->edgeMatchKey($pending['source'], $pending['target'], $pending['outcome']);
            if (isset($grouped[$key])) {
                $grouped[$key]['pending'] = true;
                $grouped[$key]['runtime_status'] = 'pending';
            } else {
                $grouped[$key] = $pending;
            }
        }

        return array_values($grouped);
    }

    /**
     * @param  list<array<string, mixed>>  $definitionEdges
     * @param  list<array<string, mixed>>  $runtimeEdges
     * @return list<array<string, mixed>>
     */
    private function combineEdges(array $definitionEdges, array $runtimeEdges): array
    {
        $combined = $definitionEdges;

        foreach ($runtimeEdges as $runtimeEdge) {
            $matchingIndex = null;

            foreach ($combined as $index => $definitionEdge) {
                if (
                    ($definitionEdge['origin'] ?? '') === 'definition'
                    && ($definitionEdge['reachable'] ?? true)
                    && $this->edgeMatchKey(
                        (string) $definitionEdge['source'],
                        (string) $definitionEdge['target'],
                        (string) $definitionEdge['outcome'],
                    ) === $this->edgeMatchKey(
                        (string) $runtimeEdge['source'],
                        (string) $runtimeEdge['target'],
                        (string) $runtimeEdge['outcome'],
                    )
                ) {
                    $matchingIndex = $index;

                    break;
                }
            }

            if ($matchingIndex === null) {
                $combined[] = $runtimeEdge;

                continue;
            }

            $definitionEdge = $combined[$matchingIndex];
            $definitionEdge['origins'] = ['definition', 'runtime'];
            $definitionEdge['runtime'] = true;
            $definitionEdge['executed'] = (bool) ($runtimeEdge['executed'] ?? false);
            $definitionEdge['pending'] = (bool) ($runtimeEdge['pending'] ?? false);
            $definitionEdge['runtime_count'] = (int) ($runtimeEdge['runtime_count'] ?? 0);
            $definitionEdge['runtime_status'] = $runtimeEdge['runtime_status'] ?? null;
            $definitionEdge['logical_outcome'] = $runtimeEdge['logical_outcome'] ?? null;
            $definitionEdge['route_disposition'] = $runtimeEdge['route_disposition'] ?? null;
            $definitionEdge['line_tone'] = $runtimeEdge['line_tone'] ?? 'default';
            $definitionEdge['latest_at'] = $runtimeEdge['latest_at'] ?? null;
            $definitionEdge['runtime_events'] = $runtimeEdge['runtime_events'] ?? [];
            $combined[$matchingIndex] = $definitionEdge;
        }

        return array_values($combined);
    }

    /**
     * @param  array<string, array<string, mixed>>  $nodes
     * @param  array<string, mixed>  $graph
     * @return array<string, array<string, mixed>>
     */
    private function applyRuntimeNodeState(array $nodes, WorkflowRun $run, array $graph): array
    {
        $context = is_array($run->context_json) ? $run->context_json : [];
        $stepRuns = $this->orderedStepRuns($run);
        $latestStepRunByStep = [];
        $taskStates = [];

        foreach (is_array($context['task_history'] ?? null) ? $context['task_history'] : [] as $historyEntry) {
            if (! is_array($historyEntry)) {
                continue;
            }

            $stepId = (int) ($historyEntry['workflow_step_id'] ?? 0);
            $taskKey = trim((string) ($historyEntry['task_key'] ?? ''));
            if ($stepId <= 0 || $taskKey === '') {
                continue;
            }

            $taskStates[$stepId][$taskKey] = [
                'status' => trim((string) ($historyEntry['status'] ?? '')) ?: 'completed',
                'sequence' => isset($historyEntry['seq']) ? (int) $historyEntry['seq'] : null,
                'source' => 'history',
            ];
        }

        foreach ($stepRuns as $stepRun) {
            $stepId = (int) $stepRun->workflow_step_id;
            $latestStepRunByStep[$stepId] = $stepRun;

            foreach (is_array(data_get($stepRun->result_json, 'tasks')) ? data_get($stepRun->result_json, 'tasks') : [] as $taskResult) {
                if (! is_array($taskResult)) {
                    continue;
                }

                $taskKey = trim((string) ($taskResult['key'] ?? ''));
                if ($taskKey === '') {
                    continue;
                }

                $knownStatus = trim((string) ($taskStates[$stepId][$taskKey]['status'] ?? ''));
                $status = trim((string) ($taskResult['status'] ?? '')) ?: $knownStatus;
                $taskStates[$stepId][$taskKey] = [
                    'status' => $status !== '' ? $status : null,
                    'logical_outcome' => trim((string) ($taskResult['logical_outcome'] ?? $taskResult['logicalOutcome'] ?? '')) ?: null,
                    'source' => 'step_run',
                    'workflow_step_run_id' => $stepRun->getKey() !== null ? (int) $stepRun->getKey() : null,
                ];
            }
        }

        $activeStepId = (int) ($run->current_workflow_step_id ?? 0);
        if ($activeStepId <= 0) {
            foreach (array_reverse($stepRuns) as $stepRun) {
                if (in_array((string) $stepRun->status, ['running', 'waiting'], true)) {
                    $activeStepId = (int) $stepRun->workflow_step_id;

                    break;
                }
            }
        }

        $pendingTaskKey = trim((string) ($context['next_task_key'] ?? ''));
        $activeStepAction = $this->actionForStepId($activeStepId, $graph);
        $contextStepAction = trim((string) ($context['next_step_action_key'] ?? ''));
        $pendingTaskAction = $this->actionForTaskKey(
            $pendingTaskKey,
            $graph,
            $contextStepAction !== '' ? $contextStepAction : $activeStepAction,
        );

        foreach ($nodes as $nodeId => $node) {
            if ($node['kind'] === 'step') {
                $stepId = (int) ($node['step_id'] ?? 0);
                $stepRun = $latestStepRunByStep[$stepId] ?? null;
                $node['status'] = $stepRun?->status;
                $node['active'] = $stepId > 0 && $stepId === $activeStepId;
                $node['runtime'] = $stepRun ? [
                    'workflow_step_run_id' => $stepRun->getKey() !== null ? (int) $stepRun->getKey() : null,
                    'status' => (string) $stepRun->status,
                ] : null;
            } elseif ($node['kind'] === 'task') {
                $stepId = (int) ($node['step_id'] ?? 0);
                $taskKey = (string) ($node['task_key'] ?? '');
                $runtime = $taskStates[$stepId][$taskKey] ?? null;
                $node['status'] = $runtime['status'] ?? null;
                $node['active'] = $pendingTaskKey !== ''
                    && $taskKey === $pendingTaskKey
                    && ($pendingTaskAction === '' || $pendingTaskAction === (string) $node['step']);

                if ($node['active'] && $node['status'] === null) {
                    $node['status'] = 'pending';
                }

                $node['runtime'] = $runtime;
            }

            $nodes[$nodeId] = $node;
        }

        $runStatus = strtolower(trim((string) $run->status));
        if (in_array($runStatus, ['completed', 'success', 'succeeded'], true)) {
            $nodes[self::TERMINAL_END]['status'] = 'completed';
            $nodes[self::TERMINAL_END]['runtime'] = ['run_status' => $runStatus];
        } elseif (in_array($runStatus, ['failed', 'cancelled', 'timed_out', 'lost', 'unreachable'], true)) {
            $nodes[self::TERMINAL_FAIL]['status'] = $runStatus;
            $nodes[self::TERMINAL_FAIL]['runtime'] = ['run_status' => $runStatus];
        }

        return $nodes;
    }

    /**
     * @param  array<string, mixed>  $graph
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function makeDefinitionEdge(
        string $source,
        string $target,
        string $outcome,
        string $type,
        bool $explicit,
        ?string $field,
        string $label,
        array $graph,
        array $extra = [],
    ): array {
        $edge = [
            'id' => $this->definitionEdgeId($source, $target, $outcome, $field, $type),
            'source' => $source,
            'target' => $target,
            'outcome' => $outcome,
            'type' => $type,
            'explicit' => $explicit,
            'configured' => $explicit,
            'origin' => 'definition',
            'origins' => ['definition'],
            'field' => $field,
            'label' => $label !== '' ? $label : $this->outcomeLabel($outcome),
            'direction' => $this->direction($source, $target, $graph),
            'reachable' => true,
            'shadowed_by' => null,
            'target_exists' => isset($graph['nodes'][$target]),
            'runtime' => false,
            'executed' => false,
            'pending' => false,
            'runtime_count' => 0,
            'runtime_status' => null,
            'logical_outcome' => null,
            'route_disposition' => null,
            'line_tone' => 'default',
            'latest_at' => null,
            'runtime_events' => [],
        ];

        return array_replace($edge, $extra);
    }

    /**
     * @param  array<string, mixed>  $graph
     * @param  array<string, mixed>  $event
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function makeRuntimeEdge(
        string $source,
        string $target,
        string $outcome,
        string $type,
        array $graph,
        array $event,
        array $extra = [],
    ): array {
        $edge = [
            'id' => $this->runtimeEdgeId($source, $target, $outcome, $type),
            'source' => $source,
            'target' => $target,
            'outcome' => $outcome,
            'type' => $type,
            'explicit' => true,
            'configured' => false,
            'origin' => 'runtime',
            'origins' => ['runtime'],
            'field' => null,
            'label' => $this->outcomeLabel($outcome),
            'direction' => $this->direction($source, $target, $graph),
            'reachable' => true,
            'shadowed_by' => null,
            'target_exists' => isset($graph['nodes'][$target]),
            'runtime' => true,
            'executed' => true,
            'pending' => false,
            'runtime_count' => 1,
            'runtime_status' => 'executed',
            'logical_outcome' => $event['logical_outcome'] ?: null,
            'route_disposition' => $event['route_disposition'] ?: null,
            'line_tone' => $this->lineTone(
                $outcome,
                (string) $event['logical_outcome'],
                (string) $event['route_disposition'],
            ),
            'latest_at' => $event['at'] ?: null,
            'runtime_events' => [$event],
        ];

        return array_replace($edge, $extra);
    }

    /**
     * @param  array<string, mixed>  $edge
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    private function appendRuntimeEvent(array $edge, array $event): array
    {
        $events = is_array($edge['runtime_events'] ?? null) ? $edge['runtime_events'] : [];
        $events[] = $event;
        $edge['runtime_events'] = $events;
        $edge['runtime_count'] = count($events);
        $edge['logical_outcome'] = $event['logical_outcome'] ?: null;
        $edge['route_disposition'] = $event['route_disposition'] ?: null;
        $edge['line_tone'] = $this->lineTone(
            (string) $edge['outcome'],
            (string) $event['logical_outcome'],
            (string) $event['route_disposition'],
        );
        $edge['latest_at'] = $event['at'] ?: null;

        return $edge;
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $graph
     * @return array<string, mixed>|null
     */
    private function pendingRuntimeEdge(array $context, array $graph, WorkflowRun $run): ?array
    {
        $targetTask = trim((string) ($context['next_task_key'] ?? ''));
        $outcome = strtolower(trim((string) ($context['next_task_route_outcome'] ?? '')));
        $sourceTask = trim((string) ($context['next_task_route_source_key'] ?? ''));
        $targetAction = trim((string) ($context['next_step_action_key'] ?? ''));

        if ($targetTask === '' || $outcome === '') {
            return null;
        }

        $currentAction = $this->actionForStepId((int) ($run->current_workflow_step_id ?? 0), $graph);
        $targetAction = $targetAction !== ''
            ? $targetAction
            : $this->actionForTaskKey($targetTask, $graph, $currentAction);
        $sourceAction = $this->actionForTaskKey($sourceTask, $graph, $targetAction);
        $sourceAction = $sourceAction !== '' ? $sourceAction : $targetAction;

        if ($sourceAction === '' || $targetAction === '') {
            return null;
        }

        $sourceNode = $sourceAction.'::'.($sourceTask !== '' ? $sourceTask : '*');
        $targetNode = $targetAction.'::'.$targetTask;
        $event = [
            'index' => null,
            'at' => null,
            'workflow_step_id' => null,
            'workflow_step_run_id' => null,
            'outcome' => $outcome,
            'logical_outcome' => trim((string) ($context['next_task_logical_outcome'] ?? '')),
            'route_disposition' => trim((string) ($context['next_task_route_disposition'] ?? '')),
            'route' => [
                'type' => 'card',
                'action_key' => $targetAction,
                'card_key' => $targetTask,
                '_source_card_key' => $sourceTask,
            ],
        ];
        $edge = $this->makeRuntimeEdge(
            $sourceNode,
            $targetNode,
            $outcome,
            'card',
            $graph,
            $event,
            ['label' => 'Naechste Task', 'pending' => true, 'runtime_status' => 'pending'],
        );

        // A pending cursor is not proof that the edge has already executed.
        $edge['executed'] = false;
        $edge['runtime_count'] = 0;
        $edge['runtime_events'] = [];

        return $edge;
    }

    /**
     * @param  array<string, mixed>  $route
     * @param  array<string, mixed>  $graph
     * @return array{node: string, type: string, exists: bool}
     */
    private function routeTarget(array $route, string $sourceAction, array $graph): array
    {
        $type = strtolower(trim((string) ($route['type'] ?? '')));
        $targetAction = trim((string) ($route['action_key'] ?? $route['step'] ?? ''));
        $targetTask = trim((string) ($route['card_key'] ?? $route['card'] ?? ''));

        if (in_array(strtolower($targetAction), ['end', 'fail'], true)) {
            $type = strtolower($targetAction);
        }

        if ($type === 'end') {
            return ['node' => self::TERMINAL_END, 'type' => 'end', 'exists' => true];
        }

        if ($type === 'fail') {
            return ['node' => self::TERMINAL_FAIL, 'type' => 'fail', 'exists' => true];
        }

        if ($targetAction === 'next') {
            $targetNode = $this->linearTargetAfterStep($sourceAction, $graph);

            return [
                'node' => $targetNode,
                'type' => $targetNode === self::TERMINAL_END ? 'end' : 'step',
                'exists' => isset($graph['nodes'][$targetNode]),
            ];
        }

        if ($targetAction === '' && $targetTask !== '') {
            $targetAction = $sourceAction;
        }

        if ($type === 'card' && $targetTask === '') {
            return ['node' => self::TERMINAL_FAIL, 'type' => 'invalid', 'exists' => false];
        }

        if ($targetTask !== '') {
            $node = $targetAction.'::'.$targetTask;

            return [
                'node' => $node,
                'type' => 'card',
                'exists' => isset($graph['nodes'][$node]),
            ];
        }

        if ($targetAction !== '') {
            $node = $targetAction.'::*';

            return [
                'node' => $node,
                'type' => 'step',
                'exists' => isset($graph['nodes'][$node]),
            ];
        }

        return ['node' => self::TERMINAL_FAIL, 'type' => 'invalid', 'exists' => false];
    }

    /**
     * @param  array<string, mixed>  $graph
     */
    private function linearTargetAfterStep(string $sourceAction, array $graph): string
    {
        $enabledActions = $graph['enabled_actions'];
        $sourceIndex = array_search($sourceAction, $enabledActions, true);

        if ($sourceIndex === false) {
            $allActions = array_keys($graph['step_by_action']);
            $allIndex = array_search($sourceAction, $allActions, true);
            if ($allIndex !== false) {
                foreach (array_slice($allActions, $allIndex + 1) as $candidate) {
                    if (in_array($candidate, $enabledActions, true)) {
                        return $candidate.'::*';
                    }
                }
            }

            return self::TERMINAL_END;
        }

        $nextAction = $enabledActions[$sourceIndex + 1] ?? null;

        return is_string($nextAction) ? $nextAction.'::*' : self::TERMINAL_END;
    }

    /**
     * @param  array<string, mixed>  $event
     * @param  array<string, mixed>  $route
     * @param  array<string, mixed>  $graph
     */
    private function sourceActionForRuntimeEvent(array $event, array $route, array $graph): string
    {
        $stepId = (int) ($event['workflow_step_id'] ?? 0);
        if ($stepId > 0 && isset($graph['step_node_by_id'][$stepId])) {
            return strstr((string) $graph['step_node_by_id'][$stepId], '::*', true) ?: '';
        }

        $explicitAction = trim((string) (
            $route['_source_action_key']
            ?? $event['workflow_step_action_key']
            ?? $event['step_action_key']
            ?? ''
        ));
        if ($explicitAction !== '' && isset($graph['step_node_by_action'][$explicitAction])) {
            return $explicitAction;
        }

        $sourceTask = trim((string) ($route['_source_card_key'] ?? ''));
        if ($sourceTask !== '') {
            return $this->actionForTaskKey($sourceTask, $graph);
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $graph
     */
    private function actionForTaskKey(string $taskKey, array $graph, string $preferredAction = ''): string
    {
        if ($taskKey === '') {
            return '';
        }

        if ($preferredAction !== '' && isset($graph['nodes'][$preferredAction.'::'.$taskKey])) {
            return $preferredAction;
        }

        $actions = array_values(array_unique(array_filter(
            (array) ($graph['task_actions_by_key'][$taskKey] ?? []),
            fn (mixed $action): bool => is_string($action) && $action !== '',
        )));

        return count($actions) === 1 ? $actions[0] : '';
    }

    /**
     * @param  array<string, mixed>  $graph
     */
    private function actionForStepId(int $stepId, array $graph): string
    {
        if ($stepId <= 0 || ! isset($graph['step_node_by_id'][$stepId])) {
            return '';
        }

        return strstr((string) $graph['step_node_by_id'][$stepId], '::*', true) ?: '';
    }

    /**
     * @return list<WorkflowStep>
     */
    private function orderedSteps(Workflow $workflow): array
    {
        $steps = $workflow->relationLoaded('steps')
            ? $workflow->getRelation('steps')
            : ($workflow->exists ? $workflow->steps()->get() : collect());

        return collect($steps)
            ->values()
            ->map(fn (WorkflowStep $step, int $index): array => ['step' => $step, 'index' => $index])
            ->sortBy(fn (array $entry): string => implode(':', [
                str_pad((string) max(0, (int) $entry['step']->position), 10, '0', STR_PAD_LEFT),
                str_pad((string) max(0, (int) ($entry['step']->getKey() ?? 0)), 10, '0', STR_PAD_LEFT),
                str_pad((string) $entry['index'], 10, '0', STR_PAD_LEFT),
            ]))
            ->pluck('step')
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function orderedTasks(WorkflowStep $step): array
    {
        return collect($step->task_cards)
            ->values()
            ->map(fn (array $task, int $index): array => ['task' => $task, 'index' => $index])
            ->sortBy(fn (array $entry): string => implode(':', [
                str_pad((string) max(0, (int) ($entry['task']['order_id'] ?? $entry['task']['position'] ?? 0)), 10, '0', STR_PAD_LEFT),
                str_pad((string) $entry['index'], 10, '0', STR_PAD_LEFT),
            ]))
            ->pluck('task')
            ->values()
            ->all();
    }

    /**
     * @return list<WorkflowStepRun>
     */
    private function orderedStepRuns(WorkflowRun $run): array
    {
        $stepRuns = $run->relationLoaded('stepRuns')
            ? $run->getRelation('stepRuns')
            : ($run->exists ? $run->stepRuns()->get() : collect());

        return collect($stepRuns)
            ->values()
            ->sortBy(fn (WorkflowStepRun $stepRun, int $index): string => implode(':', [
                str_pad((string) max(0, (int) ($stepRun->getKey() ?? 0)), 10, '0', STR_PAD_LEFT),
                str_pad((string) $index, 10, '0', STR_PAD_LEFT),
            ]))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $task
     */
    private function taskKey(array $task, WorkflowStep $step, int $taskIndex): string
    {
        $key = trim((string) ($task['key'] ?? ''));

        return $key !== ''
            ? $key
            : 'task-'.($step->getKey() ?? trim((string) $step->action_key) ?: 'step').'-'.$taskIndex;
    }

    private function stepAction(WorkflowStep $step, int $stepIndex): string
    {
        $action = trim((string) $step->action_key);

        return $action !== '' ? $action : 'step-'.($step->getKey() ?? $stepIndex);
    }

    /**
     * @param  array<string, mixed>  $routes
     * @return array<string, mixed>
     */
    private function orderedStatusRoutes(array $routes): array
    {
        uksort($routes, static fn (string|int $left, string|int $right): int => strcmp((string) $left, (string) $right));

        return $routes;
    }

    /**
     * @param  array<string, mixed>  $routes
     */
    private function hasStatusRoute(array $routes, string $outcome): bool
    {
        foreach ($routes as $status => $route) {
            if (strtolower(trim((string) $status)) === $outcome && is_array($route)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array<string, mixed>>  $edges
     * @return list<array<string, mixed>>
     */
    private function uniqueDefinitionEdges(array $edges): array
    {
        $unique = [];

        foreach ($edges as $edge) {
            $unique[(string) $edge['id']] = $edge;
        }

        return array_values($unique);
    }

    /**
     * @param  array<string, mixed>  $graph
     */
    private function direction(string $source, string $target, array $graph): string
    {
        if ($target === self::TERMINAL_END) {
            return 'end';
        }

        if ($target === self::TERMINAL_FAIL) {
            return 'fail';
        }

        $sourcePosition = $graph['node_positions'][$source] ?? null;
        $targetPosition = $graph['node_positions'][$target] ?? null;

        if (! is_array($sourcePosition) || ! is_array($targetPosition)) {
            return 'unresolved';
        }

        if ($source === $target) {
            return 'loop';
        }

        if (
            $targetPosition['step'] < $sourcePosition['step']
            || (
                $targetPosition['step'] === $sourcePosition['step']
                && $targetPosition['task'] <= $sourcePosition['task']
            )
        ) {
            return 'back';
        }

        return 'forward';
    }

    private function lineTone(string $outcome, string $logicalOutcome, string $disposition): string
    {
        return match (true) {
            in_array($disposition, ['fail', 'invalid'], true) => 'failed',
            in_array($logicalOutcome, ['condition_true', 'success'], true) => 'success',
            $logicalOutcome === 'condition_false' => 'waiting',
            in_array($logicalOutcome, ['technical_error', 'timeout'], true) => 'failed',
            $outcome === 'success' => 'success',
            in_array($outcome, ['partial', 'waiting'], true) => 'waiting',
            in_array($outcome, ['failed', 'timeout'], true) => 'failed',
            default => 'default',
        };
    }

    private function outcomeLabel(string $outcome): string
    {
        return match ($outcome) {
            'enter' => 'Listeneinstieg',
            'success' => 'Erfolg',
            'failed' => 'Fehler',
            'partial' => 'Teilergebnis',
            'timeout' => 'Zeitueberschreitung',
            default => $outcome,
        };
    }

    private function edgeMatchKey(string $source, string $target, string $outcome): string
    {
        return implode("\0", [$source, $target, strtolower(trim($outcome))]);
    }

    private function definitionEdgeId(
        string $source,
        string $target,
        string $outcome,
        ?string $field,
        string $type,
    ): string {
        return 'definition-route-'.substr(hash('sha256', implode("\0", [
            $source,
            $target,
            strtolower(trim($outcome)),
            (string) $field,
            $type,
        ])), 0, 20);
    }

    private function runtimeEdgeId(string $source, string $target, string $outcome, string $type): string
    {
        return 'runtime-route-'.substr(hash('sha256', implode("\0", [
            $source,
            $target,
            strtolower(trim($outcome)),
            $type,
        ])), 0, 20);
    }
}
