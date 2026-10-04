<?php

namespace App\Services\Workflows;

use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use App\Models\WorkflowStepRun;

/** Small read-only UI cursor shared by Studio invalidation and its live map. */
final class WorkflowLiveTaskPresenter
{
    public function present(Workflow $workflow, ?WorkflowRun $run): ?array
    {
        if (! $run || (int) $run->workflow_id !== (int) $workflow->id
            || ! in_array($run->status, ['queued', 'running', 'waiting', 'stop_requested', 'unreachable'], true)) {
            return null;
        }

        $workflow->loadMissing('steps');
        $currentStepId = (int) $run->current_workflow_step_id;
        // Poll invalidation receives an unloaded run. Read only recent scoped
        // snapshots instead of hydrating its entire execution history.
        $recentStepRuns = $run->relationLoaded('stepRuns')
            ? $run->getRelation('stepRuns')->take(-32)->reverse()
            : $run->stepRuns()
                ->when($currentStepId > 0, fn ($query) => $query->where('workflow_step_id', $currentStepId))
                ->reorder('id', 'desc')
                ->limit(32)
                ->get();
        $latestStepRun = $recentStepRuns->first(fn ($candidate): bool => (int) $candidate->workflow_run_id === (int) $run->id
            && ($currentStepId <= 0 || (int) $candidate->workflow_step_id === $currentStepId));
        // A newer finished attempt must not revive an older stale runner.
        $stepRun = $latestStepRun && in_array($latestStepRun->status, ['running', 'waiting'], true)
            ? $latestStepRun
            : null;
        $step = $workflow->steps->firstWhere('id', $stepRun?->workflow_step_id ?: $currentStepId);
        if (! $step) {
            return null;
        }
        $known = collect($step->task_cards)->keyBy('key');
        $payload = is_array($stepRun?->result_json) ? $stepRun->result_json : [];
        $snapshotTasks = is_array($payload['tasks'] ?? null) ? $payload['tasks'] : [];
        $snapshotTerminal = in_array($payload['state'] ?? $payload['status'] ?? '', ['completed', 'failed', 'cancelled', 'timed_out', 'timeout'], true);
        $candidates = [];

        // Node status.json publishes the currently executing card in tasks,
        // even when a browser-preview write omits its top-level taskKey.
        foreach (array_slice($snapshotTasks, 0, 1000) as $task) {
            if ($snapshotTerminal || ! is_array($task) || ! in_array($task['status'] ?? '', ['running', 'waiting'], true)) {
                continue;
            }
            foreach (['key', 'route_source_task_key', 'parent_task_key'] as $field) {
                $key = is_scalar($task[$field] ?? null) ? trim((string) $task[$field]) : '';
                if ($key !== '' && $known->has($key)) {
                    $candidates[$key] = $this->cursor($step, $stepRun, $known->get($key), $task['status'], 'runtime');
                    break;
                }
            }
        }

        foreach (['current_task.key', 'currentTask.key', 'currentTaskKey', 'current_task_key', 'taskKey'] as $field) {
            $hint = data_get($payload, $field);
            if (is_scalar($hint) && isset($candidates[(string) $hint])) {
                return $candidates[(string) $hint];
            }
        }
        if (count($candidates) === 1) {
            return reset($candidates);
        }
        if (count($candidates) > 1) {
            // Ambiguous public snapshots do not justify selecting an arbitrary
            // running card, especially with identical keys in another list.
            return null;
        }

        $key = trim((string) data_get($run->context_json, 'next_task_key', ''));
        if ($key !== '' && $known->has($key)) {
            return $this->cursor($step, $stepRun, $known->get($key), 'pending', 'cursor');
        }

        return null;
    }

    private function cursor(WorkflowStep $step, ?WorkflowStepRun $stepRun, array $task, string $status, string $source): array
    {
        $key = (string) $task['key'];

        return [
            'step_run_id' => $stepRun ? (int) $stepRun->getKey() : null,
            'step_id' => (int) $step->getKey(),
            'task_key' => $key,
            'node' => $step->action_key.'::'.$key,
            'status' => $status,
            'source' => $source,
            'title' => (string) ($task['title'] ?? $task['label'] ?? $key),
        ];
    }
}
