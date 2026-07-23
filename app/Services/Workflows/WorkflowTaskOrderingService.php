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

        $movingTasks = $this->movingTaskBlock($sourceStep, $movingTask);

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

            $targetPosition -= $originalPositions
                ->filter(fn (int $originalPosition): bool => $originalPosition < $targetPosition)
                ->count();

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

    /**
     * Entfernt eine Karte und – bei Loop-Karten – ihre gekoppelte Partnerkarte.
     *
     * @return list<string> die tatsaechlich entfernten Karten-Keys. Aufrufer
     *                      koennen damit melden, welche Verzweigungen jetzt ins
     *                      Leere zeigen (Feature R2).
     */
    public function removeTask(WorkflowStep $step, string $taskKey): array
    {
        $tasksBeforeRemoval = collect($this->tasks($step));
        $task = $tasksBeforeRemoval
            ->first(fn (array $task): bool => (string) ($task['key'] ?? '') === $taskKey);
        $pairId = is_array($task) ? trim((string) ($task['loop_pair_id'] ?? $task['loopPairId'] ?? '')) : '';

        $shouldRemove = fn (array $task): bool => (string) ($task['key'] ?? '') === $taskKey
            || ($pairId !== '' && trim((string) ($task['loop_pair_id'] ?? $task['loopPairId'] ?? '')) === $pairId);

        $removedKeys = $tasksBeforeRemoval
            ->filter($shouldRemove)
            ->pluck('key')
            ->filter()
            ->map(fn (mixed $key): string => (string) $key)
            ->values()
            ->all();

        $tasks = $tasksBeforeRemoval
            ->reject($shouldRemove)
            ->values()
            ->toArray();

        $this->saveTasks($step, $tasks);

        return $removedKeys;
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

    /**
     * Ein Loop-Marker repraesentiert beim Drag & Drop den vollstaendigen,
     * zusammenhaengenden Start..Body..End-Block. Normale Body-Karten bleiben
     * weiterhin einzeln verschiebbar.
     */
    protected function movingTaskBlock(WorkflowStep $step, array $task): array
    {
        $tasks = array_values($this->tasks($step));
        $taskKey = trim((string) ($task['key'] ?? ''));
        $taskType = trim((string) ($task['task_key'] ?? ''));
        $pairId = trim((string) ($task['loop_pair_id'] ?? $task['loopPairId'] ?? ''));
        $segment = trim((string) ($task['loop_pair_segment'] ?? $task['loopPairSegment'] ?? ''));
        $isStartMarker = $segment === 'start' || $taskType === 'loop.for_each_element';
        $isEndMarker = $segment === 'end' || $taskType === 'loop.end';

        if ($taskKey === '' || (! $isStartMarker && ! $isEndMarker)) {
            return [$task];
        }

        $startKey = $isStartMarker
            ? $taskKey
            : trim((string) ($task['loop_start_key'] ?? $task['loopStartKey'] ?? ''));
        $endKey = $isEndMarker
            ? $taskKey
            : trim((string) ($task['loop_end_key'] ?? $task['loopEndKey'] ?? ''));
        $startIndex = null;
        $endIndex = null;

        foreach ($tasks as $index => $candidate) {
            $candidateKey = trim((string) ($candidate['key'] ?? ''));
            $candidatePairId = trim((string) ($candidate['loop_pair_id'] ?? $candidate['loopPairId'] ?? ''));
            $candidateSegment = trim((string) ($candidate['loop_pair_segment'] ?? $candidate['loopPairSegment'] ?? ''));
            $candidateType = trim((string) ($candidate['task_key'] ?? ''));
            $samePair = $pairId !== '' && $candidatePairId === $pairId;

            if (
                $startIndex === null
                && (($startKey !== '' && $candidateKey === $startKey)
                    || ($samePair && ($candidateSegment === 'start' || $candidateType === 'loop.for_each_element')))
            ) {
                $startIndex = $index;
            }

            if (
                (($endKey !== '' && $candidateKey === $endKey)
                    || ($samePair && ($candidateSegment === 'end' || $candidateType === 'loop.end')))
            ) {
                $endIndex = $index;
            }
        }

        if ($startIndex === null || $endIndex === null || $startIndex > $endIndex) {
            return [$task];
        }

        return array_slice($tasks, $startIndex, ($endIndex - $startIndex) + 1);
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
