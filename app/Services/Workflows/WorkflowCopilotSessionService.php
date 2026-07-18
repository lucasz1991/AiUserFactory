<?php

namespace App\Services\Workflows;

use App\Models\Workflow;
use App\Models\WorkflowCopilotEvent;
use App\Models\WorkflowCopilotSession;
use App\Models\WorkflowRevision;
use App\Models\WorkflowRun;
use App\Models\WorkflowRunCheckpoint;
use App\Models\WorkflowTaskAttempt;
use App\Services\Ai\WorkflowCopilotAiUsageTracker;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WorkflowCopilotSessionService
{
    /** @var list<string> */
    private const ACTIVE_WORKFLOW_RUN_STATUSES = [
        'queued',
        'running',
        'waiting',
        'stop_requested',
        'unreachable',
    ];

    public const DEFAULT_BUDGET = [
        'max_minutes' => 90,
        'max_repair_iterations' => 15,
        'max_probe_actions' => 60,
        'max_same_state_repeats' => 2,
        'max_cost_usd' => 0.0,
    ];

    public const DEFAULT_USAGE = [
        'repair_iterations' => 0,
        'probe_actions' => 0,
        'same_state_repeats' => 0,
        'ai_requests' => 0,
        'input_tokens' => 0,
        'output_tokens' => 0,
        'total_tokens' => 0,
        'reasoning_tokens' => 0,
        'cost_usd' => 0.0,
        'provider_cost_usd' => 0.0,
        'ai_models' => [],
    ];

    public function __construct(
        protected WorkflowExecutionService $workflowExecution,
        protected WorkflowCopilotAiUsageTracker $aiUsage,
    ) {}

    /** @var array<string, list<string>> */
    private const STATUS_TRANSITIONS = [
        WorkflowCopilotSession::STATUS_RUNNING => [
            WorkflowCopilotSession::STATUS_PAUSED,
            WorkflowCopilotSession::STATUS_REPAIRING,
            WorkflowCopilotSession::STATUS_VERIFYING,
            WorkflowCopilotSession::STATUS_SUCCEEDED,
            WorkflowCopilotSession::STATUS_BUDGET_EXHAUSTED,
            WorkflowCopilotSession::STATUS_FAILED,
            WorkflowCopilotSession::STATUS_STOPPED,
        ],
        WorkflowCopilotSession::STATUS_PAUSED => [
            WorkflowCopilotSession::STATUS_RUNNING,
            WorkflowCopilotSession::STATUS_REPAIRING,
            WorkflowCopilotSession::STATUS_VERIFYING,
            WorkflowCopilotSession::STATUS_BUDGET_EXHAUSTED,
            WorkflowCopilotSession::STATUS_FAILED,
            WorkflowCopilotSession::STATUS_STOPPED,
        ],
        WorkflowCopilotSession::STATUS_REPAIRING => [
            WorkflowCopilotSession::STATUS_RUNNING,
            WorkflowCopilotSession::STATUS_PAUSED,
            WorkflowCopilotSession::STATUS_VERIFYING,
            WorkflowCopilotSession::STATUS_BUDGET_EXHAUSTED,
            WorkflowCopilotSession::STATUS_FAILED,
            WorkflowCopilotSession::STATUS_STOPPED,
        ],
        WorkflowCopilotSession::STATUS_VERIFYING => [
            WorkflowCopilotSession::STATUS_RUNNING,
            WorkflowCopilotSession::STATUS_PAUSED,
            WorkflowCopilotSession::STATUS_REPAIRING,
            WorkflowCopilotSession::STATUS_SUCCEEDED,
            WorkflowCopilotSession::STATUS_BUDGET_EXHAUSTED,
            WorkflowCopilotSession::STATUS_FAILED,
            WorkflowCopilotSession::STATUS_STOPPED,
        ],
        WorkflowCopilotSession::STATUS_BUDGET_EXHAUSTED => [WorkflowCopilotSession::STATUS_STOPPED],
        WorkflowCopilotSession::STATUS_FAILED => [WorkflowCopilotSession::STATUS_STOPPED],
    ];

    public function start(Workflow $workflow, array $options = []): WorkflowCopilotSession
    {
        $executionTarget = trim((string) ($options['execution_target'] ?? WorkflowCopilotSession::EXECUTION_TARGET_SYSTEM));
        $this->assertSystemExecutionTarget($executionTarget);

        $budget = array_replace(
            self::DEFAULT_BUDGET,
            $this->arrayOption($options, 'budget', 'budget_json'),
        );
        $usage = array_replace(
            self::DEFAULT_USAGE,
            $this->arrayOption($options, 'usage', 'usage_json'),
        );

        return DB::transaction(function () use ($workflow, $options, $executionTarget, $budget, $usage): WorkflowCopilotSession {
            $lockedWorkflow = Workflow::query()->lockForUpdate()->findOrFail($workflow->getKey());

            if ($lockedWorkflow->active_workflow_copilot_session_id) {
                $existing = WorkflowCopilotSession::query()->find($lockedWorkflow->active_workflow_copilot_session_id);

                if ($existing && $existing->retainsWorkflowLock()) {
                    throw new DomainException(
                        'Workflow #'.$lockedWorkflow->getKey().' wird bereits durch Copilot-Sitzung #'.$existing->getKey().' optimiert.',
                    );
                }

                $lockedWorkflow->forceFill([
                    'active_workflow_copilot_session_id' => null,
                    'copilot_locked_at' => null,
                ]);
            }

            $activeRun = WorkflowRun::query()
                ->where('workflow_id', $lockedWorkflow->getKey())
                ->whereIn('status', self::ACTIVE_WORKFLOW_RUN_STATUSES)
                ->whereNull('finished_at')
                ->lockForUpdate()
                ->orderByDesc('id')
                ->first();

            if ($activeRun) {
                throw new DomainException(
                    'Workflow #'.$lockedWorkflow->getKey().' hat noch den aktiven Lauf #'.$activeRun->getKey().'. Dieser muss vor der exklusiven Copilot-Optimierung beendet werden.',
                );
            }

            $now = now();
            $session = WorkflowCopilotSession::query()->create([
                'session_uuid' => (string) Str::uuid(),
                'workflow_id' => $lockedWorkflow->getKey(),
                'person_id' => $options['person_id'] ?? null,
                'status' => WorkflowCopilotSession::STATUS_RUNNING,
                'phase' => trim((string) ($options['phase'] ?? 'executing')) ?: 'executing',
                'execution_target' => $executionTarget,
                'goal' => $this->nullableText($options['goal'] ?? null),
                'success_criteria_json' => $this->arrayOption($options, 'success_criteria', 'success_criteria_json'),
                'workflow_inputs_json' => $this->arrayOption($options, 'workflow_inputs', 'workflow_inputs_json'),
                'budget_json' => $budget,
                'usage_json' => $usage,
                'state_json' => $this->arrayOption($options, 'state', 'state_json'),
                'current_revision' => (int) ($lockedWorkflow->copilot_revision ?? 0),
                'repair_round' => 0,
                'last_event_sequence' => 0,
                'started_at' => $now,
                'last_activity_at' => $now,
            ]);

            $lockedWorkflow->forceFill([
                'active_workflow_copilot_session_id' => $session->getKey(),
                'copilot_locked_at' => $now,
                'copilot_verification_status' => 'unverified',
                'copilot_verified_at' => null,
            ])->save();

            $this->appendEventToLockedSession(
                $session,
                'session.started',
                'Autonome Workflow-Optimierung wurde in der System-Ausfuehrung gestartet.',
                [
                    'workflow_id' => (int) $lockedWorkflow->getKey(),
                    'workflow_name' => (string) $lockedWorkflow->name,
                    'execution_target' => $executionTarget,
                    'current_revision' => (int) $session->current_revision,
                    'budget' => $budget,
                ],
                $session->phase,
                'info',
                true,
            );

            return $session->fresh(['workflow']) ?? $session;
        });
    }

    /** @param list<array<string, mixed>> $records */
    public function recordAiUsage(
        WorkflowCopilotSession $session,
        array $records,
        string $source = 'copilot',
    ): WorkflowCopilotSession {
        if ($records === []) {
            return $session->fresh() ?? $session;
        }

        $summary = $this->aiUsage->summarize($records);
        $updated = DB::transaction(function () use ($session, $summary): WorkflowCopilotSession {
            $locked = WorkflowCopilotSession::query()->lockForUpdate()->findOrFail($session->id);
            $usage = array_replace(
                self::DEFAULT_USAGE,
                is_array($locked->usage_json) ? $locked->usage_json : [],
            );

            foreach (['ai_requests', 'input_tokens', 'output_tokens', 'total_tokens', 'reasoning_tokens'] as $field) {
                $usage[$field] = max(0, (int) ($usage[$field] ?? 0)) + max(0, (int) ($summary[$field] ?? 0));
            }

            foreach (['cost_usd', 'provider_cost_usd'] as $field) {
                $usage[$field] = round(
                    max(0, (float) ($usage[$field] ?? 0)) + max(0, (float) ($summary[$field] ?? 0)),
                    10,
                );
            }

            $models = is_array($usage['ai_models'] ?? null) ? $usage['ai_models'] : [];

            foreach (is_array($summary['models'] ?? null) ? $summary['models'] : [] as $model => $count) {
                $models[(string) $model] = max(0, (int) ($models[(string) $model] ?? 0)) + max(0, (int) $count);
            }

            $usage['ai_models'] = $models;
            $locked->forceFill([
                'usage_json' => $usage,
                'last_activity_at' => now(),
            ])->save();

            return $locked;
        });

        $this->appendEvent(
            $updated,
            'ai.usage_recorded',
            'Token- und Kostennutzung der Copilot-Modelle wurde dem Optimierungslauf zugeordnet.',
            [
                'source' => Str::limit(trim($source), 80, '') ?: 'copilot',
                'summary' => $summary,
                'requests' => array_slice($records, 0, 12),
                'session_cost_usd' => (float) data_get($updated->usage_json, 'cost_usd', 0),
                'max_cost_usd' => (float) data_get($updated->budget_json, 'max_cost_usd', 0),
            ],
            (string) $updated->phase,
        );

        return $updated->fresh() ?? $updated;
    }

    public function appendEvent(
        WorkflowCopilotSession $session,
        string $eventType,
        string $message,
        array $payload = [],
        ?string $phase = null,
        string $level = 'info',
        bool $milestone = false,
    ): WorkflowCopilotEvent {
        return DB::transaction(function () use ($session, $eventType, $message, $payload, $phase, $level, $milestone): WorkflowCopilotEvent {
            $lockedSession = WorkflowCopilotSession::query()->lockForUpdate()->findOrFail($session->getKey());
            $this->assertSystemExecutionTarget((string) $lockedSession->execution_target);

            return $this->appendEventToLockedSession(
                $lockedSession,
                $eventType,
                $message,
                $payload,
                $phase,
                $level,
                $milestone,
            );
        });
    }

    public function eventsAfter(WorkflowCopilotSession $session, int $afterSequence = 0, int $limit = 100): Collection
    {
        return WorkflowCopilotEvent::query()
            ->where('workflow_copilot_session_id', $session->getKey())
            ->where('sequence', '>', max(0, $afterSequence))
            ->orderBy('sequence')
            ->limit(max(1, min(500, $limit)))
            ->get();
    }

    public function instruction(WorkflowCopilotSession $session, string $instruction, array $metadata = []): WorkflowCopilotEvent
    {
        $instruction = trim($instruction);

        if ($instruction === '') {
            throw new DomainException('Eine Copilot-Anweisung darf nicht leer sein.');
        }

        return DB::transaction(function () use ($session, $instruction, $metadata): WorkflowCopilotEvent {
            Workflow::query()->lockForUpdate()->findOrFail($session->workflow_id);
            $lockedSession = WorkflowCopilotSession::query()->lockForUpdate()->findOrFail($session->getKey());
            $this->assertControllable($lockedSession);

            return $this->appendEventToLockedSession(
                $lockedSession,
                'instruction.received',
                'Neue Benutzeranweisung wurde fuer den naechsten sicheren Checkpoint gespeichert.',
                ['instruction' => Str::limit($instruction, 12000, ''), 'metadata' => $metadata],
                $lockedSession->phase,
                'info',
                true,
            );
        });
    }

    public function requestReplan(WorkflowCopilotSession $session, string $instruction, array $metadata = []): WorkflowCopilotSession
    {
        $this->instruction($session, $instruction, $metadata);
        $session = $this->updateState(
            $session,
            [
                'active_repair_plan' => null,
                'processed_checkpoint_id' => null,
                'repair_counted_checkpoint_id' => null,
                'replan_requested_at' => now()->toIso8601String(),
                'replan_reason' => Str::limit(trim($instruction), 2000, ''),
            ],
            'repairing',
            'Die Benutzeranweisung wird sofort in einer neuen Reparaturplanung verarbeitet.',
        );

        return $session->status === WorkflowCopilotSession::STATUS_PAUSED
            ? $this->resume($session)
            : ($session->fresh() ?? $session);
    }

    public function pause(WorkflowCopilotSession $session, ?string $reason = null): WorkflowCopilotSession
    {
        return $this->transition(
            $session,
            WorkflowCopilotSession::STATUS_PAUSED,
            'paused',
            [],
            $reason ?: 'Workflow-Copilot-Sitzung wurde pausiert.',
        );
    }

    public function resume(WorkflowCopilotSession $session): WorkflowCopilotSession
    {
        return DB::transaction(function () use ($session): WorkflowCopilotSession {
            Workflow::query()->lockForUpdate()->findOrFail($session->workflow_id);
            $lockedSession = WorkflowCopilotSession::query()->lockForUpdate()->findOrFail($session->getKey());
            $state = is_array($lockedSession->state_json) ? $lockedSession->state_json : [];
            $resume = is_array($state['resume_after_pause'] ?? null) ? $state['resume_after_pause'] : [];
            $resumeStatus = (string) ($resume['status'] ?? WorkflowCopilotSession::STATUS_RUNNING);

            if (! in_array($resumeStatus, [
                WorkflowCopilotSession::STATUS_RUNNING,
                WorkflowCopilotSession::STATUS_REPAIRING,
                WorkflowCopilotSession::STATUS_VERIFYING,
            ], true)) {
                $resumeStatus = WorkflowCopilotSession::STATUS_RUNNING;
            }

            $resumePhase = trim((string) ($resume['phase'] ?? '')) ?: match ($resumeStatus) {
                WorkflowCopilotSession::STATUS_REPAIRING => 'repairing',
                WorkflowCopilotSession::STATUS_VERIFYING => 'verifying',
                default => 'executing',
            };
            $state['resume_after_pause'] = null;
            $state['processed_checkpoint_id'] = null;

            return $this->transition(
                $lockedSession,
                $resumeStatus,
                $resumePhase,
                $state,
                'Workflow-Copilot-Sitzung wird fortgesetzt.',
            );
        });
    }

    public function stop(WorkflowCopilotSession $session, ?string $reason = null): WorkflowCopilotSession
    {
        return $this->transition(
            $session,
            WorkflowCopilotSession::STATUS_STOPPED,
            'stopped',
            [],
            $reason ?: 'Workflow-Copilot-Sitzung wurde gestoppt.',
        );
    }

    public function restart(WorkflowCopilotSession $session, ?string $reason = null): WorkflowCopilotSession
    {
        return DB::transaction(function () use ($session, $reason): WorkflowCopilotSession {
            $previous = WorkflowCopilotSession::query()
                ->with(['workflow', 'activeRun'])
                ->findOrFail($session->getKey());
            $this->assertSystemExecutionTarget((string) $previous->execution_target);

            $restartReason = $reason ?: 'Workflow-Copilot-Sitzung wird mit denselben Vorgaben neu gestartet.';
            $this->appendEvent(
                $previous,
                'session.restart_requested',
                $restartReason,
                ['previous_status' => (string) $previous->status],
                (string) $previous->phase,
                'warning',
                true,
            );

            WorkflowRun::query()
                ->where('workflow_id', $previous->workflow_id)
                ->whereIn('status', self::ACTIVE_WORKFLOW_RUN_STATUSES)
                ->whereNotNull('finished_at')
                ->update([
                    'status' => 'cancelled',
                    'current_workflow_step_id' => null,
                ]);

            $activeRuns = WorkflowRun::query()
                ->where('workflow_id', $previous->workflow_id)
                ->whereIn('status', self::ACTIVE_WORKFLOW_RUN_STATUSES)
                ->whereNull('finished_at')
                ->lockForUpdate()
                ->get();

            foreach ($activeRuns as $activeRun) {
                $this->workflowExecution->cancel(
                    $activeRun,
                    'Workflow-Test wurde fuer den Neustart der Copilot-Optimierung beendet.',
                );
            }

            if ($previous->retainsWorkflowLock()) {
                $previous = $this->stop(
                    $previous->fresh() ?? $previous,
                    'Vorherige Copilot-Sitzung wurde fuer einen vollstaendigen Neustart beendet.',
                );
            }

            $workflow = $previous->workflow()->firstOrFail();
            $restarted = $this->start($workflow, [
                'person_id' => $previous->person_id,
                'execution_target' => WorkflowCopilotSession::EXECUTION_TARGET_SYSTEM,
                'goal' => $previous->goal,
                'success_criteria' => is_array($previous->success_criteria_json) ? $previous->success_criteria_json : [],
                'workflow_inputs' => is_array($previous->workflow_inputs_json) ? $previous->workflow_inputs_json : [],
                'budget' => is_array($previous->budget_json) ? $previous->budget_json : [],
                'state' => [
                    'restarted_from_session_id' => (int) $previous->getKey(),
                ],
            ]);

            $this->appendEvent(
                $restarted,
                'session.restarted',
                'Copilot-Optimierung wurde vollstaendig neu gestartet; Testlauf und Arbeitsbudgets beginnen von vorn.',
                [
                    'previous_session_id' => (int) $previous->getKey(),
                    'previous_status' => (string) $previous->status,
                ],
                'executing',
                'success',
                true,
            );

            return $restarted->fresh(['workflow']) ?? $restarted;
        });
    }

    public function transition(
        WorkflowCopilotSession $session,
        string $status,
        ?string $phase = null,
        array $state = [],
        ?string $message = null,
        array $payload = [],
    ): WorkflowCopilotSession {
        $this->assertKnownStatus($status);

        return DB::transaction(function () use ($session, $status, $phase, $state, $message, $payload): WorkflowCopilotSession {
            $workflow = Workflow::query()->lockForUpdate()->findOrFail($session->workflow_id);
            $lockedSession = WorkflowCopilotSession::query()->lockForUpdate()->findOrFail($session->getKey());
            $this->assertSystemExecutionTarget((string) $lockedSession->execution_target);
            $from = (string) $lockedSession->status;

            if (in_array($from, WorkflowCopilotSession::LOCK_RETAINING_STATUSES, true)
                && (int) $workflow->active_workflow_copilot_session_id !== (int) $lockedSession->getKey()) {
                throw new DomainException('Die Copilot-Sitzung besitzt den Workflow-Lock nicht mehr.');
            }

            if ($from !== $status && ! in_array($status, self::STATUS_TRANSITIONS[$from] ?? [], true)) {
                throw new DomainException("Ungueltiger Copilot-Statuswechsel von {$from} nach {$status}.");
            }

            $now = now();
            $mergedState = $this->mergeState(
                is_array($lockedSession->state_json) ? $lockedSession->state_json : [],
                $state,
            );

            if ($status === WorkflowCopilotSession::STATUS_PAUSED && $from !== WorkflowCopilotSession::STATUS_PAUSED) {
                $mergedState['resume_after_pause'] = [
                    'status' => $from,
                    'phase' => (string) $lockedSession->phase,
                    'paused_at' => $now->toIso8601String(),
                ];
            }

            $lockedSession->forceFill([
                'status' => $status,
                'phase' => $phase ?: $lockedSession->phase,
                'state_json' => $mergedState,
                'paused_at' => $status === WorkflowCopilotSession::STATUS_PAUSED ? $now : null,
                'finished_at' => in_array($status, WorkflowCopilotSession::TERMINAL_STATUSES, true) ? $now : null,
                'last_activity_at' => $now,
            ])->save();

            if ($status === WorkflowCopilotSession::STATUS_SUCCEEDED) {
                $workflow->forceFill([
                    'active_workflow_copilot_session_id' => null,
                    'copilot_locked_at' => null,
                    'copilot_verification_status' => 'verified',
                    'copilot_verified_at' => $now,
                ])->save();
                WorkflowRevision::query()
                    ->where('workflow_copilot_session_id', $lockedSession->getKey())
                    ->where('revision_number', $lockedSession->current_revision)
                    ->update(['is_verified' => true, 'verified_at' => $now]);
            } elseif ($status === WorkflowCopilotSession::STATUS_STOPPED) {
                if ((int) $workflow->active_workflow_copilot_session_id === (int) $lockedSession->getKey()) {
                    $workflow->forceFill([
                        'active_workflow_copilot_session_id' => null,
                        'copilot_locked_at' => null,
                        'copilot_verification_status' => 'unverified',
                        'copilot_verified_at' => null,
                    ])->save();
                }
            } elseif ((int) $workflow->active_workflow_copilot_session_id !== (int) $lockedSession->getKey()) {
                throw new DomainException('Die Copilot-Sitzung besitzt den Workflow-Lock nicht mehr.');
            }

            $eventLevel = match ($status) {
                WorkflowCopilotSession::STATUS_SUCCEEDED => 'success',
                WorkflowCopilotSession::STATUS_FAILED,
                WorkflowCopilotSession::STATUS_BUDGET_EXHAUSTED => 'error',
                default => 'info',
            };
            $this->appendEventToLockedSession(
                $lockedSession,
                'session.status_changed',
                $message ?: "Workflow-Copilot-Status: {$status}.",
                [...$payload, 'from' => $from, 'to' => $status],
                $lockedSession->phase,
                $eventLevel,
                true,
            );

            return $lockedSession->fresh(['workflow']) ?? $lockedSession;
        });
    }

    public function updateState(
        WorkflowCopilotSession $session,
        array $state,
        ?string $phase = null,
        ?string $message = null,
    ): WorkflowCopilotSession {
        return DB::transaction(function () use ($session, $state, $phase, $message): WorkflowCopilotSession {
            Workflow::query()->lockForUpdate()->findOrFail($session->workflow_id);
            $lockedSession = WorkflowCopilotSession::query()->lockForUpdate()->findOrFail($session->getKey());
            $this->assertControllable($lockedSession);
            $lockedSession->forceFill([
                'phase' => $phase ?: $lockedSession->phase,
                'state_json' => $this->mergeState(
                    is_array($lockedSession->state_json) ? $lockedSession->state_json : [],
                    $state,
                ),
                'last_activity_at' => now(),
            ])->save();

            if ($message !== null && trim($message) !== '') {
                $this->appendEventToLockedSession(
                    $lockedSession,
                    'session.state_updated',
                    trim($message),
                    ['state' => $state],
                    $lockedSession->phase,
                );
            }

            return $lockedSession->fresh() ?? $lockedSession;
        });
    }

    public function attachRun(WorkflowCopilotSession $session, WorkflowRun $run): WorkflowCopilotSession
    {
        return DB::transaction(function () use ($session, $run): WorkflowCopilotSession {
            Workflow::query()->lockForUpdate()->findOrFail($session->workflow_id);
            $lockedSession = WorkflowCopilotSession::query()->lockForUpdate()->findOrFail($session->getKey());
            $lockedRun = WorkflowRun::query()->lockForUpdate()->findOrFail($run->getKey());
            $this->assertControllable($lockedSession);

            if ((int) $lockedRun->workflow_id !== (int) $lockedSession->workflow_id) {
                throw new DomainException('Der Workflow-Run gehoert nicht zum Workflow der Copilot-Sitzung.');
            }

            if ($lockedRun->workflow_copilot_session_id
                && (int) $lockedRun->workflow_copilot_session_id !== (int) $lockedSession->getKey()) {
                throw new DomainException('Der Workflow-Run ist bereits einer anderen Copilot-Sitzung zugeordnet.');
            }

            $runContext = is_array($lockedRun->context_json) ? $lockedRun->context_json : [];
            $runIsFinal = in_array((string) $lockedRun->status, [
                'completed',
                'failed',
                'cancelled',
                'timed_out',
                'lost',
            ], true);

            if (! $runIsFinal && (($runContext['execution_target'] ?? null) !== WorkflowCopilotSession::EXECUTION_TARGET_SYSTEM
                || filled($runContext['network_node_id'] ?? null)
                || filled($runContext['device_id'] ?? null))) {
                throw new DomainException('Ein aktiver Workflow-Run darf nur als reine System-Ausfuehrung an den Copilot gebunden werden.');
            }

            $alreadyAttached = (int) $lockedRun->workflow_copilot_session_id === (int) $lockedSession->getKey()
                && (int) $lockedSession->active_workflow_run_id === (int) $lockedRun->getKey();

            if ($alreadyAttached) {
                return $lockedSession->fresh(['activeRun']) ?? $lockedSession;
            }

            $runContext = array_replace($runContext, [
                'workflow_copilot_session_id' => (int) $lockedSession->getKey(),
                'workflow_revision' => (int) $lockedSession->current_revision,
                'execution_target' => WorkflowCopilotSession::EXECUTION_TARGET_SYSTEM,
                'network_node_id' => null,
                'device_id' => null,
                'allow_client_reassignment' => false,
                'max_client_reassignments' => 0,
            ]);

            $lockedRun->forceFill([
                'workflow_copilot_session_id' => $lockedSession->getKey(),
                'workflow_revision' => (int) $lockedSession->current_revision,
                'context_json' => $runContext,
            ])->save();
            $lockedSession->forceFill([
                'active_workflow_run_id' => $lockedRun->getKey(),
                'last_activity_at' => now(),
            ])->save();
            $this->appendEventToLockedSession(
                $lockedSession,
                'run.attached',
                'Workflow-Testlauf #'.$lockedRun->getKey().' wurde der Copilot-Sitzung zugeordnet.',
                ['workflow_run_id' => (int) $lockedRun->getKey(), 'workflow_revision' => (int) $lockedSession->current_revision],
                $lockedSession->phase,
            );

            return $lockedSession->fresh(['activeRun']) ?? $lockedSession;
        });
    }

    public function rewind(
        WorkflowCopilotSession $session,
        WorkflowRunCheckpoint|int|string $checkpoint,
        ?string $reason = null,
    ): WorkflowCopilotSession {
        return DB::transaction(function () use ($session, $checkpoint, $reason): WorkflowCopilotSession {
            Workflow::query()->lockForUpdate()->findOrFail($session->workflow_id);
            $lockedSession = WorkflowCopilotSession::query()->lockForUpdate()->findOrFail($session->getKey());
            $this->assertControllable($lockedSession);
            $resolved = $this->resolveCheckpoint($lockedSession, $checkpoint);
            $sideEffects = is_array($resolved->side_effect_ledger_json) ? $resolved->side_effect_ledger_json : [];
            $pendingControl = [
                'action' => 'rewind',
                'checkpoint_id' => (int) $resolved->getKey(),
                'checkpoint_sequence' => (int) $resolved->sequence,
                'requested_at' => now()->toIso8601String(),
                'reason' => $this->nullableText($reason),
            ];
            $lockedSession->forceFill([
                'status' => WorkflowCopilotSession::STATUS_REPAIRING,
                'phase' => 'rewinding',
                'state_json' => $this->mergeState(
                    is_array($lockedSession->state_json) ? $lockedSession->state_json : [],
                    ['pending_control' => $pendingControl],
                ),
                'paused_at' => null,
                'last_activity_at' => now(),
            ])->save();
            $this->appendEventToLockedSession(
                $lockedSession,
                'rewind.requested',
                $reason ?: 'Ruecksprung zu Checkpoint #'.$resolved->sequence.' wurde angefordert.',
                [
                    ...$pendingControl,
                    'has_irreversible_side_effects' => $sideEffects !== [],
                    'side_effect_ledger' => $sideEffects,
                ],
                'rewinding',
                $sideEffects === [] ? 'info' : 'warning',
                true,
            );

            return $lockedSession->fresh() ?? $lockedSession;
        });
    }

    public function releaseLock(WorkflowCopilotSession $session): WorkflowCopilotSession
    {
        if ($session->isActive()) {
            throw new DomainException('Eine aktive Copilot-Sitzung muss vor dem Entsperren gestoppt werden.');
        }

        return DB::transaction(function () use ($session): WorkflowCopilotSession {
            $workflow = Workflow::query()->lockForUpdate()->findOrFail($session->workflow_id);
            $lockedSession = WorkflowCopilotSession::query()->lockForUpdate()->findOrFail($session->getKey());

            if ((int) $workflow->active_workflow_copilot_session_id === (int) $lockedSession->getKey()) {
                $workflow->forceFill([
                    'active_workflow_copilot_session_id' => null,
                    'copilot_locked_at' => null,
                ])->save();
                $this->appendEventToLockedSession(
                    $lockedSession,
                    'session.lock_released',
                    'Workflow-Lock wurde manuell freigegeben.',
                    [],
                    $lockedSession->phase,
                    'warning',
                    true,
                );
            }

            return $lockedSession->fresh(['workflow']) ?? $lockedSession;
        });
    }

    public function beginTaskAttempt(WorkflowCopilotSession $session, array $attributes): WorkflowTaskAttempt
    {
        return DB::transaction(function () use ($session, $attributes): WorkflowTaskAttempt {
            Workflow::query()->lockForUpdate()->findOrFail($session->workflow_id);
            $lockedSession = WorkflowCopilotSession::query()->lockForUpdate()->findOrFail($session->getKey());
            $this->assertControllable($lockedSession);
            $attemptNumber = (int) WorkflowTaskAttempt::query()
                ->where('workflow_copilot_session_id', $lockedSession->getKey())
                ->max('attempt_number') + 1;
            $attempt = WorkflowTaskAttempt::query()->create([
                ...$attributes,
                'workflow_copilot_session_id' => $lockedSession->getKey(),
                'attempt_number' => $attemptNumber,
                'kind' => $attributes['kind'] ?? 'regular',
                'status' => $attributes['status'] ?? 'running',
                'started_at' => $attributes['started_at'] ?? now(),
            ]);
            $this->appendEventToLockedSession(
                $lockedSession,
                'task.started',
                'Task `'.($attempt->task_title ?: $attempt->task_key ?: '#'.$attemptNumber).'` wird ausgefuehrt.',
                ['task_attempt_id' => (int) $attempt->getKey(), 'attempt_number' => $attemptNumber, 'task_key' => $attempt->task_key],
                $lockedSession->phase,
            );

            return $attempt;
        });
    }

    public function finishTaskAttempt(
        WorkflowTaskAttempt $attempt,
        string $status,
        ?array $result = null,
        ?string $error = null,
        ?array $sideEffects = null,
        ?array $artifacts = null,
    ): WorkflowTaskAttempt {
        return DB::transaction(function () use ($attempt, $status, $result, $error, $sideEffects, $artifacts): WorkflowTaskAttempt {
            $lockedAttempt = WorkflowTaskAttempt::query()->lockForUpdate()->findOrFail($attempt->getKey());
            $session = WorkflowCopilotSession::query()->lockForUpdate()->findOrFail($lockedAttempt->workflow_copilot_session_id);
            $finishedAt = now();
            $duration = $lockedAttempt->started_at
                ? max(0, $lockedAttempt->started_at->diffInMilliseconds($finishedAt))
                : null;
            $lockedAttempt->forceFill([
                'status' => $status,
                'result_json' => $result,
                'error_message' => $this->nullableText($error),
                'side_effects_json' => $sideEffects,
                'artifacts_json' => $artifacts,
                'finished_at' => $finishedAt,
                'duration_ms' => $duration,
            ])->save();
            $ok = in_array($status, ['success', 'succeeded', 'completed'], true);
            $this->appendEventToLockedSession(
                $session,
                $ok ? 'task.completed' : 'task.failed',
                'Task `'.($lockedAttempt->task_title ?: $lockedAttempt->task_key ?: '#'.$lockedAttempt->attempt_number).'` '.($ok ? 'wurde abgeschlossen.' : 'ist fehlgeschlagen.'),
                ['task_attempt_id' => (int) $lockedAttempt->getKey(), 'status' => $status, 'error' => $error],
                $session->phase,
                $ok ? 'success' : 'error',
                ! $ok,
            );

            return $lockedAttempt->fresh() ?? $lockedAttempt;
        });
    }

    public function createCheckpoint(WorkflowCopilotSession $session, array $attributes): WorkflowRunCheckpoint
    {
        return DB::transaction(function () use ($session, $attributes): WorkflowRunCheckpoint {
            Workflow::query()->lockForUpdate()->findOrFail($session->workflow_id);
            $lockedSession = WorkflowCopilotSession::query()->lockForUpdate()->findOrFail($session->getKey());
            $this->assertControllable($lockedSession);
            $sequence = (int) WorkflowRunCheckpoint::query()
                ->where('workflow_copilot_session_id', $lockedSession->getKey())
                ->max('sequence') + 1;
            $checkpoint = WorkflowRunCheckpoint::query()->create([
                ...$attributes,
                'workflow_copilot_session_id' => $lockedSession->getKey(),
                'sequence' => $sequence,
                'phase' => $attributes['phase'] ?? $lockedSession->phase,
                'is_reproducible' => $attributes['is_reproducible'] ?? true,
            ]);
            $this->appendEventToLockedSession(
                $lockedSession,
                'checkpoint.created',
                'Checkpoint #'.$sequence.' wurde gespeichert.',
                [
                    'checkpoint_id' => (int) $checkpoint->getKey(),
                    'checkpoint_sequence' => $sequence,
                    'task_key' => $checkpoint->task_key,
                    'is_reproducible' => (bool) $checkpoint->is_reproducible,
                ],
                $checkpoint->phase,
            );

            return $checkpoint;
        });
    }

    private function appendEventToLockedSession(
        WorkflowCopilotSession $session,
        string $eventType,
        string $message,
        array $payload = [],
        ?string $phase = null,
        string $level = 'info',
        bool $milestone = false,
    ): WorkflowCopilotEvent {
        $eventType = trim($eventType);
        $message = trim($message);

        if ($eventType === '' || $message === '') {
            throw new DomainException('Copilot-Ereignistyp und Nachricht duerfen nicht leer sein.');
        }

        $sequence = (int) $session->last_event_sequence + 1;
        $occurredAt = now();
        $event = WorkflowCopilotEvent::query()->create([
            'workflow_copilot_session_id' => $session->getKey(),
            'sequence' => $sequence,
            'event_type' => Str::limit($eventType, 100, ''),
            'phase' => $phase ?: $session->phase,
            'level' => Str::limit(trim($level) ?: 'info', 20, ''),
            'message' => $message,
            'payload_json' => $payload,
            'is_milestone' => $milestone,
            'occurred_at' => $occurredAt,
        ]);
        $session->forceFill([
            'last_event_sequence' => $sequence,
            'last_activity_at' => $occurredAt,
        ])->save();

        return $event;
    }

    private function resolveCheckpoint(
        WorkflowCopilotSession $session,
        WorkflowRunCheckpoint|int|string $checkpoint,
    ): WorkflowRunCheckpoint {
        if ($checkpoint instanceof WorkflowRunCheckpoint) {
            $resolved = $checkpoint;
        } else {
            $value = trim((string) $checkpoint);
            $sequence = null;

            if (preg_match('/^(?:seq(?:uence)?\s*[:#-]?\s*)(\d+)$/i', $value, $matches)) {
                $sequence = (int) $matches[1];
            }

            $query = WorkflowRunCheckpoint::query()
                ->where('workflow_copilot_session_id', $session->getKey());
            $resolved = $sequence !== null
                ? $query->where('sequence', $sequence)->first()
                : $query->whereKey((int) $value)->first();
        }

        if (! $resolved || (int) $resolved->workflow_copilot_session_id !== (int) $session->getKey()) {
            throw new DomainException('Der angeforderte Checkpoint gehoert nicht zu dieser Copilot-Sitzung.');
        }

        return $resolved;
    }

    private function assertControllable(WorkflowCopilotSession $session): void
    {
        $this->assertSystemExecutionTarget((string) $session->execution_target);

        if (! $session->isActive()) {
            throw new DomainException('Die Copilot-Sitzung ist nicht mehr aktiv steuerbar.');
        }

        $ownerId = Workflow::query()
            ->whereKey($session->workflow_id)
            ->value('active_workflow_copilot_session_id');

        if ((int) $ownerId !== (int) $session->getKey()) {
            throw new DomainException('Die Copilot-Sitzung besitzt den Workflow-Lock nicht mehr.');
        }
    }

    private function assertSystemExecutionTarget(string $executionTarget): void
    {
        if ($executionTarget !== WorkflowCopilotSession::EXECUTION_TARGET_SYSTEM) {
            throw new DomainException('Workflow-Copilot-Sitzungen duerfen ausschliesslich auf execution_target=system laufen.');
        }
    }

    private function assertKnownStatus(string $status): void
    {
        if (! in_array($status, [
            ...WorkflowCopilotSession::ACTIVE_STATUSES,
            ...WorkflowCopilotSession::TERMINAL_STATUSES,
        ], true)) {
            throw new DomainException('Unbekannter Workflow-Copilot-Status: '.$status);
        }
    }

    private function arrayOption(array $options, string $key, string $jsonKey): array
    {
        $value = $options[$key] ?? $options[$jsonKey] ?? [];

        return is_array($value) ? $value : [];
    }

    private function nullableText(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value !== '' ? $value : null;
    }

    private function mergeState(array $current, array $updates): array
    {
        foreach ($updates as $key => $value) {
            if (
                is_array($value)
                && $value !== []
                && isset($current[$key])
                && is_array($current[$key])
                && ! array_is_list($value)
                && ! array_is_list($current[$key])
            ) {
                $current[$key] = $this->mergeState($current[$key], $value);

                continue;
            }

            $current[$key] = $value;
        }

        return $current;
    }
}
