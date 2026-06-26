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

        if ((int) $sourceStep->id === (int) $targetStep->id) {
            $tasks = collect($this->tasks($targetStep))
                ->reject(fn (array $task): bool => (string) ($task['key'] ?? '') === $taskKey)
                ->values();

            $tasks->splice(min($targetPosition, $tasks->count()), 0, [$movingTask]);
            $this->saveTasks($targetStep, $tasks->values()->toArray());

            return true;
        }

        $sourceTasks = collect($this->tasks($sourceStep))
            ->reject(fn (array $task): bool => (string) ($task['key'] ?? '') === $taskKey)
            ->values()
            ->toArray();
        $targetTasks = collect($this->tasks($targetStep))->values();

        $targetTasks->splice(min($targetPosition, $targetTasks->count()), 0, [$movingTask]);

        $this->saveTasks($sourceStep, $sourceTasks);
        $this->saveTasks($targetStep, $targetTasks->values()->toArray());

        return true;
    }

    public function appendTask(WorkflowStep $step, array $task): void
    {
        $tasks = $this->tasks($step);
        $tasks[] = $task;

        $this->saveTasks($step, array_values($tasks));
    }

    public function insertTask(WorkflowStep $step, array $task, int $targetPosition): void
    {
        $tasks = collect($this->tasks($step))->values();
        $tasks->splice(min(max(0, $targetPosition), $tasks->count()), 0, [$task]);

        $this->saveTasks($step, $tasks->values()->toArray());
    }

    public function removeTask(WorkflowStep $step, string $taskKey): void
    {
        $tasks = collect($this->tasks($step))
            ->reject(fn (array $task): bool => (string) ($task['key'] ?? '') === $taskKey)
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
        $config['tasks'] = array_values($tasks);

        $step->forceFill(['config_json' => $config])->save();
    }
}
