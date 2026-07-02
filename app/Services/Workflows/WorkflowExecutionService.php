<?php

namespace App\Services\Workflows;

use App\Jobs\MonitorWorkflowStepRunJob;
use App\Jobs\RunWorkflowJob;
use App\Models\Device;
use App\Models\NetworkJob;
use App\Models\NetworkNode;
use App\Models\Person;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use App\Models\WorkflowStepRun;
use App\Services\Mail\MailAccountRegistrationRunner;
use App\Services\Mail\WebmailSessionRunner;
use App\Services\ClientController\NetworkJobDispatcher;
use App\Services\Workflows\Tasks\PersistMailAccountTask;
use App\Services\Workflows\Tasks\PersistBrowserSessionTask;
use App\Services\Workflows\Tasks\PersistWebmailSessionTask;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class WorkflowExecutionService
{
    public function __construct(
        protected MailAccountRegistrationRunner $mailRegistration,
        protected WebmailSessionRunner $webmailSession,
        protected WorkflowTaskRunner $workflowTasks,
        protected NetworkJobDispatcher $networkJobs,
        protected WorkflowResultNormalizer $resultNormalizer,
        protected WorkflowDebugArtifactService $debugArtifacts,
    ) {}

    public function start(Workflow $workflow, array $context = [], string $requestedBy = 'admin-ui'): WorkflowRun
    {
        if (! $workflow->is_active) {
            throw new \RuntimeException('Dieser Workflow ist deaktiviert.');
        }

        if (! $workflow->enabledSteps()->exists()) {
            throw new \RuntimeException('Dieser Workflow hat keine aktiven Schritte.');
        }

        $run = DB::transaction(function () use ($workflow, $context, $requestedBy): WorkflowRun {
            $run = WorkflowRun::query()->create([
                'run_uuid' => (string) Str::uuid(),
                'workflow_id' => $workflow->id,
                'status' => 'queued',
                'requested_by' => $requestedBy,
                'queued_at' => now(),
                'context_json' => $this->normalizeContext($context),
                'result_json' => [],
            ]);

            $workflow->forceFill(['last_run_at' => now()])->save();

            return $run;
        });

        RunWorkflowJob::dispatch($run->id);

        return $run;
    }

    public function advance(int|WorkflowRun $workflowRun): void
    {
        $run = $this->loadRun($workflowRun);

        if ($this->isFinalStatus($run->status)) {
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

    public function cancel(int|WorkflowRun $workflowRun, string $message = 'Workflow-Lauf wurde manuell gestoppt.'): array
    {
        $run = $this->loadRun($workflowRun);

        if ($this->isFinalStatus($run->status)) {
            return ['ok' => true, 'message' => 'Workflow-Lauf ist bereits beendet.'];
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

        $this->closeWorkflowTaskProcesses($run, $message);

        return ['ok' => true, 'message' => $message, 'cancelledStepRuns' => $stepRuns->count()];
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

        if ($this->stepRunTimedOut($stepRun)) {
            $this->expireStepRun($stepRun);

            return;
        }

        $status = $this->readExternalStatus($stepRun);

        if (! is_array($status)) {
            $message = 'Der externe Node-Lauf konnte nicht gelesen werden.';
            $this->failStepRun($stepRun, $message);
            $this->continueAfterStep($stepRun->workflowRun, $stepRun, ['ok' => false, 'statusMessage' => $message], 'failed');

            return;
        }

        $this->ingestDebugArtifacts($stepRun, $status);

        if ($this->externalStillRunning($status)) {
            if ($this->stepRunTimedOut($stepRun)) {
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

        if (in_array($stepRun->external_run_type, ['workflow-task', 'client-controller-workflow-task'], true)) {
            $this->applyWorkflowVariablesResult($stepRun->workflowRun, $result);
        }

        if (! $this->externalSucceeded($stepRun->workflowStep, $status, $result)) {
            $message = (string) (
                data_get($result, 'statusMessage')
                ?: data_get($result, 'message')
                ?: data_get($status, 'message')
                ?: 'Node-Schritt wurde nicht erfolgreich abgeschlossen.'
            );

            if ($this->routeForResult($stepRun->workflowStep, 'failed', $result)) {
                $result['routedOutcome'] = 'failed';
                $result['statusMessage'] = $message;
                $this->completeStepRun($stepRun, $result, 'failed');
                $this->continueAfterStep($stepRun->workflowRun, $stepRun, $result, 'failed');

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

    public function expireTimedOutRuns(): void
    {
        WorkflowStepRun::query()
            ->with(['workflowRun.workflow.steps', 'workflowStep'])
            ->whereIn('status', ['running', 'waiting'])
            ->whereNotNull('started_at')
            ->orderBy('started_at')
            ->limit(100)
            ->get()
            ->each(function (WorkflowStepRun $stepRun): void {
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

        $hasTaskCursor = trim((string) data_get($run->context_json, 'next_task_key', '')) !== '';

        if ($this->usesClientController($run) && $step->task_cards !== []) {
            return $this->startClientControllerWorkflowTask($run, $step, $stepRun);
        }

        if ($hasTaskCursor && $step->task_cards !== []) {
            return $this->startWorkflowTaskStep($run, $step, $stepRun);
        }

        return match ($step->type) {
            WorkflowStep::TYPE_MAIL_ACCOUNT_REGISTRATION => $this->startMailRegistrationStep($run, $step, $stepRun),
            WorkflowStep::TYPE_WEBMAIL_LOGIN => $this->startWebmailLoginStep($run, $step, $stepRun),
            WorkflowStep::TYPE_WAIT => $this->completeWaitStep($run, $step, $stepRun),
            default => $step->task_cards !== [] ? $this->startWorkflowTaskStep($run, $step, $stepRun) : $this->completePlannedActionStep($run, $step, $stepRun),
        };
    }

    protected function startWorkflowTaskStep(WorkflowRun $run, WorkflowStep $step, WorkflowStepRun $stepRun): string
    {
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
        $timeoutSeconds = max(60, $this->stepTimeoutSeconds($step));
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
            now()->addSeconds($timeoutSeconds),
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

        $this->scheduleMonitor($stepRun, 3);

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

        if ($this->hasRouteForOutcome($stepRun->workflowStep, $outcome)) {
            $this->completeStepRun($stepRun, $result, 'timeout');
            $this->continueAfterStep($stepRun->workflowRun, $stepRun, $result, $outcome);

            return;
        }

        $this->failStepRun($stepRun, $message, $result);
        $this->failRun($stepRun->workflowRun, $message);
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

        $this->closeWorkflowTaskProcesses($run, 'Workflow-Lauf wurde abgeschlossen; zugehoerige Browser-Prozesse wurden geschlossen.');
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

        $this->closeWorkflowTaskProcesses($run, 'Workflow-Lauf wurde beendet; zugehoerige Browser-Prozesse wurden geschlossen.');
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

    protected function routeForOutcome(WorkflowStep $step, string $outcome): ?array
    {
        $routes = $step->routes;
        $route = $routes[$outcome] ?? $routes['default'] ?? null;

        return is_array($route) ? $this->normalizeRoute($step, $route) : null;
    }

    protected function routeForResult(WorkflowStep $step, string $outcome, array $result): ?array
    {
        if ((bool) ($result['routeRequested'] ?? false)) {
            $completedTaskKey = trim((string) ($result['completedTaskKey'] ?? $result['completed_task_key'] ?? ''));
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
                }
            }
        }

        return $this->routeForOutcome($step, $outcome);
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
            'client-controller-workflow-task' => $this->clientControllerJobStatus($externalRunId),
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
            'client-controller-workflow-task' => is_array($status['result'] ?? null) ? $status['result'] : null,
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
            in_array($stepRun->external_run_type, ['workflow-task', 'client-controller-workflow-task'], true)
            && (
                trim((string) data_get($result, 'webmailSessionFilePath', '')) !== ''
                || trim((string) data_get($result, 'encryptedSessionPayload', '')) !== ''
            )
        ) {
            return $this->finalizeWorkflowWebmailSessionResult($result);
        }

        if (
            in_array($stepRun->external_run_type, ['workflow-task', 'client-controller-workflow-task'], true)
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
        if (in_array($stepRun->external_run_type, ['workflow-task', 'client-controller-workflow-task'], true)) {
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
            in_array($stepRun->external_run_type, ['workflow-task', 'client-controller-workflow-task'], true)
            && (
                $stepRun->workflowStep?->type === WorkflowStep::TYPE_MAIL_ACCOUNT_REGISTRATION
                || (bool) data_get($result, 'account.generated', false)
            )
        ) {
            $this->applyMailRegistrationResult($stepRun->workflowRun, $result);
        }

        if (
            in_array($stepRun->external_run_type, ['workflow-task', 'client-controller-workflow-task'], true)
            && (
                trim((string) data_get($result, 'webmailSessionFilePath', '')) !== ''
                || trim((string) data_get($result, 'encryptedSessionPayload', '')) !== ''
            )
        ) {
            $result = $this->finalizeWorkflowWebmailSessionResult($result);
            $this->applyWebmailSessionResult($stepRun->workflowRun, $result);
        }

        if (
            in_array($stepRun->external_run_type, ['workflow-task', 'client-controller-workflow-task'], true)
            && (
                trim((string) data_get($result, 'browserSessionFilePath', '')) !== ''
                || trim((string) data_get($result, 'encryptedBrowserSessionPayload', '')) !== ''
            )
        ) {
            $result = $this->finalizeWorkflowBrowserSessionResult($result);
            $this->applyBrowserSessionResult($stepRun->workflowRun, $result);
        }

        if (
            in_array($stepRun->external_run_type, ['workflow-task', 'client-controller-workflow-task'], true)
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
        $variables = array_filter([
            ...(is_array($result['workflow_variables'] ?? null) ? $result['workflow_variables'] : []),
            ...(is_array($result['workflowVariables'] ?? null) ? $result['workflowVariables'] : []),
        ], fn (mixed $value): bool => $value !== null);

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
            'client-controller-workflow-task' => NetworkJob::query()
                ->where('job_uuid', $externalRunId)
                ->whereNotIn('status', ['success', 'failed', 'cancelled'])
                ->update(['status' => 'cancelled', 'completed_at' => now(), 'error_message' => $message]),
            default => null,
        };
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
        $accountPayload = [
            'provider' => $accountProvider,
            'email' => $accountEmail,
            'username' => $accountUsername,
            'password' => $accountPassword,
            'webmailUrl' => trim((string) ($emailAccount['webmail_url'] ?? '')) ?: $this->defaultWebmailUrl($accountProvider),
            'hasPassword' => $accountPassword !== '',
            'hasWebmailSession' => is_array($webmailSessionPayload),
            'webmailSession' => $webmailSessionPayload,
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
        if (! in_array($stepRun->external_run_type, ['mail-registration', 'webmail-session', 'workflow-task', 'client-controller-workflow-task'], true)) {
            return;
        }

        MonitorWorkflowStepRunJob::dispatch($stepRun->id)->delay(now()->addSeconds(max(1, min(60, $delaySeconds))));
    }

    protected function normalizeContext(array $context): array
    {
        return [
            ...$context,
            'person_id' => (int) ($context['person_id'] ?? $context['personId'] ?? 0) ?: null,
            'started_from' => (string) ($context['started_from'] ?? 'workflow-manager'),
            'execution_target' => in_array(($context['execution_target'] ?? 'system'), ['system', 'client_controller'], true)
                ? (string) ($context['execution_target'] ?? 'system')
                : 'system',
            'network_node_id' => (int) ($context['network_node_id'] ?? 0) ?: null,
            'device_id' => (int) ($context['device_id'] ?? 0) ?: null,
        ];
    }

    protected function usesClientController(WorkflowRun $run): bool
    {
        return data_get($run->context_json, 'execution_target', 'system') === 'client_controller';
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
            'failed', 'cancelled' => $job->status,
            default => (string) $job->status,
        };

        return [
            'runId' => $job->job_uuid,
            'state' => $state,
            'message' => $job->error_message ?: 'ClientController-Job: '.$job->status,
            'result' => is_array($job->result_json) ? $job->result_json : [],
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
                unset($payload[$key]['password'], $payload[$key]['passwordEncrypted'], $payload[$key]['password_encrypted'], $payload[$key]['webmailSession'], $payload[$key]['webmail_session']);
            }
        }

        if (isset($payload['person']) && is_array($payload['person'])) {
            unset($payload['person']['password'], $payload['person']['passwordEncrypted'], $payload['person']['password_encrypted']);

            if (isset($payload['person']['emailAccount']) && is_array($payload['person']['emailAccount'])) {
                unset(
                    $payload['person']['emailAccount']['password'],
                    $payload['person']['emailAccount']['passwordEncrypted'],
                    $payload['person']['emailAccount']['password_encrypted'],
                    $payload['person']['emailAccount']['webmailSession'],
                    $payload['person']['emailAccount']['webmail_session'],
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
                    $taskPayload['sessionPayload'],
                    $taskPayload['session_payload'],
                    $taskPayload['encryptedSessionPayload'],
                    $taskPayload['encryptedBrowserSessionPayload'],
                    $taskPayload['payload_encrypted']
                );

                foreach (['account', 'email_account', 'verificationMailbox', 'verification_mailbox', 'veri_account', 'veri-account'] as $key) {
                    if (isset($taskPayload[$key]) && is_array($taskPayload[$key])) {
                        unset($taskPayload[$key]['password'], $taskPayload[$key]['passwordEncrypted'], $taskPayload[$key]['password_encrypted'], $taskPayload[$key]['webmailSession'], $taskPayload[$key]['webmail_session']);
                    }
                }

                if (isset($taskPayload['person']) && is_array($taskPayload['person'])) {
                    unset($taskPayload['person']['password'], $taskPayload['person']['passwordEncrypted'], $taskPayload['person']['password_encrypted']);

                    if (isset($taskPayload['person']['emailAccount']) && is_array($taskPayload['person']['emailAccount'])) {
                        unset(
                            $taskPayload['person']['emailAccount']['password'],
                            $taskPayload['person']['emailAccount']['passwordEncrypted'],
                            $taskPayload['person']['emailAccount']['password_encrypted'],
                            $taskPayload['person']['emailAccount']['webmailSession'],
                            $taskPayload['person']['emailAccount']['webmail_session'],
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
            );

            foreach (['account', 'email_account', 'verificationMailbox', 'verification_mailbox', 'veri_account', 'veri-account'] as $key) {
                if (isset($payload['workflow'][$key]) && is_array($payload['workflow'][$key])) {
                    unset($payload['workflow'][$key]['password'], $payload['workflow'][$key]['passwordEncrypted'], $payload['workflow'][$key]['password_encrypted'], $payload['workflow'][$key]['webmailSession'], $payload['workflow'][$key]['webmail_session']);
                }
            }

            if (isset($payload['workflow']['person']['emailAccount']) && is_array($payload['workflow']['person']['emailAccount'])) {
                unset(
                    $payload['workflow']['person']['emailAccount']['password'],
                    $payload['workflow']['person']['emailAccount']['passwordEncrypted'],
                    $payload['workflow']['person']['emailAccount']['password_encrypted'],
                    $payload['workflow']['person']['emailAccount']['webmailSession'],
                    $payload['workflow']['person']['emailAccount']['webmail_session'],
                );
            }

            if (isset($payload['workflow']['person']) && is_array($payload['workflow']['person'])) {
                unset(
                    $payload['workflow']['person']['password'],
                    $payload['workflow']['person']['passwordEncrypted'],
                    $payload['workflow']['person']['password_encrypted'],
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
        return in_array($status, ['completed', 'failed', 'cancelled'], true);
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
