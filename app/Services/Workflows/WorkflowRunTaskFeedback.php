<?php

namespace App\Services\Workflows;

use App\Models\Workflow;
use App\Models\WorkflowRun;
use Illuminate\Support\Str;

/** Read-only task feedback: a failed branch is not a failed execution. */
class WorkflowRunTaskFeedback
{
    public function tasks(Workflow $workflow, ?WorkflowRun $run): array
    {
        if (! $run || (int) $run->workflow_id !== (int) $workflow->id) {
            return [];
        }

        $workflow->loadMissing('steps');
        $known = [];
        $actions = [];
        foreach ($workflow->steps as $step) {
            $actions[$step->id] = (string) $step->action_key;
            foreach ($step->task_cards as $task) {
                $known[$step->id][(string) ($task['key'] ?? '')] = $task;
            }
        }
        $feedback = [];
        $sequence = 0;
        $record = function (int $stepId, string $taskKey, array $entry) use (&$feedback, &$sequence, $known): void {
            if (! isset($known[$stepId][$taskKey])) {
                return;
            }
            $status = trim((string) ($entry['status'] ?? ''));
            if ($status === '') {
                return;
            }
            $message = $entry['error_message'] ?? $entry['statusMessage'] ?? $entry['message'] ?? '';
            $feedback[$stepId][$taskKey] = [
                'status' => $status,
                'failed' => in_array($status, ['failed', 'error', 'timed_out', 'timeout'], true),
                'message' => is_string($message) ? Str::limit($message, 500) : '',
                'sequence' => ++$sequence,
            ];
        };
        foreach ((array) data_get($run->context_json, 'task_history', []) as $entry) {
            if (is_array($entry)) {
                $record((int) ($entry['workflow_step_id'] ?? 0), (string) ($entry['task_key'] ?? ''), $entry);
            }
        }
        $stepRuns = $run->relationLoaded('stepRuns') ? $run->stepRuns : $run->stepRuns()->orderBy('id')->get();
        foreach ($stepRuns->sortBy('id') as $stepRun) {
            if ((int) $stepRun->workflow_run_id !== (int) $run->id) {
                continue;
            }
            $snapshot = is_array($stepRun->result_json) ? $stepRun->result_json : [];
            foreach (['workflowRunId' => $run->id, 'workflowStepRunId' => $stepRun->id, 'workflowStepId' => $stepRun->workflow_step_id] as $field => $id) {
                if (isset($snapshot[$field]) && (int) $snapshot[$field] !== (int) $id) {
                    continue 2;
                }
            }
            $entries = collect((array) ($snapshot['tasks'] ?? []))->filter(fn ($entry) => is_array($entry));
            foreach ($entries as $entry) {
                if (is_array($entry)) {
                    if (! isset($entry['error_message']) && ! isset($entry['statusMessage']) && ! isset($entry['message']) && $stepRun->error_message) {
                        $entry['error_message'] = $stepRun->error_message;
                    }
                    $record((int) $stepRun->workflow_step_id, (string) ($entry['key'] ?? ''), $entry);
                }
            }
            $results = $entries->keyBy('key');
            $definitions = is_array($snapshot['configuredTasks'] ?? null) ? $snapshot['configuredTasks'] : $entries->all();
            $includeResults = [];
            foreach ($definitions as $definition) {
                if (! is_array($definition)) {
                    continue;
                }
                $entry = array_replace($definition, $results->get($definition['key'] ?? '', []));
                $key = $this->rootIncludeBoundaryKey($entry, (int) $stepRun->workflow_step_id, $known, $actions);
                if ($key === null) {
                    continue;
                }
                $status = (string) ($entry['status'] ?? '');
                $closed = $results->has($entry['key']) && ($entry['embeddedWorkflowCompleted'] ?? false) === true
                    && in_array($status, ['success', 'completed', 'failed', 'timeout', 'timed_out'], true);
                if ($closed && in_array($status, ['success', 'completed'], true)
                    && (($entry['ok'] ?? true) === false || ($entry['workflow_return_ok'] ?? true) === false)) {
                    $closed = false;
                }
                $frameKey = (string) $entry['embedded_workflow_frame_key'];
                $attempted = $entries->contains(fn ($task) => data_get($task, 'embedded_workflow_path.0.frame_key') === $frameKey
                    && (filled($task['startedAt'] ?? null) || in_array($task['status'] ?? '', ['running', 'waiting', 'success', 'completed', 'failed', 'timeout', 'timed_out'], true)));
                if (! $closed && ! $attempted) {
                    continue;
                }
                if (! $closed) {
                    // A newly started retry must not keep an older successful
                    // include green while its current boundary is unfinished.
                    $entry['status'] = $run->status === 'paused' ? 'paused'
                        : (in_array($run->status, ['queued', 'running', 'waiting', 'stop_requested'], true) ? 'configured' : 'interrupted');
                    unset($entry['statusMessage'], $entry['message'], $entry['error_message']);
                }
                $includeResults[$key][] = $entry;
            }
            foreach ($includeResults as $key => $candidates) {
                // Duplicate invocation claims are not positive completion evidence.
                if (count($candidates) === 1) {
                    $record((int) $stepRun->workflow_step_id, $key, $candidates[0]);
                }
            }
        }

        return $feedback;
    }

    private function rootIncludeBoundaryKey(array $entry, int $stepId, array $known, array $actions): ?string
    {
        $path = $entry['embedded_workflow_path'] ?? [];
        if (($entry['runner'] ?? '') !== 'workflow-boundary' || ! is_array($path) || count($path) !== 1 || ! is_array($path[0])) {
            return null;
        }
        $frame = $path[0];
        $key = (string) ($frame['include_task_key'] ?? '');
        $task = $known[$stepId][$key] ?? null;
        $workflowId = (int) ($task['workflow_id'] ?? 0);
        if ($workflowId <= 0 && preg_match('/^workflow(?:\.include)?\.(\d+)$/', (string) ($task['task_key'] ?? ''), $matches)) {
            $workflowId = (int) $matches[1];
        }
        if ($workflowId <= 0 || $workflowId !== (int) ($frame['workflow_id'] ?? 0)
            || (int) ($frame['source_step_id'] ?? 0) !== $stepId
            || (string) ($frame['source_step_action_key'] ?? '') !== ($actions[$stepId] ?? '')
            || (string) ($frame['parent_frame_key'] ?? '') !== ''
            || (string) ($frame['frame_key'] ?? '') === ''
            || (string) ($entry['embedded_workflow_frame_key'] ?? '') !== (string) $frame['frame_key']
            || (string) ($entry['key'] ?? '') !== $frame['frame_key'].'-boundary') {
            return null;
        }
        foreach (['composition_root_step_id', 'embedded_source_step_id'] as $field) {
            if (isset($entry[$field]) && (int) $entry[$field] !== $stepId) {
                return null;
            }
        }
        foreach (['parent_task_key', 'composition_root_task_key', 'embedded_source_task_key'] as $field) {
            if (isset($entry[$field]) && (string) $entry[$field] !== $key) {
                return null;
            }
        }

        return $key;
    }

    public function failure(Workflow $workflow, ?WorkflowRun $run): ?array
    {
        if (! $run || ! in_array($run->status, ['failed', 'timed_out'], true)) {
            return null;
        }
        $feedback = $this->tasks($workflow, $run);
        // The cursor may already point at cleanup or a retry target. Prefer the
        // last failed step execution, never a first match of a non-unique key.
        $failedStep = $run->stepRuns()->whereIn('status', ['failed', 'timed_out'])->reorder()->orderByDesc('finished_at')->orderByDesc('id')->first();
        $candidates = [];
        foreach ($feedback as $stepId => $tasks) {
            if ($failedStep && (int) $failedStep->workflow_step_id !== (int) $stepId) {
                continue;
            }
            foreach ($tasks as $taskKey => $task) {
                if ($task['failed']) {
                    $candidates[] = ['step_id' => (int) $stepId, 'task_key' => (string) $taskKey] + $task;
                }
            }
        }

        // Infrastructure errors without task evidence stay run-level errors.
        return collect($candidates)->sortByDesc('sequence')->first();
    }
}
