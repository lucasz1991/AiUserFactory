<?php

namespace App\Services\Workflows;

use App\Models\WorkflowRevisionEvidence;
use App\Models\WorkflowRun;
use App\Models\WorkflowStepRun;

class WorkflowRevisionEvidenceService
{
    public function record(
        WorkflowRun $run,
        WorkflowStepRun $stepRun,
        array $result,
        string $logicalOutcome,
        string $routeDisposition,
    ): WorkflowRevisionEvidence {
        $taskKey = trim((string) (
            data_get($result, 'failedTaskKey')
            ?: data_get($result, 'lastTaskKey')
            ?: data_get($result, 'currentTaskKey')
            ?: data_get($run->context_json, 'next_task_key')
        ));
        $message = trim((string) (data_get($result, 'statusMessage') ?: data_get($result, 'message') ?: data_get($result, 'error')));
        $successful = ! in_array($routeDisposition, ['fail', 'invalid'], true)
            && ! in_array($logicalOutcome, ['technical_error', 'timeout'], true);

        return WorkflowRevisionEvidence::query()->create([
            'workflow_id' => (int) $run->workflow_id,
            'workflow_copilot_session_id' => $run->workflow_copilot_session_id,
            'workflow_studio_session_id' => $run->workflow_studio_session_id,
            'workflow_run_id' => (int) $run->id,
            'workflow_step_id' => (int) $stepRun->workflow_step_id,
            'workflow_revision' => (int) $run->workflow_revision,
            'task_key' => $taskKey !== '' ? $taskKey : null,
            'logical_outcome' => $logicalOutcome,
            'route_disposition' => $routeDisposition,
            'successful' => $successful,
            'error_signature' => $successful || $message === '' ? null : hash('sha256', mb_strtolower(preg_replace('/\d+/', '#', $message) ?? $message)),
            'evidence_json' => [
                'message' => $message,
                'outcome' => data_get($result, 'outcome'),
                'branch_outcome' => data_get($result, 'branchOutcome', data_get($result, 'branch_outcome')),
                'resolved_route' => data_get($result, 'resolved_route'),
                'url' => data_get($result, 'url', data_get($result, 'currentUrl')),
                'task_count' => count(is_array($result['tasks'] ?? null) ? $result['tasks'] : []),
            ],
            'created_at' => now(),
        ]);
    }

    public function relevantHistory(int $workflowId, int $limit = 60): array
    {
        return WorkflowRevisionEvidence::query()
            ->where('workflow_id', $workflowId)
            ->latest('id')
            ->limit(max(1, min($limit, 200)))
            ->get()
            ->map(fn (WorkflowRevisionEvidence $evidence): array => [
                'revision' => $evidence->workflow_revision,
                'task_key' => $evidence->task_key,
                'logical_outcome' => $evidence->logical_outcome,
                'route_disposition' => $evidence->route_disposition,
                'successful' => $evidence->successful,
                'error_signature' => $evidence->error_signature,
                'evidence' => $evidence->evidence_json,
                'created_at' => $evidence->created_at?->toIso8601String(),
            ])->all();
    }
}
