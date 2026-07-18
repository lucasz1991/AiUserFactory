<?php

namespace App\Services\Workflows;

use App\Jobs\MonitorWorkflowStepRunJob;
use App\Jobs\RunWorkflowJob;
use App\Jobs\WorkflowCopilotSupervisorJob;
use App\Models\Device;
use App\Models\NetworkJob;
use App\Models\NetworkNode;
use App\Models\Person;
use App\Models\Workflow;
use App\Models\WorkflowCopilotSession;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use App\Models\WorkflowStepRun;
use App\Models\WorkflowStudioSession;
use App\Services\ClientController\NetworkJobDispatcher;
use App\Services\Mail\MailAccountRegistrationRunner;
use App\Services\Mail\WebmailSessionRunner;
use App\Services\Workflows\Tasks\PersistBrowserSessionTask;
use App\Services\Workflows\Tasks\PersistMailAccountTask;
use App\Services\Workflows\Tasks\PersistWebmailSessionTask;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class WorkflowExecutionService
{
    /** @var list<string> */
    private const FINAL_RUN_STATUSES = [
        'completed',
        'failed',
        'cancelled',
        'timed_out',
        'lost',
    ];

    public function __construct(
        protected MailAccountRegistrationRunner $mailRegistration,
        protected WebmailSessionRunner $webmailSession,
        protected WorkflowTaskRunner $workflowTasks,
        protected NetworkJobDispatcher $networkJobs,
        protected WorkflowResultNormalizer $resultNormalizer,
        protected WorkflowDebugArtifactService $debugArtifacts,
        protected ClientWorkflowBundleCompiler $clientBundles,
    ) {}

    public function start(Workflow $workflow, array $context = [], string $requestedBy = 'admin-ui'): WorkflowRun
    {
        $workflowId = (int) $workflow->getKey();

        if ($workflowId <= 0) {
            throw new \InvalidArgumentException('Der Workflow muss vor dem Start gespeichert sein.');
        }

        $copilotSessionId = max(0, (int) ($context['workflow_copilot_session_id'] ?? $context['workflowCopilotSessionId'] ?? 0));
        $studioSessionId = max(0, (int) ($context['workflow_studio_session_id'] ?? $context['workflowStudioSessionId'] ?? 0));
        if ($studioSessionId <= 0 && $copilotSessionId > 0 && Schema::hasTable('workflow_studio_sessions')) {
            $studioSessionId = (int) (WorkflowStudioSession::query()
                ->where('workflow_copilot_session_id', $copilotSessionId)
                ->latest('id')
                ->value('id') ?? 0);
        }
        $run = DB::transaction(function () use ($workflowId, $context, $requestedBy, $copilotSessionId, $studioSessionId): WorkflowRun {
            $lockedWorkflow = Workflow::query()->lockForUpdate()->findOrFail($workflowId);
            $hasCopilotLockColumn = Schema::hasColumn('workflows', 'active_workflow_copilot_session_id');
            $activeCopilotSessionId = $hasCopilotLockColumn
                ? max(0, (int) $lockedWorkflow->active_workflow_copilot_session_id)
                : 0;

            if ($activeCopilotSessionId > 0 && $copilotSessionId !== $activeCopilotSessionId) {
                throw new \RuntimeException('Dieser Workflow ist durch eine aktive Copilot-Optimierung exklusiv gesperrt.');
            }

            $copilotSession = null;
            $studioSession = null;

            if ($studioSessionId > 0) {
                $studioSession = WorkflowStudioSession::query()->lockForUpdate()->find($studioSessionId);

                if (! $studioSession || (int) $studioSession->workflow_id !== (int) $lockedWorkflow->id) {
                    throw new \RuntimeException('Die Workflow-Studio-Sitzung gehoert nicht zu diesem Workflow.');
                }

                $context['workflow_studio_session_id'] = $studioSessionId;
                $context['workflow_revision'] = (int) ($lockedWorkflow->copilot_revision ?? 0);
            }

            if ($copilotSessionId > 0) {
                $copilotSession = WorkflowCopilotSession::query()->lockForUpdate()->find($copilotSessionId);

                if (! $copilotSession || (int) $copilotSession->workflow_id !== (int) $lockedWorkflow->id) {
                    throw new \RuntimeException('Die Workflow-Copilot-Sitzung gehoert nicht zu diesem Workflow.');
                }

                if ($hasCopilotLockColumn && $activeCopilotSessionId !== (int) $copilotSession->id) {
                    throw new \RuntimeException('Die Workflow-Copilot-Sitzung besitzt den exklusiven Workflow-Lock nicht.');
                }

                if (! in_array($copilotSession->status, [
                    WorkflowCopilotSession::STATUS_RUNNING,
                    WorkflowCopilotSession::STATUS_REPAIRING,
                    WorkflowCopilotSession::STATUS_VERIFYING,
                ], true)) {
                    throw new \RuntimeException('Die Workflow-Copilot-Sitzung ist pausiert oder nicht mehr aktiv.');
                }

                if ($copilotSession->execution_target !== WorkflowCopilotSession::EXECUTION_TARGET_SYSTEM) {
                    throw new \RuntimeException('Workflow-Copilot-Laeufe duerfen ausschliesslich auf execution_target=system laufen.');
                }

                $context = array_replace($context, [
                    'workflow_copilot_session_id' => $copilotSessionId,
                    'workflow_revision' => (int) ($lockedWorkflow->copilot_revision ?? 0),
                    'execution_target' => 'system',
                    'network_node_id' => null,
                    'device_id' => null,
                    'allow_client_reassignment' => false,
                    'max_client_reassignments' => 0,
                ]);
            }

            if (! $lockedWorkflow->is_active && ! $copilotSession) {
                throw new \RuntimeException('Dieser Workflow ist deaktiviert.');
            }

            if (! $lockedWorkflow->enabledSteps()->exists()) {
                throw new \RuntimeException('Dieser Workflow hat keine aktiven Schritte.');
            }

            if ($copilotSession) {
                $existingRun = null;

                if ($copilotSession->active_workflow_run_id) {
                    $candidate = WorkflowRun::query()
                        ->lockForUpdate()
                        ->find($copilotSession->active_workflow_run_id);

                    if ($candidate && ! in_array((string) $candidate->status, self::FINAL_RUN_STATUSES, true)) {
                        $existingRun = $candidate;
                    }
                }

                $existingRun ??= WorkflowRun::query()
                    ->where('workflow_copilot_session_id', $copilotSession->id)
                    ->whereNotIn('status', self::FINAL_RUN_STATUSES)
                    ->lockForUpdate()
                    ->orderByDesc('id')
                    ->first();

                if ($existingRun) {
                    $existingContext = is_array($existingRun->context_json) ? $existingRun->context_json : [];
                    $samePurpose = (bool) ($existingContext['copilot_verification_run'] ?? false)
                        === (bool) ($context['copilot_verification_run'] ?? false);
                    $systemOnly = ($existingContext['execution_target'] ?? null) === WorkflowCopilotSession::EXECUTION_TARGET_SYSTEM
                        && blank($existingContext['network_node_id'] ?? null)
                        && blank($existingContext['device_id'] ?? null);
                    $sameRevision = (int) $existingRun->workflow_revision === (int) ($lockedWorkflow->copilot_revision ?? 0);
                    $sameOwner = (int) $existingRun->workflow_id === (int) $lockedWorkflow->id
                        && (int) $existingRun->workflow_copilot_session_id === (int) $copilotSession->id;

                    if (! $samePurpose || ! $systemOnly || ! $sameRevision || ! $sameOwner) {
                        throw new \RuntimeException(
                            'Die Copilot-Sitzung besitzt bereits einen nicht-finalen Lauf, der nicht sicher wiederverwendet werden kann.',
                        );
                    }

                    if ((int) $copilotSession->active_workflow_run_id !== (int) $existingRun->id) {
                        $copilotSession->forceFill([
                            'active_workflow_run_id' => $existingRun->id,
                            'last_activity_at' => now(),
                        ])->save();
                    }

                    return $existingRun;
                }
            }

            $attributes = [
                'run_uuid' => (string) Str::uuid(),
                'workflow_id' => $lockedWorkflow->id,
                'status' => 'queued',
                'requested_by' => $requestedBy,
                'queued_at' => now(),
                'context_json' => $this->normalizeContext($context),
                'result_json' => [],
            ];

            if (Schema::hasColumn('workflow_runs', 'workflow_copilot_session_id')) {
                $attributes['workflow_copilot_session_id'] = $copilotSessionId ?: null;
                $attributes['workflow_revision'] = ($copilotSessionId > 0 || $studioSessionId > 0)
                    ? (int) ($lockedWorkflow->copilot_revision ?? 0)
                    : null;
            }

            if (Schema::hasColumn('workflow_runs', 'workflow_studio_session_id')) {
                $attributes['workflow_studio_session_id'] = $studioSessionId ?: null;
            }

            $run = WorkflowRun::query()->create($attributes);

            $lockedWorkflow->forceFill(['last_run_at' => now()])->save();

            if ($copilotSession) {
                $copilotSession->forceFill([
                    'active_workflow_run_id' => $run->id,
                    'last_activity_at' => now(),
                ])->save();
                RunWorkflowJob::dispatch($run->id)->afterCommit();
            }

            if ($studioSession) {
                $studioSession->forceFill([
                    'active_workflow_run_id' => $run->id,
                    'status' => 'queued',
                    'started_at' => $studioSession->started_at ?: now(),
                    'last_activity_at' => now(),
                ])->save();
            }

            return $run;
        });

        if ($copilotSessionId === 0) {
            RunWorkflowJob::dispatch($run->id);
        }

        return $run;
    }

    public function advance(int|WorkflowRun $workflowRun): void
    {
        $runId = $workflowRun instanceof WorkflowRun ? (int) $workflowRun->id : (int) $workflowRun;
        $locator = WorkflowRun::query()
            ->select(['id', 'workflow_id', 'workflow_copilot_session_id', 'context_json'])
            ->find($runId);

        if (! $locator) {
            throw (new \Illuminate\Database\Eloquent\ModelNotFoundException)->setModel(WorkflowRun::class, [$runId]);
        }

        $sessionId = (int) (
            $locator->workflow_copilot_session_id
            ?: data_get($locator->context_json, 'workflow_copilot_session_id', 0)
        );

        if ($sessionId <= 0) {
            $this->advanceRun($runId);

            return;
        }

        DB::transaction(function () use ($locator, $sessionId): void {
            $workflow = Workflow::query()->lockForUpdate()->findOrFail($locator->workflow_id);
            $session = WorkflowCopilotSession::query()->lockForUpdate()->find($sessionId);
            $run = WorkflowRun::query()->lockForUpdate()->findOrFail($locator->id);
            $context = is_array($run->context_json) ? $run->context_json : [];
            $contextSessionId = (int) ($context['workflow_copilot_session_id'] ?? 0);

            $identityMatches = $session
                && (int) $session->workflow_id === (int) $workflow->id
                && (int) $run->workflow_id === (int) $workflow->id
                && (int) $run->workflow_copilot_session_id === (int) $session->id
                && $contextSessionId === (int) $session->id;
            $systemTarget = $identityMatches
                && $session->execution_target === WorkflowCopilotSession::EXECUTION_TARGET_SYSTEM
                && ($context['execution_target'] ?? null) === 'system';
            $ownerMatches = $systemTarget
                && (! Schema::hasColumn('workflows', 'active_workflow_copilot_session_id')
                    || (int) $workflow->active_workflow_copilot_session_id === (int) $session->id);
            $statusAllowsExecution = $ownerMatches && in_array($session->status, [
                WorkflowCopilotSession::STATUS_RUNNING,
                WorkflowCopilotSession::STATUS_REPAIRING,
                WorkflowCopilotSession::STATUS_VERIFYING,
            ], true);

            if (! $statusAllowsExecution) {
                if ($session?->status === WorkflowCopilotSession::STATUS_STOPPED
                    && ! $this->isFinalStatus((string) $run->status)
                    && ! $run->stepRuns()->exists()) {
                    $message = 'Workflow-Lauf wurde gestoppt, bevor der erste System-Task gestartet wurde.';
                    $run->forceFill([
                        'status' => 'cancelled',
                        'current_workflow_step_id' => null,
                        'finished_at' => now(),
                        'result_json' => [
                            'ok' => false,
                            'status' => 'cancelled',
                            'statusMessage' => $message,
                            'source' => 'workflow-copilot-advance-guard',
                        ],
                        'error_message' => $message,
                    ])->save();
                }

                return;
            }

            unset($context['copilot_advance_blocked']);
            $run->forceFill(['context_json' => $context])->save();
            $this->advanceRun($run);
        });
    }

    protected function advanceRun(int|WorkflowRun $workflowRun): void
    {
        $run = $this->loadRun($workflowRun);

        if ($this->isFinalStatus($run->status)) {
            return;
        }

        if (in_array($run->status, ['stop_requested', 'unreachable'], true)) {
            return;
        }

        if ($run->status === 'paused') {
            return;
        }

        $runContext = is_array($run->context_json) ? $run->context_json : [];
        if ((bool) ($runContext['manual_pause_requested'] ?? false)
            && ! $run->stepRuns()->whereIn('status', ['running', 'waiting'])->exists()) {
            $this->holdManualPause($run, $runContext);

            return;
        }

        if ($this->usesClientController($run) && ! $this->assignClientExecutionTarget($run)) {
            return;
        }

        $run = $this->loadRun($run->id);

        if ($this->preserveHeldCopilotCheckpoint($run)) {
            return;
        }

        if (! $run->started_at) {
            $run->forceFill([
                'status' => 'running',
                'started_at' => now(),
            ])->save();
        } elseif ($run->status === 'waiting') {
            $run->forceFill(['status' => 'running'])->save();
        }

        if ($this->shouldStartClientWorkflowBundle($run) && $this->startClientControllerWorkflowRun($run)) {
            return;
        }

        if ($this->hasActiveClientWorkflowBundleJob($run)) {
            return;
        }

        $activeStepRun = $run->stepRuns()
            ->whereIn('status', ['running', 'waiting'])
            ->first();

        if ($activeStepRun) {
            $this->scheduleMonitor($activeStepRun);

            return;
        }

        try {
            $step = $this->nextStepForRun($run);
        } catch (\Throwable $exception) {
            $this->failRun($run, $exception->getMessage());

            return;
        }

        if (! $step) {
            $this->completeRun($run);

            return;
        }

        if ($this->holdForStudioCopilotAuthorization($run, $step)) {
            return;
        }

        $stepRun = WorkflowStepRun::query()
            ->where('workflow_run_id', $run->id)
            ->where('workflow_step_id', $step->id)
            ->first();

        if ($stepRun && $stepRun->status === 'failed') {
            $this->failRun($run, $stepRun->error_message ?: 'Workflow-Schritt ist fehlgeschlagen.');

            return;
        }

        $stepRun = $stepRun ?: $this->createStepRun($run, $step);

        try {
            $this->executeStep($run, $step, $stepRun);
        } catch (\Throwable $exception) {
            $this->failStepRun($stepRun, $exception->getMessage());
            $this->failRun($run, $exception->getMessage());
        }
    }

    public function refresh(int|WorkflowRun $workflowRun): void
    {
        $run = $this->loadRun($workflowRun);

        if ($this->isFinalStatus($run->status)) {
            return;
        }

        $activeStepRun = $run->stepRuns()
            ->whereIn('status', ['running', 'waiting'])
            ->latest('id')
            ->first();

        if ($activeStepRun && trim((string) $activeStepRun->external_run_id) !== '') {
            $this->monitorStepRun($activeStepRun->id);

            return;
        }

        $this->advance($run);
    }

    public function requestManualPause(int|WorkflowRun $workflowRun): array
    {
        $run = $this->loadRun($workflowRun);

        if ($this->isFinalStatus((string) $run->status)) {
            return ['ok' => false, 'message' => 'Der Workflow-Lauf ist bereits beendet.'];
        }

        if ($run->status === 'paused') {
            return ['ok' => true, 'message' => 'Der Workflow-Lauf ist bereits pausiert.'];
        }

        $context = is_array($run->context_json) ? $run->context_json : [];
        $context['manual_pause_requested'] = true;
        $context['manual_pause_requested_at'] = now()->toIso8601String();
        $run->forceFill(['context_json' => $context])->save();

        if (! $run->stepRuns()->whereIn('status', ['running', 'waiting'])->exists()
            && ! $this->hasActiveClientWorkflowBundleJob($run)) {
            $this->holdManualPause($run, $context);

            return ['ok' => true, 'message' => 'Workflow-Lauf wurde pausiert.'];
        }

        return ['ok' => true, 'message' => 'Pause ist angefordert und greift nach der aktuell laufenden Task.'];
    }

    public function resumeManualPause(
        int|WorkflowRun $workflowRun,
        ?int $workflowStepId = null,
        ?string $taskKey = null,
        bool $singleTask = false,
    ): array {
        $run = $this->loadRun($workflowRun);

        if ($run->status !== 'paused') {
            return ['ok' => false, 'message' => 'Nur ein pausierter Workflow-Lauf kann fortgesetzt werden.'];
        }

        $context = is_array($run->context_json) ? $run->context_json : [];
        unset(
            $context['manual_pause_requested'],
            $context['manual_pause_requested_at'],
            $context['manual_pause_checkpoint'],
        );

        if ($singleTask) {
            $context['studio_single_task'] = true;
        } else {
            unset($context['studio_single_task']);
        }

        $taskKey = trim((string) $taskKey);
        if ($workflowStepId && $taskKey !== '') {
            $step = $run->workflow->steps->firstWhere('id', $workflowStepId);
            $taskExists = $step && collect($step->task_cards)->contains(
                fn (array $task): bool => trim((string) ($task['key'] ?? '')) === $taskKey,
            );

            if (! $taskExists) {
                throw new \InvalidArgumentException('Der ausgewaehlte Fortsetzungs-Task existiert nicht mehr.');
            }

            $context['next_task_key'] = $taskKey;
            unset($context['next_step_action_key']);
            $run->current_workflow_step_id = $workflowStepId;

            $run->stepRuns()
                ->where('workflow_step_id', $workflowStepId)
                ->update([
                    'status' => 'queued',
                    'external_run_type' => null,
                    'external_run_id' => null,
                    'finished_at' => null,
                    'duration_ms' => null,
                    'error_message' => null,
                ]);
        } elseif ((int) $run->current_workflow_step_id > 0 && trim((string) ($context['next_task_key'] ?? '')) !== '') {
            $run->stepRuns()
                ->where('workflow_step_id', (int) $run->current_workflow_step_id)
                ->where('status', 'waiting')
                ->update([
                    'status' => 'queued',
                    'external_run_type' => null,
                    'external_run_id' => null,
                    'finished_at' => null,
                    'duration_ms' => null,
                    'error_message' => null,
                ]);
        }

        $run->forceFill([
            'status' => 'running',
            'context_json' => $context,
            'finished_at' => null,
            'error_message' => null,
        ])->save();

        RunWorkflowJob::dispatch($run->id);

        return [
            'ok' => true,
            'message' => $singleTask
                ? 'Die ausgewählte Task wird einmal ausgeführt; danach pausiert der Lauf wieder.'
                : ($taskKey !== '' ? 'Workflow-Lauf wird ab dem ausgewaehlten Task kontinuierlich fortgesetzt.' : 'Workflow-Lauf wird kontinuierlich fortgesetzt.'),
        ];
    }

    public function runManualProbe(
        int|WorkflowRun $workflowRun,
        array $task,
        ?int $workflowStepId = null,
    ): array {
        $run = $this->loadRun($workflowRun);

        if ($run->status !== 'paused') {
            return ['ok' => false, 'message' => 'Probeaktionen sind ausschliesslich im pausierten Zustand zulaessig.'];
        }

        if (! is_array($task) || trim((string) ($task['task_key'] ?? '')) === '') {
            throw new \InvalidArgumentException('Die Probeaktion besitzt keinen gueltigen Task-Typ.');
        }

        $stepId = $workflowStepId ?: (int) $run->current_workflow_step_id;
        $step = $run->workflow->steps->firstWhere('id', $stepId) ?: $run->workflow->steps->first();

        if (! $step) {
            throw new \RuntimeException('Fuer die Probeaktion ist kein Workflow-Schritt vorhanden.');
        }

        $task['key'] = trim((string) ($task['key'] ?? 'studio-probe-'.Str::lower(Str::random(8))));
        $context = is_array($run->context_json) ? $run->context_json : [];
        $context['interactive_debug'] = true;
        $context['copilot_transient_task'] = $task;
        $context['studio_probe'] = [
            'task_key' => $task['key'],
            'task' => $task,
            'return_cursor' => [
                'workflow_step_id' => $run->current_workflow_step_id,
                'next_task_key' => $context['next_task_key'] ?? null,
            ],
            'started_at' => now()->toIso8601String(),
        ];
        unset($context['manual_pause_requested'], $context['manual_pause_checkpoint']);

        $stepRun = $run->stepRuns()->firstOrCreate(
            ['workflow_step_id' => $step->getKey()],
            ['status' => 'queued', 'logs_json' => [], 'result_json' => []],
        );
        $stepRun->forceFill([
            'status' => 'queued',
            'external_run_type' => null,
            'external_run_id' => null,
            'started_at' => null,
            'finished_at' => null,
            'duration_ms' => null,
            'error_message' => null,
        ])->save();
        $run->forceFill([
            'status' => 'running',
            'current_workflow_step_id' => $step->getKey(),
            'context_json' => $context,
            'finished_at' => null,
            'error_message' => null,
        ])->save();

        RunWorkflowJob::dispatch($run->id);

        return ['ok' => true, 'message' => 'Probeaktion wurde gestartet.', 'task_key' => $task['key']];
    }

    public function cancel(int|WorkflowRun $workflowRun, string $message = 'Workflow-Lauf wurde manuell gestoppt.'): array
    {
        $run = $this->loadRun($workflowRun);

        if ($this->isFinalStatus($run->status)) {
            return ['ok' => true, 'message' => 'Workflow-Lauf ist bereits beendet.'];
        }

        $clientJob = NetworkJob::query()
            ->where('workflow_run_id', $run->id)
            ->whereIn('type', ['workflow_task', 'workflow_run'])
            ->whereIn('status', ['pending', 'dispatched', 'unreachable', 'stop_requested'])
            ->latest('id')
            ->first();

        if ($clientJob) {
            if ($clientJob->status === 'pending') {
                $cancelledAt = now();
                $clientJob->forceFill([
                    'status' => 'cancelled',
                    'completed_at' => $cancelledAt,
                    'error_message' => $message,
                ])->save();
                $run->stepRuns()->whereIn('status', ['queued', 'waiting'])->update([
                    'status' => 'cancelled',
                    'finished_at' => $cancelledAt,
                    'error_message' => $message,
                ]);
                $run->forceFill([
                    'status' => 'cancelled',
                    'current_workflow_step_id' => null,
                    'finished_at' => $cancelledAt,
                    'result_json' => [
                        'state' => 'cancelled',
                        'statusMessage' => $message,
                        'source' => 'ai-user-factory-before-dispatch',
                    ],
                    'error_message' => $message,
                ])->save();
                $this->releaseClientReservation($run);

                return ['ok' => true, 'message' => 'Der noch nicht gestartete Client-Job wurde abgebrochen.'];
            }

            $this->requestClientJobStop($clientJob, $message, 'cancelled');
            $run->forceFill([
                'status' => 'stop_requested',
                'result_json' => array_replace(is_array($run->result_json) ? $run->result_json : [], [
                    'state' => 'stop_requested',
                    'statusMessage' => $message,
                    'source' => 'ai-user-factory-control',
                    'stopRequestedAt' => now()->toIso8601String(),
                ]),
            ])->save();

            return ['ok' => true, 'message' => 'Stop-Befehl wurde an den ClientController uebermittelt.', 'pendingClientConfirmation' => true];
        }

        $cancelledAt = now();
        $stepRuns = $run->stepRuns()
            ->whereIn('status', ['running', 'waiting'])
            ->get();

        foreach ($stepRuns as $stepRun) {
            $this->cancelExternalRun($stepRun, $message);

            $startedAt = $stepRun->started_at instanceof Carbon ? $stepRun->started_at : $cancelledAt;
            $result = array_replace(is_array($stepRun->result_json) ? $stepRun->result_json : [], [
                'ok' => false,
                'status' => 'cancelled',
                'statusLevel' => 'cancelled',
                'statusMessage' => $message,
                'cancelledAt' => $cancelledAt->toIso8601String(),
            ]);

            $stepRun->forceFill([
                'status' => 'cancelled',
                'finished_at' => $cancelledAt,
                'duration_ms' => max(0, $startedAt->diffInMilliseconds($cancelledAt)),
                'result_json' => $this->publicRunSnapshot($result),
                'logs_json' => $this->logsFromExternalStatus($result),
                'error_message' => $message,
            ])->save();
        }

        $durationMs = $this->workflowRunDurationMs($run, $cancelledAt);

        $run->forceFill([
            'status' => 'cancelled',
            'current_workflow_step_id' => null,
            'finished_at' => $cancelledAt,
            'duration_ms' => $durationMs,
            'result_json' => array_replace(is_array($run->result_json) ? $run->result_json : [], [
                'ok' => false,
                'status' => 'cancelled',
                'statusMessage' => $message,
                'cancelledAt' => $cancelledAt->toIso8601String(),
                'durationMs' => $durationMs,
                'duration_ms' => $durationMs,
            ], $this->workflowReturnPayload($run)),
            'error_message' => $message,
        ])->save();

        $this->releaseClientReservation($run);
        $this->closeWorkflowTaskProcesses($run, $message);

        return ['ok' => true, 'message' => $message, 'cancelledStepRuns' => $stepRuns->count()];
    }

    public function terminate(int|WorkflowRun $workflowRun, string $message = 'Workflow-Lauf und zugehoerige Node-Prozesse wurden beendet.'): array
    {
        $run = $this->loadRun($workflowRun);
        $terminatedAt = now();
        $message = trim($message) ?: 'Workflow-Lauf und zugehoerige Node-Prozesse wurden beendet.';
        $clientJobs = NetworkJob::query()
            ->where('workflow_run_id', $run->id)
            ->whereIn('type', ['workflow_task', 'workflow_run'])
            ->whereIn('status', ['pending', 'dispatched', 'unreachable', 'stop_requested'])
            ->get();
        $pendingClientConfirmation = false;

        foreach ($clientJobs as $clientJob) {
            if ($clientJob->status === 'pending') {
                $clientJob->forceFill([
                    'status' => 'cancelled',
                    'completed_at' => $terminatedAt,
                    'error_message' => $message,
                ])->save();

                continue;
            }

            $this->requestClientJobStop($clientJob, $message, 'cancelled', true);
            $pendingClientConfirmation = true;
        }

        $terminatedExternalRuns = 0;
        $terminationErrors = [];
        $stepRuns = $run->stepRuns()->get();

        foreach ($stepRuns as $stepRun) {
            try {
                if ($this->terminateExternalRun($stepRun, $message)) {
                    $terminatedExternalRuns++;
                }
            } catch (\Throwable $exception) {
                report($exception);
                $terminationErrors[] = [
                    'workflow_step_run_id' => (int) $stepRun->getKey(),
                    'external_run_type' => (string) $stepRun->external_run_type,
                    'message' => $exception->getMessage(),
                ];
            }
        }

        if ($pendingClientConfirmation) {
            if (! $this->isFinalStatus((string) $run->status)) {
                $run->forceFill([
                    'status' => 'stop_requested',
                    'result_json' => array_replace(is_array($run->result_json) ? $run->result_json : [], [
                        'state' => 'stop_requested',
                        'statusMessage' => $message,
                        'source' => 'ai-user-factory-force-control',
                        'forceStopRequestedAt' => $terminatedAt->toIso8601String(),
                    ]),
                    'error_message' => $message,
                ])->save();
            }

            return [
                'ok' => $terminationErrors === [],
                'message' => 'Beenden wurde an den ClientController uebermittelt; der zugehoerige Prozessbaum wird dort erzwungen beendet.',
                'pendingClientConfirmation' => true,
                'terminatedExternalRuns' => $terminatedExternalRuns,
                'errors' => $terminationErrors,
            ];
        }

        $stepRunsToCancel = $stepRuns->filter(fn (WorkflowStepRun $stepRun): bool => in_array(
            (string) $stepRun->status,
            ['queued', 'running', 'waiting', 'stop_requested'],
            true,
        ));

        foreach ($stepRunsToCancel as $stepRun) {
            $startedAt = $stepRun->started_at instanceof Carbon ? $stepRun->started_at : $terminatedAt;
            $stepRun->forceFill([
                'status' => 'cancelled',
                'finished_at' => $terminatedAt,
                'duration_ms' => max(0, $startedAt->diffInMilliseconds($terminatedAt)),
                'result_json' => $this->publicRunSnapshot(array_replace(
                    is_array($stepRun->result_json) ? $stepRun->result_json : [],
                    [
                        'ok' => false,
                        'status' => 'cancelled',
                        'statusLevel' => 'cancelled',
                        'statusMessage' => $message,
                        'forceTerminatedAt' => $terminatedAt->toIso8601String(),
                    ],
                )),
                'error_message' => $message,
            ])->save();
        }

        $alreadyFinal = $this->isFinalStatus((string) $run->status);
        $terminationSummary = [
            'at' => $terminatedAt->toIso8601String(),
            'message' => $message,
            'external_runs' => $terminatedExternalRuns,
            'errors' => $terminationErrors,
        ];

        if ($alreadyFinal) {
            $run->forceFill([
                'result_json' => array_replace(is_array($run->result_json) ? $run->result_json : [], [
                    'process_termination' => $terminationSummary,
                ]),
            ])->save();
        } else {
            $durationMs = $this->workflowRunDurationMs($run, $terminatedAt);
            $run->forceFill([
                'status' => 'cancelled',
                'current_workflow_step_id' => null,
                'finished_at' => $terminatedAt,
                'duration_ms' => $durationMs,
                'result_json' => array_replace(is_array($run->result_json) ? $run->result_json : [], [
                    'ok' => false,
                    'status' => 'cancelled',
                    'statusMessage' => $message,
                    'forceTerminatedAt' => $terminatedAt->toIso8601String(),
                    'durationMs' => $durationMs,
                    'duration_ms' => $durationMs,
                    'process_termination' => $terminationSummary,
                ], $this->workflowReturnPayload($run)),
                'error_message' => $message,
            ])->save();
        }

        $this->releaseClientReservation($run);

        return [
            'ok' => $terminationErrors === [],
            'message' => $alreadyFinal
                ? 'Zugehoerige Node-Prozesse des bereits beendeten Workflow-Laufs wurden bereinigt.'
                : $message,
            'alreadyFinal' => $alreadyFinal,
            'terminatedExternalRuns' => $terminatedExternalRuns,
            'cancelledStepRuns' => $stepRunsToCancel->count(),
            'errors' => $terminationErrors,
        ];
    }

    public function terminateCopilotRuns(WorkflowCopilotSession $session, string $message = 'Copilot-Sitzung und zugehoerige Node-Prozesse wurden beendet.'): array
    {
        $runIds = $session->runs()->pluck('id')
            ->when($session->active_workflow_run_id, fn ($ids) => $ids->push((int) $session->active_workflow_run_id))
            ->filter(fn (mixed $id): bool => (int) $id > 0)
            ->unique()
            ->values();
        $results = $runIds
            ->map(fn (mixed $runId): array => $this->terminate((int) $runId, $message))
            ->all();

        return [
            'ok' => collect($results)->every(fn (array $result): bool => (bool) ($result['ok'] ?? false)),
            'message' => $runIds->isEmpty()
                ? 'Copilot-Sitzung wurde beendet; es waren keine zugeordneten Workflow-Laeufe vorhanden.'
                : $runIds->count().' Copilot-Testlauf/-laeufe und deren zugehoerige Node-Prozesse wurden beendet.',
            'runCount' => $runIds->count(),
            'results' => $results,
        ];
    }

    public function deleteQueued(int|WorkflowRun $workflowRun): array
    {
        $run = $this->loadRun($workflowRun);

        if ($run->status !== 'queued') {
            return [
                'ok' => false,
                'message' => 'Nur eingeplante Workflow-Laeufe koennen direkt geloescht werden. Laufende Laeufe bitte zuerst stoppen.',
            ];
        }

        $runId = $run->id;
        $run->delete();

        return [
            'ok' => true,
            'message' => 'Workflow-Lauf #'.$runId.' wurde geloescht.',
        ];
    }

    public function monitorStepRun(int $workflowStepRunId): void
    {
        $stepRun = WorkflowStepRun::query()
            ->with(['workflowRun.workflow.steps', 'workflowStep'])
            ->find($workflowStepRunId);

        if (! $stepRun || ! in_array($stepRun->status, ['running', 'waiting'], true)) {
            return;
        }

        if ($stepRun->external_run_type === 'client-controller-workflow-run') {
            // Full client workflows are advanced exclusively by client progress/result callbacks.
            return;
        }

        if ($this->isWaitingAtCopilotCheckpoint($stepRun)) {
            // The supervisor owns a held checkpoint. Delayed monitor jobs must
            // not reinterpret a cleared external run id as a second failure.
            return;
        }

        $isClientControllerStep = in_array($stepRun->external_run_type, ['client-controller-workflow-task', 'client-controller-workflow-run'], true);

        if (! $isClientControllerStep && $this->stepRunTimedOut($stepRun)) {
            $this->expireStepRun($stepRun);

            return;
        }

        $status = $this->readExternalStatus($stepRun);

        if (! is_array($status)) {
            $message = 'Der externe Node-Lauf konnte nicht gelesen werden.';

            if ($this->isCopilotSupervisedRun($stepRun->workflowRun)) {
                $this->holdCopilotTaskCheckpoint($stepRun, [], [
                    'ok' => false,
                    'status' => 'failed',
                    'statusMessage' => $message,
                ], false);

                return;
            }

            $this->failStepRun($stepRun, $message);
            $this->continueAfterStep($stepRun->workflowRun, $stepRun, ['ok' => false, 'statusMessage' => $message], 'failed');

            return;
        }

        $this->ingestDebugArtifacts($stepRun, $status);

        if ($this->externalStillRunning($status)) {
            if (! $isClientControllerStep && $this->stepRunTimedOut($stepRun)) {
                $this->expireStepRun($stepRun);

                return;
            }

            $stepRun->forceFill([
                'status' => 'waiting',
                'result_json' => $this->publicRunSnapshot($status),
                'logs_json' => $this->logsFromExternalStatus($status),
            ])->save();

            $this->scheduleMonitor($stepRun, (int) ($status['livePreviewPollIntervalSeconds'] ?? $status['livePreviewIntervalSeconds'] ?? 10));

            return;
        }

        $result = $this->prepareExternalResult($stepRun, $this->readExternalResult($stepRun, $status));
        $result = $this->normalizeStepResult($stepRun, $result, $status);
        $this->ingestDebugArtifacts($stepRun, $status, $result);

        $clientReportedStatus = strtolower(trim((string) ($result['status'] ?? $result['state'] ?? '')));
        if ($stepRun->external_run_type === 'client-controller-workflow-task'
            && $stepRun->workflowRun->status === 'stop_requested'
            && in_array($clientReportedStatus, ['cancelled', 'canceled', 'stopped'], true)) {
            $finishedAt = $this->clientReportedAt($result, ['finishedAt', 'finished_at', 'cancelledAt', 'cancelled_at']) ?: now();
            $message = (string) ($result['statusMessage'] ?? $result['message'] ?? 'ClientController-Workflow wurde abgebrochen.');
            $stepRun->forceFill([
                'status' => 'cancelled',
                'finished_at' => $finishedAt,
                'result_json' => $this->publicRunSnapshot($result),
                'logs_json' => $this->logsFromExternalStatus($result),
                'error_message' => $message,
            ])->save();
            $stepRun->workflowRun->forceFill([
                'status' => 'cancelled',
                'current_workflow_step_id' => null,
                'finished_at' => $finishedAt,
                'result_json' => array_replace($this->publicRunSnapshot($result), ['source' => 'client-controller']),
                'error_message' => $message,
            ])->save();
            $this->releaseClientReservation($stepRun->workflowRun);

            return;
        }

        if (in_array($stepRun->external_run_type, ['workflow-task', 'client-controller-workflow-task'], true)) {
            $this->applyWorkflowVariablesResult($stepRun->workflowRun, $result);
        }

        if (is_array(data_get($stepRun->workflowRun->fresh(), 'context_json.studio_probe'))) {
            $this->holdStudioProbeResult(
                $stepRun,
                $result,
                $this->externalSucceeded($stepRun->workflowStep, $status, $result),
            );

            return;
        }

        if ((bool) data_get($stepRun->workflowRun->context_json, 'interactive_debug', false)) {
            $this->continueInteractiveDebugTask(
                $stepRun,
                $result,
                $this->externalSucceeded($stepRun->workflowStep, $status, $result),
            );

            return;
        }

        if ($this->isCopilotSupervisedRun($stepRun->workflowRun)) {
            $this->holdCopilotTaskCheckpoint(
                $stepRun,
                $status,
                $result,
                $this->externalSucceeded($stepRun->workflowStep, $status, $result),
            );

            return;
        }

        if (! $this->externalSucceeded($stepRun->workflowStep, $status, $result)) {
            $message = (string) (
                data_get($result, 'statusMessage')
                ?: data_get($result, 'message')
                ?: data_get($status, 'message')
                ?: 'Node-Schritt wurde nicht erfolgreich abgeschlossen.'
            );

            $failureOutcome = $this->resultOutcome($result);
            $failureOutcome = in_array($failureOutcome, ['failed', 'timeout'], true) ? $failureOutcome : 'failed';

            if ($this->routeForResult($stepRun->workflowStep, $failureOutcome, $result)) {
                $result['routedOutcome'] = $failureOutcome;
                $result['statusMessage'] = $message;
                $this->completeStepRun($stepRun, $result, $failureOutcome);
                $this->continueAfterStep($stepRun->workflowRun, $stepRun, $result, $failureOutcome);

                return;
            }

            $this->failStepRun($stepRun, $message, $result);
            $this->failRun($stepRun->workflowRun, $message);

            return;
        }

        $result = $this->applyExternalResult($stepRun, $result);
        $outcome = $this->resultOutcome($result);
        $this->completeStepRun($stepRun, $result, 'completed');
        $this->continueAfterStep($stepRun->workflowRun, $stepRun, $result, $outcome, max(0, (int) $stepRun->workflowStep->wait_after_seconds));
    }

    public function resumeCopilotCheckpoint(
        int|WorkflowRun $workflowRun,
        ?string $completedProbeTaskKey = null,
    ): bool {
        $runId = $workflowRun instanceof WorkflowRun ? (int) $workflowRun->id : (int) $workflowRun;
        $continued = $this->withLockedCopilotRun($runId, function (WorkflowRun $run) use ($completedProbeTaskKey): bool {
            $context = is_array($run->context_json) ? $run->context_json : [];
            $checkpoint = is_array($context['copilot_checkpoint'] ?? null) ? $context['copilot_checkpoint'] : [];

            if ($checkpoint === []) {
                return false;
            }

            $stepRun = WorkflowStepRun::query()
                ->where('workflow_run_id', $run->id)
                ->where('workflow_step_id', (int) ($checkpoint['workflow_step_id'] ?? $run->current_workflow_step_id))
                ->lockForUpdate()
                ->first();

            if (! $stepRun) {
                throw new \RuntimeException('Der Copilot-Checkpoint verweist auf keinen Workflow-Schritt.');
            }

            $action = trim((string) ($checkpoint['next_action'] ?? ''));
            $result = is_array($checkpoint['result'] ?? null) ? $checkpoint['result'] : [];
            $outcome = trim((string) ($checkpoint['outcome'] ?? 'success')) ?: 'success';
            $probeTaskKey = trim((string) $completedProbeTaskKey);

            if ($probeTaskKey !== '') {
                if (($checkpoint['kind'] ?? null) !== 'probe' || ! (bool) ($checkpoint['successful'] ?? false)) {
                    throw new \RuntimeException('Nur ein erfolgreicher Copilot-Probe-Checkpoint kann als Original-Task fortgesetzt werden.');
                }

                $step = $stepRun->workflowStep;
                $originalTask = collect($step->task_cards)->firstWhere('key', $probeTaskKey);

                if (! is_array($originalTask)) {
                    throw new \RuntimeException('Die Original-Task der erfolgreichen Copilot-Probe wurde nicht gefunden.');
                }

                $result = $this->probeResultAsOriginalTask(
                    $result,
                    $checkpoint,
                    $probeTaskKey,
                    $originalTask,
                );
                $outcome = 'success';
                [$action, $nextTaskKey] = $this->successfulCheckpointContinuation(
                    $step,
                    $probeTaskKey,
                    $result,
                );
            } else {
                $nextTaskKey = trim((string) ($checkpoint['next_task_key'] ?? ''));
            }

            unset(
                $context['copilot_checkpoint'],
                $context['copilot_transient_task'],
                $context['copilot_probe_plan'],
                $context['copilot_repair_plan'],
                $context['copilot_segment_started_at'],
            );

            if ($action === 'next_task') {
                if ($nextTaskKey === '') {
                    throw new \RuntimeException('Der naechste Copilot-Task fehlt im Checkpoint.');
                }

                $context['next_task_key'] = $nextTaskKey;
                $context['copilot_current_task_key'] = $nextTaskKey;
                $run->forceFill([
                    'status' => 'running',
                    'current_workflow_step_id' => (int) $stepRun->workflow_step_id,
                    'context_json' => $context,
                ])->save();
                $stepRun->forceFill([
                    'status' => 'queued',
                    'external_run_type' => null,
                    'external_run_id' => null,
                    'finished_at' => null,
                    'duration_ms' => null,
                    'error_message' => null,
                ])->save();

                RunWorkflowJob::dispatch($run->id);

                return true;
            }

            if ($action !== 'complete_step') {
                throw new \RuntimeException('Der Copilot-Checkpoint kann mit next_action='.$action.' nicht fortgesetzt werden.');
            }

            unset($context['next_task_key'], $context['copilot_current_task_key']);
            $run->forceFill([
                'status' => 'running',
                'context_json' => $context,
            ])->save();
            $this->completeStepRun(
                $stepRun,
                $result,
                in_array($outcome, ['failed', 'timeout'], true) ? $outcome : 'completed',
            );
            $this->continueAfterStep(
                $run,
                $stepRun,
                $result,
                $outcome,
                max(0, (int) $stepRun->workflowStep->wait_after_seconds),
            );

            return true;
        });

        return $continued === true;
    }

    public function skipResolvedCopilotTask(
        int|WorkflowRun $workflowRun,
        string $taskKey,
    ): bool {
        $taskKey = trim($taskKey);

        if ($taskKey === '') {
            throw new \InvalidArgumentException('Task-Key fuer das erledigte Copilot-Hindernis fehlt.');
        }

        $runId = $workflowRun instanceof WorkflowRun ? (int) $workflowRun->id : (int) $workflowRun;
        $prepared = $this->withLockedCopilotRun($runId, function (WorkflowRun $run) use ($taskKey): bool {
            $context = is_array($run->context_json) ? $run->context_json : [];
            $checkpoint = is_array($context['copilot_checkpoint'] ?? null) ? $context['copilot_checkpoint'] : [];

            if ($checkpoint === [] || trim((string) ($checkpoint['task_key'] ?? '')) !== $taskKey) {
                throw new \RuntimeException('Der Copilot-Checkpoint passt nicht zum erledigten Hindernis.');
            }

            $stepRun = WorkflowStepRun::query()
                ->where('workflow_run_id', $run->id)
                ->where('workflow_step_id', (int) ($checkpoint['workflow_step_id'] ?? $run->current_workflow_step_id))
                ->lockForUpdate()
                ->first();

            if (! $stepRun) {
                throw new \RuntimeException('Der Workflow-Schritt des erledigten Hindernisses wurde nicht gefunden.');
            }

            $step = $stepRun->workflowStep;
            $task = collect($step->task_cards)->firstWhere('key', $taskKey);

            if (! is_array($task)) {
                throw new \RuntimeException('Die Task des erledigten Hindernisses wurde nicht gefunden.');
            }

            $result = is_array($checkpoint['result'] ?? null) ? $checkpoint['result'] : [];
            $result['tasks'] = collect(is_array($result['tasks'] ?? null) ? $result['tasks'] : [])
                ->map(function (mixed $resultTask) use ($taskKey): mixed {
                    if (! is_array($resultTask)
                        || ! in_array($taskKey, [
                            trim((string) ($resultTask['key'] ?? '')),
                            trim((string) ($resultTask['parent_task_key'] ?? '')),
                        ], true)) {
                        return $resultTask;
                    }

                    return array_replace($resultTask, [
                        'ok' => true,
                        'status' => 'skipped',
                        'statusMessage' => 'Optionales Hindernis ist laut aktueller Bild- und DOM-Evidenz nicht mehr vorhanden.',
                        'error' => null,
                    ]);
                })
                ->values()
                ->all();
            unset(
                $result['failedTaskKey'],
                $result['failed_task_key'],
                $result['routeRequested'],
                $result['route_requested'],
                $result['routeOutcome'],
                $result['route_outcome'],
            );
            $result = array_replace($result, [
                'ok' => true,
                'status' => 'skipped',
                'technicalSuccess' => true,
                'technical_success' => true,
                'completedTaskKey' => $taskKey,
                'completed_task_key' => $taskKey,
                'statusMessage' => 'Optionales Hindernis ist laut aktueller Bild- und DOM-Evidenz nicht mehr vorhanden.',
            ]);

            if (is_array($task['next'] ?? null)) {
                $result['routeRequested'] = true;
                $result['route_requested'] = true;
                $result['routeOutcome'] = 'success';
                $result['route_outcome'] = 'success';
            }

            [$nextAction, $nextTaskKey] = $this->successfulCheckpointContinuation($step, $taskKey, $result);
            $checkpoint = array_replace($checkpoint, [
                'successful' => true,
                'outcome' => 'success',
                'next_action' => $nextAction,
                'next_task_key' => $nextTaskKey,
                'result' => $result,
                'resolved_obstacle' => true,
            ]);
            $context['copilot_checkpoint'] = $checkpoint;
            $stepRun->forceFill(['result_json' => $result])->save();
            $run->forceFill(['context_json' => $context])->save();

            return true;
        });

        return $prepared === true && $this->resumeCopilotCheckpoint($runId);
    }

    public function retryCopilotTask(
        int|WorkflowRun $workflowRun,
        string $taskKey,
        ?array $transientTask = null,
        array $repairPlan = [],
    ): void {
        $taskKey = trim($taskKey);

        if ($taskKey === '') {
            throw new \InvalidArgumentException('Task-Key fuer den Copilot-Wiederholungsversuch fehlt.');
        }

        $runId = $workflowRun instanceof WorkflowRun ? (int) $workflowRun->id : (int) $workflowRun;
        $this->withLockedCopilotRun(
            $runId,
            function (WorkflowRun $run) use ($taskKey, $transientTask, $repairPlan): void {
                $context = is_array($run->context_json) ? $run->context_json : [];
                $checkpoint = is_array($context['copilot_checkpoint'] ?? null) ? $context['copilot_checkpoint'] : [];
                $stepId = (int) ($checkpoint['workflow_step_id'] ?? $run->current_workflow_step_id);
                $stepRun = WorkflowStepRun::query()
                    ->where('workflow_run_id', $run->id)
                    ->where('workflow_step_id', $stepId)
                    ->lockForUpdate()
                    ->first();

                if (! $stepRun) {
                    throw new \RuntimeException('Workflow-Schritt fuer den Copilot-Wiederholungsversuch wurde nicht gefunden.');
                }

                $context['execution_target'] = 'system';
                $context['network_node_id'] = null;
                $context['device_id'] = null;
                $context['copilot_supervised'] = true;
                $context['next_task_key'] = $taskKey;
                $context['copilot_current_task_key'] = $taskKey;
                $context['copilot_repair_plan'] = $repairPlan;
                unset($context['copilot_checkpoint']);

                if (is_array($transientTask) && $transientTask !== []) {
                    $context['copilot_transient_task'] = $transientTask;
                    $context['copilot_probe_plan'] = $repairPlan;
                } else {
                    unset($context['copilot_transient_task'], $context['copilot_probe_plan']);
                }

                $run->forceFill([
                    'status' => 'running',
                    'current_workflow_step_id' => $stepId,
                    'context_json' => $context,
                    'finished_at' => null,
                    'error_message' => null,
                ])->save();
                $stepRun->forceFill([
                    'status' => 'queued',
                    'external_run_type' => null,
                    'external_run_id' => null,
                    'started_at' => null,
                    'finished_at' => null,
                    'duration_ms' => null,
                    'error_message' => null,
                ])->save();

                RunWorkflowJob::dispatch($run->id);
            },
        );
    }

    protected function holdCopilotTaskCheckpoint(
        WorkflowStepRun $stepRun,
        array $status,
        array $result,
        bool $successful,
    ): string {
        [$checkpointId, $sessionId] = DB::transaction(
            fn (): array => $this->persistCopilotTaskCheckpoint($stepRun, $status, $result, $successful),
        );

        if ($sessionId > 0) {
            WorkflowCopilotSupervisorJob::dispatch($sessionId);
        }

        return $checkpointId;
    }

    protected function persistCopilotTaskCheckpoint(
        WorkflowStepRun $stepRun,
        array $status,
        array $result,
        bool $successful,
    ): array {
        $run = WorkflowRun::query()->lockForUpdate()->findOrFail($stepRun->workflow_run_id);
        $stepRun = WorkflowStepRun::query()
            ->where('workflow_run_id', $run->id)
            ->whereKey($stepRun->id)
            ->lockForUpdate()
            ->firstOrFail();
        $step = $stepRun->workflowStep;
        $context = is_array($run->context_json) ? $run->context_json : [];
        $resumeTaskKey = trim((string) ($context['copilot_current_task_key'] ?? ''));
        $transientTask = is_array($context['copilot_transient_task'] ?? null) ? $context['copilot_transient_task'] : [];
        $isProbe = $transientTask !== [];

        if ($resumeTaskKey === '') {
            $resumeTaskKey = trim((string) (
                $result['failedTaskKey']
                ?? $result['completedTaskKey']
                ?? data_get($step->task_cards, '0.key', $step->action_key ?: 'step-'.$step->id)
            ));
        }

        $failureTaskKey = trim((string) ($result['failedTaskKey'] ?? $result['failed_task_key'] ?? ''));
        $completedTaskKey = trim((string) ($result['completedTaskKey'] ?? $result['completed_task_key'] ?? ''));

        if ($successful) {
            $result = $this->applyExternalResult($stepRun, $result);
        } elseif (in_array($stepRun->external_run_type, ['workflow-task', 'client-controller-workflow-task', 'client-controller-workflow-run'], true)) {
            $this->applyWorkflowVariablesResult($run, $result);
        }
        $run = $run->fresh();
        $context = is_array($run->context_json) ? $run->context_json : [];

        $outcome = $this->resultOutcome($result);
        $hasFailureOutcome = in_array($outcome, ['failed', 'timeout'], true);
        $failureRoute = $hasFailureOutcome
            ? $this->routeForResult($step, $outcome, $result)
            : null;
        $routedFailure = ! $isProbe
            && $hasFailureOutcome
            && $this->isContinuableFailureRoute($failureRoute);
        $taskSuccessful = $successful && ! $hasFailureOutcome;
        $workflowMayContinue = $taskSuccessful || $routedFailure;
        $context = $this->mergeWorkflowBrowserState($context, $result);
        $nextAction = 'repair';
        $nextTaskKey = null;

        if ($isProbe) {
            $nextAction = 'repair';
        } elseif ($workflowMayContinue) {
            [$nextAction, $nextTaskKey] = $this->successfulCheckpointContinuation(
                $step,
                $resumeTaskKey,
                $result,
            );
        }

        $publicResult = $this->publicRunSnapshot($result);
        $resultSignature = hash('sha256', (string) json_encode(
            $publicResult,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        ));
        $existingCheckpoint = is_array($context['copilot_checkpoint'] ?? null)
            ? $context['copilot_checkpoint']
            : [];
        $reusableCheckpointId = (string) ($existingCheckpoint['id'] ?? '');
        $sameRuntimeResult = $reusableCheckpointId !== ''
            && (int) ($existingCheckpoint['workflow_step_run_id'] ?? 0) === (int) $stepRun->id
            && (int) ($existingCheckpoint['workflow_step_id'] ?? 0) === (int) $step->id
            && (string) ($existingCheckpoint['external_run_id'] ?? '') === (string) $stepRun->external_run_id
            && (string) ($existingCheckpoint['resume_task_key'] ?? $existingCheckpoint['task_key'] ?? '') === $resumeTaskKey
            && (string) ($existingCheckpoint['failure_task_key'] ?? '') === $failureTaskKey
            && (bool) ($existingCheckpoint['successful'] ?? false) === $workflowMayContinue
            && (string) ($existingCheckpoint['outcome'] ?? '') === $outcome
            && (string) ($existingCheckpoint['result_signature'] ?? '') === $resultSignature;
        $checkpoint = [
            'id' => $sameRuntimeResult ? $reusableCheckpointId : (string) Str::uuid(),
            'kind' => $isProbe ? 'probe' : 'regular',
            'workflow_step_id' => (int) $step->id,
            'workflow_step_run_id' => (int) $stepRun->id,
            'workflow_step_name' => (string) $step->name,
            'task_key' => $resumeTaskKey,
            'resume_task_key' => $resumeTaskKey,
            'failure_task_key' => $failureTaskKey !== '' ? $failureTaskKey : null,
            'completed_task_key' => $completedTaskKey !== '' ? $completedTaskKey : null,
            'failure_reason_code' => $result['reason_code'] ?? $result['reasonCode'] ?? null,
            'task_title' => (string) (collect($step->task_cards)->firstWhere('key', $failureTaskKey ?: $resumeTaskKey)['title'] ?? $transientTask['title'] ?? ($failureTaskKey ?: $resumeTaskKey)),
            'successful' => $workflowMayContinue,
            'task_successful' => $taskSuccessful,
            'routed_failure' => $routedFailure,
            'outcome' => $outcome,
            'next_action' => $nextAction,
            'next_task_key' => $nextTaskKey,
            'result' => $publicResult,
            'result_signature' => $resultSignature,
            'status' => $this->publicRunSnapshot($status),
            'external_run_id' => (string) $stepRun->external_run_id,
            'started_at' => (string) ($context['copilot_segment_started_at'] ?? optional($stepRun->started_at)->toIso8601String()),
            'finished_at' => now()->toIso8601String(),
        ];
        $context['copilot_checkpoint'] = $checkpoint;

        $run->forceFill([
            'status' => 'waiting',
            'context_json' => $context,
        ])->save();
        $stepRun->forceFill([
            'status' => 'waiting',
            'result_json' => array_replace($publicResult, [
                'copilotCheckpoint' => true,
                'copilotCheckpointId' => $checkpoint['id'],
            ]),
            'logs_json' => $this->logsFromExternalStatus($result),
            'error_message' => $workflowMayContinue ? null : (string) (
                $result['statusMessage']
                ?? $result['message']
                ?? 'Copilot-Task ist fehlgeschlagen.'
            ),
        ])->save();

        return [
            (string) $checkpoint['id'],
            (int) ($run->workflow_copilot_session_id ?: data_get($context, 'workflow_copilot_session_id', 0)),
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $checkpoint
     * @param  array<string, mixed>  $originalTask
     * @return array<string, mixed>
     */
    protected function probeResultAsOriginalTask(
        array $result,
        array $checkpoint,
        string $originalTaskKey,
        array $originalTask,
    ): array {
        $result['copilotProbeTaskKey'] = trim((string) ($checkpoint['task_key'] ?? ''));
        $result['copilot_probe_task_key'] = $result['copilotProbeTaskKey'];
        $result['completedTaskKey'] = $originalTaskKey;
        $result['completed_task_key'] = $originalTaskKey;

        unset(
            $result['failedTaskKey'],
            $result['failed_task_key'],
            $result['routeRequested'],
            $result['route_requested'],
            $result['routeOutcome'],
            $result['route_outcome'],
        );

        if (is_array($originalTask['next'] ?? null)) {
            $result['routeRequested'] = true;
            $result['route_requested'] = true;
            $result['routeOutcome'] = 'success';
            $result['route_outcome'] = 'success';
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array{0:string,1:?string}
     */
    protected function successfulCheckpointContinuation(
        WorkflowStep $step,
        string $currentTaskKey,
        array $result,
        bool $skipAtomicLoopBody = true,
    ): array {
        $route = $this->routeForResult($step, $this->resultOutcome($result), $result);
        $taskRequestedRoute = trim((string) ($route['_source_card_key'] ?? '')) !== '';
        $routeType = trim((string) ($route['type'] ?? ''));
        $routeStep = trim((string) ($route['action_key'] ?? $route['step'] ?? ''));
        $routeTask = trim((string) ($route['card_key'] ?? $route['card'] ?? ''));

        if ($taskRequestedRoute
            && $routeType === 'card'
            && $routeTask !== ''
            && ($routeStep === '' || $routeStep === $step->action_key)) {
            return ['next_task', $routeTask];
        }

        if ($taskRequestedRoute) {
            return ['complete_step', null];
        }

        $tasks = collect($step->task_cards)->values();
        $currentIndex = $tasks->search(
            fn (array $task): bool => (string) ($task['key'] ?? '') === $currentTaskKey,
        );
        $nextIndex = $currentIndex === false ? null : ((int) $currentIndex) + 1;

        if ($currentIndex !== false) {
            $currentTask = $tasks->get((int) $currentIndex);

            if ($skipAtomicLoopBody && is_array($currentTask) && (string) ($currentTask['task_key'] ?? '') === 'loop.for_each_element') {
                $endKey = trim((string) ($currentTask['loop_end_key'] ?? $currentTask['loopEndKey'] ?? ''));
                $pairId = trim((string) ($currentTask['loop_pair_id'] ?? $currentTask['loopPairId'] ?? ''));
                $endIndex = $tasks->search(function (array $task) use ($endKey, $pairId): bool {
                    return ($endKey !== '' && trim((string) ($task['key'] ?? '')) === $endKey)
                        || ($pairId !== ''
                            && trim((string) ($task['loop_pair_id'] ?? $task['loopPairId'] ?? '')) === $pairId
                            && (string) ($task['task_key'] ?? '') === 'loop.end');
                });

                if ($endIndex !== false && (int) $endIndex >= (int) $currentIndex) {
                    $nextIndex = (int) $endIndex + 1;
                }
            }
        }

        $nextTask = $nextIndex === null ? null : $tasks->get($nextIndex);
        $nextTaskKey = is_array($nextTask) ? trim((string) ($nextTask['key'] ?? '')) : '';

        return $nextTaskKey !== ''
            ? ['next_task', $nextTaskKey]
            : ['complete_step', null];
    }

    protected function continueInteractiveDebugTask(
        WorkflowStepRun $stepRun,
        array $result,
        bool $successful,
    ): void {
        $run = $stepRun->workflowRun->fresh();
        $step = $stepRun->workflowStep;
        $context = is_array($run->context_json) ? $run->context_json : [];
        $currentTaskKey = trim((string) (
            $result['completedTaskKey']
            ?? $result['completed_task_key']
            ?? $result['failedTaskKey']
            ?? $result['failed_task_key']
            ?? $context['next_task_key']
            ?? data_get($step->task_cards, '0.key', '')
        ));

        $outcome = $this->resultOutcome($result);
        $hasFailureOutcome = in_array($outcome, ['failed', 'timeout'], true);
        $failureRoute = $hasFailureOutcome
            ? $this->routeForResult($step, $outcome, $result)
            : null;
        $routedFailure = $hasFailureOutcome && $this->isContinuableFailureRoute($failureRoute);
        $taskSuccessful = $successful && ! $hasFailureOutcome;
        $workflowMayContinue = $taskSuccessful || $routedFailure;

        if ($successful) {
            $result = $this->applyExternalResult($stepRun, $result);
        } elseif (in_array($stepRun->external_run_type, ['workflow-task', 'client-controller-workflow-task', 'client-controller-workflow-run'], true)) {
            $this->applyWorkflowVariablesResult($run, $result);
        }
        $run = $run->fresh();
        $context = is_array($run->context_json) ? $run->context_json : [];

        $studioSingleTask = (bool) ($context['studio_single_task'] ?? false);

        if ($workflowMayContinue) {
            [$nextAction, $nextTaskKey] = $this->successfulCheckpointContinuation(
                $step,
                $currentTaskKey,
                $result,
                ! $studioSingleTask,
            );
        } else {
            $nextAction = 'next_task';
            $nextTaskKey = $currentTaskKey;
        }

        $pauseAfterTask = (bool) ($context['manual_pause_requested'] ?? false) || $studioSingleTask;
        unset($context['studio_single_task']);

        if ($nextAction === 'complete_step' && $workflowMayContinue) {
            unset($context['next_task_key']);

            if ($pauseAfterTask) {
                $context['manual_pause_requested'] = true;
            }

            $run->forceFill(['context_json' => $context])->save();
            $this->completeStepRun($stepRun, $result, $routedFailure ? $outcome : 'completed');
            $this->continueAfterStep($run, $stepRun, $result, $outcome, max(0, (int) $step->wait_after_seconds));

            return;
        }

        if ($nextTaskKey === '') {
            throw new \RuntimeException('Der interaktive Debug-Lauf besitzt keinen gueltigen Fortsetzungs-Task.');
        }

        $context['next_task_key'] = $nextTaskKey;
        $context['manual_debug_last_task'] = [
            'task_key' => $currentTaskKey,
            'successful' => $taskSuccessful,
            'routed_failure' => $routedFailure,
            'recorded_at' => now()->toIso8601String(),
        ];
        $pauseNow = ! $workflowMayContinue || $pauseAfterTask;

        $stepRun->forceFill([
            'status' => $pauseNow ? 'waiting' : 'queued',
            'external_run_type' => null,
            'external_run_id' => null,
            'result_json' => $this->publicRunSnapshot($result),
            'logs_json' => $this->logsFromExternalStatus($result),
            'finished_at' => null,
            'duration_ms' => null,
            'error_message' => $workflowMayContinue ? null : (string) ($result['statusMessage'] ?? $result['message'] ?? 'Task fehlgeschlagen.'),
        ])->save();
        $run->forceFill([
            'status' => $pauseNow ? 'paused' : 'running',
            'current_workflow_step_id' => $step->id,
            'context_json' => $context,
        ])->save();

        if ($pauseNow) {
            $this->holdManualPause($run, $context, $stepRun);

            return;
        }

        RunWorkflowJob::dispatch($run->id);
    }

    protected function holdStudioProbeResult(WorkflowStepRun $stepRun, array $result, bool $successful): void
    {
        $run = $stepRun->workflowRun->fresh();
        $context = is_array($run->context_json) ? $run->context_json : [];
        $probe = is_array($context['studio_probe'] ?? null) ? $context['studio_probe'] : [];
        $returnCursor = is_array($probe['return_cursor'] ?? null) ? $probe['return_cursor'] : [];
        $context['studio_probe_result'] = [
            'successful' => $successful,
            'task' => $probe['task'] ?? null,
            'result' => $this->publicRunSnapshot($result),
            'completed_at' => now()->toIso8601String(),
        ];
        unset($context['studio_probe'], $context['copilot_transient_task']);
        if (filled($returnCursor['next_task_key'] ?? null)) {
            $context['next_task_key'] = $returnCursor['next_task_key'];
        } else {
            unset($context['next_task_key']);
        }

        $stepRun->forceFill([
            'status' => 'waiting',
            'external_run_type' => null,
            'external_run_id' => null,
            'result_json' => $this->publicRunSnapshot($result),
            'logs_json' => $this->logsFromExternalStatus($result),
            'finished_at' => null,
            'duration_ms' => null,
            'error_message' => $successful ? null : (string) ($result['statusMessage'] ?? $result['message'] ?? 'Probeaktion fehlgeschlagen.'),
        ])->save();
        $run->forceFill([
            'status' => 'paused',
            'current_workflow_step_id' => $returnCursor['workflow_step_id'] ?? $stepRun->workflow_step_id,
            'context_json' => $context,
        ])->save();
        if (! $successful) {
            $sessionId = (int) ($run->workflow_studio_session_id ?: data_get($run->context_json, 'workflow_studio_session_id', 0));
            $selector = trim((string) data_get($probe, 'task.selector', data_get($probe, 'task.element_selector', '')));
            $session = $sessionId > 0 ? WorkflowStudioSession::query()->find($sessionId) : null;
            if ($session && $selector !== '') {
                $state = is_array($session->state_json) ? $session->state_json : [];
                $state['failed_selectors'] = array_values(array_unique([...(array) ($state['failed_selectors'] ?? []), $selector]));
                $session->forceFill(['state_json' => $state, 'last_activity_at' => now()])->save();
            }
        }
    }

    protected function holdForStudioCopilotAuthorization(WorkflowRun $run, WorkflowStep $step): bool
    {
        $copilotSessionId = (int) ($run->workflow_copilot_session_id ?: data_get($run->context_json, 'workflow_copilot_session_id', 0));
        if ($copilotSessionId <= 0) {
            return false;
        }

        $studio = WorkflowStudioSession::query()
            ->where('workflow_copilot_session_id', $copilotSessionId)
            ->latest('id')
            ->first();
        if (! $studio) {
            return false;
        }

        $context = is_array($run->context_json) ? $run->context_json : [];
        $taskKey = trim((string) ($context['next_task_key'] ?? ''));
        $task = collect($step->task_cards)->first(fn (array $candidate): bool => $taskKey !== ''
            ? (string) ($candidate['key'] ?? '') === $taskKey
            : true);
        if (! is_array($task)) {
            return false;
        }

        $action = $this->studioPermissionActionForTask($step, $task);
        $parameters = [
            'workflow_run_id' => (int) $run->getKey(),
            'workflow_revision' => (int) $run->workflow_revision,
            'workflow_step_id' => (int) $step->getKey(),
            'task_key' => (string) ($task['key'] ?? ''),
            'task' => $task,
        ];
        $confirmationId = trim((string) ($context['studio_authorization_confirmation_id'] ?? '')) ?: null;
        $authorization = app(WorkflowStudioAuthorizationService::class);
        $decision = $authorization->decide($studio, $action, $parameters, $confirmationId);

        if ($decision['allowed']) {
            if ($confirmationId) {
                $authorization->consume($studio, $confirmationId);
            }
            unset($context['studio_authorization_confirmation_id'], $context['studio_authorization_hold']);
            $run->forceFill(['context_json' => $context])->save();
            $studioState = is_array($studio->state_json) ? $studio->state_json : [];
            if (data_get($studioState, 'pending_copilot_confirmation.confirmation_id') === $confirmationId) {
                $studioState['pending_copilot_confirmation'] = null;
                $studio->forceFill(['state_json' => $studioState, 'status' => 'running', 'last_activity_at' => now()])->save();
            }

            return false;
        }

        $studioState = is_array($studio->state_json) ? $studio->state_json : [];
        $studioState['pending_copilot_confirmation'] = [
            'type' => 'copilot_task',
            'action' => $action,
            'confirmation_id' => $decision['confirmation_id'],
            'message' => 'Copilot moechte Task „'.($task['title'] ?? $task['key'] ?? $task['task_key']).'“ ausfuehren.',
            'parameters' => $parameters,
            'workflow_run_id' => (int) $run->getKey(),
            'workflow_copilot_session_id' => $copilotSessionId,
            'created_at' => now()->toIso8601String(),
        ];
        $studio->forceFill(['state_json' => $studioState, 'status' => 'confirmation_required', 'last_activity_at' => now()])->save();
        $context['studio_authorization_hold'] = $studioState['pending_copilot_confirmation'];
        $run->forceFill(['status' => 'paused', 'context_json' => $context])->save();
        $copilot = WorkflowCopilotSession::query()->find($copilotSessionId);
        if ($copilot && $copilot->status !== WorkflowCopilotSession::STATUS_PAUSED) {
            app(WorkflowCopilotSessionService::class)->pause($copilot, $decision['message']);
        }
        app(WorkflowStudioSessionService::class)->appendEvent($studio, 'authorization.requested', $studioState['pending_copilot_confirmation']['message'], [
            'action' => $action,
            'confirmation_id' => $decision['confirmation_id'],
            'workflow_run_id' => (int) $run->getKey(),
            'task_key' => $task['key'] ?? null,
        ], 'warning');

        return true;
    }

    protected function studioPermissionActionForTask(WorkflowStep $step, array $task): string
    {
        $catalogKey = Str::lower(trim((string) ($task['task_key'] ?? '')));
        $taskText = Str::lower(implode(' ', array_filter([
            $task['key'] ?? null,
            $task['title'] ?? null,
            $task['description'] ?? null,
            $task['selector'] ?? null,
            $task['element_selector'] ?? null,
        ], fn (mixed $value): bool => is_scalar($value))));

        if ($step->type === WorkflowStep::TYPE_MAIL_ACCOUNT_REGISTRATION
            || str_contains($catalogKey, 'register')) {
            return 'external.register';
        }
        if ($catalogKey === 'input.submit' || str_contains($taskText, 'type=submit')) {
            return 'external.send';
        }
        if (str_contains($catalogKey, 'delete') || str_contains($taskText, 'loesch')) {
            return 'external.delete';
        }
        if (str_contains($catalogKey, 'persist') || str_contains($catalogKey, 'send')) {
            return 'external.send';
        }

        return 'workflow.execute_task';
    }

    public function expireTimedOutRuns(): void
    {
        $this->reconcileClientWorkflowJobs();

        WorkflowStepRun::query()
            ->with(['workflowRun.workflow.steps', 'workflowStep'])
            ->whereIn('status', ['running', 'waiting'])
            ->whereNotNull('started_at')
            ->orderBy('started_at')
            ->limit(100)
            ->get()
            ->each(function (WorkflowStepRun $stepRun): void {
                if (in_array($stepRun->external_run_type, ['client-controller-workflow-task', 'client-controller-workflow-run'], true)) {
                    return;
                }

                if ($this->stepRunTimedOut($stepRun)) {
                    $this->expireStepRun($stepRun);
                }
            });
    }

    protected function executeStep(WorkflowRun $run, WorkflowStep $step, WorkflowStepRun $stepRun): string
    {
        $run->forceFill([
            'status' => 'running',
            'current_workflow_step_id' => $step->id,
        ])->save();

        if ($this->usesClientController($run) && $step->task_cards !== []) {
            return $this->startClientControllerWorkflowTask($run, $step, $stepRun);
        }

        if ($step->task_cards !== []) {
            return $this->startWorkflowTaskStep($run, $step, $stepRun);
        }

        return match ($step->type) {
            WorkflowStep::TYPE_MAIL_ACCOUNT_REGISTRATION => $this->startMailRegistrationStep($run, $step, $stepRun),
            WorkflowStep::TYPE_WEBMAIL_LOGIN => $this->startWebmailLoginStep($run, $step, $stepRun),
            WorkflowStep::TYPE_WAIT => $this->completeWaitStep($run, $step, $stepRun),
            default => $this->completePlannedActionStep($run, $step, $stepRun),
        };
    }

    protected function shouldStartClientWorkflowBundle(WorkflowRun $run): bool
    {
        if (
            ! $this->usesClientController($run)
            || (bool) data_get($run->context_json, 'interactive_debug', false)
            || data_get($run->context_json, 'client_bundle_fallback_reasons')
        ) {
            return false;
        }

        if (NetworkJob::query()
            ->where('workflow_run_id', $run->id)
            ->where('type', 'workflow_run')
            ->whereNotIn('status', ['lost'])
            ->exists()) {
            return false;
        }

        $nodeId = (int) data_get($run->context_json, 'network_node_id', 0);
        $node = NetworkNode::query()->find($nodeId);

        return (bool) data_get($node?->capabilities_json, 'workflow_bundle_v1', false);
    }

    protected function hasActiveClientWorkflowBundleJob(WorkflowRun $run): bool
    {
        return NetworkJob::query()
            ->where('workflow_run_id', $run->id)
            ->where('type', 'workflow_run')
            ->whereIn('status', ['pending', 'dispatched', 'stop_requested', 'unreachable'])
            ->exists();
    }

    protected function startClientControllerWorkflowRun(WorkflowRun $run): bool
    {
        $run->loadMissing(['workflow.steps']);
        $node = NetworkNode::query()->find((int) data_get($run->context_json, 'network_node_id', 0));

        if (! $node || ! $node->isAvailable()) {
            throw new \RuntimeException('Der ausgewaehlte ClientController-Node ist nicht verfuegbar.');
        }

        $deviceId = (int) data_get($run->context_json, 'device_id', 0);
        $device = $deviceId > 0 ? Device::query()->find($deviceId) : null;
        $steps = $run->workflow->steps->filter(fn (WorkflowStep $step): bool => $step->is_enabled)->values();

        foreach ($steps as $index => $step) {
            WorkflowStepRun::query()->updateOrCreate([
                'workflow_run_id' => $run->id,
                'workflow_step_id' => $step->id,
            ], [
                'status' => $index === 0 ? 'waiting' : 'queued',
                'external_run_type' => null,
                'external_run_id' => null,
                'started_at' => $index === 0 ? now() : null,
                'finished_at' => null,
                'duration_ms' => null,
                'result_json' => [],
                'logs_json' => [],
                'error_message' => null,
            ]);
        }

        $run->load('stepRuns');
        $compiled = $this->clientBundles->compile($run);

        if (! $compiled['portable']) {
            $run->stepRuns()->delete();
            $context = is_array($run->context_json) ? $run->context_json : [];
            $context['client_bundle_fallback_reasons'] = $compiled['reasons'];
            $run->forceFill(['context_json' => $context])->save();

            return false;
        }

        $networkJob = $this->networkJobs->dispatch(
            $node,
            'workflow_run',
            ['workflow_bundle' => $compiled['bundle']],
            $device,
            null,
            $run->requested_by,
            null,
            $run,
            2,
        );
        $firstStepRun = $run->stepRuns->sortBy('id')->first();

        $firstStepRun?->forceFill([
            'external_run_type' => 'client-controller-workflow-run',
            'external_run_id' => $networkJob->job_uuid,
            'result_json' => [
                'state' => 'queued',
                'message' => 'Vollstaendiger Workflow wurde an den ClientController uebergeben.',
                'networkJobUuid' => $networkJob->job_uuid,
            ],
        ])->save();

        $run->forceFill([
            'status' => 'waiting',
            'current_workflow_step_id' => $steps->first()?->id,
        ])->save();

        return true;
    }

    protected function assignClientExecutionTarget(WorkflowRun $run): bool
    {
        return DB::transaction(function () use ($run): bool {
            $run = WorkflowRun::query()->lockForUpdate()->findOrFail($run->id);
            $context = is_array($run->context_json) ? $run->context_json : [];
            $nodeId = (int) ($context['network_node_id'] ?? 0);
            $deviceId = (int) ($context['device_id'] ?? 0);
            $requestedNodeId = (int) (array_key_exists('requested_network_node_id', $context) ? $context['requested_network_node_id'] : $nodeId);
            $requestedDeviceId = (int) (array_key_exists('requested_device_id', $context) ? $context['requested_device_id'] : $deviceId);

            if ($requestedDeviceId > 0) {
                $device = Device::query()->lockForUpdate()->find($requestedDeviceId);
                $node = $device ? NetworkNode::query()->lockForUpdate()->find($device->network_node_id) : null;

                if ($device && $node && $node->isAvailable()
                    && $device->status === 'online'
                    && $device->last_seen_at?->gte(now()->subSeconds(NetworkNode::heartbeatTimeoutSeconds()))
                    && in_array($node->workflow_reservation_run_id, [null, $run->id], true)
                        && in_array($device->workflow_reservation_run_id, [null, $run->id], true)
                        && $this->clientTargetIsIdle($node->id, $device->id, $run->id)) {
                    $context['network_node_id'] = $node->id;
                    $context['device_id'] = $device->id;
                    $context['requested_device_id'] = $requestedDeviceId;
                    $context['requested_network_node_id'] = $node->id;
                    $node->forceFill(['workflow_reservation_run_id' => $run->id])->save();
                    $device->forceFill(['workflow_reservation_run_id' => $run->id])->save();
                    $run->forceFill(['context_json' => $context])->save();

                    return true;
                }

                return $this->queueForClientCapacity($run, 'Das ausgewaehlte Geraet ist derzeit nicht verfuegbar.');
            }

            if ($requestedNodeId > 0) {
                $node = NetworkNode::query()->lockForUpdate()->find($requestedNodeId);

                if ($node && $node->isAvailable()
                    && in_array($node->workflow_reservation_run_id, [null, $run->id], true)
                    && $this->clientTargetIsIdle($node->id, null, $run->id)) {
                    $context['network_node_id'] = $node->id;
                    $context['device_id'] = null;
                    $context['requested_network_node_id'] = $requestedNodeId;
                    $node->forceFill(['workflow_reservation_run_id' => $run->id])->save();
                    $run->forceFill(['context_json' => $context])->save();

                    return true;
                }

                return $this->queueForClientCapacity($run, 'Der ausgewaehlte Node ist derzeit ausgelastet oder nicht erreichbar.');
            }

            $node = NetworkNode::query()
                ->available()
                ->where(function ($query) use ($run): void {
                    $query->whereNull('workflow_reservation_run_id')->orWhere('workflow_reservation_run_id', $run->id);
                })
                ->whereDoesntHave('jobs', function ($query) use ($run): void {
                    $query->whereIn('type', ['workflow_task', 'workflow_run'])
                        ->whereIn('status', ['pending', 'dispatched', 'stop_requested', 'unreachable'])
                        ->where(function ($query) use ($run): void {
                            $query->whereNull('workflow_run_id')->orWhere('workflow_run_id', '!=', $run->id);
                        });
                })
                ->orderByDesc('last_seen_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if (! $node) {
                return $this->queueForClientCapacity($run, 'Kein freier ClientController-Node verfuegbar; der Workflow bleibt eingereiht.');
            }

            $context['network_node_id'] = $node->id;
            $context['device_id'] = null;
            $context['assignment_source'] = 'automatic';
            $context['assigned_at'] = now()->toIso8601String();
            $node->forceFill(['workflow_reservation_run_id' => $run->id])->save();
            $run->forceFill(['context_json' => $context])->save();

            return true;
        });
    }

    protected function clientTargetIsIdle(int $nodeId, ?int $deviceId, int $workflowRunId): bool
    {
        return ! NetworkJob::query()
            ->where('network_node_id', $nodeId)
            ->when($deviceId, fn ($query) => $query->where('device_id', $deviceId))
            ->whereIn('type', ['workflow_task', 'workflow_run'])
            ->whereIn('status', ['pending', 'dispatched', 'stop_requested', 'unreachable'])
            ->where(function ($query) use ($workflowRunId): void {
                $query->whereNull('workflow_run_id')->orWhere('workflow_run_id', '!=', $workflowRunId);
            })
            ->exists();
    }

    protected function queueForClientCapacity(WorkflowRun $run, string $message): bool
    {
        $run->forceFill([
            'status' => 'queued',
            'result_json' => array_replace(is_array($run->result_json) ? $run->result_json : [], [
                'state' => 'queued',
                'statusMessage' => $message,
                'source' => 'ai-user-factory-scheduler',
            ]),
        ])->save();

        RunWorkflowJob::dispatch($run->id)->delay(now()->addSeconds(10));

        return false;
    }

    public function applyClientWorkflowProgress(NetworkJob $job, array $snapshot): void
    {
        $run = $job->workflowRun()->with(['workflow.steps', 'stepRuns.workflowStep'])->first();

        if (! $run || $this->isFinalStatus($run->status)) {
            return;
        }

        $currentStepId = (int) ($snapshot['currentStepId'] ?? $snapshot['workflowStepId'] ?? 0);
        $stepSnapshots = collect(is_array($snapshot['steps'] ?? null) ? $snapshot['steps'] : [])
            ->filter(fn (mixed $step): bool => is_array($step))
            ->keyBy(fn (array $step): int => (int) ($step['workflowStepId'] ?? 0));

        foreach ($run->stepRuns as $stepRun) {
            $stepSnapshot = $stepSnapshots->get((int) $stepRun->workflow_step_id);

            if (! is_array($stepSnapshot) && (int) $stepRun->workflow_step_id !== $currentStepId) {
                continue;
            }

            $stepSnapshot = is_array($stepSnapshot) ? $stepSnapshot : $snapshot;
            $state = strtolower((string) ($stepSnapshot['state'] ?? $stepSnapshot['status'] ?? 'running'));
            $isCurrentStep = (int) $stepRun->workflow_step_id === $currentStepId;
            $stepRun->forceFill(array_filter([
                'status' => $this->clientStepStatus($state),
                'started_at' => $this->clientReportedAt($stepSnapshot, ['startedAt', 'started_at']) ?: $stepRun->started_at,
                'finished_at' => $this->clientReportedAt($stepSnapshot, ['finishedAt', 'finished_at', 'completedAt', 'completed_at']) ?: $stepRun->finished_at,
                'duration_ms' => $stepSnapshot['durationMs'] ?? $stepSnapshot['duration_ms'] ?? $stepRun->duration_ms,
                'external_run_type' => $isCurrentStep ? 'client-controller-workflow-run' : $stepRun->external_run_type,
                'external_run_id' => $isCurrentStep ? $job->job_uuid : $stepRun->external_run_id,
                'result_json' => $this->publicRunSnapshot($stepSnapshot),
                'logs_json' => $this->logsFromExternalStatus($stepSnapshot),
                'error_message' => in_array($state, ['failed', 'cancelled', 'timed_out', 'timeout'], true)
                    ? (string) ($stepSnapshot['statusMessage'] ?? $stepSnapshot['message'] ?? '')
                    : null,
            ], static fn (mixed $value): bool => $value !== null))->save();
        }

        $run->forceFill([
            'status' => $job->status === 'stop_requested' ? 'stop_requested' : 'running',
            'current_workflow_step_id' => $currentStepId ?: $run->current_workflow_step_id,
            'result_json' => array_replace($this->publicRunSnapshot($snapshot), [
                'source' => 'client-controller',
                'networkJobUuid' => $job->job_uuid,
            ]),
        ])->save();
    }

    public function completeClientWorkflowRun(NetworkJob $job, array $result, string $status, ?string $errorMessage = null): void
    {
        if (! $this->isAuthoritativeClientWorkflowResult($result, $status)) {
            $this->applyClientWorkflowProgress($job, $result);

            return;
        }

        $run = $job->workflowRun()->with(['workflow.steps', 'stepRuns.workflowStep'])->first();

        if (! $run || $this->isFinalStatus($run->status)) {
            return;
        }

        $steps = collect(is_array($result['steps'] ?? null) ? $result['steps'] : [])
            ->filter(fn (mixed $step): bool => is_array($step))
            ->keyBy(fn (array $step): int => (int) ($step['workflowStepId'] ?? 0));

        foreach ($run->stepRuns as $stepRun) {
            $stepResult = $steps->get((int) $stepRun->workflow_step_id);

            if (! is_array($stepResult)) {
                continue;
            }

            $state = strtolower((string) ($stepResult['state'] ?? $stepResult['status'] ?? ((bool) ($stepResult['ok'] ?? false) ? 'completed' : 'failed')));
            $stepRun->forceFill([
                'status' => $this->clientStepStatus($state),
                'external_run_type' => 'client-controller-workflow-run',
                'external_run_id' => $job->job_uuid,
                'started_at' => $this->clientReportedAt($stepResult, ['startedAt', 'started_at']) ?: $stepRun->started_at,
                'finished_at' => $this->clientReportedAt($stepResult, ['finishedAt', 'finished_at', 'completedAt', 'completed_at'])
                    ?: $job->completed_at,
                'duration_ms' => $stepResult['durationMs'] ?? $stepResult['duration_ms'] ?? $stepRun->duration_ms,
                'result_json' => $this->publicRunSnapshot($stepResult),
                'logs_json' => $this->logsFromExternalStatus($stepResult),
                'error_message' => in_array($state, ['failed', 'cancelled', 'timed_out', 'timeout'], true)
                    ? (string) ($stepResult['statusMessage'] ?? $stepResult['message'] ?? $errorMessage ?? '')
                    : null,
            ])->save();
        }

        $this->applyWorkflowVariablesResult($run, $result);
        $lastStepRun = $run->stepRuns->last();

        if ($lastStepRun instanceof WorkflowStepRun) {
            $result = $this->applyExternalResult($lastStepRun, $result);
        }

        $runStatus = match ($status) {
            'success' => 'completed',
            'cancelled' => 'cancelled',
            'timed_out' => 'timed_out',
            default => 'failed',
        };
        $finishedAt = $this->clientReportedAt($result, ['finishedAt', 'finished_at', 'completedAt', 'completed_at']) ?: $job->completed_at;
        $run->forceFill([
            'status' => $runStatus,
            'current_workflow_step_id' => null,
            'finished_at' => $finishedAt,
            'duration_ms' => $result['durationMs'] ?? $result['duration_ms'] ?? ($finishedAt ? $this->workflowRunDurationMs($run, $finishedAt) : null),
            'result_json' => array_replace($this->publicRunSnapshot($result), [
                'source' => 'client-controller',
                'clientStatus' => $status,
                'networkJobUuid' => $job->job_uuid,
            ]),
            'error_message' => $runStatus === 'completed' ? null : ($errorMessage ?: (string) ($result['statusMessage'] ?? '')),
        ])->save();
        $this->releaseClientReservation($run);
    }

    public function isAuthoritativeClientWorkflowResult(array $result, string $status = 'success'): bool
    {
        if ((bool) ($result['clientWorkflowComplete'] ?? $result['workflowComplete'] ?? false)) {
            return true;
        }

        $steps = collect(is_array($result['steps'] ?? null) ? $result['steps'] : [])
            ->filter(fn (mixed $step): bool => is_array($step));

        if ($steps->isNotEmpty()) {
            return true;
        }

        if (in_array($status, ['failed', 'cancelled', 'timed_out'], true)
            && ! $this->looksLikeClientWorkflowStepSnapshot($result)) {
            return true;
        }

        return false;
    }

    protected function looksLikeClientWorkflowStepSnapshot(array $result): bool
    {
        if (isset($result['workflowStepId'], $result['workflowStepRunId']) || is_array($result['tasks'] ?? null)) {
            return true;
        }

        $scriptName = trim((string) ($result['scriptName'] ?? ''));

        return $scriptName !== '' && str_ends_with($scriptName, 'run_step.cjs');
    }

    protected function startWorkflowTaskStep(WorkflowRun $run, WorkflowStep $step, WorkflowStepRun $stepRun): string
    {
        if ($this->isCopilotSupervisedRun($run)) {
            $context = is_array($run->context_json) ? $run->context_json : [];
            $transientTask = is_array($context['copilot_transient_task'] ?? null) ? $context['copilot_transient_task'] : [];
            $currentTaskKey = trim((string) ($transientTask['key'] ?? $context['next_task_key'] ?? ''));

            if ($currentTaskKey === '') {
                $currentTaskKey = trim((string) data_get($step->task_cards, '0.key', ''));
            }

            $context['copilot_current_task_key'] = $currentTaskKey;
            $context['copilot_segment_started_at'] = now()->toIso8601String();
            $context['copilot_supervised'] = true;
            $context['execution_target'] = 'system';
            unset($context['copilot_checkpoint']);
            $run->forceFill(['context_json' => $context])->save();
        }

        $stepRun->forceFill([
            'status' => 'running',
            'started_at' => now(),
            'finished_at' => null,
            'duration_ms' => null,
            'error_message' => null,
        ])->save();

        $runtimeContext = $this->workflowRuntimeContext($run, $step, $stepRun);
        $externalRun = $this->workflowTasks->start(
            $run,
            $step,
            $stepRun,
            $runtimeContext,
        );

        $this->clearRouteCursor($run);

        $stepRun->forceFill([
            'status' => 'waiting',
            'external_run_type' => 'workflow-task',
            'external_run_id' => $externalRun['runId'] ?? null,
            'result_json' => $this->publicRunSnapshot($externalRun),
            'logs_json' => $this->logsFromExternalStatus($externalRun),
        ])->save();

        $this->scheduleMonitor($stepRun, (int) ($externalRun['livePreviewPollIntervalSeconds'] ?? $externalRun['livePreviewIntervalSeconds'] ?? 3));

        return 'waiting';
    }

    protected function startClientControllerWorkflowTask(WorkflowRun $run, WorkflowStep $step, WorkflowStepRun $stepRun): string
    {
        $nodeId = (int) data_get($run->context_json, 'network_node_id', 0);
        $deviceId = (int) data_get($run->context_json, 'device_id', 0);
        $node = NetworkNode::query()->find($nodeId);

        if (! $node || ! $node->isAvailable()) {
            throw new \RuntimeException('Der ausgewaehlte ClientController-Node ist nicht verfuegbar.');
        }

        $device = $deviceId > 0 ? Device::query()->find($deviceId) : null;
        $runtimeContext = $this->workflowRuntimeContext($run, $step, $stepRun);
        $runtime = $this->workflowTasks->remoteRuntime($run, $step, $stepRun, $runtimeContext);
        $networkJob = $this->networkJobs->dispatch(
            $node,
            'workflow_task',
            [
                'runtime' => $runtime,
                'execution' => [
                    'target' => 'node',
                    'workflow_run_id' => $run->id,
                    'workflow_step_run_id' => $stepRun->id,
                    'device_uuid' => $device?->device_uuid,
                ],
            ],
            $device,
            null,
            $run->requested_by,
            null,
            $run,
            2,
        );

        $this->clearRouteCursor($run);

        $stepRun->forceFill([
            'status' => 'waiting',
            'started_at' => $stepRun->started_at ?: now(),
            'external_run_type' => 'client-controller-workflow-task',
            'external_run_id' => $networkJob->job_uuid,
            'result_json' => [
                'state' => 'queued',
                'message' => 'Workflow-Task wurde an den ClientController-Node '.$node->name.' uebergeben.',
                'networkJobUuid' => $networkJob->job_uuid,
                'networkNodeId' => $node->id,
                'networkNodeName' => $node->name,
            ],
        ])->save();

        $this->scheduleMonitor(
            $stepRun,
            (int) ($runtime['livePreviewPollIntervalSeconds'] ?? $runtime['livePreviewIntervalSeconds'] ?? 3),
        );

        return 'waiting';
    }

    protected function startMailRegistrationStep(WorkflowRun $run, WorkflowStep $step, WorkflowStepRun $stepRun): string
    {
        $subject = $this->mailRegistrationSubject($run, $step);
        $providerKey = trim((string) data_get($step->config_json, 'provider_key')) ?: null;
        $externalRun = $this->mailRegistration->start(
            $subject,
            $providerKey,
            $this->workflowRuntimeContext($run, $step, $stepRun),
        );

        $stepRun->forceFill([
            'status' => 'waiting',
            'external_run_type' => 'mail-registration',
            'external_run_id' => $externalRun['runId'] ?? null,
            'result_json' => $this->publicRunSnapshot($externalRun),
        ])->save();

        $this->scheduleMonitor($stepRun, (int) ($externalRun['livePreviewPollIntervalSeconds'] ?? $externalRun['livePreviewIntervalSeconds'] ?? 10));

        return 'waiting';
    }

    protected function startWebmailLoginStep(WorkflowRun $run, WorkflowStep $step, WorkflowStepRun $stepRun): string
    {
        $account = $this->webmailAccount($run, $step);
        $externalRun = $this->webmailSession->start(
            $account,
            'workflow-'.$run->id.'-step-'.$step->id,
            $this->workflowRuntimeContext($run, $step, $stepRun),
        );

        $stepRun->forceFill([
            'status' => 'waiting',
            'external_run_type' => 'webmail-session',
            'external_run_id' => $externalRun['runId'] ?? null,
            'result_json' => $this->publicRunSnapshot($externalRun),
        ])->save();

        $this->scheduleMonitor($stepRun, (int) ($externalRun['livePreviewPollIntervalSeconds'] ?? $externalRun['livePreviewIntervalSeconds'] ?? 10));

        return 'waiting';
    }

    protected function completePlannedActionStep(WorkflowRun $run, WorkflowStep $step, WorkflowStepRun $stepRun): string
    {
        $result = [
            'ok' => true,
            'statusMessage' => 'Geplante Persona-Aktion wurde im Workflow verarbeitet.',
            'action' => $step->config_json,
            'debugMessage' => 'Dieser Schritt wurde als geplante Aktion verarbeitet. Die Task-Karten sind Planungskarten und wurden in diesem Lauf nicht als einzelne Runner-Tasks ausgefuehrt.',
            'completedAt' => now()->toIso8601String(),
        ];

        $this->completeStepRun($stepRun, $result);
        $this->continueAfterStep($run, $stepRun, $result, 'success');

        return 'waiting';
    }

    protected function completeWaitStep(WorkflowRun $run, WorkflowStep $step, WorkflowStepRun $stepRun): string
    {
        $seconds = max(0, (int) (data_get($step->config_json, 'seconds') ?: $step->wait_after_seconds));

        $result = [
            'ok' => true,
            'statusMessage' => $seconds > 0 ? 'Workflow wartet bis zum naechsten Schritt.' : 'Warteschritt abgeschlossen.',
            'waitSeconds' => $seconds,
        ];

        $this->completeStepRun($stepRun, $result);
        $this->continueAfterStep($run, $stepRun, $result, 'success', $seconds);

        return 'waiting';
    }

    protected function createStepRun(WorkflowRun $run, WorkflowStep $step): WorkflowStepRun
    {
        return WorkflowStepRun::query()->create([
            'workflow_run_id' => $run->id,
            'workflow_step_id' => $step->id,
            'status' => 'running',
            'started_at' => now(),
            'result_json' => [],
        ]);
    }

    protected function completeStepRun(WorkflowStepRun $stepRun, array $result, string $taskStatus = 'completed'): void
    {
        $startedAt = $stepRun->started_at instanceof Carbon ? $stepRun->started_at : now();
        $finishedAt = now();
        $result = $this->normalizeStepResult($stepRun, $result);
        $result = $this->withTaskStatuses($stepRun->workflowStep, $result, $taskStatus);

        $stepRun->forceFill([
            'status' => 'completed',
            'finished_at' => $finishedAt,
            'duration_ms' => max(0, $startedAt->diffInMilliseconds($finishedAt)),
            'result_json' => $this->publicRunSnapshot($result),
            'logs_json' => $this->logsFromExternalStatus($result),
            'error_message' => null,
        ])->save();
    }

    protected function failStepRun(WorkflowStepRun $stepRun, string $message, ?array $result = null): void
    {
        $startedAt = $stepRun->started_at instanceof Carbon ? $stepRun->started_at : now();
        $finishedAt = now();
        $result = $result
            ? $this->withTaskStatuses($stepRun->workflowStep, $this->normalizeStepResult($stepRun, $result), 'failed', $message)
            : null;

        $stepRun->forceFill([
            'status' => 'failed',
            'finished_at' => $finishedAt,
            'duration_ms' => max(0, $startedAt->diffInMilliseconds($finishedAt)),
            'result_json' => $result ? $this->publicRunSnapshot($result) : $stepRun->result_json,
            'logs_json' => $result ? $this->logsFromExternalStatus($result) : $stepRun->logs_json,
            'error_message' => $message,
        ])->save();
    }

    protected function expireStepRun(WorkflowStepRun $stepRun): void
    {
        if (! in_array($stepRun->status, ['running', 'waiting'], true)) {
            return;
        }

        if ($this->isWaitingAtCopilotCheckpoint($stepRun)) {
            return;
        }

        $timeoutSeconds = $this->stepTimeoutSeconds($stepRun->workflowStep);
        $message = 'Workflow-Schritt hat das Timeout von '.$timeoutSeconds.' Sekunden ueberschritten.';
        $result = [
            'ok' => false,
            'status' => 'timeout',
            'statusLevel' => 'timeout',
            'statusMessage' => $message,
            'timedOutAt' => now()->toIso8601String(),
            'timeoutSeconds' => $timeoutSeconds,
        ];
        $outcome = $this->hasRouteForOutcome($stepRun->workflowStep, 'timeout') ? 'timeout' : 'failed';

        if ($this->isCopilotSupervisedRun($stepRun->workflowRun)) {
            $this->cancelExternalRun($stepRun, $message);
            $this->holdCopilotTaskCheckpoint($stepRun, [], $result, false);

            return;
        }

        if ($this->hasRouteForOutcome($stepRun->workflowStep, $outcome)) {
            $this->completeStepRun($stepRun, $result, 'timeout');
            $this->continueAfterStep($stepRun->workflowRun, $stepRun, $result, $outcome);

            return;
        }

        $this->failStepRun($stepRun, $message, $result);
        $this->failRun($stepRun->workflowRun, $message);
    }

    protected function isWaitingAtCopilotCheckpoint(WorkflowStepRun $stepRun): bool
    {
        if ($stepRun->status !== 'waiting' || ! $this->isCopilotSupervisedRun($stepRun->workflowRun)) {
            return false;
        }

        $checkpoint = data_get($stepRun->workflowRun->context_json, 'copilot_checkpoint');

        return is_array($checkpoint)
            && trim((string) ($checkpoint['id'] ?? '')) !== ''
            && (int) ($checkpoint['workflow_step_id'] ?? 0) === (int) $stepRun->workflow_step_id;
    }

    protected function preserveHeldCopilotCheckpoint(WorkflowRun $run, bool $lockStepRun = false): bool
    {
        if (! $this->heldCopilotCheckpointStepRun($run, $lockStepRun)) {
            return false;
        }

        if ($run->status !== 'waiting') {
            $run->forceFill(['status' => 'waiting'])->save();
        }

        return true;
    }

    protected function heldCopilotCheckpointStepRun(
        WorkflowRun $run,
        bool $lockForUpdate = false,
    ): ?WorkflowStepRun {
        if (! $this->isCopilotSupervisedRun($run)) {
            return null;
        }

        $checkpoint = data_get($run->context_json, 'copilot_checkpoint');

        if (! is_array($checkpoint)
            || trim((string) ($checkpoint['id'] ?? '')) === ''
            || (int) ($checkpoint['workflow_step_id'] ?? 0) <= 0) {
            return null;
        }

        $query = WorkflowStepRun::query()
            ->where('workflow_run_id', $run->id)
            ->where('workflow_step_id', (int) $checkpoint['workflow_step_id'])
            ->where('status', 'waiting');
        $stepRunId = (int) ($checkpoint['workflow_step_run_id'] ?? 0);

        if ($stepRunId > 0) {
            $query->whereKey($stepRunId);
        }

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    protected function completeRun(WorkflowRun $run): void
    {
        $run = $this->loadRun($run->id);
        $finishedAt = now();
        $durationMs = $this->workflowRunDurationMs($run, $finishedAt);
        $normalizedWorkflow = $this->resultNormalizer->summarizeRun($run);

        $run->forceFill([
            'status' => 'completed',
            'current_workflow_step_id' => null,
            'finished_at' => $finishedAt,
            'duration_ms' => $durationMs,
            'result_json' => [
                'ok' => true,
                'completed_steps' => $run->stepRuns()->where('status', 'completed')->count(),
                'finishedAt' => $finishedAt->toIso8601String(),
                'durationMs' => $durationMs,
                'duration_ms' => $durationMs,
                'normalized_result' => $normalizedWorkflow,
                'technical_status' => $normalizedWorkflow['technical_status'],
                'business_status' => $normalizedWorkflow['business_status'],
                'result_class' => $normalizedWorkflow['result_class'],
                'business_ok' => $normalizedWorkflow['business_ok'],
                'empty_result' => $normalizedWorkflow['empty_result'],
                'retryable' => $normalizedWorkflow['retryable'],
                'state_mismatch' => $normalizedWorkflow['state_mismatch'],
                'diagnostic_reason_code' => $normalizedWorkflow['diagnostic_reason_code'],
                'diagnostic_reason' => $normalizedWorkflow['diagnostic_reason'],
                ...$this->workflowReturnPayload($run),
            ],
            'error_message' => null,
        ])->save();

        $this->releaseClientReservation($run);
        $this->closeWorkflowTaskProcesses($run, 'Workflow-Lauf wurde abgeschlossen; zugehoerige Browser-Prozesse wurden geschlossen.');
        $this->notifyCopilotRunFinished($run);
    }

    protected function failRun(WorkflowRun $run, string $message): void
    {
        $finishedAt = now();
        $durationMs = $this->workflowRunDurationMs($run, $finishedAt);
        $normalizedWorkflow = $this->resultNormalizer->summarizeRun($this->loadRun($run->id));

        $run->forceFill([
            'status' => 'failed',
            'finished_at' => $finishedAt,
            'duration_ms' => $durationMs,
            'result_json' => array_replace(is_array($run->result_json) ? $run->result_json : [], [
                'ok' => false,
                'failedAt' => $finishedAt->toIso8601String(),
                'durationMs' => $durationMs,
                'duration_ms' => $durationMs,
                'normalized_result' => $normalizedWorkflow,
                'technical_status' => 'failed',
                'business_status' => 'failed',
                'result_class' => $normalizedWorkflow['result_class'] ?? 'workflow_hard_failure',
                'business_ok' => false,
                'empty_result' => $normalizedWorkflow['empty_result'] ?? false,
                'retryable' => $normalizedWorkflow['retryable'] ?? false,
                'state_mismatch' => $normalizedWorkflow['state_mismatch'] ?? false,
                'diagnostic_reason_code' => $normalizedWorkflow['diagnostic_reason_code'] ?? 'workflow_technical_failure',
                'diagnostic_reason' => $normalizedWorkflow['diagnostic_reason'] ?? $message,
                'statusMessage' => $message,
            ], $this->workflowReturnPayload($run)),
            'error_message' => $message,
        ])->save();

        $this->releaseClientReservation($run);

        $this->closeWorkflowTaskProcesses($run, 'Workflow-Lauf wurde beendet; zugehoerige Browser-Prozesse wurden geschlossen.');
        $this->notifyCopilotRunFinished($run);
    }

    protected function workflowRunDurationMs(WorkflowRun $run, Carbon $finishedAt): int
    {
        $startedAt = $run->started_at instanceof Carbon
            ? $run->started_at
            : ($run->queued_at instanceof Carbon ? $run->queued_at : $finishedAt);

        return max(0, $startedAt->diffInMilliseconds($finishedAt));
    }

    protected function workflowReturnPayload(WorkflowRun $run): array
    {
        $context = is_array($run->context_json) ? $run->context_json : [];
        $payload = [];
        $variables = array_filter([
            ...(is_array($context['workflow_variables'] ?? null) ? $context['workflow_variables'] : []),
            ...(is_array($context['workflowVariables'] ?? null) ? $context['workflowVariables'] : []),
        ], fn (mixed $value): bool => $value !== null);

        if (array_key_exists('workflow_return', $context) || array_key_exists('workflowReturn', $context)) {
            $value = array_key_exists('workflow_return', $context)
                ? $context['workflow_return']
                : $context['workflowReturn'];

            $payload['workflow_return'] = $value;
            $payload['workflowReturn'] = $value;
            $variables['workflow_return'] = $value;
        }

        if (array_key_exists('workflow_return_ok', $context)) {
            $payload['workflow_return_ok'] = (bool) $context['workflow_return_ok'];
            $variables['workflow_return_ok'] = (bool) $context['workflow_return_ok'];
        }

        if (array_key_exists('workflow_return_key', $context) || array_key_exists('workflowReturnKey', $context)) {
            $key = trim((string) ($context['workflow_return_key'] ?? $context['workflowReturnKey'] ?? ''));

            if ($key !== '') {
                $payload['workflow_return_key'] = $key;
                $payload['workflowReturnKey'] = $key;
            }
        }

        if ($variables !== []) {
            $payload['workflow_variables'] = $variables;
            $payload['workflowVariables'] = $variables;
        }

        return $payload;
    }

    protected function closeWorkflowTaskProcesses(WorkflowRun $run, string $message): void
    {
        $run->stepRuns()
            ->where('external_run_type', 'workflow-task')
            ->whereNotNull('external_run_id')
            ->get()
            ->each(function (WorkflowStepRun $stepRun) use ($message): void {
                $externalRunId = trim((string) $stepRun->external_run_id);

                if ($externalRunId !== '') {
                    try {
                        $this->workflowTasks->closeRun($externalRunId, $message);
                    } catch (\Throwable) {
                        // Cleanup ist best-effort; der Workflow-Status wurde bereits final gespeichert.
                    }
                }
            });
    }

    protected function nextStepForRun(WorkflowRun $run): ?WorkflowStep
    {
        $steps = $run->workflow
            ->steps
            ->filter(fn (WorkflowStep $step): bool => $step->is_enabled)
            ->values();
        $targetActionKey = trim((string) data_get($run->context_json, 'next_step_action_key', ''));

        if ($targetActionKey !== '') {
            $target = $steps->first(fn (WorkflowStep $step): bool => $step->action_key === $targetActionKey);

            if (! $target) {
                throw new \RuntimeException('Routing-Ziel wurde nicht gefunden: '.$targetActionKey);
            }

            $this->clearRouteCursor($run, true);

            return $target;
        }

        $nextTaskKey = trim((string) data_get($run->context_json, 'next_task_key', ''));
        $currentStepId = (int) $run->current_workflow_step_id;

        if ($nextTaskKey !== '' && $currentStepId > 0) {
            $checkpointStep = $steps->first(
                fn (WorkflowStep $step): bool => (int) $step->id === $currentStepId,
            );

            if ($checkpointStep) {
                return $checkpointStep;
            }
        }

        foreach ($steps as $step) {
            $stepRun = WorkflowStepRun::query()
                ->where('workflow_run_id', $run->id)
                ->where('workflow_step_id', $step->id)
                ->first();

            if (! $stepRun || ! in_array($stepRun->status, ['completed', 'skipped'], true)) {
                return $step;
            }
        }

        return null;
    }

    protected function continueAfterStep(WorkflowRun $run, WorkflowStepRun $stepRun, array $result, string $outcome, int $delaySeconds = 0): void
    {
        $route = $this->routeForResult($stepRun->workflowStep, $outcome, $result)
            ?: $this->linearRouteAfterStep($run, $stepRun->workflowStep, $outcome);
        $routeType = (string) ($route['type'] ?? 'step');

        if ($routeType === 'step' && trim((string) ($route['action_key'] ?? $route['step'] ?? '')) === 'next') {
            $route = $this->linearRouteAfterStep($run, $stepRun->workflowStep, $outcome);
            $routeType = (string) ($route['type'] ?? 'step');
        }

        $context = is_array($run->context_json) ? $run->context_json : [];
        $context = $this->mergeWorkflowBrowserState($context, $result);

        if ($this->failedBackRouteExceeded($run, $stepRun, $outcome, $route, $context, $result)) {
            $blockedResult = $this->sameStateRetryBlockedResult($result, $route);
            $stepRun->forceFill([
                'result_json' => $this->publicRunSnapshot($this->normalizeStepResult($stepRun, $blockedResult)),
                'logs_json' => $this->logsFromExternalStatus($blockedResult),
                'error_message' => 'Fehlerroute wurde im gleichen Zustand zu oft wiederholt.',
            ])->save();
            $this->failRun($run, 'Fehlerroute wurde im gleichen Zustand zu oft wiederholt und der Workflow wurde abgebrochen.');

            return;
        }

        $this->recordRoute($run, $stepRun, $outcome, $route, $context);
        $run->refresh();

        if (in_array($outcome, ['failed', 'timeout'], true) && ! $this->isContinuableFailureRoute($route)) {
            $this->failRun($run, (string) (
                $route['message']
                ?? data_get($result, 'statusMessage')
                ?? data_get($result, 'message')
                ?? 'Workflow wurde durch einen Taskfehler ohne Fortsetzungsroute beendet.'
            ));

            return;
        }

        if ($routeType === 'end') {
            $this->completeRun($run);

            return;
        }

        if ($routeType === 'fail') {
            $this->failRun($run, (string) (
                $route['message']
                ?? data_get($result, 'statusMessage')
                ?? data_get($result, 'message')
                ?? 'Workflow wurde ueber Fehlerroute beendet.'
            ));

            return;
        }

        $context = is_array($run->context_json) ? $run->context_json : [];
        $targetActionKey = trim((string) ($route['action_key'] ?? $route['step'] ?? ''));
        $targetCardKey = trim((string) ($route['card_key'] ?? $route['card'] ?? ''));

        if ($targetActionKey !== '') {
            $context['next_step_action_key'] = $targetActionKey;
        } else {
            unset($context['next_step_action_key']);
        }

        if ($routeType === 'card' && $targetCardKey !== '') {
            $context['next_task_key'] = $targetCardKey;
        } elseif ($targetCardKey !== '') {
            $context['next_task_key'] = $targetCardKey;
        } else {
            unset($context['next_task_key']);
        }

        if ($targetCardKey !== '') {
            $context['next_task_route_outcome'] = $outcome;
            $context['next_task_route_source_key'] = trim((string) ($route['_source_card_key'] ?? ''));
        } else {
            unset($context['next_task_route_outcome'], $context['next_task_route_source_key']);
        }

        if ((bool) ($context['manual_pause_requested'] ?? false)) {
            $run->forceFill([
                'current_workflow_step_id' => null,
                'context_json' => $context,
            ])->save();
            $this->holdManualPause($run, $context, $stepRun);

            return;
        }

        $run->forceFill([
            'status' => $delaySeconds > 0 ? 'waiting' : 'running',
            'current_workflow_step_id' => null,
            'context_json' => $context,
        ])->save();

        $pendingDispatch = RunWorkflowJob::dispatch($run->id);

        if ($delaySeconds > 0) {
            $pendingDispatch->delay(now()->addSeconds($delaySeconds));
        }
    }

    protected function holdManualPause(WorkflowRun $run, array $context, ?WorkflowStepRun $afterStepRun = null): void
    {
        $context['manual_pause_requested'] = false;
        $context['manual_pause_checkpoint'] = [
            'paused_at' => now()->toIso8601String(),
            'after_workflow_step_run_id' => $afterStepRun?->id,
            'after_workflow_step_id' => $afterStepRun?->workflow_step_id,
            'next_step_action_key' => $context['next_step_action_key'] ?? null,
            'next_task_key' => $context['next_task_key'] ?? null,
            'workflow_variables' => is_array($context['workflow_variables'] ?? null) ? $context['workflow_variables'] : [],
            'browser_windows' => is_array($context['browser_windows'] ?? null) ? $context['browser_windows'] : [],
            'loop_state' => is_array($context['loop_state'] ?? null) ? $context['loop_state'] : [],
        ];

        $run->forceFill([
            'status' => 'paused',
            'context_json' => $context,
        ])->save();
    }

    protected function routeForOutcome(WorkflowStep $step, string $outcome): ?array
    {
        $routes = $step->routes;
        $route = $routes[$outcome] ?? $routes['default'] ?? null;

        return is_array($route) ? $this->normalizeRoute($step, $route) : null;
    }

    protected function routeForResult(WorkflowStep $step, string $outcome, array $result): ?array
    {
        $dynamicTarget = trim((string) ($result['routeTargetKey'] ?? $result['route_target_key'] ?? ''));
        if ((bool) ($result['routeRequested'] ?? $result['route_requested'] ?? false) && $dynamicTarget !== '') {
            return $this->normalizeRoute($step, [
                'type' => 'card',
                'action_key' => $step->action_key,
                'card_key' => $dynamicTarget,
                '_source_card_key' => trim((string) ($result['completedTaskKey'] ?? $result['completed_task_key'] ?? '')),
            ]);
        }

        if ((bool) ($result['routeRequested'] ?? false)) {
            $completedTaskKey = trim((string) (
                $result['completedTaskKey']
                ?? $result['completed_task_key']
                ?? $result['failedTaskKey']
                ?? $result['failed_task_key']
                ?? ''
            ));
            $sourceTask = collect($step->task_cards)
                ->first(fn (array $task): bool => (string) ($task['key'] ?? '') === $completedTaskKey);
            $route = is_array($sourceTask)
                ? ($outcome === 'success'
                    ? ($sourceTask['next'] ?? null)
                    : ($sourceTask['on_error'] ?? data_get($sourceTask, 'status_routes.'.$outcome)))
                : null;

            if (is_array($route)) {
                $route['_source_card_key'] = $completedTaskKey;

                return $this->normalizeRoute($step, $route);
            }

            if (is_array($sourceTask) && in_array($outcome, ['failed', 'timeout'], true)) {
                return null;
            }
        }

        if (in_array($outcome, ['failed', 'timeout'], true)) {
            $failedTaskKey = trim((string) ($result['failedTaskKey'] ?? $result['failed_task_key'] ?? ''));

            if ($failedTaskKey !== '') {
                $resultTask = collect(is_array($result['tasks'] ?? null) ? $result['tasks'] : [])
                    ->first(fn (mixed $task): bool => is_array($task) && (string) ($task['key'] ?? '') === $failedTaskKey);
                $sourceTaskKey = trim((string) data_get($resultTask, 'parent_task_key', $failedTaskKey));
                $sourceTask = collect($step->task_cards)
                    ->first(fn (array $task): bool => (string) ($task['key'] ?? '') === $sourceTaskKey);

                if ($sourceTask) {
                    $route = $sourceTask['on_error'] ?? data_get($sourceTask, 'status_routes.'.$outcome);

                    if (is_array($route)) {
                        $route['_source_card_key'] = $sourceTaskKey;

                        return $this->normalizeRoute($step, $route);
                    }

                    return null;
                }
            }
        }

        return $this->routeForOutcome($step, $outcome);
    }

    /**
     * A failed task may continue the workflow only when its error route points
     * to another executable card or step. Missing routes and the explicit
     * terminal targets `end` and `fail` are real workflow failures.
     */
    protected function isContinuableFailureRoute(?array $route): bool
    {
        if (! is_array($route) || $route === []) {
            return false;
        }

        $type = strtolower(trim((string) ($route['type'] ?? '')));
        $step = strtolower(trim((string) ($route['action_key'] ?? $route['step'] ?? '')));
        $card = trim((string) ($route['card_key'] ?? $route['card'] ?? ''));

        if (in_array($type, ['end', 'fail'], true) || in_array($step, ['end', 'fail'], true)) {
            return false;
        }

        if ($type === 'card') {
            return $card !== '';
        }

        return $step !== '';
    }

    protected function hasRouteForOutcome(WorkflowStep $step, string $outcome): bool
    {
        return $this->routeForOutcome($step, $outcome) !== null;
    }

    protected function linearRouteAfterStep(WorkflowRun $run, WorkflowStep $currentStep, string $outcome): array
    {
        if ($outcome === 'failed') {
            return [
                'type' => 'fail',
                'label' => 'Fehler ohne explizite Route',
            ];
        }

        $steps = $run->workflow
            ->steps
            ->filter(fn (WorkflowStep $step): bool => $step->is_enabled)
            ->values();
        $currentIndex = $steps->search(fn (WorkflowStep $step): bool => $step->id === $currentStep->id);

        if ($currentIndex === false) {
            return ['type' => 'end', 'label' => 'Kein naechster Schritt'];
        }

        $nextStep = $steps->get($currentIndex + 1);

        if (! $nextStep) {
            return ['type' => 'end', 'label' => 'Workflow abschliessen'];
        }

        return [
            'type' => 'step',
            'action_key' => $nextStep->action_key,
            'label' => $nextStep->name,
        ];
    }

    protected function normalizeRoute(WorkflowStep $sourceStep, array $route): array
    {
        $type = trim((string) ($route['type'] ?? ''));
        $step = trim((string) ($route['action_key'] ?? $route['step'] ?? ''));
        $card = trim((string) ($route['card_key'] ?? $route['card'] ?? ''));

        if ($type === '') {
            $type = $card !== '' ? 'card' : 'step';
        }

        if (in_array($step, ['end', 'fail'], true)) {
            $type = $step;
        }

        if ($type === 'card') {
            $route['action_key'] = $step !== '' && ! in_array($step, ['end', 'fail', 'next'], true)
                ? $step
                : $sourceStep->action_key;
            $route['step'] = $route['action_key'];
            $route['card_key'] = $card;
            $route['card'] = $card;
        } elseif ($type === 'step' && $step !== '') {
            $route['action_key'] = $step;
            $route['step'] = $step;
        }

        $route['type'] = $type;

        return $route;
    }

    protected function resultOutcome(array $result): string
    {
        $requestedRouteOutcome = strtolower(trim((string) ($result['routeOutcome'] ?? $result['route_outcome'] ?? '')));

        if ((bool) ($result['routeRequested'] ?? false) && in_array($requestedRouteOutcome, ['success', 'failed', 'timeout'], true)) {
            return $requestedRouteOutcome;
        }

        $normalized = is_array($result['normalized_result'] ?? null) ? $result['normalized_result'] : [];

        if ($normalized !== []) {
            $technicalStatus = (string) ($normalized['technical_status'] ?? '');
            $businessStatus = (string) ($normalized['business_status'] ?? '');

            if ($technicalStatus === 'timeout') {
                return 'timeout';
            }

            if (in_array($technicalStatus, ['failed', 'cancelled'], true) || $businessStatus === 'failed') {
                return 'failed';
            }

            if (in_array($businessStatus, ['partial', 'unknown'], true)) {
                return 'partial';
            }

            return 'success';
        }

        if (strtolower(trim((string) ($result['status'] ?? $result['statusLevel'] ?? ''))) === 'timeout') {
            return 'timeout';
        }

        if (! (bool) ($result['ok'] ?? false)) {
            return 'failed';
        }

        $statusLevel = strtolower(trim((string) ($result['statusLevel'] ?? '')));

        if (in_array($statusLevel, ['partial', 'waiting', 'warning'], true)) {
            return 'partial';
        }

        return 'success';
    }

    protected function stepRunTimedOut(WorkflowStepRun $stepRun): bool
    {
        if (! ($stepRun->started_at instanceof Carbon)) {
            return false;
        }

        $timeoutSeconds = $this->stepTimeoutSeconds($stepRun->workflowStep);

        if ($timeoutSeconds <= 0) {
            return false;
        }

        return $stepRun->started_at->copy()->addSeconds($timeoutSeconds)->lte(now());
    }

    protected function requestClientJobStop(NetworkJob $job, string $message, string $resultStatus = 'cancelled', bool $force = false): void
    {
        if (in_array($job->status, ['success', 'failed', 'cancelled', 'timed_out', 'lost'], true)) {
            return;
        }

        $job->forceFill([
            'status' => 'stop_requested',
            'control_command' => 'stop',
            'control_sequence' => ((int) $job->control_sequence) + 1,
            'control_payload_json' => [
                'reason' => $message,
                'result_status' => $resultStatus,
                'force' => $force,
                'terminate_process_tree' => $force,
            ],
            'control_requested_at' => now(),
            'control_acknowledged_at' => null,
            'control_deadline_at' => now()->addSeconds(45),
            'error_message' => $message,
        ])->save();
    }

    protected function reconcileClientWorkflowJobs(): void
    {
        NetworkJob::query()
            ->with(['workflowRun.stepRuns'])
            ->whereIn('type', ['workflow_task', 'workflow_run'])
            ->whereNotNull('workflow_run_id')
            ->whereIn('status', ['pending', 'dispatched', 'stop_requested', 'unreachable'])
            ->orderBy('id')
            ->limit(100)
            ->get()
            ->each(function (NetworkJob $job): void {
                if ($job->status === 'dispatched' && $job->lease_expires_at?->lte(now())) {
                    $this->markClientJobUnreachable($job);

                    return;
                }

                if ($job->status === 'stop_requested'
                    && $job->control_acknowledged_at === null
                    && $job->control_deadline_at?->lte(now())) {
                    $this->markClientJobUnreachable($job);

                    return;
                }

                if ($job->status === 'stop_requested'
                    && $job->control_acknowledged_at !== null
                    && $job->lease_expires_at?->lte(now())) {
                    $this->markClientJobUnreachable($job);

                    return;
                }

                if ($job->status === 'unreachable' && $job->unreachable_at?->copy()->addSeconds(180)->lte(now())) {
                    $this->loseClientJob($job);
                }
            });
    }

    protected function markClientJobUnreachable(NetworkJob $job): void
    {
        $job->forceFill(['status' => 'unreachable', 'unreachable_at' => now()])->save();
        $job->workflowRun?->forceFill([
            'status' => 'unreachable',
            'result_json' => [
                'state' => 'unreachable',
                'statusMessage' => 'Der ClientController meldet sich derzeit nicht. Der Lauf wird noch nicht als fehlgeschlagen gewertet.',
                'source' => 'ai-user-factory-liveness',
            ],
        ])->save();
    }

    protected function loseClientJob(NetworkJob $job): void
    {
        $run = $job->workflowRun;
        $job->forceFill([
            'status' => 'lost',
            'completed_at' => now(),
            'lease_token_hash' => null,
            'lease_expires_at' => null,
            'error_message' => 'ClientController blieb nach dem Erreichbarkeits-Timeout ohne Antwort.',
        ])->save();

        if (! $run || $this->isFinalStatus($run->status)) {
            return;
        }

        $this->releaseClientReservation($run);
        $run->stepRuns()->whereIn('status', ['running', 'waiting', 'queued'])->update(['status' => 'lost']);
        $run->forceFill([
            'status' => 'lost',
            'finished_at' => now(),
            'current_workflow_step_id' => null,
            'error_message' => $job->error_message,
            'result_json' => [
                'state' => 'lost',
                'statusMessage' => $job->error_message,
                'source' => 'ai-user-factory-liveness',
            ],
        ])->save();
    }

    protected function clientStepStatus(string $state): string
    {
        return match (strtolower($state)) {
            'success', 'completed' => 'completed',
            'failed', 'error' => 'failed',
            'cancelled', 'canceled', 'stopped' => 'cancelled',
            'timed_out', 'timeout' => 'timed_out',
            'queued', 'pending' => 'queued',
            default => 'waiting',
        };
    }

    protected function clientReportedAt(array $payload, array $keys): ?Carbon
    {
        foreach ($keys as $key) {
            $value = trim((string) ($payload[$key] ?? ''));

            if ($value === '') {
                continue;
            }

            try {
                return Carbon::parse($value);
            } catch (\Throwable) {
                // Invalid client timestamps remain part of the raw payload but do not alter server columns.
            }
        }

        return null;
    }

    protected function stepTimeoutSeconds(WorkflowStep $step): int
    {
        $configured = (int) data_get($step->config_json, 'timeout_seconds', 0);

        if ($configured > 0) {
            return $configured;
        }

        $taskTimeouts = collect($step->task_cards)
            ->map(fn (array $task): int => (int) ($task['timeout_seconds'] ?? 0))
            ->filter(fn (int $seconds): bool => $seconds > 0);

        if ($taskTimeouts->isNotEmpty()) {
            return min(3600, max(60, $taskTimeouts->sum() + 60));
        }

        if ($step->type === WorkflowStep::TYPE_WAIT) {
            return max(60, (int) data_get($step->config_json, 'seconds', 0) + 60);
        }

        return match ($step->type) {
            WorkflowStep::TYPE_MAIL_ACCOUNT_REGISTRATION => 1800,
            WorkflowStep::TYPE_WEBMAIL_LOGIN => 900,
            default => 300,
        };
    }

    protected function failedBackRouteExceeded(WorkflowRun $run, WorkflowStepRun $stepRun, string $outcome, array $route, array $context, array $result = []): bool
    {
        if ($outcome !== 'failed') {
            return false;
        }

        if ($this->sameStateRetryExceeded($stepRun, $route, $context, $result)) {
            return true;
        }

        $maxAttempts = max(0, (int) ($route['max_attempts'] ?? $route['retry_limit'] ?? 0));

        if ($maxAttempts <= 0 || ! $this->isBackRoute($run, $stepRun->workflowStep, $route)) {
            return false;
        }

        $attemptKey = $this->routeAttemptKey($stepRun->workflowStep, $outcome, $route);
        $attempts = is_array($context['route_attempts'] ?? null) ? $context['route_attempts'] : [];
        $currentAttempts = max(0, (int) ($attempts[$attemptKey] ?? 0));

        return $currentAttempts >= $maxAttempts;
    }

    protected function sameStateRetryExceeded(WorkflowStepRun $stepRun, array $route, array $context, array $result): bool
    {
        $stepRun->loadMissing(['workflowRun.workflow', 'workflowStep']);

        if (! $stepRun->workflowRun || ! $stepRun->workflowStep) {
            return false;
        }

        if (! $this->isBackRoute($stepRun->workflowRun, $stepRun->workflowStep, $route)) {
            return false;
        }

        $signature = trim((string) data_get($result, 'normalized_result.state_signature'));

        if ($signature === '') {
            $signature = trim((string) data_get($stepRun->result_json, 'normalized_result.state_signature'));
        }

        if ($signature === '') {
            return false;
        }

        $limit = max(1, (int) (
            data_get($route, 'same_state_retry_limit')
            ?: data_get($stepRun->workflowRun?->workflow?->settings_json, 'same_state_retry_limit')
            ?: 2
        ));
        $attemptKey = $this->stateSignatureAttemptKey($stepRun->workflowStep, $route, $signature);
        $attempts = is_array($context['state_signature_attempts'] ?? null) ? $context['state_signature_attempts'] : [];
        $currentAttempts = max(0, (int) ($attempts[$attemptKey] ?? 0));

        return $currentAttempts >= $limit;
    }

    protected function isBackRoute(WorkflowRun $run, WorkflowStep $sourceStep, array $route): bool
    {
        $type = trim((string) ($route['type'] ?? ''));

        if (in_array($type, ['end', 'fail'], true)) {
            return false;
        }

        $targetActionKey = trim((string) ($route['action_key'] ?? $route['step'] ?? ''));

        if ($targetActionKey === '' || in_array($targetActionKey, ['next', 'end', 'fail'], true)) {
            return false;
        }

        $targetStep = $run->workflow
            ? $run->workflow->steps->first(fn (WorkflowStep $step): bool => $step->action_key === $targetActionKey)
            : null;

        if (! $targetStep) {
            $targetStep = WorkflowStep::query()
                ->where('workflow_id', $run->workflow_id)
                ->where('action_key', $targetActionKey)
                ->first();
        }

        if (! $targetStep) {
            return false;
        }

        if ((int) $targetStep->position < (int) $sourceStep->position) {
            return true;
        }

        if ((int) $targetStep->position > (int) $sourceStep->position) {
            return false;
        }

        $sourceCardKey = trim((string) ($route['_source_card_key'] ?? ''));
        $targetCardKey = trim((string) ($route['card_key'] ?? $route['card'] ?? ''));

        if ($sourceCardKey === '' || $targetCardKey === '') {
            return true;
        }

        $tasks = collect($sourceStep->task_cards)->values();
        $sourceIndex = $tasks->search(fn (array $task): bool => (string) ($task['key'] ?? '') === $sourceCardKey);
        $targetIndex = $tasks->search(fn (array $task): bool => (string) ($task['key'] ?? '') === $targetCardKey);

        return $sourceIndex !== false && $targetIndex !== false && $targetIndex <= $sourceIndex;
    }

    protected function routeAttemptKey(WorkflowStep $step, string $outcome, array $route): string
    {
        return implode(':', [
            $step->id,
            $outcome,
            trim((string) ($route['type'] ?? 'step')),
            trim((string) ($route['action_key'] ?? $route['step'] ?? '')),
            trim((string) ($route['card_key'] ?? $route['card'] ?? '')),
            trim((string) ($route['_source_card_key'] ?? '')),
        ]);
    }

    protected function stateSignatureAttemptKey(WorkflowStep $step, array $route, string $signature): string
    {
        return implode(':', [
            $step->id,
            trim((string) ($route['type'] ?? 'step')),
            trim((string) ($route['action_key'] ?? $route['step'] ?? '')),
            trim((string) ($route['card_key'] ?? $route['card'] ?? '')),
            trim((string) ($route['_source_card_key'] ?? '')),
            $signature,
        ]);
    }

    protected function sameStateRetryBlockedResult(array $result, array $route): array
    {
        return array_replace($result, [
            'ok' => false,
            'status' => 'failed',
            'statusLevel' => 'failed',
            'statusMessage' => 'Fehlerroute wurde im gleichen Zustand zu oft wiederholt.',
            'diagnostic_reason_code' => 'same_state_retry_blocked',
            'retryBlocked' => true,
            'retry_blocked' => true,
            'blockedRoute' => $route,
            'blocked_route' => $route,
        ]);
    }

    protected function recordRoute(WorkflowRun $run, WorkflowStepRun $stepRun, string $outcome, array $route, ?array $context = null): void
    {
        $context = is_array($context) ? $context : (is_array($run->context_json) ? $run->context_json : []);
        $history = is_array($context['route_history'] ?? null) ? $context['route_history'] : [];
        $history[] = [
            'at' => now()->toIso8601String(),
            'workflow_step_id' => $stepRun->workflow_step_id,
            'workflow_step_run_id' => $stepRun->id,
            'outcome' => $outcome,
            'route' => $route,
        ];

        $context['route_history'] = array_slice($history, -50);

        if ($outcome === 'failed' && $this->isBackRoute($run, $stepRun->workflowStep, $route)) {
            $attempts = is_array($context['route_attempts'] ?? null) ? $context['route_attempts'] : [];
            $attemptKey = $this->routeAttemptKey($stepRun->workflowStep, $outcome, $route);
            $attempts[$attemptKey] = max(0, (int) ($attempts[$attemptKey] ?? 0)) + 1;
            $context['route_attempts'] = $attempts;

            $signature = trim((string) data_get($stepRun->result_json, 'normalized_result.state_signature'));

            if ($signature !== '') {
                $stateAttempts = is_array($context['state_signature_attempts'] ?? null) ? $context['state_signature_attempts'] : [];
                $stateAttemptKey = $this->stateSignatureAttemptKey($stepRun->workflowStep, $route, $signature);
                $stateAttempts[$stateAttemptKey] = max(0, (int) ($stateAttempts[$stateAttemptKey] ?? 0)) + 1;
                $context['state_signature_attempts'] = $stateAttempts;
            }
        }

        $run->forceFill(['context_json' => $context])->save();
    }

    protected function clearRouteCursor(WorkflowRun $run, bool $preserveTaskCursor = false): void
    {
        $context = is_array($run->context_json) ? $run->context_json : [];
        unset($context['next_step_action_key']);

        if (! $preserveTaskCursor) {
            unset($context['next_task_key'], $context['next_task_route_outcome'], $context['next_task_route_source_key']);
        }

        $run->forceFill(['context_json' => $context])->save();
    }

    protected function readExternalStatus(WorkflowStepRun $stepRun): ?array
    {
        $externalRunId = trim((string) $stepRun->external_run_id);

        if ($externalRunId === '') {
            return null;
        }

        return match ($stepRun->external_run_type) {
            'mail-registration' => $this->mailRegistration->readRun($externalRunId),
            'webmail-session' => $this->webmailSession->readRun($externalRunId),
            'workflow-task' => $this->workflowTasks->readRun($externalRunId),
            'client-controller-workflow-task', 'client-controller-workflow-run' => $this->clientControllerJobStatus($externalRunId),
            default => null,
        };
    }

    protected function readExternalResult(WorkflowStepRun $stepRun, array $status): array
    {
        $externalRunId = trim((string) $stepRun->external_run_id);

        $result = match ($stepRun->external_run_type) {
            'mail-registration' => $this->mailRegistration->readResult($externalRunId),
            'webmail-session' => is_array($status['result'] ?? null)
                ? $status['result']
                : $this->webmailSession->readResult($externalRunId),
            'workflow-task' => $this->workflowTasks->readResult($externalRunId)
                ?: (is_array($status['result'] ?? null) ? $status['result'] : null),
            'client-controller-workflow-task', 'client-controller-workflow-run' => is_array($status['result'] ?? null) ? $status['result'] : null,
            default => null,
        };

        return is_array($result) ? $result : $status;
    }

    protected function normalizeStepResult(WorkflowStepRun $stepRun, array $result, array $status = []): array
    {
        $stepRun->loadMissing('workflowStep');

        if (! $stepRun->workflowStep instanceof WorkflowStep) {
            return $result;
        }

        return $this->resultNormalizer->normalizeStepResult(
            $stepRun->workflowStep,
            $status,
            $result,
            $stepRun->external_run_type,
        );
    }

    protected function ingestDebugArtifacts(WorkflowStepRun $stepRun, array ...$payloads): void
    {
        foreach ($payloads as $payload) {
            foreach ([
                $payload['debugArtifacts'] ?? null,
                $payload['debug_artifacts'] ?? null,
                data_get($payload, 'result.debugArtifacts'),
                data_get($payload, 'result.debug_artifacts'),
            ] as $candidate) {
                if (! is_array($candidate) || $candidate === []) {
                    continue;
                }

                $this->debugArtifacts->ingestManifest($stepRun, array_is_list($candidate) ? ['artifacts' => $candidate] : $candidate);
            }
        }
    }

    protected function externalStillRunning(array $status): bool
    {
        $state = (string) data_get($status, 'state', '');

        if ((bool) data_get($status, 'isRunning', false)) {
            return true;
        }

        if (in_array($state, ['queued', 'starting', 'running'], true)) {
            return true;
        }

        return $state === 'waiting' && (bool) data_get($status, 'result.webmailCheckPending', false);
    }

    protected function externalSucceeded(WorkflowStep $step, array $status, array $result): bool
    {
        $normalized = is_array($result['normalized_result'] ?? null) ? $result['normalized_result'] : [];

        if ($normalized !== []) {
            $technicalStatus = (string) ($normalized['technical_status'] ?? '');
            $businessStatus = (string) ($normalized['business_status'] ?? '');

            if (in_array($technicalStatus, ['failed', 'timeout', 'cancelled'], true)) {
                return false;
            }

            if ($technicalStatus === 'success' && $businessStatus !== 'failed') {
                return true;
            }
        }

        if ((bool) data_get($result, 'ok', false)) {
            return true;
        }

        if ((bool) data_get($step->config_json, 'allow_partial', false)) {
            return ! in_array((string) data_get($status, 'state'), ['failed'], true);
        }

        return false;
    }

    protected function prepareExternalResult(WorkflowStepRun $stepRun, array $result): array
    {
        if (
            in_array($stepRun->external_run_type, ['workflow-task', 'client-controller-workflow-task', 'client-controller-workflow-run'], true)
            && (
                trim((string) data_get($result, 'webmailSessionFilePath', '')) !== ''
                || trim((string) data_get($result, 'encryptedSessionPayload', '')) !== ''
            )
        ) {
            return $this->finalizeWorkflowWebmailSessionResult($result);
        }

        if (
            in_array($stepRun->external_run_type, ['workflow-task', 'client-controller-workflow-task', 'client-controller-workflow-run'], true)
            && (
                trim((string) data_get($result, 'browserSessionFilePath', '')) !== ''
                || trim((string) data_get($result, 'encryptedBrowserSessionPayload', '')) !== ''
            )
        ) {
            return $this->finalizeWorkflowBrowserSessionResult($result);
        }

        return $result;
    }

    protected function applyExternalResult(WorkflowStepRun $stepRun, array $result): array
    {
        if (in_array($stepRun->external_run_type, ['workflow-task', 'client-controller-workflow-task', 'client-controller-workflow-run'], true)) {
            $this->applyWorkflowVariablesResult($stepRun->workflowRun, $result);
            $this->applyWorkflowTaskMailAccountPersistence($stepRun->workflowRun, $result);
        }

        if ($stepRun->external_run_type === 'mail-registration') {
            $this->applyMailRegistrationResult($stepRun->workflowRun, $result);

            return $result;
        }

        if ($stepRun->external_run_type === 'webmail-session') {
            $this->applyWebmailSessionResult($stepRun->workflowRun, $result);

            return $result;
        }

        if (
            in_array($stepRun->external_run_type, ['workflow-task', 'client-controller-workflow-task', 'client-controller-workflow-run'], true)
            && (
                $stepRun->workflowStep?->type === WorkflowStep::TYPE_MAIL_ACCOUNT_REGISTRATION
                || (bool) data_get($result, 'account.generated', false)
            )
        ) {
            $this->applyMailRegistrationResult($stepRun->workflowRun, $result);
        }

        if (
            in_array($stepRun->external_run_type, ['workflow-task', 'client-controller-workflow-task', 'client-controller-workflow-run'], true)
            && (
                trim((string) data_get($result, 'webmailSessionFilePath', '')) !== ''
                || trim((string) data_get($result, 'encryptedSessionPayload', '')) !== ''
            )
        ) {
            $result = $this->finalizeWorkflowWebmailSessionResult($result);
            $this->applyWebmailSessionResult($stepRun->workflowRun, $result);
        }

        if (
            in_array($stepRun->external_run_type, ['workflow-task', 'client-controller-workflow-task', 'client-controller-workflow-run'], true)
            && (
                trim((string) data_get($result, 'browserSessionFilePath', '')) !== ''
                || trim((string) data_get($result, 'encryptedBrowserSessionPayload', '')) !== ''
            )
        ) {
            $result = $this->finalizeWorkflowBrowserSessionResult($result);
            $this->applyBrowserSessionResult($stepRun->workflowRun, $result);
        }

        if (
            in_array($stepRun->external_run_type, ['workflow-task', 'client-controller-workflow-task', 'client-controller-workflow-run'], true)
            && (
                (bool) data_get($result, 'browserSessionDeleted', false)
                || (bool) data_get($result, 'deletedBrowserSession', false)
            )
        ) {
            $this->applyBrowserSessionDeletionResult($stepRun->workflowRun, $result);
        }

        return $result;
    }

    protected function applyWorkflowVariablesResult(WorkflowRun $run, array $result): void
    {
        $variables = [
            ...(is_array($result['workflow_variables'] ?? null) ? $result['workflow_variables'] : []),
            ...(is_array($result['workflowVariables'] ?? null) ? $result['workflowVariables'] : []),
        ];

        if (array_key_exists('workflow_return', $result) || array_key_exists('workflowReturn', $result)) {
            $variables['workflow_return'] = array_key_exists('workflow_return', $result)
                ? $result['workflow_return']
                : $result['workflowReturn'];
        }

        if (array_key_exists('workflow_return_ok', $result)) {
            $variables['workflow_return_ok'] = (bool) $result['workflow_return_ok'];
        }

        $workflowReturnKey = trim((string) ($result['workflow_return_key'] ?? $result['workflowReturnKey'] ?? ''));

        if ($variables === [] && $workflowReturnKey === '') {
            return;
        }

        $context = is_array($run->context_json) ? $run->context_json : [];
        $context['workflow_variables'] = array_replace(
            is_array($context['workflow_variables'] ?? null) ? $context['workflow_variables'] : [],
            $variables,
        );
        $context['workflowVariables'] = array_replace(
            is_array($context['workflowVariables'] ?? null) ? $context['workflowVariables'] : [],
            $variables,
        );
        $loopState = is_array($context['loop_state'] ?? null) ? $context['loop_state'] : [];
        foreach ($variables as $name => $value) {
            if (is_string($name) && str_starts_with($name, '__workflow_loop_state_') && is_array($value)) {
                $loopState[substr($name, strlen('__workflow_loop_state_'))] = $value;
            }
        }
        $context['loop_state'] = $loopState;

        if (array_key_exists('workflow_return', $variables)) {
            $context['workflow_return'] = $variables['workflow_return'];
            $context['workflowReturn'] = $variables['workflow_return'];
        }

        if (array_key_exists('workflow_return_ok', $variables)) {
            $context['workflow_return_ok'] = (bool) $variables['workflow_return_ok'];
        }

        if ($workflowReturnKey !== '') {
            $context['workflow_return_key'] = $workflowReturnKey;
            $context['workflowReturnKey'] = $workflowReturnKey;
        }

        $run->forceFill(['context_json' => $context])->save();
    }

    protected function cancelExternalRun(WorkflowStepRun $stepRun, string $message): void
    {
        $externalRunId = trim((string) $stepRun->external_run_id);

        if ($externalRunId === '') {
            return;
        }

        match ($stepRun->external_run_type) {
            'mail-registration' => $this->mailRegistration->cancelRun($externalRunId, true, $message),
            'webmail-session' => $this->webmailSession->cancelRun($externalRunId, true, $message),
            'workflow-task' => $this->workflowTasks->cancelRun($externalRunId, false, $message),
            'client-controller-workflow-task', 'client-controller-workflow-run' => NetworkJob::query()
                ->where('job_uuid', $externalRunId)
                ->whereNotIn('status', ['success', 'failed', 'cancelled'])
                ->update(['status' => 'cancelled', 'completed_at' => now(), 'error_message' => $message]),
            default => null,
        };
    }

    protected function terminateExternalRun(WorkflowStepRun $stepRun, string $message): bool
    {
        $externalRunId = trim((string) $stepRun->external_run_id);

        if ($externalRunId === '') {
            return false;
        }

        $result = match ($stepRun->external_run_type) {
            'mail-registration' => $this->mailRegistration->cancelRun($externalRunId, true, $message),
            'webmail-session' => $this->webmailSession->cancelRun($externalRunId, true, $message),
            'workflow-task' => $this->workflowTasks->cancelRun($externalRunId, true, $message),
            default => null,
        };

        if (! is_array($result)) {
            return false;
        }

        if (! (bool) ($result['ok'] ?? false)) {
            throw new \RuntimeException((string) ($result['message'] ?? 'Der externe Prozess konnte nicht beendet werden.'));
        }

        return true;
    }

    protected function applyMailRegistrationResult(WorkflowRun $run, array $result): void
    {
        $person = $this->personForRun($run);
        $account = is_array($result['account'] ?? null) ? $result['account'] : null;

        if (! $person || ! $account) {
            return;
        }

        app(PersistMailAccountTask::class)->handle($person, $account);
    }

    protected function applyWorkflowTaskMailAccountPersistence(WorkflowRun $run, array $result): void
    {
        $person = $this->personForRun($run);

        if (! $person) {
            return;
        }

        foreach ($this->mailAccountsToPersistFromWorkflowTaskResult($result) as $account) {
            app(PersistMailAccountTask::class)->handle($person, $account);
        }
    }

    protected function mailAccountsToPersistFromWorkflowTaskResult(array $result): array
    {
        $accounts = [];

        if ($this->shouldPersistMailAccountPayload($result)) {
            $account = $this->mailAccountPayload($result);

            if ($account !== null) {
                $accounts[] = $account;
            }
        }

        $tasks = is_array($result['tasks'] ?? null) ? array_reverse($result['tasks']) : [];

        foreach ($tasks as $taskPayload) {
            if (! is_array($taskPayload) || ! $this->shouldPersistMailAccountPayload($taskPayload)) {
                continue;
            }

            $account = $this->mailAccountPayload($taskPayload);

            if ($account !== null) {
                $accounts[] = $account;
            }
        }

        $seen = [];

        return collect($accounts)
            ->filter(fn (array $account): bool => trim((string) ($account['email'] ?? '')) !== '')
            ->filter(function (array $account) use (&$seen): bool {
                $key = strtolower(trim((string) ($account['email'] ?? ''))).'|'.strtolower(trim((string) ($account['provider'] ?? '')));

                if (isset($seen[$key])) {
                    return false;
                }

                $seen[$key] = true;

                return true;
            })
            ->values()
            ->all();
    }

    protected function shouldPersistMailAccountPayload(array $payload): bool
    {
        if (array_key_exists('persist_mail_account', $payload) || array_key_exists('persistMailAccount', $payload)) {
            return filter_var(
                $payload['persist_mail_account'] ?? $payload['persistMailAccount'],
                FILTER_VALIDATE_BOOL,
                FILTER_NULL_ON_FAILURE,
            ) ?? false;
        }

        $taskKey = trim((string) ($payload['task_key'] ?? $payload['taskKey'] ?? ''));

        return in_array($taskKey, ['data.persist_mail_account', 'data.save_workflow_data'], true);
    }

    protected function mailAccountPayload(array $payload): ?array
    {
        $account = is_array($payload['account'] ?? null)
            ? $payload['account']
            : (is_array($payload['email_account'] ?? null) ? $payload['email_account'] : null);

        if ($account === null) {
            return null;
        }

        $account['email'] = trim((string) ($account['email'] ?? ''));

        return $account['email'] === '' ? null : $account;
    }

    protected function applyWebmailSessionResult(WorkflowRun $run, array $result): void
    {
        $person = $this->personForRun($run);
        $encryptedPayload = trim((string) ($result['encryptedSessionPayload'] ?? ''));

        if ($encryptedPayload === '') {
            return;
        }

        $mailboxSource = trim((string) ($result['mailboxSource'] ?? $result['mailbox_source'] ?? 'person'));

        if ($person && ! in_array($mailboxSource, ['verification', 'verification_mailbox', 'veri-account', 'veri_account', 'main', 'master'], true)) {
            app(PersistWebmailSessionTask::class)->handle($person, $result);

            return;
        }

        app(PersistWebmailSessionTask::class)->handleVerificationMailbox($result);
    }

    protected function applyBrowserSessionResult(WorkflowRun $run, array $result): void
    {
        $person = $this->personForRun($run);
        $encryptedPayload = trim((string) ($result['encryptedBrowserSessionPayload'] ?? ''));

        if (! $person || $encryptedPayload === '') {
            return;
        }

        app(PersistBrowserSessionTask::class)->handle($person, $result);
    }

    protected function applyBrowserSessionDeletionResult(WorkflowRun $run, array $result): void
    {
        $person = $this->personForRun($run);

        if (! $person) {
            return;
        }

        app(PersistBrowserSessionTask::class)->delete($person, $result);
    }

    protected function finalizeWorkflowWebmailSessionResult(array $result): array
    {
        if (trim((string) ($result['encryptedSessionPayload'] ?? '')) !== '') {
            return $result;
        }

        $sessionFilePath = trim((string) ($result['webmailSessionFilePath'] ?? $result['webmail_session_file_path'] ?? ''));

        if ($sessionFilePath === '' || ! File::exists($sessionFilePath)) {
            $result['ok'] = false;
            $result['status'] = 'failed';
            $result['statusMessage'] = 'Webmail-Session-Datei wurde nicht gefunden.';

            return $result;
        }

        $sessionPayload = trim((string) File::get($sessionFilePath));

        if ($sessionPayload === '') {
            File::delete($sessionFilePath);
            $result['ok'] = false;
            $result['status'] = 'failed';
            $result['statusMessage'] = 'Webmail-Session-Datei ist leer.';

            return $result;
        }

        $decodedSession = json_decode($sessionPayload, true);
        $summary = is_array($result['sessionSummary'] ?? null) ? $result['sessionSummary'] : [];

        $result['encryptedSessionPayload'] = Crypt::encryptString($sessionPayload);
        $result['sessionPayloadHash'] = (string) ($result['sessionPayloadHash'] ?? hash('sha256', $sessionPayload));
        $result['sessionSummary'] = array_replace([
            'capturedAt' => is_array($decodedSession) ? ($decodedSession['capturedAt'] ?? now()->toIso8601String()) : now()->toIso8601String(),
            'finalUrl' => is_array($decodedSession) ? ($decodedSession['finalUrl'] ?? null) : null,
            'origin' => is_array($decodedSession) ? ($decodedSession['origin'] ?? null) : null,
            'domain' => is_array($decodedSession) ? ($decodedSession['domain'] ?? null) : null,
            'domains' => is_array($decodedSession) && is_array($decodedSession['domains'] ?? null) ? $decodedSession['domains'] : [],
            'cookieDomains' => is_array($decodedSession) && is_array($decodedSession['cookieDomains'] ?? null) ? $decodedSession['cookieDomains'] : [],
            'cookieCount' => is_array($decodedSession) && is_array($decodedSession['cookies'] ?? null) ? count($decodedSession['cookies']) : 0,
        ], $summary);
        $result['sessionFinalized'] = true;

        File::delete($sessionFilePath);

        return $result;
    }

    protected function finalizeWorkflowBrowserSessionResult(array $result): array
    {
        if (trim((string) ($result['encryptedBrowserSessionPayload'] ?? '')) !== '') {
            return $result;
        }

        $sessionFilePath = trim((string) ($result['browserSessionFilePath'] ?? $result['browser_session_file_path'] ?? ''));

        if ($sessionFilePath === '' || ! File::exists($sessionFilePath)) {
            $result['ok'] = false;
            $result['status'] = 'failed';
            $result['statusMessage'] = 'Browser-Session-Datei wurde nicht gefunden.';

            return $result;
        }

        $sessionPayload = trim((string) File::get($sessionFilePath));

        if ($sessionPayload === '') {
            File::delete($sessionFilePath);
            $result['ok'] = false;
            $result['status'] = 'failed';
            $result['statusMessage'] = 'Browser-Session-Datei ist leer.';

            return $result;
        }

        $decodedSession = json_decode($sessionPayload, true);
        $summary = is_array($result['browserSessionSummary'] ?? null) ? $result['browserSessionSummary'] : [];

        $result['encryptedBrowserSessionPayload'] = Crypt::encryptString($sessionPayload);
        $result['browserSessionPayloadHash'] = (string) ($result['browserSessionPayloadHash'] ?? hash('sha256', $sessionPayload));
        $result['browserSessionSummary'] = array_replace([
            'capturedAt' => is_array($decodedSession) ? ($decodedSession['capturedAt'] ?? now()->toIso8601String()) : now()->toIso8601String(),
            'finalUrl' => is_array($decodedSession) ? ($decodedSession['finalUrl'] ?? null) : null,
            'origin' => is_array($decodedSession) ? ($decodedSession['origin'] ?? null) : null,
            'domain' => is_array($decodedSession) ? ($decodedSession['domain'] ?? null) : null,
            'domains' => is_array($decodedSession) && is_array($decodedSession['domains'] ?? null) ? $decodedSession['domains'] : [],
            'cookieDomains' => is_array($decodedSession) && is_array($decodedSession['cookieDomains'] ?? null) ? $decodedSession['cookieDomains'] : [],
            'cookieCount' => is_array($decodedSession) && is_array($decodedSession['cookies'] ?? null) ? count($decodedSession['cookies']) : 0,
        ], $summary);
        $result['browserSessionFinalized'] = true;

        File::delete($sessionFilePath);

        return $result;
    }

    protected function mailRegistrationSubject(WorkflowRun $run, WorkflowStep $step): array
    {
        $person = $this->personForRun($run, $step);
        $configuredSubject = is_array(data_get($step->config_json, 'subject'))
            ? data_get($step->config_json, 'subject')
            : [];

        if (! $person) {
            return array_replace([
                'displayName' => '',
                'desiredEmail' => '',
                'accountUsername' => '',
            ], $configuredSubject);
        }

        $emailAccount = is_array(data_get($person->metadata, 'email_account'))
            ? data_get($person->metadata, 'email_account')
            : [];
        $desiredEmail = trim((string) ($emailAccount['email'] ?? $person->person_email ?? ''));
        $username = trim((string) ($emailAccount['username'] ?? '')) ?: ($desiredEmail ?: $this->suggestedUsername($person));

        return array_replace([
            'personId' => $person->id,
            'displayName' => $person->display_name,
            'firstName' => $person->person_first_name,
            'lastName' => $person->person_last_name,
            'desiredEmail' => $desiredEmail,
            'accountUsername' => $username,
            'recoveryEmail' => (string) ($emailAccount['recovery_email'] ?? ''),
            'city' => $person->person_city,
            'country' => $person->person_country,
            'timezone' => $person->person_timezone,
        ], $configuredSubject);
    }

    protected function webmailAccount(WorkflowRun $run, WorkflowStep $step): array
    {
        $config = is_array($step->config_json) ? $step->config_json : [];
        $account = is_array($config['account'] ?? null) ? $config['account'] : [];
        $person = ((bool) ($config['use_person_email_account'] ?? true)) ? $this->personForRun($run, $step) : null;
        $settings = $this->mailRegistration->settings();

        if ($person) {
            $emailAccount = is_array(data_get($person->metadata, 'email_account'))
                ? data_get($person->metadata, 'email_account')
                : [];

            $account = array_replace([
                'provider' => $emailAccount['provider'] ?? ($config['provider'] ?? 'proton'),
                'email' => $emailAccount['email'] ?? $person->person_email,
                'username' => $emailAccount['username'] ?? ($emailAccount['email'] ?? $person->person_email),
                'password' => $this->decryptString($emailAccount['password_encrypted'] ?? null),
                'webmailUrl' => $emailAccount['webmail_url'] ?? null,
                'personId' => $person->id,
            ], $account);
        }

        $account['provider'] = $this->normalizeProvider($account['provider'] ?? $config['provider'] ?? 'proton');
        $account['email'] = trim((string) ($account['email'] ?? ''));
        $account['username'] = trim((string) ($account['username'] ?? $account['email']));
        $password = trim((string) ($account['password'] ?? ''));

        if ($password === '') {
            $password = (string) ($this->decryptString($account['password_encrypted'] ?? null) ?? '');
        }

        $account['password'] = $password;
        $account['webmailUrl'] = trim((string) ($account['webmailUrl'] ?? $account['webmail_url'] ?? ''))
            ?: $this->defaultWebmailUrl($account['provider']);
        $account['browserEngine'] = $settings['browser_engine'] ?? 'cloak-with-chrome-fallback';
        $account['cloakHumanizeEnabled'] = (bool) ($settings['cloak_humanize_enabled'] ?? false);
        $account['cloakHumanPreset'] = $settings['cloak_human_preset'] ?? '';
        $account['headlessEnabled'] = (bool) ($settings['headless_enabled'] ?? false);
        $account['livePreviewEnabled'] = (bool) ($settings['live_preview_enabled'] ?? true);
        $account['livePreviewIntervalSeconds'] = max(1, (int) ($settings['live_preview_interval_seconds'] ?? 3));
        $account['navigationTimeoutMs'] = ((int) ($settings['navigation_timeout_seconds'] ?? 120)) * 1000;
        $account['observationTimeoutMs'] = min(180000, max(30000, ((int) ($settings['observation_timeout_seconds'] ?? 60)) * 1000));

        if (trim($account['email']) === '' || trim($account['username']) === '' || trim($account['password']) === '') {
            throw new \RuntimeException('Fuer den Webmail-Login fehlen E-Mail, Benutzername oder Passwort.');
        }

        return $account;
    }

    protected function personForRun(WorkflowRun $run, ?WorkflowStep $step = null): ?Person
    {
        $personId = (int) (
            data_get($step?->config_json, 'person_id')
            ?: data_get($run->context_json, 'person_id')
            ?: 0
        );

        return $personId > 0 ? Person::query()->find($personId) : null;
    }

    protected function workflowRuntimeContext(WorkflowRun $run, WorkflowStep $step, WorkflowStepRun $stepRun): array
    {
        $person = $this->personForRun($run, $step);
        $settings = $this->mailRegistration->settings();
        $verificationMailbox = $this->workflowVerificationMailbox($settings['verification_mailbox'] ?? []);
        $emailAccount = $person && is_array(data_get($person->metadata, 'email_account'))
            ? data_get($person->metadata, 'email_account')
            : [];
        $accountEmail = trim((string) ($emailAccount['email'] ?? $person?->person_email ?? ''));
        $accountUsername = trim((string) ($emailAccount['username'] ?? $accountEmail));
        $accountProvider = (string) ($emailAccount['provider'] ?? 'proton');
        $accountPassword = trim((string) ($emailAccount['password'] ?? ''));

        if ($accountPassword === '') {
            $accountPassword = (string) ($this->decryptString($emailAccount['password_encrypted'] ?? null) ?? '');
        }

        $webmailSessionPayload = $this->decryptedWebmailSessionPayload($emailAccount);
        $browserSessions = $person
            ? $this->decryptedBrowserSessionPayloads(is_array($person->metadata) ? $person->metadata : [])
            : [];
        $accountPayload = [
            'provider' => $accountProvider,
            'email' => $accountEmail,
            'username' => $accountUsername,
            'password' => $accountPassword,
            'webmailUrl' => trim((string) ($emailAccount['webmail_url'] ?? '')) ?: $this->defaultWebmailUrl($accountProvider),
            'hasPassword' => $accountPassword !== '',
            'hasWebmailSession' => is_array($webmailSessionPayload),
            'webmailSession' => $webmailSessionPayload,
            'browserSessions' => $browserSessions,
            'browser_sessions' => $browserSessions,
        ];
        $effectiveAccount = $person ? $accountPayload : $verificationMailbox;
        $effectiveAccountEmail = trim((string) ($effectiveAccount['email'] ?? ''));
        $effectiveAccountUsername = trim((string) ($effectiveAccount['username'] ?? $effectiveAccountEmail));
        $effectiveAccountPassword = (string) ($effectiveAccount['password'] ?? '');
        $personPayload = null;

        if ($person) {
            $personPayload = [
                'id' => $person->id,
                'displayName' => $person->display_name,
                'firstName' => $person->person_first_name,
                'lastName' => $person->person_last_name,
                'email' => $effectiveAccountEmail ?: $person->person_email,
                'username' => $effectiveAccountUsername,
                'password' => $effectiveAccountPassword,
                'provider' => $effectiveAccount['provider'] ?? $accountProvider,
                'webmailUrl' => $effectiveAccount['webmailUrl'] ?? $effectiveAccount['webmail_url'] ?? '',
                'hasPassword' => (bool) ($effectiveAccount['hasPassword'] ?? ($effectiveAccountPassword !== '')),
                'phone' => $person->person_phone,
                'country' => $person->person_country,
                'city' => $person->person_city,
                'timezone' => $person->person_timezone,
                'loginUsername' => $person->login_username,
                'emailAccount' => $effectiveAccount,
                'browserSessions' => $browserSessions,
                'browser_sessions' => $browserSessions,
                'metadata' => [
                    'browser_sessions' => $browserSessions,
                ],
            ];
        } elseif ($effectiveAccountEmail !== '') {
            $personPayload = [
                'id' => null,
                'displayName' => 'Verification Mailbox',
                'firstName' => '',
                'lastName' => '',
                'email' => $effectiveAccountEmail,
                'username' => $effectiveAccountUsername,
                'password' => $effectiveAccountPassword,
                'provider' => $effectiveAccount['provider'] ?? 'proton',
                'webmailUrl' => $effectiveAccount['webmailUrl'] ?? $effectiveAccount['webmail_url'] ?? '',
                'hasPassword' => (bool) ($effectiveAccount['hasPassword'] ?? ($effectiveAccountPassword !== '')),
                'phone' => '',
                'country' => '',
                'city' => '',
                'timezone' => '',
                'loginUsername' => $effectiveAccountUsername,
                'emailAccount' => $effectiveAccount,
                'isVerificationMailbox' => true,
            ];
        }

        $context = is_array($run->context_json) ? $run->context_json : [];
        $browserWindows = is_array($context['browser_windows'] ?? null) ? $context['browser_windows'] : [];
        $browserRuntime = is_array($context['browser_runtime'] ?? null) ? $context['browser_runtime'] : [];
        $workflowVariables = array_replace(
            is_array($context['workflow_variables'] ?? null) ? $context['workflow_variables'] : [],
            is_array($context['workflowVariables'] ?? null) ? $context['workflowVariables'] : [],
        );
        $contextValue = static function (array $source, string ...$keys): mixed {
            foreach ($keys as $key) {
                if (array_key_exists($key, $source)) {
                    return $source[$key];
                }
            }

            return null;
        };
        $verificationCode = $contextValue($context, 'verification_code', 'verificationCode')
            ?? $contextValue($workflowVariables, 'verification_code', 'verificationCode');
        $workflowReturn = $contextValue($context, 'workflow_return', 'workflowReturn')
            ?? $contextValue($workflowVariables, 'workflow_return', 'workflowReturn');
        $workflowReturnOk = $contextValue($context, 'workflow_return_ok')
            ?? $contextValue($workflowVariables, 'workflow_return_ok');
        $workflowReturnKey = $contextValue($context, 'workflow_return_key', 'workflowReturnKey')
            ?? $contextValue($workflowVariables, 'workflow_return_key', 'workflowReturnKey');
        $generatedPassword = $contextValue($context, 'new_password', 'generated_password', 'generated-password')
            ?? $contextValue($workflowVariables, 'new_password', 'generated_password', 'generated-password');

        return [
            'workflowRunId' => $run->id,
            'workflowRunUuid' => $run->run_uuid,
            'workflowName' => $run->workflow?->name,
            'workflowSlug' => $run->workflow?->slug,
            'workflowStepId' => $step->id,
            'workflowStepRunId' => $stepRun->id,
            'workflowStepName' => $step->name,
            'workflowStepType' => $step->type,
            'nextTaskKey' => trim((string) data_get($run->context_json, 'next_task_key', '')) ?: null,
            'next_task_key' => trim((string) data_get($run->context_json, 'next_task_key', '')) ?: null,
            'nextTaskRouteOutcome' => trim((string) data_get($run->context_json, 'next_task_route_outcome', '')) ?: null,
            'next_task_route_outcome' => trim((string) data_get($run->context_json, 'next_task_route_outcome', '')) ?: null,
            'nextTaskRouteSourceKey' => trim((string) data_get($run->context_json, 'next_task_route_source_key', '')) ?: null,
            'next_task_route_source_key' => trim((string) data_get($run->context_json, 'next_task_route_source_key', '')) ?: null,
            'workflowCopilotSessionId' => (int) data_get($run->context_json, 'workflow_copilot_session_id', 0) ?: null,
            'workflow_copilot_session_id' => (int) data_get($run->context_json, 'workflow_copilot_session_id', 0) ?: null,
            'copilotSupervised' => (bool) data_get($run->context_json, 'copilot_supervised', false) || (bool) data_get($run->context_json, 'interactive_debug', false),
            'copilot_supervised' => (bool) data_get($run->context_json, 'copilot_supervised', false) || (bool) data_get($run->context_json, 'interactive_debug', false),
            'copilotTransientTask' => data_get($run->context_json, 'copilot_transient_task'),
            'copilot_transient_task' => data_get($run->context_json, 'copilot_transient_task'),
            'personId' => data_get($run->context_json, 'person_id'),
            'browserWindows' => $browserWindows,
            'browser_windows' => $browserWindows,
            'browser' => $browserRuntime,
            'browser_runtime' => $browserRuntime,
            'workflowVariables' => $workflowVariables,
            'workflow_variables' => $workflowVariables,
            'verificationCode' => $verificationCode,
            'verification_code' => $verificationCode,
            'workflowReturn' => $workflowReturn,
            'workflow_return' => $workflowReturn,
            'workflow_return_ok' => $workflowReturnOk,
            'workflowReturnKey' => $workflowReturnKey,
            'workflow_return_key' => $workflowReturnKey,
            'generated_password' => $generatedPassword,
            'new_password' => $generatedPassword,
            'generated-password' => $generatedPassword,
            'account' => $effectiveAccountEmail !== '' ? $effectiveAccount : null,
            'email_account' => $effectiveAccountEmail !== '' ? $effectiveAccount : null,
            'verificationMailbox' => $verificationMailbox,
            'verification_mailbox' => $verificationMailbox,
            'veri_account' => $verificationMailbox,
            'veri-account' => $verificationMailbox,
            'browserSessions' => $browserSessions,
            'browser_sessions' => $browserSessions,
            'person' => $personPayload,
        ];
    }

    protected function workflowVerificationMailbox(mixed $mailbox): array
    {
        $mailbox = is_array($mailbox) ? $mailbox : [];
        $provider = strtolower(trim((string) ($mailbox['provider'] ?? 'proton')));
        $provider = str_contains($provider, 'gmx') ? 'gmx' : 'proton';
        $email = trim((string) ($mailbox['email'] ?? ''));
        $username = trim((string) ($mailbox['username'] ?? '')) ?: $email;
        $webmailUrl = trim((string) ($mailbox['webmail_url'] ?? $mailbox['webmailUrl'] ?? ''))
            ?: $this->defaultWebmailUrl($provider);
        $password = trim((string) ($mailbox['password'] ?? ''));

        if ($password === '') {
            $password = (string) ($this->decryptString($mailbox['password_encrypted'] ?? null) ?? '');
        }

        $webmailSessionPayload = $this->decryptedWebmailSessionPayload($mailbox);

        return [
            'enabled' => (bool) ($mailbox['enabled'] ?? false),
            'email' => $email,
            'provider' => $provider,
            'username' => $username,
            'password' => $password,
            'webmailUrl' => $webmailUrl,
            'webmail_url' => $webmailUrl,
            'hasPassword' => $password !== '',
            'hasWebmailSession' => is_array($webmailSessionPayload),
            'webmailSession' => $webmailSessionPayload,
            'webmail_session' => $webmailSessionPayload,
        ];
    }

    protected function decryptedWebmailSessionPayload(array $emailAccount): ?array
    {
        $encryptedPayload = trim((string) data_get($emailAccount, 'webmail_session.payload_encrypted', ''));

        if ($encryptedPayload === '') {
            return null;
        }

        $decrypted = $this->decryptString($encryptedPayload);
        $payload = is_string($decrypted) ? json_decode($decrypted, true) : null;

        return is_array($payload) ? $payload : null;
    }

    protected function decryptedBrowserSessionPayloads(array $metadata): array
    {
        $sessions = is_array($metadata['browser_sessions'] ?? null) ? $metadata['browser_sessions'] : [];
        $decryptedSessions = [];

        foreach ($sessions as $key => $session) {
            if (! is_array($session)) {
                continue;
            }

            $encryptedPayload = trim((string) ($session['payload_encrypted'] ?? ''));
            $decrypted = $this->decryptString($encryptedPayload);
            $payload = is_string($decrypted) ? json_decode($decrypted, true) : null;

            if (! is_array($payload)) {
                continue;
            }

            $sessionKey = trim((string) ($session['session_key'] ?? $key));
            $publicSession = collect($session)->except(['payload_encrypted'])->all();
            $decryptedSessions[$sessionKey !== '' ? $sessionKey : (string) $key] = array_replace(
                $publicSession,
                $payload,
                [
                    'sessionKey' => $sessionKey !== '' ? $sessionKey : (string) $key,
                    'session_key' => $sessionKey !== '' ? $sessionKey : (string) $key,
                    'finalUrl' => $payload['finalUrl'] ?? $session['final_url'] ?? null,
                    'final_url' => $session['final_url'] ?? $payload['finalUrl'] ?? null,
                    'updated_at' => $session['updated_at'] ?? null,
                ],
            );
        }

        return $decryptedSessions;
    }

    protected function mergeWorkflowBrowserState(array $context, array $result): array
    {
        $closedWindow = trim((string) ($result['closedBrowserWindow'] ?? ''));
        $closedBrowser = (bool) ($result['closedBrowser'] ?? false);

        if ($closedWindow !== '' && is_array($context['browser_windows'] ?? null)) {
            unset($context['browser_windows'][$closedWindow]);
            $context['browserWindows'] = array_values($context['browser_windows']);
        }

        if ($closedBrowser) {
            unset($context['browser_runtime'], $context['browser_ws_endpoint'], $context['browser_windows'], $context['browserWindows']);
        } else {
            $wsEndpoint = trim((string) ($result['browserWsEndpoint'] ?? data_get($result, 'browser.wsEndpoint', '')));

            if ($wsEndpoint !== '') {
                $context['browser_runtime'] = [
                    'wsEndpoint' => $wsEndpoint,
                    'updatedAt' => now()->toIso8601String(),
                ];
                $context['browser_ws_endpoint'] = $wsEndpoint;
            }
        }

        $windows = collect(data_get($result, 'browserWindows', []))
            ->filter(fn (mixed $window): bool => is_array($window))
            ->mapWithKeys(function (array $window): array {
                $key = trim((string) ($window['key'] ?? $window['name'] ?? ''));
                $url = trim((string) ($window['url'] ?? ''));

                if ($key === '' || $url === '' || $url === 'about:blank') {
                    return [];
                }

                return [
                    $key => [
                        'key' => $key,
                        'label' => trim((string) ($window['label'] ?? $key)) ?: $key,
                        'url' => $url,
                        'title' => trim((string) ($window['title'] ?? '')),
                        'targetId' => trim((string) ($window['targetId'] ?? $window['target_id'] ?? '')),
                        'capturedAt' => trim((string) ($window['capturedAt'] ?? now()->toIso8601String())),
                        'livePreviewRelativePath' => trim((string) ($window['livePreviewRelativePath'] ?? $window['screenshotRelativePath'] ?? '')),
                        'debugDomRelativePath' => trim((string) ($window['debugDomRelativePath'] ?? '')),
                        'screenshotUrl' => trim((string) ($window['screenshotUrl'] ?? '')),
                        'debugDomUrl' => trim((string) ($window['debugDomUrl'] ?? '')),
                    ],
                ];
            })
            ->all();

        if ($windows === []) {
            return $context;
        }

        $existing = is_array($context['browser_windows'] ?? null) ? $context['browser_windows'] : [];
        $context['browser_windows'] = array_replace($existing, $windows);
        $context['browserWindows'] = array_values($context['browser_windows']);

        return $context;
    }

    protected function scheduleMonitor(WorkflowStepRun $stepRun, int $delaySeconds = 10): void
    {
        if (! in_array($stepRun->external_run_type, ['mail-registration', 'webmail-session', 'workflow-task', 'client-controller-workflow-task', 'client-controller-workflow-run'], true)) {
            return;
        }

        MonitorWorkflowStepRunJob::dispatch($stepRun->id)->delay(now()->addSeconds(max(1, min(60, $delaySeconds))));
    }

    protected function normalizeContext(array $context): array
    {
        $copilotSessionId = max(0, (int) ($context['workflow_copilot_session_id'] ?? $context['workflowCopilotSessionId'] ?? 0));
        $executionTarget = $copilotSessionId > 0
            ? 'system'
            : (in_array(($context['execution_target'] ?? 'system'), ['system', 'client_controller'], true)
                ? (string) ($context['execution_target'] ?? 'system')
                : 'system');

        return [
            ...$context,
            'person_id' => (int) ($context['person_id'] ?? $context['personId'] ?? 0) ?: null,
            'started_from' => (string) ($context['started_from'] ?? 'workflow-manager'),
            'workflow_copilot_session_id' => $copilotSessionId ?: null,
            'execution_target' => $executionTarget,
            'network_node_id' => $copilotSessionId > 0 ? null : ((int) ($context['network_node_id'] ?? 0) ?: null),
            'device_id' => $copilotSessionId > 0 ? null : ((int) ($context['device_id'] ?? 0) ?: null),
            'requested_network_node_id' => $copilotSessionId > 0 ? null : ((int) ($context['network_node_id'] ?? 0) ?: null),
            'requested_device_id' => $copilotSessionId > 0 ? null : ((int) ($context['device_id'] ?? 0) ?: null),
            'allow_client_reassignment' => false,
            'max_client_reassignments' => 0,
        ];
    }

    protected function releaseClientReservation(WorkflowRun $run): void
    {
        NetworkNode::query()
            ->where('workflow_reservation_run_id', $run->id)
            ->update(['workflow_reservation_run_id' => null]);
        Device::query()
            ->where('workflow_reservation_run_id', $run->id)
            ->update(['workflow_reservation_run_id' => null]);
    }

    protected function usesClientController(WorkflowRun $run): bool
    {
        if ((int) data_get($run->context_json, 'workflow_copilot_session_id', 0) > 0) {
            return false;
        }

        return data_get($run->context_json, 'execution_target', 'system') === 'client_controller';
    }

    /**
     * Serializes every Copilot continuation against workflow/session controls.
     *
     * A user pause or stop that commits first wins and turns a queued supervisor
     * continuation into a no-op. Invalid ownership or a client execution target
     * is rejected instead of being silently repaired in memory.
     */
    protected function withLockedCopilotRun(int $runId, callable $callback): mixed
    {
        $locator = WorkflowRun::query()
            ->select(['id', 'workflow_id', 'workflow_copilot_session_id'])
            ->find($runId);

        if (! $locator) {
            throw new \RuntimeException('Der Workflow-Run wurde nicht gefunden.');
        }

        $sessionId = (int) $locator->workflow_copilot_session_id;

        if ($sessionId <= 0) {
            throw new \RuntimeException('Task-Proben sind nur in ueberwachten Copilot-Laeufen erlaubt.');
        }

        return DB::transaction(function () use ($locator, $sessionId, $callback): mixed {
            $workflow = Workflow::query()->lockForUpdate()->findOrFail($locator->workflow_id);
            $session = WorkflowCopilotSession::query()->lockForUpdate()->find($sessionId);

            if (! $session || (int) $session->workflow_id !== (int) $workflow->id) {
                throw new \RuntimeException('Die Workflow-Copilot-Sitzung gehoert nicht zu diesem Workflow.');
            }

            $run = WorkflowRun::query()->lockForUpdate()->findOrFail($locator->id);
            $context = is_array($run->context_json) ? $run->context_json : [];
            $contextSessionId = (int) ($context['workflow_copilot_session_id'] ?? 0);

            if ((int) $run->workflow_id !== (int) $workflow->id
                || (int) $run->workflow_copilot_session_id !== (int) $session->id
                || $contextSessionId !== (int) $session->id) {
                throw new \RuntimeException('Der Workflow-Run ist nicht eindeutig der aktiven Copilot-Sitzung zugeordnet.');
            }

            if (! (bool) ($context['copilot_supervised'] ?? false)) {
                throw new \RuntimeException('Task-Proben sind nur in ueberwachten Copilot-Laeufen erlaubt.');
            }

            if (($context['execution_target'] ?? null) !== 'system'
                || $session->execution_target !== WorkflowCopilotSession::EXECUTION_TARGET_SYSTEM) {
                throw new \RuntimeException('Workflow-Copilot-Laeufe duerfen ausschliesslich auf execution_target=system laufen.');
            }

            if (! in_array($session->status, [
                WorkflowCopilotSession::STATUS_RUNNING,
                WorkflowCopilotSession::STATUS_REPAIRING,
                WorkflowCopilotSession::STATUS_VERIFYING,
            ], true)) {
                return null;
            }

            if (Schema::hasColumn('workflows', 'active_workflow_copilot_session_id')
                && (int) $workflow->active_workflow_copilot_session_id !== (int) $session->id) {
                throw new \RuntimeException('Die Workflow-Copilot-Sitzung besitzt den exklusiven Workflow-Lock nicht.');
            }

            if ($run->status !== 'waiting') {
                if ($run->status !== 'running' || ! $this->preserveHeldCopilotCheckpoint($run, true)) {
                    return null;
                }
            }

            return $callback($run, $workflow, $session);
        });
    }

    protected function isCopilotSupervisedRun(WorkflowRun $run): bool
    {
        return (int) data_get($run->context_json, 'workflow_copilot_session_id', 0) > 0
            && (bool) data_get($run->context_json, 'copilot_supervised', false)
            && data_get($run->context_json, 'execution_target', 'system') === 'system';
    }

    protected function notifyCopilotRunFinished(WorkflowRun $run): void
    {
        $sessionId = (int) (
            $run->workflow_copilot_session_id
            ?: data_get($run->context_json, 'workflow_copilot_session_id', 0)
        );

        if ($sessionId > 0) {
            WorkflowCopilotSupervisorJob::dispatch($sessionId);
        }
    }

    protected function clientControllerJobStatus(string $jobUuid): ?array
    {
        $job = NetworkJob::query()->where('job_uuid', $jobUuid)->first();

        if (! $job) {
            return null;
        }

        $state = match ($job->status) {
            'pending' => 'queued',
            'dispatched' => 'running',
            'success' => 'completed',
            'failed', 'cancelled', 'timed_out', 'stop_requested', 'unreachable', 'lost' => $job->status,
            default => (string) $job->status,
        };

        $result = is_array($job->result_json) ? $job->result_json : [];

        if ($job->status === 'dispatched' && $result !== []) {
            return array_replace($result, [
                'runId' => $job->job_uuid,
                'state' => 'running',
                'isRunning' => true,
                'message' => trim((string) ($result['message'] ?? $result['statusMessage'] ?? ''))
                    ?: 'ClientController-Job: dispatched',
                'networkJobUuid' => $job->job_uuid,
            ]);
        }

        return [
            'runId' => $job->job_uuid,
            'state' => $state,
            'message' => $job->error_message ?: 'ClientController-Job: '.$job->status,
            'result' => $result,
            'networkJobUuid' => $job->job_uuid,
        ];
    }

    protected function loadRun(int|WorkflowRun $workflowRun): WorkflowRun
    {
        $runId = $workflowRun instanceof WorkflowRun ? $workflowRun->id : $workflowRun;

        return WorkflowRun::query()
            ->with([
                'workflow.steps' => fn ($query) => $query->ordered(),
                'stepRuns.workflowStep',
            ])
            ->findOrFail($runId);
    }

    protected function publicRunSnapshot(array $payload): array
    {
        unset(
            $payload['encryptedSessionPayload'],
            $payload['password'],
            $payload['passwordEncrypted'],
            $payload['browserWsEndpoint'],
            $payload['browser_ws_endpoint'],
            $payload['browserSessions'],
            $payload['browser_sessions'],
            $payload['webmailSessionFilePath'],
            $payload['webmail_session_file_path'],
            $payload['browserSessionFilePath'],
            $payload['browser_session_file_path'],
            $payload['webmailSessionPayload'],
            $payload['webmail_session_payload'],
            $payload['browserSessionPayload'],
            $payload['browser_session_payload'],
            $payload['sessionPayload'],
            $payload['session_payload'],
            $payload['encryptedBrowserSessionPayload'],
            $payload['payload_encrypted'],
        );

        foreach (['account', 'email_account', 'verificationMailbox', 'verification_mailbox', 'veri_account', 'veri-account'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                unset($payload[$key]['password'], $payload[$key]['passwordEncrypted'], $payload[$key]['password_encrypted'], $payload[$key]['webmailSession'], $payload[$key]['webmail_session'], $payload[$key]['browserSessions'], $payload[$key]['browser_sessions']);
            }
        }

        if (isset($payload['person']) && is_array($payload['person'])) {
            unset($payload['person']['password'], $payload['person']['passwordEncrypted'], $payload['person']['password_encrypted'], $payload['person']['browserSessions'], $payload['person']['browser_sessions']);

            if (isset($payload['person']['emailAccount']) && is_array($payload['person']['emailAccount'])) {
                unset(
                    $payload['person']['emailAccount']['password'],
                    $payload['person']['emailAccount']['passwordEncrypted'],
                    $payload['person']['emailAccount']['password_encrypted'],
                    $payload['person']['emailAccount']['webmailSession'],
                    $payload['person']['emailAccount']['webmail_session'],
                    $payload['person']['emailAccount']['browserSessions'],
                    $payload['person']['emailAccount']['browser_sessions'],
                );
            }

            if (isset($payload['person']['metadata']) && is_array($payload['person']['metadata'])) {
                unset($payload['person']['metadata']['browser_sessions']);

                if (isset($payload['person']['metadata']['email_account']) && is_array($payload['person']['metadata']['email_account'])) {
                    unset($payload['person']['metadata']['email_account']['webmail_session']);
                }
            }
        }

        if (isset($payload['tasks']) && is_array($payload['tasks'])) {
            foreach ($payload['tasks'] as &$taskPayload) {
                if (! is_array($taskPayload)) {
                    continue;
                }

                unset(
                    $taskPayload['webmailSessionFilePath'],
                    $taskPayload['webmail_session_file_path'],
                    $taskPayload['browserSessionFilePath'],
                    $taskPayload['browser_session_file_path'],
                    $taskPayload['webmailSessionPayload'],
                    $taskPayload['webmail_session_payload'],
                    $taskPayload['browserSessionPayload'],
                    $taskPayload['browser_session_payload'],
                    $taskPayload['browserSessions'],
                    $taskPayload['browser_sessions'],
                    $taskPayload['sessionPayload'],
                    $taskPayload['session_payload'],
                    $taskPayload['encryptedSessionPayload'],
                    $taskPayload['encryptedBrowserSessionPayload'],
                    $taskPayload['payload_encrypted']
                );

                foreach (['account', 'email_account', 'verificationMailbox', 'verification_mailbox', 'veri_account', 'veri-account'] as $key) {
                    if (isset($taskPayload[$key]) && is_array($taskPayload[$key])) {
                        unset($taskPayload[$key]['password'], $taskPayload[$key]['passwordEncrypted'], $taskPayload[$key]['password_encrypted'], $taskPayload[$key]['webmailSession'], $taskPayload[$key]['webmail_session'], $taskPayload[$key]['browserSessions'], $taskPayload[$key]['browser_sessions']);
                    }
                }

                if (isset($taskPayload['person']) && is_array($taskPayload['person'])) {
                    unset($taskPayload['person']['password'], $taskPayload['person']['passwordEncrypted'], $taskPayload['person']['password_encrypted'], $taskPayload['person']['browserSessions'], $taskPayload['person']['browser_sessions']);

                    if (isset($taskPayload['person']['emailAccount']) && is_array($taskPayload['person']['emailAccount'])) {
                        unset(
                            $taskPayload['person']['emailAccount']['password'],
                            $taskPayload['person']['emailAccount']['passwordEncrypted'],
                            $taskPayload['person']['emailAccount']['password_encrypted'],
                            $taskPayload['person']['emailAccount']['webmailSession'],
                            $taskPayload['person']['emailAccount']['webmail_session'],
                            $taskPayload['person']['emailAccount']['browserSessions'],
                            $taskPayload['person']['emailAccount']['browser_sessions'],
                        );
                    }

                    if (isset($taskPayload['person']['metadata']) && is_array($taskPayload['person']['metadata'])) {
                        unset($taskPayload['person']['metadata']['browser_sessions']);

                        if (isset($taskPayload['person']['metadata']['email_account']) && is_array($taskPayload['person']['metadata']['email_account'])) {
                            unset($taskPayload['person']['metadata']['email_account']['webmail_session']);
                        }
                    }
                }
            }
            unset($taskPayload);
        }

        if (isset($payload['workflow']) && is_array($payload['workflow'])) {
            unset(
                $payload['workflow']['browser'],
                $payload['workflow']['browser_runtime'],
                $payload['workflow']['browserWsEndpoint'],
                $payload['workflow']['browser_ws_endpoint'],
                $payload['workflow']['browserSessions'],
                $payload['workflow']['browser_sessions'],
            );

            foreach (['account', 'email_account', 'verificationMailbox', 'verification_mailbox', 'veri_account', 'veri-account'] as $key) {
                if (isset($payload['workflow'][$key]) && is_array($payload['workflow'][$key])) {
                    unset($payload['workflow'][$key]['password'], $payload['workflow'][$key]['passwordEncrypted'], $payload['workflow'][$key]['password_encrypted'], $payload['workflow'][$key]['webmailSession'], $payload['workflow'][$key]['webmail_session'], $payload['workflow'][$key]['browserSessions'], $payload['workflow'][$key]['browser_sessions']);
                }
            }

            if (isset($payload['workflow']['person']['emailAccount']) && is_array($payload['workflow']['person']['emailAccount'])) {
                unset(
                    $payload['workflow']['person']['emailAccount']['password'],
                    $payload['workflow']['person']['emailAccount']['passwordEncrypted'],
                    $payload['workflow']['person']['emailAccount']['password_encrypted'],
                    $payload['workflow']['person']['emailAccount']['webmailSession'],
                    $payload['workflow']['person']['emailAccount']['webmail_session'],
                    $payload['workflow']['person']['emailAccount']['browserSessions'],
                    $payload['workflow']['person']['emailAccount']['browser_sessions'],
                );
            }

            if (isset($payload['workflow']['person']) && is_array($payload['workflow']['person'])) {
                unset(
                    $payload['workflow']['person']['password'],
                    $payload['workflow']['person']['passwordEncrypted'],
                    $payload['workflow']['person']['password_encrypted'],
                    $payload['workflow']['person']['browserSessions'],
                    $payload['workflow']['person']['browser_sessions'],
                );

                if (isset($payload['workflow']['person']['metadata']) && is_array($payload['workflow']['person']['metadata'])) {
                    unset($payload['workflow']['person']['metadata']['browser_sessions']);

                    if (isset($payload['workflow']['person']['metadata']['email_account']) && is_array($payload['workflow']['person']['metadata']['email_account'])) {
                        unset($payload['workflow']['person']['metadata']['email_account']['webmail_session']);
                    }
                }
            }
        }

        return $this->redactPublicSecretKeys($payload);
    }

    protected function redactPublicSecretKeys(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $secretKeys = [
            'password',
            'passwordEncrypted',
            'password_encrypted',
            'webmailSession',
            'webmail_session',
            'webmailSessionPayload',
            'webmail_session_payload',
            'browserSessionPayload',
            'browser_session_payload',
            'browserSessions',
            'browser_sessions',
            'sessionPayload',
            'session_payload',
            'encryptedSessionPayload',
            'encryptedBrowserSessionPayload',
            'payload_encrypted',
            'webmailSessionFilePath',
            'webmail_session_file_path',
            'browserSessionFilePath',
            'browser_session_file_path',
            'browser_sessions',
            'browserWsEndpoint',
            'browser_ws_endpoint',
        ];

        $redacted = [];

        foreach ($value as $key => $item) {
            if (is_string($key) && $this->isPublicSecretKey($key, $secretKeys)) {
                continue;
            }

            $redacted[$key] = $this->redactPublicSecretKeys($item);
        }

        return $redacted;
    }

    protected function isPublicSecretKey(string $key, array $exactKeys): bool
    {
        if (in_array($key, $exactKeys, true)) {
            return true;
        }

        $normalized = strtolower($key);

        if (! str_contains($normalized, 'password') && ! str_contains($normalized, 'passwort')) {
            return false;
        }

        return ! str_ends_with($normalized, '_source')
            && ! str_ends_with($normalized, 'source')
            && ! str_ends_with($normalized, '_variable')
            && ! str_ends_with($normalized, 'variable');
    }

    protected function logsFromExternalStatus(array $status): array
    {
        $events = is_array($status['events'] ?? null) ? array_values($status['events']) : [];
        $browserDebugEvents = is_array($status['browserDebugEvents'] ?? null) ? array_values($status['browserDebugEvents']) : [];
        $warnings = is_array($status['warnings'] ?? null) ? array_values($status['warnings']) : [];
        $message = $status['message'] ?? $status['statusMessage'] ?? null;

        if ($events === [] && is_string($message) && trim($message) !== '') {
            $events[] = [
                'at' => now()->toIso8601String(),
                'stage' => $status['stage'] ?? $status['status'] ?? 'status',
                'message' => $message,
            ];
        }

        return [
            'capturedAt' => now()->toIso8601String(),
            'state' => $status['state'] ?? $status['status'] ?? null,
            'stage' => $status['stage'] ?? null,
            'message' => $message,
            'events' => $events,
            'browserDebugEvents' => $browserDebugEvents,
            'warnings' => $warnings,
        ];
    }

    protected function withTaskStatuses(WorkflowStep $step, array $result, string $status, ?string $errorMessage = null): array
    {
        $tasks = $step->task_cards;

        if ($tasks === []) {
            return $result;
        }

        $allResultTasks = collect(is_array($result['tasks'] ?? null) ? $result['tasks'] : [])
            ->filter(fn (mixed $task): bool => is_array($task));
        $resultTasks = $allResultTasks
            ->keyBy(fn (array $task): string => (string) ($task['key'] ?? ''));

        if ($resultTasks->isEmpty()) {
            return $result;
        }

        $result['tasks'] = collect($tasks)
            ->map(function (array $task) use ($allResultTasks, $resultTasks, $status, $errorMessage): array {
                $taskKey = (string) ($task['key'] ?? '');
                $resultTask = $resultTasks->get($taskKey);

                if (($task['runner'] ?? null) === 'workflow') {
                    $includedTasks = $allResultTasks
                        ->where('parent_task_key', $taskKey)
                        ->values();

                    if ($includedTasks->isNotEmpty()) {
                        $returnTask = $includedTasks
                            ->reverse()
                            ->first(fn (array $item): bool => array_key_exists('workflow_return', $item) || array_key_exists('workflowReturn', $item));
                        $resultTask = [
                            'key' => $taskKey,
                            'status' => $includedTasks->contains(fn (array $item): bool => in_array((string) ($item['status'] ?? ''), ['failed', 'timeout'], true))
                                ? 'failed'
                                : ($includedTasks->every(fn (array $item): bool => in_array((string) ($item['status'] ?? ''), ['success', 'completed'], true)) ? 'completed' : $status),
                            'included_tasks' => $includedTasks->all(),
                        ];

                        if (is_array($returnTask)) {
                            $workflowReturn = array_key_exists('workflow_return', $returnTask)
                                ? $returnTask['workflow_return']
                                : $returnTask['workflowReturn'];
                            $resultTask['workflow_return'] = $workflowReturn;
                            $resultTask['workflowReturn'] = $workflowReturn;
                            $resultTask['workflow_return_ok'] = array_key_exists('workflow_return_ok', $returnTask)
                                ? (bool) $returnTask['workflow_return_ok']
                                : $workflowReturn !== false;

                            foreach (['workflow_return_key', 'workflowReturnKey', 'workflow_variables', 'workflowVariables'] as $returnKey) {
                                if (array_key_exists($returnKey, $returnTask)) {
                                    $resultTask[$returnKey] = $returnTask[$returnKey];
                                }
                            }
                        }
                    }
                }

                if (! is_array($resultTask)) {
                    return $task;
                }

                $merged = array_replace($task, $resultTask);
                $merged['status'] = (string) ($resultTask['status'] ?? $status);
                $merged['finishedAt'] = $resultTask['finishedAt'] ?? now()->toIso8601String();

                if ($errorMessage && ! isset($merged['errorMessage'])) {
                    $merged['errorMessage'] = $errorMessage;
                }

                return $merged;
            })
            ->values()
            ->toArray();

        return $result;
    }

    protected function isFinalStatus(string $status): bool
    {
        return in_array($status, ['completed', 'failed', 'cancelled', 'timed_out', 'lost'], true);
    }

    protected function normalizeProvider(mixed $provider): string
    {
        $provider = strtolower(trim((string) $provider));

        if ($provider === '' || str_contains($provider, 'proton')) {
            return 'proton';
        }

        if (str_contains($provider, 'gmx')) {
            return 'gmx';
        }

        return 'proton';
    }

    protected function defaultWebmailUrl(string $provider): string
    {
        return $provider === 'gmx'
            ? 'https://www.gmx.net'
            : 'https://mail.proton.me';
    }

    protected function decryptString(mixed $encrypted): ?string
    {
        if (! is_string($encrypted) || trim($encrypted) === '') {
            return null;
        }

        try {
            return Crypt::decryptString($encrypted);
        } catch (\Throwable) {
            return null;
        }
    }

    protected function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    protected function suggestedUsername(Person $person): string
    {
        $source = trim((string) (
            $person->profile_key
            ?: $person->person_alias
            ?: $person->display_name
            ?: ''
        ));

        return str($source)
            ->lower()
            ->ascii()
            ->replaceMatches('/[^a-z0-9._-]+/', '-')
            ->replaceMatches('/^[._-]+|[._-]+$/', '')
            ->replaceMatches('/[._-]{2,}/', '-')
            ->limit(64, '')
            ->toString();
    }
}
