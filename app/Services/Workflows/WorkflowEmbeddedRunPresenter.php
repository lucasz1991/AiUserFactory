<?php

namespace App\Services\Workflows;

use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use App\Models\WorkflowStepRun;

/** Frozen, invocation-scoped child diagrams. Never reads another workflow's current definition. */
final class WorkflowEmbeddedRunPresenter
{
    private const ACTIVE = ['queued', 'running', 'waiting', 'stop_requested', 'unreachable'];

    public function present(Workflow $workflow, ?WorkflowRun $run): array
    {
        if (! $run || (int) $run->workflow_id !== (int) $workflow->id) {
            return [];
        }

        $workflow->loadMissing('steps');
        $knownSteps = $workflow->steps->keyBy('id');
        $stepRuns = $run->relationLoaded('stepRuns') ? $run->stepRuns : $run->stepRuns()->get();
        $latest = $stepRuns->filter(fn ($stepRun) => (int) $stepRun->workflow_run_id === (int) $run->id)
            ->sortBy('id')->groupBy('workflow_step_id')->map->last();
        $maps = [];

        foreach ($latest as $stepRun) {
            if (! $knownSteps->has((int) $stepRun->workflow_step_id)) {
                continue;
            }
            $snapshot = is_array($stepRun->result_json) ? $stepRun->result_json : [];
            foreach (['workflowRunId' => $run->id, 'workflowStepRunId' => $stepRun->id, 'workflowStepId' => $stepRun->workflow_step_id] as $field => $id) {
                if (isset($snapshot[$field]) && (int) $snapshot[$field] !== (int) $id) {
                    continue 2;
                }
            }
            $tasks = is_array($snapshot['tasks'] ?? null) ? $snapshot['tasks'] : [];
            $snapshot['__frozen_configurations'] = is_array($snapshot['configuredTasks'] ?? null);
            if ($snapshot['__frozen_configurations']) {
                $validResults = collect($tasks)->filter(fn ($task) => is_array($task) && isset($task['key']));
                if ($validResults->count() !== $validResults->pluck('key')->unique()->count()) {
                    continue;
                }
                $results = collect($tasks)->filter(fn ($task) => is_array($task) && isset($task['key']))->keyBy('key');
                $tasks = collect($snapshot['configuredTasks'])->filter(fn ($task) => is_array($task))
                    ->map(fn ($task) => array_replace($task, $results->get($task['key'] ?? '', [])))->all();
            }
            if (count($tasks) > 5000) {
                continue;
            }
            [$frames, $tasks] = $this->frames($tasks, (int) $stepRun->workflow_step_id);
            $activeKeys = [];
            $live = in_array($run->status, self::ACTIVE, true)
                && in_array($stepRun->status, ['running', 'waiting'], true)
                && (int) $run->current_workflow_step_id === (int) $stepRun->workflow_step_id
                && ! in_array($snapshot['state'] ?? $snapshot['status'] ?? '', ['completed', 'failed', 'cancelled', 'timed_out'], true);
            foreach ($tasks as $key => $task) {
                if ($live && in_array($task['status'] ?? '', ['running', 'waiting'], true)) {
                    $activeKeys[] = $key;
                }
            }
            // Ambiguous snapshots do not identify a current task.
            $activeKey = count($activeKeys) === 1 ? $activeKeys[0] : null;
            foreach ($frames as $frameKey => $frame) {
                $map = $this->map($run, $stepRun, $snapshot, $tasks, $frames, $frameKey, $activeKey);
                if ($map !== null) {
                    $maps[$frameKey] = $map;
                }
            }
        }

        // Preorder keeps every descendant visibly below its actual invocation.
        $ordered = [];
        $visit = function (?string $parent, int $depth) use (&$visit, &$ordered, $maps): void {
            foreach ($maps as $key => $map) {
                if (($map['parent_frame_key'] ?: null) !== $parent || isset($ordered[$key])) {
                    continue;
                }
                $map['depth'] = $depth;
                $ordered[$key] = $map;
                $visit($key, $depth + 1);
            }
        };
        $visit(null, 1);

        return array_values($ordered);
    }

    private function frames(array $rawTasks, int $rootStepId): array
    {
        $frames = [];
        $tasks = [];
        foreach (array_values($rawTasks) as $configuredIndex => $task) {
            if (! is_array($task) || ! is_string($task['key'] ?? null) || $task['key'] === '') {
                continue;
            }
            // A duplicated runtime key makes the whole attempt ambiguous.
            if (isset($tasks[$task['key']])) {
                return [[], []];
            }
            $path = $task['embedded_workflow_path'] ?? [];
            if (! is_array($path) || count($path) > 32) {
                continue;
            }
            if ($path !== [] && (int) ($path[0]['source_step_id'] ?? 0) !== $rootStepId) {
                continue;
            }
            $parent = '';
            foreach ($path as $frame) {
                if (! is_array($frame) || ! is_string($frame['frame_key'] ?? null)
                    || $frame['frame_key'] === '' || strlen($frame['frame_key']) > 2048
                    || (string) ($frame['parent_frame_key'] ?? '') !== $parent
                    || (int) ($frame['workflow_id'] ?? 0) <= 0) {
                    continue 2;
                }
                $key = $frame['frame_key'];
                if (isset($frames[$key]) && $frames[$key] !== $frame) {
                    return [[], []];
                }
                $frames[$key] = $frame;
                $parent = $key;
            }
            if (count($frames) > 128) {
                return [[], []];
            }
            $tasks[$task['key']] = $task + ['__configured_index' => $configuredIndex];
        }

        return [$frames, $tasks];
    }

    private function map(WorkflowRun $rootRun, WorkflowStepRun $rootStepRun, array $snapshot, array $rawTasks, array $frames, string $frameKey, ?string $activeKey): ?array
    {
        $frame = $frames[$frameKey];
        $children = array_filter($frames, fn (array $child) => ($child['parent_frame_key'] ?? '') === $frameKey);
        $boundaries = [];
        foreach ($rawTasks as $task) {
            if (($task['runner'] ?? '') === 'workflow-boundary') {
                $boundaries[(string) ($task['embedded_workflow_frame_key'] ?? '')] = $task;
            }
        }
        $closing = $boundaries[$frameKey] ?? [];
        $mapping = [];
        $cards = [];
        $sources = [];
        $windows = [];
        $observed = false;
        $failed = false;
        $activeNode = null;
        foreach ($rawTasks as $key => $task) {
            $path = $task['embedded_workflow_path'] ?? [];
            $keys = array_column($path, 'frame_key');
            $index = array_search($frameKey, $keys, true);
            if ($index === false) {
                continue;
            }
            $window = trim((string) ($task['browser_window_name'] ?? $task['browser_window'] ?? ''));
            if ($window !== '') {
                $windows[$window] = true;
            }
            $observed = $observed || $this->executed($task);
            $failed = $failed || in_array($task['status'] ?? '', ['failed', 'timeout', 'timed_out'], true);
            if ($index + 1 < count($path)) {
                $childKey = $keys[$index + 1];
                $child = $children[$childKey] ?? null;
                if ($child === null) {
                    continue;
                }
                $cardKey = $boundaries[$childKey]['key'] ?? 'include-'.$childKey;
                $stepId = (int) ($child['source_step_id'] ?? 0);
                $action = (string) ($child['source_step_action_key'] ?? '');
                $mapping[$key] = ['step_id' => $stepId, 'action' => $action, 'key' => $cardKey];
                $sources[$stepId] = ['action' => $action, 'name' => $child['source_step_name'] ?? $action, 'position' => $child['source_step_position'] ?? count($sources)];
                $cards[$stepId][$cardKey] = [
                    'key' => $cardKey, 'task_key' => 'workflow.'.$child['workflow_id'],
                    'title' => $child['include_task_title'] ?? $child['workflow_name'] ?? 'Unter-Workflow',
                    'order_id' => $child['include_task_order'] ?? count($cards[$stepId] ?? []) * 10,
                    'status' => $this->safeStatus($boundaries[$childKey]['status'] ?? 'configured', $rootRun),
                ] + array_intersect_key($boundaries[$childKey] ?? [], array_flip(['next', 'on_partial', 'on_error', 'status_routes']));
            } elseif (($task['runner'] ?? '') === 'workflow-boundary') {
                $mapping[$key] = ['terminal' => ($task['status'] ?? '') === 'failed' ? 'fail' : 'end'];
            } else {
                $stepId = (int) ($task['embedded_source_step_id'] ?? 0);
                $action = (string) ($task['embedded_source_action_key'] ?? '');
                if ($stepId <= 0 || $action === '') {
                    continue;
                }
                $mapping[$key] = ['step_id' => $stepId, 'action' => $action, 'key' => $key];
                $sources[$stepId] = ['action' => $action, 'name' => $task['embedded_source_step_name'] ?? $action, 'position' => $task['embedded_source_step_position'] ?? count($sources)];
                $cards[$stepId][$key] = [
                    'key' => $key, 'task_key' => $task['task_key'] ?? '', 'title' => $task['title'] ?? $key,
                    'order_id' => $task['embedded_source_task_order'] ?? $task['order_id'] ?? count($cards[$stepId] ?? []) * 10,
                    'status' => $this->safeStatus($task['status'] ?? 'configured', $rootRun, $key === $activeKey),
                ] + array_intersect_key($task, array_flip(['next', 'on_partial', 'on_error', 'status_routes', 'startedAt', 'finishedAt', 'ok', 'logical_outcome', 'logicalOutcome']));
            }
            if ($key === $activeKey && isset($mapping[$key]['key'])) {
                $activeNode = $mapping[$key] + ['status' => $task['status'], 'source' => 'runtime'];
                $cards[$stepId][$mapping[$key]['key']]['status'] = $task['status'];
            }
        }
        if ($cards === []) {
            return null;
        }
        if ($activeNode !== null) {
            $cards[$activeNode['step_id']][$activeNode['key']]['status'] = $activeNode['status'];
        }
        $workflow = new Workflow(['name' => $frame['workflow_name'] ?? 'Unter-Workflow']);
        $workflow->id = (int) $frame['workflow_id'];
        $steps = collect();
        $stepRuns = collect();
        $status = in_array($closing['status'] ?? '', ['success', 'completed'], true) ? 'completed'
            : (($closing['status'] ?? '') === 'failed' || $failed ? 'failed'
                : ($activeNode ? 'running' : ($observed ? ($rootRun->status === 'paused' ? 'paused' : (in_array($rootRun->status, self::ACTIVE, true) ? 'waiting' : 'interrupted')) : 'configured')));
        $routes = $this->observedRoutes($snapshot, $rawTasks, $mapping);
        foreach ($cards as $stepId => $taskCards) {
            if ($stepId <= 0 || ($sources[$stepId]['action'] ?? '') === '') {
                continue;
            }
            foreach ($taskCards as &$card) {
                foreach (['next', 'on_error', 'on_partial'] as $field) {
                    if (is_array($card[$field] ?? null)) {
                        $card[$field] = $this->route($card[$field], $mapping);
                    }
                }
                foreach ((array) ($card['status_routes'] ?? []) as $outcome => $route) {
                    $card['status_routes'][$outcome] = is_array($route) ? $this->route($route, $mapping) : null;
                }
            }
            unset($card);
            $step = new WorkflowStep(['workflow_id' => $workflow->id, 'name' => $sources[$stepId]['name'],
                'action_key' => $sources[$stepId]['action'], 'position' => $sources[$stepId]['position'],
                'type' => WorkflowStep::TYPE_BROWSER_TASK, 'is_enabled' => true, 'config_json' => ['tasks' => array_values($taskCards)]]);
            $step->id = $stepId;
            $steps->push($step);
            $stepStatuses = array_column($taskCards, 'status');
            $stepStatus = $activeNode && $activeNode['step_id'] === $stepId ? 'running'
                : (in_array('failed', $stepStatuses, true) ? 'failed'
                    : (count(array_diff($stepStatuses, ['completed', 'success'])) === 0 ? 'completed' : 'configured'));
            $stepRun = new WorkflowStepRun(['workflow_run_id' => $rootRun->id, 'workflow_step_id' => $stepId,
                'status' => $stepStatus,
                'result_json' => ['tasks' => array_values($taskCards)]]);
            $stepRun->id = $rootStepRun->id;
            $stepRuns->push($stepRun);
        }
        $workflow->setRelation('steps', $steps->sortBy('position')->values());
        $run = new WorkflowRun(['workflow_id' => $workflow->id, 'status' => $status,
            'current_workflow_step_id' => $activeNode['step_id'] ?? null,
            'context_json' => ['route_history' => $routes, 'next_task_key' => $activeNode['key'] ?? '']]);
        $run->id = $rootRun->id;
        $run->setRelation('stepRuns', $stepRuns)->setRelation('workflow', $workflow);

        return [
            'frame_key' => $frameKey, 'parent_frame_key' => (string) ($frame['parent_frame_key'] ?? ''),
            'name' => (string) $workflow->name, 'include_title' => (string) ($frame['include_task_title'] ?? $workflow->name),
            'workflow' => $workflow, 'run' => $run, 'status' => $status, 'active' => $activeNode !== null,
            'runtime_task' => $activeNode ? ['step_id' => $activeNode['step_id'], 'task_key' => $activeNode['key'], 'status' => $activeNode['status'], 'source' => 'runtime'] : [],
            'browser_windows' => array_keys($windows),
            'route_map' => app(WorkflowRouteMapPresenter::class)->present($workflow, $run, WorkflowRouteMapPresenter::MODE_COMBINED),
        ];
    }

    private function route(array $route, array $mapping): array
    {
        $key = (string) ($route['card_key'] ?? $route['card'] ?? '');
        $target = $mapping[$key] ?? null;
        if (isset($target['terminal'])) {
            return ['type' => $target['terminal']];
        }
        if ($target !== null && isset($target['key'])) {
            return ['type' => 'card', 'action_key' => $target['action'], 'card_key' => $target['key']];
        }

        // Preserve terminal semantics but do not invent a target for foreign keys.
        return in_array($route['type'] ?? '', ['end', 'fail'], true) ? ['type' => $route['type']] : ['type' => 'card', 'card_key' => 'unobserved-target'];
    }

    private function observedRoutes(array $snapshot, array $tasks, array $mapping): array
    {
        $routes = [];
        $append = function (string $source, string $target, string $outcome, string $at) use (&$routes, $mapping): void {
            $from = $mapping[$source] ?? null;
            $to = $mapping[$target] ?? null;
            if (! isset($from['key']) || $to === null || ($from['key'] ?? null) === ($to['key'] ?? null)) {
                return;
            }
            $routes[] = ['at' => $at, 'workflow_step_id' => $from['step_id'], 'outcome' => $outcome,
                'route' => (isset($to['terminal']) ? ['type' => $to['terminal']] : ['type' => 'card', 'action_key' => $to['action'], 'card_key' => $to['key']])
                    + ['_source_card_key' => $from['key']]];
        };
        $events = is_array($snapshot['events'] ?? null) ? array_slice($snapshot['events'], -100) : [];
        $last = null;
        $lastAt = null;
        foreach ($events as $event) {
            if (! is_array($event) || ! str_starts_with((string) ($event['stage'] ?? ''), 'task-')) {
                continue;
            }
            $at = $this->time($event['at'] ?? null);
            $key = (string) ($event['taskKey'] ?? '');
            if ($at === null || ($lastAt !== null && $at < $lastAt) || ! isset($mapping[$key])) {
                $last = null;

                continue;
            }
            $lastAt = $at;
            if (in_array($event['stage'], ['task-completed', 'task-failed', 'task-condition-not-met'], true)) {
                $last = ['key' => $key, 'outcome' => $event['stage'] !== 'task-completed' ? 'failed' : (($event['status'] ?? '') === 'partial' ? 'partial' : 'success'), 'target' => null];
            } elseif (in_array($event['stage'], ['task-dynamic-route-followed', 'task-error-route-followed', 'task-branch-route-followed'], true) && ($last['key'] ?? null) === $key) {
                $last['target'] = (string) ($event['targetTaskKey'] ?? '');
                $last['outcome'] = (string) ($event['routeOutcome'] ?? $last['outcome']);
            } elseif ($event['stage'] === 'task-started') {
                if ($last !== null && ($last['target'] === null || $last['target'] === $key)) {
                    $append($last['key'], $key, $last['outcome'], (string) $event['at']);
                }
                $last = null;
            }
        }
        if ($events !== []) {
            return $routes;
        }
        // Live public snapshots omit debug events. Time alone never proves an
        // edge: require successful completion AND the exact frozen next route.
        $timed = [];
        foreach ($tasks as $key => $task) {
            $start = $this->time($task['startedAt'] ?? null);
            if ($start !== null) {
                $timed[] = ['key' => $key, 'task' => $task, 'start' => $start];
            }
        }
        usort($timed, fn ($a, $b) => $a['start'] <=> $b['start']);
        foreach ($timed as $index => $entry) {
            if ($index > 0 && $entry['start'] === $timed[$index - 1]['start']) {
                return [];
            }
        }
        foreach ($timed as $index => $source) {
            $target = $timed[$index + 1] ?? null;
            $finish = $this->time($source['task']['finishedAt'] ?? null);
            if ($target === null || $finish === null || $finish < $source['start'] || $target['start'] < $finish
                || ! in_array($source['task']['status'] ?? '', ['success', 'completed'], true) || ($source['task']['ok'] ?? true) === false
                || ! in_array($source['task']['logical_outcome'] ?? $source['task']['logicalOutcome'] ?? 'success', ['', 'success'], true)) {
                continue;
            }
            $next = $source['task']['next'] ?? $source['task']['status_routes']['success'] ?? null;
            $exactRoute = is_array($next) && ($next['type'] ?? '') === 'card' && ($next['card_key'] ?? '') === $target['key'];
            $implicitSuccessor = ($snapshot['__frozen_configurations'] ?? false) && ! is_array($next)
                && ($target['task']['__configured_index'] ?? -2) === ($source['task']['__configured_index'] ?? -4) + 1
                && empty($source['task']['route_target_key']) && empty($source['task']['routeTargetKey'])
                && (($source['task']['runner'] ?? '') === 'workflow-boundary'
                    || (! array_key_exists('workflow_return', $source['task']) && ! array_key_exists('workflowReturn', $source['task'])))
                && ! str_starts_with((string) ($source['task']['task_key'] ?? ''), 'decision.')
                && ! str_starts_with((string) ($source['task']['task_key'] ?? ''), 'loop.');
            if (! $exactRoute && ! $implicitSuccessor) {
                continue;
            }
            $append($source['key'], $target['key'], 'success', (string) $target['task']['startedAt']);
        }

        return $routes;
    }

    private function executed(array $task): bool
    {
        return $this->time($task['startedAt'] ?? null) !== null || in_array($task['status'] ?? '', ['running', 'waiting', 'success', 'completed', 'failed', 'partial', 'timeout'], true);
    }

    private function safeStatus(string $status, WorkflowRun $run, bool $current = true): string
    {
        return in_array($status, ['running', 'waiting'], true) && (! $current || ! in_array($run->status, self::ACTIVE, true))
            ? ($run->status === 'paused' ? 'paused' : 'interrupted') : $status;
    }

    private function time(mixed $time): ?float
    {
        if (! is_string($time) || strlen($time) > 64 || ! preg_match('/^\d{4}-\d{2}-\d{2}T/', $time)) {
            return null;
        }
        try {
            return (float) (new \DateTimeImmutable($time))->format('U.u');
        } catch (\Exception) {
            return null;
        }
    }
}
