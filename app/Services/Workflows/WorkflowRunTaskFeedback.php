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

        $workflow->load('steps');
        $known = [];
        foreach ($workflow->steps as $step) {
            foreach ($step->task_cards as $task) {
                $known[$step->id][(string) ($task['key'] ?? '')] = true;
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
        foreach ($run->stepRuns()->orderBy('id')->get() as $stepRun) {
            foreach ((array) data_get($stepRun->result_json, 'tasks', []) as $entry) {
                if (is_array($entry)) {
                    if (! isset($entry['error_message']) && ! isset($entry['statusMessage']) && ! isset($entry['message']) && $stepRun->error_message) {
                        $entry['error_message'] = $stepRun->error_message;
                    }
                    $record((int) $stepRun->workflow_step_id, (string) ($entry['key'] ?? ''), $entry);
                }
            }
        }

        return $feedback;
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
