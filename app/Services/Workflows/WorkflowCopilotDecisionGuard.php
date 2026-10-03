<?php

namespace App\Services\Workflows;

use App\Exceptions\WorkflowSupervisorInterruptedException;
use App\Models\WorkflowCopilotSession;
use Carbon\CarbonImmutable;
use Throwable;

/** Revalidate slow provider answers without keeping a database lock during I/O. */
class WorkflowCopilotDecisionGuard
{
    public function snapshot(WorkflowCopilotSession $session): array
    {
        $fresh = $session->fresh(['workflow', 'activeRun']);
        if (! $fresh) {
            throw new WorkflowSupervisorInterruptedException;
        }

        $controlSequence = (int) $fresh->events()->where(function ($query): void {
            $query->whereIn('event_type', ['instruction.received', 'rewind.requested', 'session.restart_requested'])
                ->orWhere(function ($transition): void {
                    $transition->where('event_type', 'session.status_changed')
                        ->whereIn('payload_json->to', [WorkflowCopilotSession::STATUS_PAUSED, ...WorkflowCopilotSession::TERMINAL_STATUSES]);
                });
        })->max('sequence');

        return [
            'status' => $fresh->status,
            'control_sequence' => $controlSequence,
            'revision' => (int) $fresh->current_revision,
            'owner_id' => (int) $fresh->workflow?->active_workflow_copilot_session_id,
            'run_id' => (int) $fresh->active_workflow_run_id,
            'run_status' => $fresh->activeRun?->status,
            'step_id' => (int) $fresh->activeRun?->current_workflow_step_id,
            'checkpoint_id' => data_get($fresh->activeRun?->context_json, 'copilot_checkpoint.id'),
            'lease_token' => data_get($fresh->state_json, 'supervisor_lease.token'),
            'lease_expires_at' => data_get($fresh->state_json, 'supervisor_lease.expires_at'),
            'inputs' => [$fresh->goal, $fresh->success_criteria_json, $fresh->workflow_inputs_json],
            'budget' => $fresh->budget_json,
            'execution_target' => $fresh->execution_target,
        ];
    }

    public function assertCurrent(WorkflowCopilotSession $session, array $expected, bool $allowOwnRevision = false): void
    {
        $current = $this->snapshot($session);
        if (filled($current['lease_token'])) {
            try {
                $expiresAt = filled($current['lease_expires_at']) ? CarbonImmutable::parse($current['lease_expires_at']) : null;
            } catch (Throwable) {
                throw new WorkflowSupervisorInterruptedException;
            }
            if (! $expiresAt || $expiresAt->isPast()) {
                throw new WorkflowSupervisorInterruptedException;
            }
        }
        if ($current['status'] === WorkflowCopilotSession::STATUS_PAUSED
            || in_array($current['status'], WorkflowCopilotSession::TERMINAL_STATUSES, true)
            || $current['owner_id'] !== (int) $session->id) {
            throw new WorkflowSupervisorInterruptedException;
        }

        // Normal internal phase changes are allowed. A pause/resume in between
        // still changes the durable control sequence (no ABA acceptance).
        unset($expected['status'], $current['status']);
        if ($allowOwnRevision) {
            unset($expected['revision'], $current['revision']);
        }

        if (! app(WorkflowRunContextStore::class)->equivalent($expected, $current)) {
            throw new WorkflowSupervisorInterruptedException;
        }
    }
}
