<?php

namespace App\Services\Workflows;

use App\Models\Workflow;
use App\Models\WorkflowCopilotSession;
use App\Models\WorkflowRun;
use App\Models\WorkflowStudioSession;
use DomainException;

class WorkflowStudioDefinitionMutationPolicy
{
    /** @var list<string> */
    private const FINAL_RUN_STATUSES = [
        'completed',
        'failed',
        'cancelled',
        'timed_out',
        'lost',
    ];

    /** @var list<string> */
    public const EDITABLE_RUN_STATUSES = [
        'paused',
        'completed',
        'failed',
        'cancelled',
        'timed_out',
        'lost',
    ];

    /**
     * @return array{
     *     can_edit: bool,
     *     can_pause_for_edit: bool,
     *     message: string,
     *     session: ?WorkflowStudioSession,
     *     run: ?WorkflowRun
     * }
     */
    public function inspect(
        Workflow $workflow,
        ?WorkflowStudioSession $preferredSession = null,
        bool $lockForUpdate = false,
    ): array {
        $workflow = Workflow::query()
            ->when($lockForUpdate, fn ($query) => $query->lockForUpdate())
            ->findOrFail($workflow->getKey());

        $preferredSessionId = (int) ($preferredSession?->getKey() ?? 0);
        $sessionQuery = WorkflowStudioSession::query()
            ->where('workflow_id', $workflow->getKey())
            ->where(function ($query) use ($preferredSessionId): void {
                $query->whereNull('finished_at');

                if ($preferredSessionId > 0) {
                    $query->orWhere('id', $preferredSessionId);
                }
            })
            ->latest('last_activity_at')
            ->latest('id');

        if ($lockForUpdate) {
            $sessionQuery->lockForUpdate();
        }

        $sessions = $sessionQuery->get();
        $preferred = $preferredSessionId > 0
            ? $sessions->firstWhere('id', $preferredSessionId)
            : null;

        if ($workflow->has_active_copilot_lock) {
            return $this->locked(
                'Der autonome Copilot steuert die aktuelle Workflow-Definition exklusiv.',
                $preferred,
                $this->runForSession($preferred, $lockForUpdate),
            );
        }

        foreach ($sessions as $session) {
            $run = $this->runForSession($session, $lockForUpdate);
            $copilotControlActive = $session->workflow_copilot_session_id
                && WorkflowCopilotSession::query()
                    ->where('workflow_id', $workflow->getKey())
                    ->whereKey($session->workflow_copilot_session_id)
                    ->whereIn('status', WorkflowCopilotSession::LOCK_RETAINING_STATUSES)
                    ->exists();
            $autonomousControlActive = $session->mode === 'autonomous'
                && ! $session->finished_at
                && (
                    ($run && ! in_array((string) $run->status, self::FINAL_RUN_STATUSES, true))
                    || $copilotControlActive
                    || (! $run && $session->mode_locked_at)
                );

            if ($autonomousControlActive) {
                return $this->locked(
                    'Ein autonomer Lauf steuert den Workflow. Die aktuelle Definition bleibt schreibgeschuetzt.',
                    $session,
                    $run,
                );
            }

            if (! $run) {
                continue;
            }

            $status = trim((string) $run->status);
            if (! in_array($status, self::EDITABLE_RUN_STATUSES, true)) {
                return $this->locked(
                    'Der interaktive Test ist '.$this->statusLabel($status).'. Pausiere ihn am sicheren Haltepunkt, bevor du die Definition aenderst.',
                    $session,
                    $run,
                    $session->mode !== 'autonomous'
                        && (int) $session->getKey() === $preferredSessionId,
                );
            }

            if ($status === 'paused' && $run->stepRuns()->where('status', 'running')->exists()) {
                return $this->locked(
                    'Der Lauf erreicht gerade erst seinen sicheren Pausepunkt. Bearbeiten wird danach automatisch freigeschaltet.',
                    $session,
                    $run,
                );
            }
        }

        return [
            'can_edit' => true,
            'can_pause_for_edit' => false,
            'message' => '',
            'session' => $preferred,
            'run' => $this->runForSession($preferred, $lockForUpdate),
        ];
    }

    public function assertCanMutate(
        Workflow $workflow,
        WorkflowStudioSession|int $session,
        bool $lockForUpdate = true,
    ): WorkflowStudioSession {
        $sessionId = $session instanceof WorkflowStudioSession
            ? (int) $session->getKey()
            : (int) $session;
        $trustedSession = WorkflowStudioSession::query()
            ->where('workflow_id', $workflow->getKey())
            ->findOrFail($sessionId);
        $policy = $this->inspect($workflow, $trustedSession, $lockForUpdate);

        if (! $policy['can_edit']) {
            throw new DomainException($policy['message']);
        }

        return $trustedSession->fresh() ?? $trustedSession;
    }

    /**
     * @return array{can_edit: false, can_pause_for_edit: bool, message: string, session: ?WorkflowStudioSession, run: ?WorkflowRun}
     */
    private function locked(
        string $message,
        ?WorkflowStudioSession $session,
        ?WorkflowRun $run,
        bool $canPauseForEdit = false,
    ): array {
        return [
            'can_edit' => false,
            'can_pause_for_edit' => $canPauseForEdit,
            'message' => $message,
            'session' => $session,
            'run' => $run,
        ];
    }

    private function runForSession(?WorkflowStudioSession $session, bool $lockForUpdate): ?WorkflowRun
    {
        if (! $session?->active_workflow_run_id) {
            return null;
        }

        return WorkflowRun::query()
            ->where('workflow_id', $session->workflow_id)
            ->where('workflow_studio_session_id', $session->getKey())
            ->when($lockForUpdate, fn ($query) => $query->lockForUpdate())
            ->find($session->active_workflow_run_id);
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'queued' => 'in der Warteschlange',
            'running' => 'aktiv',
            'waiting' => 'wartend',
            'stop_requested' => 'im Stoppvorgang',
            'unreachable' => 'voruebergehend nicht erreichbar',
            default => $status !== '' ? 'im Status '.$status : 'in einem unbekannten Status',
        };
    }
}
