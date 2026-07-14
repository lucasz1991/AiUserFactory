<?php

namespace App\Services\Workflows;

use App\Exceptions\WorkflowRevisionConflictException;
use App\Jobs\WorkflowCopilotSupervisorJob;
use App\Models\WorkflowCopilotSession;
use App\Models\WorkflowRevision;
use App\Models\WorkflowRun;
use App\Models\WorkflowRunCheckpoint;
use App\Models\WorkflowStep;
use App\Models\WorkflowTaskAttempt;
use App\Services\Ai\WorkflowCopilotVisionService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Throwable;

class WorkflowCopilotSupervisorService
{
    private const SUPERVISOR_LEASE_SECONDS = 300;

    private const MIN_VERIFICATION_VISION_CONFIDENCE = 0.7;

    public function __construct(
        protected WorkflowExecutionService $execution,
        protected WorkflowCopilotSessionService $sessions,
        protected WorkflowCopilotObservationService $observations,
        protected WorkflowCopilotVisionService $vision,
        protected WorkflowCopilotRepairService $repairs,
        protected WorkflowRevisionService $revisions,
    ) {}

    public function supervise(int $sessionId): void
    {
        $leaseToken = $this->acquireSupervisorLease($sessionId);

        if ($leaseToken === null) {
            return;
        }

        try {
            $this->superviseWithLease($sessionId);
        } finally {
            $this->releaseSupervisorLease($sessionId, $leaseToken);
        }
    }

    protected function superviseWithLease(int $sessionId): void
    {
        $session = WorkflowCopilotSession::query()
            ->with(['workflow.steps', 'activeRun.stepRuns.workflowStep'])
            ->find($sessionId);

        if (! $session || in_array($session->status, WorkflowCopilotSession::TERMINAL_STATUSES, true)) {
            return;
        }

        if ($session->execution_target !== WorkflowCopilotSession::EXECUTION_TARGET_SYSTEM) {
            throw new \DomainException('Copilot-Supervisor verweigert eine Ausfuehrung ausserhalb von execution_target=system.');
        }

        if (array_key_exists('auto_execute_workflow_actions', is_array($session->budget_json) ? $session->budget_json : [])
            && ! filter_var(data_get($session->budget_json, 'auto_execute_workflow_actions'), FILTER_VALIDATE_BOOL)) {
            $this->sessions->pause($session, 'Autonome Workflow-Aktionen sind fuer diese Sitzung nicht freigegeben.');

            return;
        }

        if ($this->budgetExceeded($session)) {
            $this->exhaustBudget($session);

            return;
        }

        if ($session->status === WorkflowCopilotSession::STATUS_PAUSED) {
            return;
        }

        if ($this->processPendingControl($session)) {
            return;
        }

        $run = $session->activeRun;

        if (! $run) {
            $this->startRepairRun($session);

            return;
        }

        $run->refresh();
        $context = is_array($run->context_json) ? $run->context_json : [];
        $checkpoint = is_array($context['copilot_checkpoint'] ?? null) ? $context['copilot_checkpoint'] : [];

        if ($checkpoint !== [] && ! $this->checkpointProcessed($session, $checkpoint)) {
            $this->processCheckpoint($session, $run, $checkpoint);

            return;
        }

        if (! in_array($run->status, ['completed', 'failed', 'cancelled', 'timed_out', 'lost'], true)) {
            return;
        }

        if ((bool) ($context['copilot_verification_run'] ?? false)) {
            $this->finishVerification($session, $run);

            return;
        }

        if ($run->status === 'completed') {
            $this->startVerification($session, $run);

            return;
        }

        $this->sessions->appendEvent(
            $session,
            'run.failed',
            'Der Reparaturlauf wurde technisch beendet; der Copilot startet eine neue Analyse.',
            ['workflow_run_id' => $run->id, 'status' => $run->status, 'error' => $run->error_message],
            'repairing',
            'error',
            true,
        );
        $this->sessions->transition(
            $session,
            WorkflowCopilotSession::STATUS_REPAIRING,
            'repairing',
            ['last_failed_run_id' => (int) $run->id],
            'Der fehlgeschlagene Lauf wird ab dem letzten reproduzierbaren Zustand neu aufgebaut.',
        );
        $this->startRepairRun($session->fresh() ?? $session);
    }

    protected function processCheckpoint(
        WorkflowCopilotSession $session,
        WorkflowRun $run,
        array $checkpoint,
    ): void {
        $checkpoint['id'] = $this->runtimeCheckpointId($run, $checkpoint);
        $stepRun = $run->stepRuns()
            ->with('workflowStep')
            ->where('workflow_step_id', (int) ($checkpoint['workflow_step_id'] ?? 0))
            ->first();

        if (! $stepRun || ! $stepRun->workflowStep) {
            throw new \RuntimeException('Der Copilot-Checkpoint enthaelt keinen gueltigen Workflow-Schritt.');
        }

        if (! $this->applyPendingInstructions($session, $run)) {
            return;
        }

        $observation = $this->observations->observe($run, $stepRun);
        $vision = [];
        $shouldAnalyze = ! (bool) ($checkpoint['successful'] ?? false)
            || (string) ($checkpoint['kind'] ?? '') === 'probe'
            || (bool) ($observation['screenshot_changed'] ?? false)
            || trim((string) data_get($observation, 'page.state', data_get($observation, 'page_state', ''))) === '';

        if ($shouldAnalyze) {
            $vision = $this->vision->analyze($observation, (string) $session->goal);
        }

        [$attempt, $storedCheckpoint] = $this->storeCheckpoint(
            $session,
            $run,
            $stepRun->workflowStep,
            $checkpoint,
            $observation,
            $vision,
        );
        $this->markCheckpointObserved($session, $checkpoint, $storedCheckpoint, $observation, $vision);

        if ((string) ($checkpoint['kind'] ?? '') === 'probe') {
            $this->processProbeResult($session->fresh() ?? $session, $run->fresh() ?? $run, $stepRun->workflowStep, $checkpoint, $observation, $vision);

            return;
        }

        if ((bool) ($checkpoint['successful'] ?? false)) {
            $message = 'Task `'.($checkpoint['task_title'] ?? $checkpoint['task_key'] ?? '').'` wurde erfolgreich ausgefuehrt.';
            $this->sessions->appendEvent(
                $session,
                'checkpoint.continue',
                $message,
                [
                    'workflow_run_id' => $run->id,
                    'task_attempt_id' => $attempt->id,
                    'checkpoint_id' => $storedCheckpoint->id,
                    'next_action' => $checkpoint['next_action'] ?? null,
                    'next_task_key' => $checkpoint['next_task_key'] ?? null,
                ],
                'executing',
                'success',
            );

            if (($checkpoint['next_action'] ?? '') === 'next_task') {
                $this->sessions->appendEvent(
                    $session,
                    'task.scheduled',
                    'Task `'.($checkpoint['next_task_key'] ?? '').'` wird als Naechstes ausgefuehrt.',
                    ['task_key' => $checkpoint['next_task_key'] ?? null],
                    'executing',
                );
            }

            $this->sessions->transition(
                $session,
                WorkflowCopilotSession::STATUS_RUNNING,
                'executing',
                ['next_action' => (string) ($checkpoint['next_action'] ?? '')],
                'Der Workflow wird am sicheren Task-Checkpoint fortgesetzt.',
            );
            $this->execution->resumeCopilotCheckpoint($run);
            $this->markContinuationApplied($session, $checkpoint, 'resume');

            return;
        }

        $this->repairFailedCheckpoint($session->fresh() ?? $session, $run->fresh() ?? $run, $stepRun->workflowStep, $checkpoint, $observation, $vision);
    }

    protected function repairFailedCheckpoint(
        WorkflowCopilotSession $session,
        WorkflowRun $run,
        WorkflowStep $step,
        array $checkpoint,
        array $observation,
        array $vision,
    ): void {
        $usage = is_array($session->usage_json) ? $session->usage_json : [];
        $repairIterations = max(0, (int) ($usage['repair_iterations'] ?? 0)) + 1;
        $usage['repair_iterations'] = $repairIterations;
        $session->forceFill([
            'status' => WorkflowCopilotSession::STATUS_REPAIRING,
            'phase' => 'repairing',
            'repair_round' => $repairIterations,
            'usage_json' => $usage,
            'last_activity_at' => now(),
        ])->save();
        $this->sessions->appendEvent(
            $session,
            'repair.analysis_started',
            'Screenshot und DOM wurden erfasst; die Reparaturanalyse fuer Task `'.($checkpoint['task_key'] ?? '').'` startet.',
            [
                'workflow_run_id' => $run->id,
                'task_key' => $checkpoint['task_key'] ?? null,
                'state_signature' => $observation['state_signature'] ?? null,
                'vision_model' => $vision['model'] ?? null,
                'vision_fallback' => $vision['fallback_used'] ?? false,
            ],
            'visual_analysis',
            'info',
            true,
        );

        if ($this->budgetExceeded($session->fresh() ?? $session)) {
            $this->exhaustBudget($session->fresh() ?? $session);

            return;
        }

        $state = is_array($session->state_json) ? $session->state_json : [];
        $rejectedSelectors = is_array($state['rejected_selectors'] ?? null) ? $state['rejected_selectors'] : [];
        $plan = $this->repairs->plan($session, $step, $checkpoint, $observation, $vision, $rejectedSelectors);

        if ($plan['action'] === 'continue_route') {
            $context = is_array($run->context_json) ? $run->context_json : [];
            $pending = is_array($context['copilot_checkpoint'] ?? null) ? $context['copilot_checkpoint'] : [];
            $pending['next_action'] = 'complete_step';
            $context['copilot_checkpoint'] = $pending;
            $run->forceFill(['context_json' => $context])->save();
            $this->sessions->appendEvent($session, 'repair.route_selected', $plan['reason'], $plan, 'repairing', 'info', true);
            $this->sessions->transition($session, WorkflowCopilotSession::STATUS_RUNNING, 'executing');
            $this->execution->resumeCopilotCheckpoint($run);
            $this->markContinuationApplied($session, $checkpoint, 'continue_route');

            return;
        }

        if ($plan['action'] === 'retry') {
            $this->sessions->appendEvent($session, 'repair.retry', $plan['reason'], $plan, 'repairing', 'info', true);
            $this->sessions->transition($session, WorkflowCopilotSession::STATUS_RUNNING, 'executing');
            $this->execution->retryCopilotTask($run, (string) $plan['task_key']);
            $this->markContinuationApplied($session, $checkpoint, 'retry');

            return;
        }

        if ($plan['action'] !== 'probe_update') {
            $this->sessions->appendEvent(
                $session,
                'repair.paused',
                (string) ($plan['reason'] ?? 'Keine sichere Reparatur gefunden.'),
                $plan,
                'repairing',
                'warning',
                true,
            );
            $this->sessions->pause($session, 'Die Reparatur wurde pausiert, weil weder Vision noch DOM eine sichere Aktion liefern.');

            return;
        }

        $probeActions = max(0, (int) ($usage['probe_actions'] ?? 0)) + 1;
        $usage['probe_actions'] = $probeActions;
        $session->forceFill([
            'usage_json' => $usage,
            'state_json' => array_replace_recursive($state, ['active_repair_plan' => $plan]),
        ])->save();
        $this->sessions->appendEvent(
            $session,
            'probe.started',
            'Probeaktion mit `'.($plan['task_catalog_key'] ?? '').'` und der vorgeschlagenen Task-Konfiguration wird ausgefuehrt.',
            Arr::except($plan, ['probe_task']),
            'probing',
            'info',
            true,
        );
        $this->execution->retryCopilotTask(
            $run,
            (string) $plan['task_key'],
            is_array($plan['probe_task'] ?? null) ? $plan['probe_task'] : null,
            $plan,
        );
        $this->markContinuationApplied($session, $checkpoint, 'probe');
    }

    protected function processProbeResult(
        WorkflowCopilotSession $session,
        WorkflowRun $run,
        WorkflowStep $step,
        array $checkpoint,
        array $observation,
        array $vision,
    ): void {
        if ($session->status === WorkflowCopilotSession::STATUS_VERIFYING) {
            $this->sessions->pause($session, 'Eine Workflow-Revision ist waehrend des eingefrorenen Kontrolllaufs nicht erlaubt.');

            return;
        }

        $context = is_array($run->context_json) ? $run->context_json : [];
        $plan = is_array($context['copilot_repair_plan'] ?? null)
            ? $context['copilot_repair_plan']
            : (is_array(data_get($session->state_json, 'active_repair_plan')) ? data_get($session->state_json, 'active_repair_plan') : []);
        $originalTaskKey = trim((string) ($plan['original_task_key'] ?? $plan['task_key'] ?? ''));

        if (! (bool) ($checkpoint['successful'] ?? false)) {
            $selector = trim((string) data_get($plan, 'changes.selector', ''));
            $state = is_array($session->state_json) ? $session->state_json : [];
            $rejected = is_array($state['rejected_selectors'] ?? null) ? $state['rejected_selectors'] : [];

            if ($selector !== '') {
                $rejected[] = $selector;
            }

            $state['rejected_selectors'] = array_values(array_unique($rejected));
            $session->forceFill(['state_json' => $state])->save();
            $this->sessions->appendEvent(
                $session,
                'probe.failed',
                'Die Probeaktion hat den erwarteten Zustand nicht erreicht; der Kandidat wird verworfen.',
                ['selector' => $selector, 'task_key' => $originalTaskKey],
                'probing',
                'warning',
                true,
            );
            $failedCheckpoint = array_replace($checkpoint, [
                'kind' => 'regular',
                'task_key' => $originalTaskKey,
            ]);
            $this->repairFailedCheckpoint($session, $run, $step, $failedCheckpoint, $observation, $vision);

            return;
        }

        if ($plan === [] || $originalTaskKey === '' || ! is_array($plan['changes'] ?? null)) {
            $this->sessions->pause($session, 'Die erfolgreiche Probe konnte keiner gespeicherten Reparatur zugeordnet werden.');

            return;
        }

        $runtimeCheckpointId = $this->runtimeCheckpointId($run, $checkpoint);
        $state = is_array($session->state_json) ? $session->state_json : [];
        $appliedRevisionNumber = (int) ($state['revision_applied_number'] ?? -1);
        $revision = null;

        if (($state['revision_applied_checkpoint_id'] ?? null) === $runtimeCheckpointId
            && $appliedRevisionNumber === (int) $session->current_revision) {
            $revision = WorkflowRevision::query()
                ->where('workflow_id', $session->workflow_id)
                ->where('revision_number', $appliedRevisionNumber)
                ->first();
        }

        if (! $revision) {
            $expectedRevision = (int) $session->current_revision;
            $reason = trim((string) ($plan['reason'] ?? 'Erfolgreich gepruefte Copilot-Reparatur.'));
            $revision = $this->revisions->apply(
                $session,
                $expectedRevision,
                $reason,
                function () use ($step, $originalTaskKey, $plan): void {
                    $freshStep = WorkflowStep::query()->findOrFail($step->id);
                    $this->repairs->applyChangesToStep($freshStep, $originalTaskKey, $plan['changes']);
                },
            );
            $session = $this->sessions->updateState($session->fresh() ?? $session, [
                'revision_applied_checkpoint_id' => $runtimeCheckpointId,
                'revision_applied_number' => (int) $revision->revision_number,
            ]);
        }

        $runContext = is_array($run->context_json) ? $run->context_json : [];
        $runContext['workflow_revision'] = (int) $revision->revision_number;
        $run->forceFill([
            'workflow_revision' => (int) $revision->revision_number,
            'context_json' => $runContext,
        ])->save();
        $this->sessions->transition(
            $session->fresh() ?? $session,
            WorkflowCopilotSession::STATUS_RUNNING,
            'rewinding',
            ['active_repair_plan' => null, 'rejected_selectors' => null],
            'Der Lauf wird zum Checkpoint vor dem geaenderten Task zurueckgesetzt.',
        );
        $this->execution->retryCopilotTask($run, $originalTaskKey, null, []);
        $this->markContinuationApplied($session, $checkpoint, 'revision_retry');
    }

    protected function startRepairRun(WorkflowCopilotSession $session, array $resumeContext = []): void
    {
        if ($session->status !== WorkflowCopilotSession::STATUS_RUNNING) {
            $session = $this->sessions->transition(
                $session,
                WorkflowCopilotSession::STATUS_RUNNING,
                'executing',
                [],
                'Der naechste System-Reparaturlauf wird gestartet.',
            );
        }

        $session->loadMissing('workflow.steps');
        $workflowInputs = is_array($session->workflow_inputs_json) ? $session->workflow_inputs_json : [];
        $context = array_replace($workflowInputs, $resumeContext, [
            'person_id' => $session->person_id,
            'workflow_variables' => $workflowInputs,
            'workflow_copilot_session_id' => (int) $session->id,
            'workflow_revision' => (int) $session->current_revision,
            'copilot_supervised' => true,
            'copilot_verification_run' => false,
            'execution_target' => 'system',
            'network_node_id' => null,
            'device_id' => null,
            'started_from' => 'workflow-copilot',
        ]);
        $run = $this->execution->start($session->workflow, $context, 'workflow-copilot');
        $session = $this->sessions->attachRun($session, $run);
        $firstStep = $session->workflow->enabledSteps()->first();
        $firstTask = $firstStep ? data_get($firstStep->task_cards, '0') : null;
        $this->sessions->updateState($session, [
            'phase' => 'executing',
            'current_step_name' => $firstStep?->name,
            'current_task_key' => is_array($firstTask) ? ($firstTask['key'] ?? null) : null,
            'last_action' => 'System-Test gestartet',
            'next_action' => 'Ersten Task ausfuehren',
        ], 'executing');
        $this->sessions->appendEvent(
            $session,
            'run.started',
            'System-Reparaturlauf #'.$run->id.' wurde gestartet.',
            ['workflow_run_id' => $run->id, 'execution_target' => 'system', 'revision' => $session->current_revision],
            'executing',
            'info',
            true,
        );
    }

    protected function startVerification(WorkflowCopilotSession $session, WorkflowRun $repairRun): void
    {
        $session = $this->sessions->transition(
            $session,
            WorkflowCopilotSession::STATUS_VERIFYING,
            'verifying',
            ['repair_run_id' => (int) $repairRun->id],
            'Kontrolllauf von Anfang an wird gestartet.',
        );
        $inputs = is_array($session->workflow_inputs_json) ? $session->workflow_inputs_json : [];
        $run = $this->execution->start($session->workflow, array_replace($inputs, [
            'person_id' => $session->person_id,
            'workflow_variables' => $inputs,
            'workflow_copilot_session_id' => (int) $session->id,
            'workflow_revision' => (int) $session->current_revision,
            'copilot_supervised' => false,
            'copilot_verification_run' => true,
            'copilot_frozen_success_criteria' => $session->success_criteria_json,
            'execution_target' => 'system',
            'network_node_id' => null,
            'device_id' => null,
            'started_from' => 'workflow-copilot-verification',
        ]), 'workflow-copilot-verification');
        $this->sessions->attachRun($session, $run);
        $this->sessions->appendEvent(
            $session,
            'verification.started',
            'Frischer End-to-End-Kontrolllauf #'.$run->id.' wurde mit der gespeicherten Revision gestartet.',
            ['workflow_run_id' => $run->id, 'revision' => $session->current_revision, 'execution_target' => 'system'],
            'verifying',
            'info',
            true,
        );
    }

    protected function finishVerification(WorkflowCopilotSession $session, WorkflowRun $run): void
    {
        $observation = $this->observations->observe($run, $run->stepRuns()->latest('id')->first());
        $vision = $this->vision->analyze($observation, (string) $session->goal);
        $technicalPass = $run->status === 'completed'
            && (bool) data_get($run->result_json, 'ok', false)
            && ! in_array(data_get($run->result_json, 'business_status'), ['failed'], true);
        $visionVerdict = strtolower(trim((string) ($vision['verdict'] ?? 'unknown')));
        $visionPass = in_array($visionVerdict, ['pass', 'success'], true)
            || ($visionVerdict === 'unknown' && (float) ($vision['confidence'] ?? 0) < 0.5 && $technicalPass);

        if ($technicalPass && $visionPass) {
            $this->revisions->markVerified($session, (int) $session->current_revision);
            $this->sessions->appendEvent(
                $session,
                'verification.passed',
                'Workflow vollstaendig erfolgreich und automatisch verifiziert.',
                [
                    'workflow_run_id' => $run->id,
                    'revision' => $session->current_revision,
                    'vision_verdict' => $visionVerdict,
                    'technical_status' => data_get($run->result_json, 'technical_status'),
                    'business_status' => data_get($run->result_json, 'business_status'),
                ],
                'verifying',
                'success',
                true,
            );
            $this->sessions->transition(
                $session,
                WorkflowCopilotSession::STATUS_SUCCEEDED,
                'completed',
                ['verification_run_id' => (int) $run->id, 'verification' => ['pass' => true, 'vision' => $vision]],
                'Workflow vollstaendig erfolgreich und automatisch verifiziert.',
            );

            return;
        }

        $this->sessions->appendEvent(
            $session,
            'verification.failed',
            'Der Kontrolllauf hat die technische und fachliche Endpruefung noch nicht bestanden.',
            [
                'workflow_run_id' => $run->id,
                'technical_pass' => $technicalPass,
                'vision_verdict' => $visionVerdict,
                'vision' => Arr::except($vision, ['raw_response']),
            ],
            'verifying',
            'warning',
            true,
        );
        $session = $this->sessions->transition(
            $session,
            WorkflowCopilotSession::STATUS_REPAIRING,
            'repairing',
            ['verification_run_id' => (int) $run->id, 'verification' => ['pass' => false, 'vision' => $vision]],
            'Die Reparaturschleife wird nach dem fehlgeschlagenen Kontrolllauf fortgesetzt.',
        );
        $this->startRepairRun($session);
    }

    protected function storeCheckpoint(
        WorkflowCopilotSession $session,
        WorkflowRun $run,
        WorkflowStep $step,
        array $checkpoint,
        array $observation,
        array $vision,
    ): array {
        $taskKey = trim((string) ($checkpoint['task_key'] ?? ''));
        $task = collect($step->task_cards)->firstWhere('key', $taskKey);
        $attempt = $this->sessions->beginTaskAttempt($session, [
            'workflow_run_id' => $run->id,
            'workflow_step_id' => $step->id,
            'workflow_revision_id' => $this->currentRevisionId($session),
            'kind' => (string) ($checkpoint['kind'] ?? 'regular'),
            'status' => 'running',
            'task_key' => $taskKey,
            'task_title' => (string) ($checkpoint['task_title'] ?? data_get($task, 'title', $taskKey)),
            'task_definition_json' => is_array($task) ? $this->safeTask($task) : [],
            'input_json' => ['execution_target' => 'system'],
            'started_at' => $checkpoint['started_at'] ?? now(),
        ]);
        $attempt = $this->sessions->finishTaskAttempt(
            $attempt,
            (bool) ($checkpoint['successful'] ?? false) ? 'completed' : 'failed',
            is_array($checkpoint['result'] ?? null) ? $checkpoint['result'] : [],
            (bool) ($checkpoint['successful'] ?? false) ? null : (string) data_get($checkpoint, 'result.statusMessage', 'Task fehlgeschlagen.'),
            is_array(data_get($checkpoint, 'result.sideEffects')) ? data_get($checkpoint, 'result.sideEffects') : [],
            [
                'screenshot_url' => $observation['screenshot_url'] ?? null,
                'screenshot_artifact_id' => data_get($observation, 'screenshot.artifact_id'),
                'vision_model' => $vision['model'] ?? null,
            ],
        );
        $stored = $this->sessions->createCheckpoint($session, [
            'workflow_run_id' => $run->id,
            'workflow_step_id' => $step->id,
            'workflow_task_attempt_id' => $attempt->id,
            'workflow_revision_id' => $this->currentRevisionId($session),
            'screenshot_artifact_id' => data_get($observation, 'screenshot.artifact_id'),
            'phase' => (bool) ($checkpoint['successful'] ?? false) ? 'observing' : 'repairing',
            'task_key' => $taskKey,
            'cursor_json' => [
                'step_id' => $step->id,
                'step_action_key' => $step->action_key,
                'step_name' => $step->name,
                'task_key' => $taskKey,
                'next_action' => $checkpoint['next_action'] ?? null,
                'next_task_key' => $checkpoint['next_task_key'] ?? null,
            ],
            'context_json' => [
                'workflow_variables_keys' => array_keys(is_array(data_get($run->context_json, 'workflow_variables')) ? data_get($run->context_json, 'workflow_variables') : []),
                'execution_target' => 'system',
            ],
            'browser_state_json' => [
                'windows' => $observation['browser_windows'] ?? [],
                'page' => $observation['page'] ?? [],
            ],
            'dom_snapshot_json' => [
                'interaction_map' => $observation['interaction_map'] ?? [],
                'sensitive_fields_removed' => $observation['sensitive_fields_removed'] ?? [],
                'vision' => Arr::except($vision, ['raw_response']),
            ],
            'state_signature' => $observation['state_signature'] ?? null,
            'side_effect_ledger_json' => is_array(data_get($checkpoint, 'result.sideEffects')) ? data_get($checkpoint, 'result.sideEffects') : [],
            'is_reproducible' => ! (bool) data_get($checkpoint, 'result.irreversibleSideEffect', false),
        ]);

        $this->sessions->appendEvent(
            $session,
            'observation.captured',
            'Screenshot und DOM wurden erfasst.',
            [
                'checkpoint_id' => $stored->id,
                'screenshot_url' => $observation['screenshot_url'] ?? null,
                'state_signature' => $observation['state_signature'] ?? null,
                'page_state' => data_get($vision, 'ui_state', data_get($observation, 'page.state')),
            ],
            'observing',
            'info',
            true,
        );

        return [$attempt, $stored];
    }

    protected function markCheckpointProcessed(
        WorkflowCopilotSession $session,
        array $runtimeCheckpoint,
        WorkflowRunCheckpoint $stored,
        array $observation,
        array $vision,
    ): void {
        $state = is_array($session->state_json) ? $session->state_json : [];
        $usage = is_array($session->usage_json) ? $session->usage_json : [];
        $signature = trim((string) ($observation['state_signature'] ?? ''));
        $previousSignature = trim((string) ($state['last_state_signature'] ?? ''));
        $usage['same_state_repeats'] = $signature !== '' && $signature === $previousSignature
            ? max(0, (int) ($usage['same_state_repeats'] ?? 0)) + 1
            : 0;
        $session->forceFill([
            'state_json' => array_replace_recursive($state, [
                'processed_checkpoint_id' => (string) ($runtimeCheckpoint['id'] ?? ''),
                'latest_checkpoint_id' => (int) $stored->id,
                'latest_checkpoint_sequence' => (int) $stored->sequence,
                'last_state_signature' => $signature,
                'current_step_name' => $runtimeCheckpoint['workflow_step_name'] ?? null,
                'current_task_key' => $runtimeCheckpoint['task_key'] ?? null,
                'latest_screenshot_url' => $observation['screenshot_url'] ?? null,
                'page_state' => data_get($vision, 'ui_state', data_get($observation, 'page.state')),
                'observation' => Arr::except($observation, ['screenshot_data_url', 'raw_dom', 'html']),
                'vision' => Arr::except($vision, ['raw_response']),
                'last_action' => $runtimeCheckpoint['kind'] === 'probe' ? 'Probeaktion ausgefuehrt' : 'Task ausgefuehrt',
                'current_result' => (bool) ($runtimeCheckpoint['successful'] ?? false) ? 'Erfolgreich' : 'Fehlgeschlagen',
                'next_action' => $runtimeCheckpoint['next_action'] ?? null,
            ]),
            'usage_json' => $usage,
            'last_activity_at' => now(),
        ])->save();
    }

    protected function checkpointProcessed(WorkflowCopilotSession $session, array $checkpoint): bool
    {
        return trim((string) data_get($session->state_json, 'processed_checkpoint_id', '')) !== ''
            && trim((string) data_get($session->state_json, 'processed_checkpoint_id', '')) === trim((string) ($checkpoint['id'] ?? ''));
    }

    protected function applyPendingInstructions(WorkflowCopilotSession $session, WorkflowRun $run): bool
    {
        $state = is_array($session->state_json) ? $session->state_json : [];
        $lastApplied = max(0, (int) ($state['last_instruction_sequence'] ?? 0));
        $instructions = $session->events()
            ->where('event_type', 'instruction.received')
            ->where('sequence', '>', $lastApplied)
            ->orderBy('sequence')
            ->get();

        foreach ($instructions as $event) {
            $instruction = trim((string) data_get($event->payload_json, 'instruction', ''));
            $state['last_instruction_sequence'] = (int) $event->sequence;
            $state['active_instructions'] = array_slice([
                ...(is_array($state['active_instructions'] ?? null) ? $state['active_instructions'] : []),
                $instruction,
            ], -20);
            $this->sessions->appendEvent(
                $session,
                'instruction.applied',
                'Benutzeranweisung wurde am sicheren Task-Checkpoint uebernommen.',
                ['instruction_sequence' => (int) $event->sequence, 'instruction' => $instruction],
                $session->phase,
                'info',
                true,
            );

            if (preg_match('/\b(halte|pause|pausiere|stoppe nach)\b/iu', $instruction)) {
                $session->forceFill(['state_json' => $state])->save();
                $this->sessions->pause($session, 'Auf Benutzeranweisung am sicheren Task-Checkpoint pausiert.');

                return false;
            }

            if (preg_match('/\b(von vorne|von anfang|neu starten)\b/iu', $instruction)) {
                $session->forceFill(['state_json' => $state])->save();
                $this->execution->cancel($run, 'Copilot startet den System-Test auf Benutzeranweisung von Anfang an neu.');
                $this->startRepairRun($session->fresh() ?? $session);

                return false;
            }
        }

        $session->forceFill(['state_json' => $state])->save();

        return true;
    }

    protected function processPendingControl(WorkflowCopilotSession $session): bool
    {
        $control = is_array(data_get($session->state_json, 'pending_control')) ? data_get($session->state_json, 'pending_control') : [];

        if (($control['action'] ?? null) !== 'rewind') {
            return false;
        }

        $checkpoint = WorkflowRunCheckpoint::query()
            ->where('workflow_copilot_session_id', $session->id)
            ->find((int) ($control['checkpoint_id'] ?? 0));

        if (! $checkpoint) {
            $this->sessions->pause($session, 'Der angeforderte Ruecksprung-Checkpoint wurde nicht gefunden.');

            return true;
        }

        if ($session->activeRun && ! in_array($session->activeRun->status, ['completed', 'failed', 'cancelled'], true)) {
            $this->execution->cancel($session->activeRun, 'Copilot springt logisch zu einem frueheren Checkpoint zurueck.');
        }

        $cursor = is_array($checkpoint->cursor_json) ? $checkpoint->cursor_json : [];
        $browser = is_array($checkpoint->browser_state_json) ? $checkpoint->browser_state_json : [];
        $state = is_array($session->state_json) ? $session->state_json : [];
        unset($state['pending_control']);
        $session->forceFill(['state_json' => $state])->save();
        $this->sessions->appendEvent(
            $session,
            'rewind.applied',
            'Der Lauf wird logisch zum Checkpoint #'.$checkpoint->sequence.' zurueckgesetzt.',
            [
                'checkpoint_id' => $checkpoint->id,
                'checkpoint_sequence' => $checkpoint->sequence,
                'irreversible_side_effects' => $checkpoint->side_effect_ledger_json ?: [],
            ],
            'rewinding',
            'warning',
            true,
        );
        $this->startRepairRun($session->fresh() ?? $session, [
            'next_step_action_key' => $cursor['step_action_key'] ?? null,
            'next_task_key' => $cursor['task_key'] ?? null,
            'browser_windows' => $browser['windows'] ?? [],
        ]);

        return true;
    }

    protected function budgetExceeded(WorkflowCopilotSession $session): bool
    {
        $budget = is_array($session->budget_json) ? $session->budget_json : [];
        $usage = is_array($session->usage_json) ? $session->usage_json : [];
        $maxMinutes = max(1, (int) ($budget['max_minutes'] ?? 90));

        return ($session->started_at && $session->started_at->diffInMinutes(now()) >= $maxMinutes)
            || (int) ($usage['repair_iterations'] ?? 0) >= max(1, (int) ($budget['max_repair_iterations'] ?? 15))
            || (int) ($usage['probe_actions'] ?? 0) >= max(1, (int) ($budget['max_probe_actions'] ?? 60))
            || (int) ($usage['same_state_repeats'] ?? 0) > max(1, (int) ($budget['max_same_state_repeats'] ?? 2));
    }

    protected function exhaustBudget(WorkflowCopilotSession $session): void
    {
        if ($session->activeRun && ! in_array($session->activeRun->status, ['completed', 'failed', 'cancelled', 'timed_out', 'lost'], true)) {
            $this->execution->cancel($session->activeRun, 'Copilot-Budget wurde erreicht.');
        }

        $session->workflow->forceFill([
            'copilot_verification_status' => 'unverified',
            'copilot_verified_at' => null,
        ])->save();
        $this->sessions->transition(
            $session,
            WorkflowCopilotSession::STATUS_BUDGET_EXHAUSTED,
            'budget_exhausted',
            ['budget_exhausted_at' => now()->toIso8601String()],
            'Copilot-Budget ist erreicht. Die letzte Revision bleibt gespeichert, unverified und gesperrt.',
            ['budget' => $session->budget_json, 'usage' => $session->usage_json],
        );
    }

    protected function currentRevisionId(WorkflowCopilotSession $session): ?int
    {
        return WorkflowRevision::query()
            ->where('workflow_copilot_session_id', $session->id)
            ->where('revision_number', $session->current_revision)
            ->value('id');
    }

    protected function safeTask(array $task): array
    {
        return Arr::except($task, [
            'value',
            'input',
            'password',
            'token',
            'cookie',
            'node_script',
            'php_handler',
        ]);
    }
}
