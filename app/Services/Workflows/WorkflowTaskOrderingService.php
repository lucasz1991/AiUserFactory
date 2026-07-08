<?php

namespace App\Services\Workflows;

use App\Models\Workflow;
use App\Models\WorkflowStep;

class WorkflowTaskOrderingService
{
    public function moveTask(Workflow $workflow, WorkflowStep $targetStep, string $taskKey, int $targetPosition, ?int $sourceStepId = null): bool
    {
        $taskKey = trim($taskKey);

        if ($taskKey === '' || (int) $targetStep->workflow_id !== (int) $workflow->id) {
            return false;
        }

        $steps = $workflow->steps()->ordered()->get();
        $sourceStep = null;
        $movingTask = null;

        foreach ($steps as $step) {
            if ($sourceStepId !== null && (int) $step->id !== $sourceStepId) {
                continue;
            }

            $movingTask = collect($this->tasks($step))
                ->first(fn (array $task): bool => (string) ($task['key'] ?? '') === $taskKey);

            if ($movingTask) {
                $sourceStep = $step;

                break;
            }
        }

        if (! $sourceStep || ! $movingTask) {
            return false;
        }

        $targetPosition = max(0, $targetPosition);

        $movingTasks = $this->pairedTasks($sourceStep, $movingTask);

        if ((int) $sourceStep->id === (int) $targetStep->id) {
            $allTasks = collect($this->tasks($targetStep))->values();
            $movingKeys = collect($movingTasks)
                ->map(fn (array $task): string => (string) ($task['key'] ?? ''))
                ->filter()
                ->values()
                ->all();
            $originalPositions = $allTasks
                ->keys()
                ->filter(fn (int $index): bool => in_array((string) ($allTasks->get($index)['key'] ?? ''), $movingKeys, true))
                ->values();

            foreach ($originalPositions as $originalPosition) {
                if ($targetPosition > $originalPosition) {
                    $targetPosition--;
                }
            }

            $tasks = $allTasks
                ->reject(fn (array $task): bool => in_array((string) ($task['key'] ?? ''), $movingKeys, true))
                ->values();

            $tasks->splice(min($targetPosition, $tasks->count()), 0, $movingTasks);
            $this->saveTasks($targetStep, $tasks->values()->toArray());

            return true;
        }

        $movingKeys = collect($movingTasks)
            ->map(fn (array $task): string => (string) ($task['key'] ?? ''))
            ->filter()
            ->values()
            ->all();
        $sourceTasks = collect($this->tasks($sourceStep))
            ->reject(fn (array $task): bool => in_array((string) ($task['key'] ?? ''), $movingKeys, true))
            ->values()
            ->toArray();
        $targetTasks = collect($this->tasks($targetStep))->values();

        $targetTasks->splice(min($targetPosition, $targetTasks->count()), 0, $movingTasks);

        $this->saveTasks($sourceStep, $sourceTasks);
        $this->saveTasks($targetStep, $targetTasks->values()->toArray());

        return true;
    }

    public function appendTask(WorkflowStep $step, array $task): void
    {
        $this->appendTasks($step, [$task]);
    }

    public function appendTasks(WorkflowStep $step, array $newTasks): void
    {
        $tasks = array_values([...$this->tasks($step), ...array_values($newTasks)]);

        $this->saveTasks($step, $tasks);
    }

    public function insertTask(WorkflowStep $step, array $task, int $targetPosition): void
    {
        $this->insertTasks($step, [$task], $targetPosition);
    }

    public function insertTasks(WorkflowStep $step, array $newTasks, int $targetPosition): void
    {
        $tasks = collect($this->tasks($step))->values();
        $tasks->splice(min(max(0, $targetPosition), $tasks->count()), 0, array_values($newTasks));

        $this->saveTasks($step, $tasks->values()->toArray());
    }

    public function removeTask(WorkflowStep $step, string $taskKey): void
    {
        $tasksBeforeRemoval = collect($this->tasks($step));
        $task = $tasksBeforeRemoval
            ->first(fn (array $task): bool => (string) ($task['key'] ?? '') === $taskKey);
        $pairId = is_array($task) ? trim((string) ($task['loop_pair_id'] ?? $task['loopPairId'] ?? '')) : '';

        $tasks = collect($this->tasks($step))
            ->reject(fn (array $task): bool => (string) ($task['key'] ?? '') === $taskKey
                || ($pairId !== '' && trim((string) ($task['loop_pair_id'] ?? $task['loopPairId'] ?? '')) === $pairId))
            ->values()
            ->toArray();

        $this->saveTasks($step, $tasks);
    }

    public function sortSteps(Workflow $workflow, int $stepId, int $targetPosition): bool
    {
        $steps = $workflow->steps()->ordered()->get();
        $moving = $steps->firstWhere('id', $stepId);

        if (! $moving) {
            return false;
        }

        $ordered = $steps
            ->reject(fn (WorkflowStep $step): bool => (int) $step->id === $stepId)
            ->values();

        $ordered->splice(min(max(0, $targetPosition), $ordered->count()), 0, [$moving]);

        foreach ($ordered->values() as $index => $step) {
            $step->forceFill(['position' => ($index + 1) * 10])->save();
        }

        return true;
    }

    protected function tasks(WorkflowStep $step): array
    {
        $config = is_array($step->config_json) ? $step->config_json : [];

        return is_array($config['tasks'] ?? null) ? array_values($config['tasks']) : [];
    }

    protected function saveTasks(WorkflowStep $step, array $tasks): void
    {
        $config = is_array($step->config_json) ? $step->config_json : [];
        $config['tasks'] = $this->normalizeTaskOrder($tasks);

        $step->forceFill(['config_json' => $config])->save();
    }

    protected function pairedTasks(WorkflowStep $step, array $task): array
    {
        $pairId = trim((string) ($task['loop_pair_id'] ?? $task['loopPairId'] ?? ''));

        if ($pairId === '') {
            return [$task];
        }

        $paired = collect($this->tasks($step))
            ->filter(fn (array $candidate): bool => trim((string) ($candidate['loop_pair_id'] ?? $candidate['loopPairId'] ?? '')) === $pairId)
            ->values()
            ->all();

        if ($paired === []) {
            return [$task];
        }

        return collect($paired)
            ->sortBy(fn (array $candidate): int => match ((string) ($candidate['loop_pair_segment'] ?? $candidate['loopPairSegment'] ?? '')) {
                'start' => 0,
                'end' => 2,
                default => 1,
            })
            ->values()
            ->all();
    }

    protected function normalizeTaskOrder(array $tasks): array
    {
        return collect($tasks)
            ->values()
            ->map(function (array $task, int $index): array {
                $order = ($index + 1) * 10;

                $task['order_id'] = $order;
                $task['position'] = $order;

                return $task;
            })
            ->toArray();
    }
}
