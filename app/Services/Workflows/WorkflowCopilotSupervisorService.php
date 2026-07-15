<?php

namespace App\Services\Workflows;

use App\Exceptions\WorkflowRevisionConflictException;
use App\Jobs\RunWorkflowJob;
use App\Jobs\WorkflowCopilotSupervisorJob;
use App\Models\Workflow;
use App\Models\WorkflowCopilotSession;
use App\Models\WorkflowRevision;
use App\Models\WorkflowRun;
use App\Models\WorkflowRunCheckpoint;
use App\Models\WorkflowStep;
use App\Models\WorkflowTaskAttempt;
use App\Services\Ai\WorkflowCopilotVisionService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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

        $run = $session->activeRun;
        $runContext = $run && is_array($run->context_json) ? $run->context_json : [];
        $pendingCheckpoint = is_array($runContext['copilot_checkpoint'] ?? null) ? $runContext['copilot_checkpoint'] : [];
        $hasUnprocessedCheckpoint = $run
            && $pendingCheckpoint !== []
            && ! $this->checkpointProcessed($session, $run, $pendingCheckpoint);

        if (! $hasUnprocessedCheckpoint && $this->budgetExceeded($session)) {
            $this->exhaustBudget($session);

            return;
        }

        if ($session->status === WorkflowCopilotSession::STATUS_PAUSED) {
            return;
        }

        if ($this->processPendingControl($session)) {
            return;
        }

        if (! $run) {
            $this->startRepairRun($session);

            return;
        }

        $run->refresh();
        $context = is_array($run->context_json) ? $run->context_json : [];
        $checkpoint = is_array($context['copilot_checkpoint'] ?? null) ? $context['copilot_checkpoint'] : [];

        if ($checkpoint !== [] && ! $this->checkpointProcessed($session, $run, $checkpoint)) {
            $this->processCheckpoint($session, $run, $checkpoint);

            return;
        }

        if ($run->status === 'queued') {
            $this->redispatchQueuedRunAfterResume($session, $run);

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
        $this->handleTechnicalRunFailure($session, $run);
    }

    /**
     * Ein technischer Fehllauf (harter Fehler mitten im Lauf, ohne reparierbaren
     * Task-Checkpoint) kann durch blindes Neustarten nicht selbst heilen. Ohne
     * Zaehler liefe der Copilot bis zum Zeitbudget in einer Endlosschleife
     * identischer Laeufe. Deshalb wird hier die Fehlersignatur verglichen, jede
     * Iteration auf das Reparaturbudget angerechnet und bei wiederholtem oder
     * ausgeschoepftem Fehlschlag mit klarer Diagnose abgebrochen.
     */
    protected function handleTechnicalRunFailure(WorkflowCopilotSession $session, WorkflowRun $run): void
    {
        $state = is_array($session->state_json) ? $session->state_json : [];
        $usage = is_array($session->usage_json) ? $session->usage_json : [];
        $budget = is_array($session->budget_json) ? $session->budget_json : [];

        $signature = $this->technicalRunFailureSignature($run);
        $previousSignature = trim((string) ($state['last_technical_failure_signature'] ?? ''));
        $repeats = $signature !== '' && $signature === $previousSignature
            ? max(0, (int) ($state['technical_failure_repeats'] ?? 0)) + 1
            : 0;
        $repairIterations = max(0, (int) ($usage['repair_iterations'] ?? 0)) + 1;

        $usage['repair_iterations'] = $repairIterations;
        $state['last_technical_failure_signature'] = $signature;
        $state['technical_failure_repeats'] = $repeats;
        $state['last_failed_run_id'] = (int) $run->id;
        $session->forceFill(['usage_json' => $usage, 'state_json' => $state, 'last_activity_at' => now()])->save();

        $maxSameState = max(1, (int) ($budget['max_same_state_repeats'] ?? 2));
        $maxRepairIterations = max(1, (int) ($budget['max_repair_iterations'] ?? 15));
        $sameFailureExhausted = $repeats >= $maxSameState;
        $iterationBudgetReached = $repairIterations >= $maxRepairIterations;

        if ($sameFailureExhausted || $iterationBudgetReached || $this->timeBudgetExceeded($session)) {
            $target = $this->unresolvedRouteTargetFromError((string) $run->error_message);
            $this->sessions->appendEvent(
                $session,
                'run.unrepairable',
                $sameFailureExhausted
                    ? 'Der Lauf scheitert technisch wiederholt an derselben Stelle und kann autonom nicht behoben werden.'
                    : 'Das Reparaturbudget wurde durch technische Fehllaeufe erreicht, bevor ein sicherer Checkpoint zur Reparatur entstand.',
                [
                    'workflow_run_id' => (int) $run->id,
                    'status' => $run->status,
                    'error' => $run->error_message,
                    'repeats' => $repeats,
                    'repair_iterations' => $repairIterations,
                    'unresolved_route_target' => $target,
                ],
                'repairing',
                'error',
                true,
            );

            $this->exhaustBudget($session->fresh() ?? $session);

            return;
        }

        $this->sessions->transition(
            $session,
            WorkflowCopilotSession::STATUS_REPAIRING,
            'repairing',
            ['last_failed_run_id' => (int) $run->id],
            'Der fehlgeschlagene Lauf wird ab dem letzten reproduzierbaren Zustand neu aufgebaut.',
        );
        $this->startRepairRun($session->fresh() ?? $session);
    }

    protected function technicalRunFailureSignature(WorkflowRun $run): string
    {
        $error = trim((string) $run->error_message);

        if ($error === '') {
            return trim((string) $run->status);
        }

        // Volatile Zahlen (Lauf-/Schritt-IDs) normalisieren, damit derselbe
        // strukturelle Fehler ueber mehrere Laeufe hinweg als identisch gilt.
        return trim((string) preg_replace('/\d+/', '#', $error));
    }

    protected function unresolvedRouteTargetFromError(string $error): ?string
    {
        if (preg_match('/nicht gefunden:\s*(.+)$/u', trim($error), $matches) === 1) {
            return trim($matches[1]) ?: null;
        }

        return null;
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
        $runContext = is_array($run->context_json) ? $run->context_json : [];
        $isVerificationCheckpoint = (bool) ($runContext['copilot_verification_run'] ?? false)
            && ($runContext['copilot_mutations_allowed'] ?? null) === false;
        $pageState = Str::lower(trim((string) data_get($observation, 'page.state', data_get($observation, 'page_state', ''))));
        $stateSignature = trim((string) ($observation['state_signature'] ?? ''));
        $previousStateSignature = trim((string) data_get($session->state_json, 'last_state_signature', ''));
        $shouldAnalyze = ! (bool) ($checkpoint['successful'] ?? false)
            || (string) ($checkpoint['kind'] ?? '') === 'probe'
            || $isVerificationCheckpoint
            || (bool) ($observation['screenshot_changed'] ?? false)
            || in_array($pageState, ['', 'unknown', 'unknown_browser_state'], true)
            || ($stateSignature !== '' && $stateSignature === $previousStateSignature);

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
        $this->sessions->appendEvent(
            $session,
            'checkpoint.review_pause',
            'Der Vorschau-Test ist am sicheren Task-Checkpoint angehalten; aktueller Bildschirm, DOM und Ergebnis werden vor der Fortsetzung geprueft.',
            [
                'workflow_run_id' => (int) $run->id,
                'checkpoint_id' => (int) $storedCheckpoint->id,
                'task_key' => $checkpoint['task_key'] ?? null,
                'successful' => (bool) ($checkpoint['successful'] ?? false),
                'state_signature' => $observation['state_signature'] ?? null,
                'screenshot_changed' => (bool) ($observation['screenshot_changed'] ?? false),
                'vision_analyzed' => $vision !== [],
            ],
            'observing',
        );

        if ($isVerificationCheckpoint) {
            $this->processVerificationCheckpoint($session, $run, $checkpoint, $attempt->id, $storedCheckpoint->id, $vision);

            return;
        }

        if ((string) ($checkpoint['kind'] ?? '') === 'probe') {
            $this->processProbeResult($session->fresh() ?? $session, $run->fresh() ?? $run, $stepRun->workflowStep, $checkpoint, $observation, $vision);

            return;
        }

        if ((bool) ($checkpoint['successful'] ?? false)) {
            if ($this->timeBudgetExceeded($session->fresh() ?? $session)) {
                $this->exhaustBudget($session->fresh() ?? $session);

                return;
            }

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

    protected function processVerificationCheckpoint(
        WorkflowCopilotSession $session,
        WorkflowRun $run,
        array $checkpoint,
        int $taskAttemptId,
        int $storedCheckpointId,
        array $vision,
    ): void {
        if (! (bool) ($checkpoint['successful'] ?? false)) {
            $context = is_array($run->context_json) ? $run->context_json : [];
            $pending = is_array($context['copilot_checkpoint'] ?? null) ? $context['copilot_checkpoint'] : [];
            $pending['next_action'] = 'complete_step';
            $context['copilot_checkpoint'] = $pending;
            $run->forceFill(['context_json' => $context])->save();
        }

        $this->sessions->appendEvent(
            $session,
            'verification.task_checkpoint',
            'Kontrolllauf-Task `'.($checkpoint['task_title'] ?? $checkpoint['task_key'] ?? '').'` wurde ohne Workflow-Aenderung ausgewertet.',
            [
                'workflow_run_id' => (int) $run->id,
                'task_attempt_id' => $taskAttemptId,
                'checkpoint_id' => $storedCheckpointId,
                'task_key' => $checkpoint['task_key'] ?? null,
                'successful' => (bool) ($checkpoint['successful'] ?? false),
                'vision_verdict' => $vision['verdict'] ?? null,
                'mutations_allowed' => false,
            ],
            'verifying',
            (bool) ($checkpoint['successful'] ?? false) ? 'success' : 'warning',
            ! (bool) ($checkpoint['successful'] ?? false),
        );
        $this->execution->resumeCopilotCheckpoint($run);
        $this->markContinuationApplied($session, $checkpoint, 'verification_resume');
    }

    protected function repairFailedCheckpoint(
        WorkflowCopilotSession $session,
        WorkflowRun $run,
        WorkflowStep $step,
        array $checkpoint,
        array $observation,
        array $vision,
    ): void {
        $runtimeCheckpointId = $this->runtimeCheckpointId($run, $checkpoint);
        $state = is_array($session->state_json) ? $session->state_json : [];
        $usage = is_array($session->usage_json) ? $session->usage_json : [];
        $newRepairIteration = ($state['repair_counted_checkpoint_id'] ?? null) !== $runtimeCheckpointId;

        if ($newRepairIteration && $this->repairBudgetReachedBeforeAction($session)) {
            $this->exhaustBudget($session);

            return;
        }

        $repairIterations = max(0, (int) ($usage['repair_iterations'] ?? 0)) + ($newRepairIteration ? 1 : 0);
        $usage['repair_iterations'] = $repairIterations;
        $state['repair_counted_checkpoint_id'] = $runtimeCheckpointId;
        $session->forceFill([
            'status' => WorkflowCopilotSession::STATUS_REPAIRING,
            'phase' => 'repairing',
            'repair_round' => $repairIterations,
            'usage_json' => $usage,
            'state_json' => $state,
            'last_activity_at' => now(),
        ])->save();

        if ($newRepairIteration) {
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
        }

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

        $sourceCheckpoint = $this->storedCheckpointForRuntime($session, $run, $runtimeCheckpointId);
        $sourceSideEffects = is_array($sourceCheckpoint?->side_effect_ledger_json)
            ? $sourceCheckpoint->side_effect_ledger_json
            : (is_array(data_get($checkpoint, 'result.sideEffects')) ? data_get($checkpoint, 'result.sideEffects') : []);

        if ($sourceSideEffects !== [] || (bool) data_get($checkpoint, 'result.irreversibleSideEffect', false)) {
            $this->sessions->appendEvent(
                $session,
                'probe.blocked_after_side_effect',
                'Die autonome Probe wurde nicht gestartet, weil der fehlgeschlagene Task bereits externe Wirkungen protokolliert hat.',
                [
                    'runtime_checkpoint_id' => $runtimeCheckpointId,
                    'checkpoint_id' => $sourceCheckpoint?->id,
                    'side_effect_ledger' => $sourceSideEffects,
                    'external_side_effects_reverted' => false,
                ],
                'probing',
                'warning',
                true,
            );
            $this->sessions->pause(
                $session,
                'Eine Probe auf dem bereits veraenderten Browserzustand waere nicht sicher reproduzierbar.',
            );

            return;
        }

        $plan['source_checkpoint'] = [
            'runtime_checkpoint_id' => $runtimeCheckpointId,
            'checkpoint_id' => $sourceCheckpoint?->id,
            'checkpoint_sequence' => $sourceCheckpoint?->sequence,
            'is_reproducible' => $sourceCheckpoint?->is_reproducible ?? true,
            'side_effect_ledger' => [],
        ];

        $newProbeAction = ($state['probe_counted_checkpoint_id'] ?? null) !== $runtimeCheckpointId;

        if ($newProbeAction && $this->probeBudgetReachedBeforeAction($session->fresh() ?? $session)) {
            $this->exhaustBudget($session->fresh() ?? $session);

            return;
        }

        $probeActions = max(0, (int) ($usage['probe_actions'] ?? 0)) + ($newProbeAction ? 1 : 0);
        $usage['probe_actions'] = $probeActions;
        $state['probe_counted_checkpoint_id'] = $runtimeCheckpointId;
        $session->forceFill([
            'usage_json' => $usage,
            'state_json' => array_replace_recursive($state, ['active_repair_plan' => $plan]),
        ])->save();

        if ($newProbeAction) {
            $this->sessions->appendEvent(
                $session,
                'probe.started',
                'Probeaktion mit `'.($plan['task_catalog_key'] ?? '').'` und der vorgeschlagenen Task-Konfiguration wird ausgefuehrt.',
                Arr::except($plan, ['probe_task']),
                'probing',
                'info',
                true,
            );
        }
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
            $probeSideEffects = is_array(data_get($checkpoint, 'result.sideEffects'))
                ? data_get($checkpoint, 'result.sideEffects')
                : [];

            if ($probeSideEffects !== [] || (bool) data_get($checkpoint, 'result.irreversibleSideEffect', false)) {
                $this->sessions->appendEvent(
                    $session,
                    'probe.failed_with_side_effect',
                    'Die fehlgeschlagene Probe hat externe Wirkungen protokolliert und wird deshalb nicht auf demselben Browserzustand wiederholt.',
                    [
                        'task_key' => $originalTaskKey,
                        'side_effect_ledger' => $probeSideEffects,
                        'external_side_effects_reverted' => false,
                    ],
                    'probing',
                    'warning',
                    true,
                );
                $this->sessions->pause(
                    $session,
                    'Der Browserzustand ist nach der fehlgeschlagenen Probe nicht sicher reproduzierbar.',
                );

                return;
            }

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

        [$revision, $session, $run] = $this->persistSuccessfulProbeRevision(
            $session,
            $run,
            $step,
            $checkpoint,
            $plan,
            $originalTaskKey,
        );

        if ($this->timeBudgetExceeded($session->fresh() ?? $session)) {
            $this->exhaustBudget($session->fresh() ?? $session);

            return;
        }

        $this->sessions->transition(
            $session->fresh() ?? $session,
            WorkflowCopilotSession::STATUS_RUNNING,
            'executing',
            [
                'active_repair_plan' => null,
                'rejected_selectors' => null,
                'last_committed_probe_checkpoint_id' => $this->runtimeCheckpointId($run, $checkpoint),
                'last_committed_probe_side_effects' => is_array(data_get($checkpoint, 'result.sideEffects'))
                    ? data_get($checkpoint, 'result.sideEffects')
                    : [],
            ],
            'Die erfolgreiche Probe gilt als Task-Ausfuehrung und wird auf dem bereits veraenderten Browserzustand nicht doppelt ausgefuehrt.',
        );
        $this->sessions->appendEvent(
            $session,
            'probe.committed_as_task_result',
            'Die getestete Task-Konfiguration wurde gespeichert; der Workflow setzt hinter der erfolgreichen Probe fort.',
            [
                'workflow_revision_id' => (int) $revision->id,
                'revision_number' => (int) $revision->revision_number,
                'task_key' => $originalTaskKey,
                'side_effect_ledger' => is_array(data_get($checkpoint, 'result.sideEffects'))
                    ? data_get($checkpoint, 'result.sideEffects')
                    : [],
                'probe_reexecuted' => false,
            ],
            'executing',
            'success',
            true,
        );
        $this->execution->resumeCopilotCheckpoint($run);
        $this->markContinuationApplied($session, $checkpoint, 'revision_continue_after_probe');
    }

    /** @return array{WorkflowRevision, WorkflowCopilotSession, WorkflowRun} */
    protected function persistSuccessfulProbeRevision(
        WorkflowCopilotSession $session,
        WorkflowRun $run,
        WorkflowStep $step,
        array $checkpoint,
        array $plan,
        string $originalTaskKey,
    ): array {
        $runtimeCheckpointId = $this->runtimeCheckpointId($run, $checkpoint);

        return DB::transaction(function () use (
            $session,
            $run,
            $step,
            $plan,
            $originalTaskKey,
            $runtimeCheckpointId,
        ): array {
            Workflow::query()->lockForUpdate()->findOrFail($session->workflow_id);
            $lockedSession = WorkflowCopilotSession::query()->lockForUpdate()->findOrFail($session->id);
            $lockedRun = WorkflowRun::query()->lockForUpdate()->findOrFail($run->id);
            $checkpointId = WorkflowRunCheckpoint::query()
                ->where('workflow_copilot_session_id', $lockedSession->id)
                ->where('workflow_run_id', $lockedRun->id)
                ->latest('sequence')
                ->limit(100)
                ->get()
                ->first(fn (WorkflowRunCheckpoint $candidate): bool => data_get($candidate->cursor_json, 'runtime_checkpoint_id') === $runtimeCheckpointId)
                ?->id;

            if (! $checkpointId) {
                throw new \RuntimeException('Der persistierte Probe-Checkpoint wurde fuer die Revision nicht gefunden.');
            }

            $storedCheckpoint = WorkflowRunCheckpoint::query()->lockForUpdate()->findOrFail($checkpointId);
            $revision = $storedCheckpoint->workflow_revision_id
                ? WorkflowRevision::query()->lockForUpdate()->find($storedCheckpoint->workflow_revision_id)
                : null;

            if ($revision
                && ((int) $revision->workflow_id !== (int) $lockedSession->workflow_id
                    || (int) $revision->workflow_copilot_session_id !== (int) $lockedSession->id)) {
                throw new \RuntimeException('Der Probe-Checkpoint verweist auf eine fremde Workflow-Revision.');
            }

            if (! $revision) {
                $reason = trim((string) ($plan['reason'] ?? 'Erfolgreich gepruefte Copilot-Reparatur.'));
                $revision = $this->revisions->apply(
                    $lockedSession,
                    (int) $lockedSession->current_revision,
                    $reason,
                    function () use ($step, $originalTaskKey, $plan, $lockedSession): void {
                        $freshStep = WorkflowStep::query()->findOrFail($step->id);
                        $this->repairs->applyChangesToStep(
                            $freshStep,
                            $originalTaskKey,
                            $plan['changes'],
                            $lockedSession,
                        );
                    },
                );
                $storedCheckpoint->forceFill(['workflow_revision_id' => $revision->id])->save();

                if ($storedCheckpoint->workflow_task_attempt_id) {
                    WorkflowTaskAttempt::query()
                        ->whereKey($storedCheckpoint->workflow_task_attempt_id)
                        ->update(['workflow_revision_id' => $revision->id]);
                }
            }

            $state = is_array($lockedSession->state_json) ? $lockedSession->state_json : [];
            $state['revision_applied_checkpoint_id'] = $runtimeCheckpointId;
            $state['revision_applied_number'] = (int) $revision->revision_number;
            $lockedSession->forceFill([
                'state_json' => $state,
                'last_activity_at' => now(),
            ])->save();
            $runContext = is_array($lockedRun->context_json) ? $lockedRun->context_json : [];
            $runContext['workflow_revision'] = (int) $revision->revision_number;
            $lockedRun->forceFill([
                'workflow_revision' => (int) $revision->revision_number,
                'context_json' => $runContext,
            ])->save();

            return [
                $revision->fresh() ?? $revision,
                $lockedSession->fresh() ?? $lockedSession,
                $lockedRun->fresh() ?? $lockedRun,
            ];
        });
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
        $runtimeVariables = is_array($resumeContext['workflow_variables'] ?? null)
            ? $resumeContext['workflow_variables']
            : $workflowInputs;
        $context = array_replace($workflowInputs, $resumeContext, [
            'person_id' => $session->person_id,
            'workflow_variables' => $runtimeVariables,
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
        $workflowHash = $this->workflowSnapshotHash($session->workflow);
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
            'copilot_supervised' => true,
            'copilot_verification_run' => true,
            'copilot_mutations_allowed' => false,
            'copilot_frozen_success_criteria' => $session->success_criteria_json,
            'copilot_frozen_workflow_hash' => $workflowHash,
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
            [
                'workflow_run_id' => $run->id,
                'revision' => $session->current_revision,
                'workflow_snapshot_hash' => $workflowHash,
                'execution_target' => 'system',
            ],
            'verifying',
            'info',
            true,
        );
    }

    protected function finishVerification(WorkflowCopilotSession $session, WorkflowRun $run): void
    {
        $binding = $this->verificationBinding($session, $run);

        if (! $binding['valid']) {
            $this->failVerification(
                $session,
                $run,
                'Der Kontrolllauf ist nicht mehr an die aktive, eingefrorene Workflow-Revision gebunden.',
                [
                    'binding_errors' => $binding['errors'],
                    'revision_binding' => $binding['revisions'],
                    'workflow_snapshot_binding' => $binding['workflow_hashes'],
                ],
            );

            return;
        }

        $session = $binding['session'];
        $run = $binding['run'];
        $criteria = $binding['criteria'];
        $observation = $this->observations->observe($run, $run->stepRuns()->latest('id')->first());
        $criteriaEvaluation = $this->evaluateSuccessCriteria($criteria, $run, $observation);
        $vision = $this->vision->analyze($observation, $this->verificationGoal($session, $criteria));
        $technicalPass = $run->status === 'completed'
            && (bool) data_get($run->result_json, 'ok', false)
            && data_get($run->result_json, 'technical_status') === 'success'
            && data_get($run->result_json, 'business_status') === 'success';
        $visionVerdict = Str::lower(trim((string) ($vision['verdict'] ?? 'unknown')));
        $visionConfidence = is_numeric($vision['confidence'] ?? null) ? (float) $vision['confidence'] : 0.0;
        $visionPass = $visionVerdict === 'pass'
            && $visionConfidence >= self::MIN_VERIFICATION_VISION_CONFIDENCE
            && ! (bool) ($vision['safe_pause'] ?? false);
        $binding = $this->verificationBinding($session, $run);

        if (! $binding['valid']) {
            $this->failVerification(
                $session,
                $run,
                'Die Workflow-Revision oder die eingefrorenen Zielassertionen wurden waehrend der Endpruefung veraendert.',
                [
                    'binding_errors' => $binding['errors'],
                    'revision_binding' => $binding['revisions'],
                    'workflow_snapshot_binding' => $binding['workflow_hashes'],
                    'criteria_evaluation' => $criteriaEvaluation,
                    'vision_verdict' => $visionVerdict,
                    'vision_confidence' => $visionConfidence,
                ],
                $vision,
            );

            return;
        }

        $session = $binding['session'];
        $run = $binding['run'];

        if ($technicalPass && $criteriaEvaluation['pass'] && $visionPass) {
            try {
                $this->revisions->markVerified($session, (int) $session->current_revision);
            } catch (WorkflowRevisionConflictException $exception) {
                $this->failVerification(
                    $session,
                    $run,
                    'Die Workflow-Revision wurde unmittelbar vor der Verifizierung veraendert; der alte Lauf wird nicht akzeptiert.',
                    [
                        'revision_conflict' => $exception->getMessage(),
                        'criteria_evaluation' => $criteriaEvaluation,
                        'vision_verdict' => $visionVerdict,
                        'vision_confidence' => $visionConfidence,
                    ],
                    $vision,
                );

                return;
            }

            $this->sessions->appendEvent(
                $session,
                'verification.passed',
                'Workflow vollstaendig erfolgreich und automatisch verifiziert.',
                [
                    'workflow_run_id' => $run->id,
                    'revision' => $session->current_revision,
                    'vision_verdict' => $visionVerdict,
                    'vision_confidence' => $visionConfidence,
                    'criteria_evaluation' => $criteriaEvaluation,
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
                [
                    'verification_run_id' => (int) $run->id,
                    'verification' => [
                        'pass' => true,
                        'criteria' => $criteriaEvaluation,
                        'vision' => Arr::except($vision, ['raw_response']),
                    ],
                ],
                'Workflow vollstaendig erfolgreich und automatisch verifiziert.',
            );

            return;
        }

        $this->failVerification(
            $session,
            $run,
            'Der Kontrolllauf hat die technische und fachliche Endpruefung noch nicht bestanden.',
            [
                'technical_pass' => $technicalPass,
                'criteria_evaluation' => $criteriaEvaluation,
                'vision_verdict' => $visionVerdict,
                'vision_confidence' => $visionConfidence,
                'vision_pass' => $visionPass,
            ],
            $vision,
        );
    }

    protected function failVerification(
        WorkflowCopilotSession $session,
        WorkflowRun $run,
        string $message,
        array $details,
        array $vision = [],
    ): void {
        $this->sessions->appendEvent(
            $session,
            'verification.failed',
            $message,
            [
                'workflow_run_id' => (int) $run->id,
                ...$details,
                'vision' => Arr::except($vision, ['raw_response']),
            ],
            'verifying',
            'warning',
            true,
        );

        $session = WorkflowCopilotSession::query()->with('workflow')->find($session->id) ?? $session;

        if ($session->status === WorkflowCopilotSession::STATUS_PAUSED
            || in_array($session->status, WorkflowCopilotSession::TERMINAL_STATUSES, true)) {
            return;
        }

        $unsafeBindingErrors = array_intersect(
            is_array($details['binding_errors'] ?? null) ? $details['binding_errors'] : [],
            ['active_run_mismatch', 'workflow_lock_mismatch', 'run_ownership_mismatch', 'session_not_verifying'],
        );

        if ($unsafeBindingErrors !== []) {
            if (in_array('workflow_lock_mismatch', $unsafeBindingErrors, true)) {
                return;
            }

            $this->sessions->pause($session, 'Die Endpruefung verweist nicht mehr auf den aktiven Kontrolllauf und wurde sicher pausiert.');

            return;
        }

        $session = $this->sessions->transition(
            $session,
            WorkflowCopilotSession::STATUS_REPAIRING,
            'repairing',
            [
                'verification_run_id' => (int) $run->id,
                'verification' => [
                    'pass' => false,
                    'details' => $details,
                    'vision' => Arr::except($vision, ['raw_response']),
                ],
            ],
            'Die Reparaturschleife wird nach dem fehlgeschlagenen Kontrolllauf fortgesetzt.',
        );
        $this->startRepairRun($session);
    }

    /**
     * @return array{valid: bool, errors: list<string>, revisions: array<string, int|null>, workflow_hashes: array{frozen: string|null, current: string|null}, criteria: array, session: WorkflowCopilotSession, run: WorkflowRun}
     */
    protected function verificationBinding(WorkflowCopilotSession $session, WorkflowRun $run): array
    {
        $session = WorkflowCopilotSession::query()->with('workflow')->findOrFail($session->id);
        $run = WorkflowRun::query()->findOrFail($run->id);
        $workflow = $session->workflow;
        $context = is_array($run->context_json) ? $run->context_json : [];
        $sessionCriteria = is_array($session->success_criteria_json) ? $session->success_criteria_json : [];
        $frozenCriteria = is_array($context['copilot_frozen_success_criteria'] ?? null)
            ? $context['copilot_frozen_success_criteria']
            : [];
        $errors = [];
        $sessionRevision = (int) $session->current_revision;
        $workflowRevision = $workflow ? (int) $workflow->copilot_revision : null;
        $runRevision = $run->workflow_revision !== null ? (int) $run->workflow_revision : null;
        $contextRevision = array_key_exists('workflow_revision', $context) && is_numeric($context['workflow_revision'])
            ? (int) $context['workflow_revision']
            : null;
        $frozenWorkflowHash = filled($context['copilot_frozen_workflow_hash'] ?? null)
            ? trim((string) $context['copilot_frozen_workflow_hash'])
            : null;
        $currentWorkflowHash = $workflow ? $this->workflowSnapshotHash($workflow) : null;

        if ($session->status !== WorkflowCopilotSession::STATUS_VERIFYING) {
            $errors[] = 'session_not_verifying';
        }

        if ($session->execution_target !== WorkflowCopilotSession::EXECUTION_TARGET_SYSTEM
            || ($context['execution_target'] ?? null) !== WorkflowCopilotSession::EXECUTION_TARGET_SYSTEM) {
            $errors[] = 'execution_target_not_system';
        }

        if (! $workflow || (int) $workflow->active_workflow_copilot_session_id !== (int) $session->id) {
            $errors[] = 'workflow_lock_mismatch';
        }

        if ((int) $session->active_workflow_run_id !== (int) $run->id) {
            $errors[] = 'active_run_mismatch';
        }

        if ((int) $run->workflow_copilot_session_id !== (int) $session->id
            || (int) $run->workflow_id !== (int) $session->workflow_id) {
            $errors[] = 'run_ownership_mismatch';
        }

        if (($context['copilot_verification_run'] ?? null) !== true
            || ($context['copilot_supervised'] ?? null) !== true
            || ($context['copilot_mutations_allowed'] ?? null) !== false) {
            $errors[] = 'not_frozen_verification_run';
        }

        if ($runRevision === null
            || $contextRevision === null
            || $workflowRevision === null
            || count(array_unique([$sessionRevision, $workflowRevision, $runRevision, $contextRevision])) !== 1) {
            $errors[] = 'revision_mismatch';
        }

        if (! array_key_exists('copilot_frozen_success_criteria', $context)
            || $this->canonicalValue($frozenCriteria) !== $this->canonicalValue($sessionCriteria)) {
            $errors[] = 'success_criteria_mismatch';
        }

        if ($frozenWorkflowHash === null
            || $currentWorkflowHash === null
            || ! hash_equals($frozenWorkflowHash, $currentWorkflowHash)) {
            $errors[] = 'workflow_snapshot_mismatch';
        }

        return [
            'valid' => $errors === [],
            'errors' => array_values(array_unique($errors)),
            'revisions' => [
                'workflow' => $workflowRevision,
                'session' => $sessionRevision,
                'run' => $runRevision,
                'context' => $contextRevision,
            ],
            'workflow_hashes' => [
                'frozen' => $frozenWorkflowHash,
                'current' => $currentWorkflowHash,
            ],
            'criteria' => $frozenCriteria,
            'session' => $session,
            'run' => $run,
        ];
    }

    protected function verificationGoal(WorkflowCopilotSession $session, array $criteria): string
    {
        $encoded = json_encode(
            $criteria,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
        );

        return Str::limit(
            trim((string) $session->goal)."\nEingefrorene Zielassertionen: ".(is_string($encoded) ? $encoded : '[]'),
            4000,
            '',
        );
    }

    /**
     * @return array{pass: bool, total: int, passed: int, assertions: list<array<string, mixed>>}
     */
    protected function evaluateSuccessCriteria(array $criteria, WorkflowRun $run, array $observation): array
    {
        $assertions = $this->successCriteriaAssertions($criteria);
        $evaluations = array_map(
            fn (mixed $assertion, int $index): array => $this->evaluateSuccessAssertion($assertion, $index, $run, $observation),
            $assertions,
            array_keys($assertions),
        );
        $passed = count(array_filter($evaluations, static fn (array $evaluation): bool => $evaluation['pass']));

        return [
            'pass' => $passed === count($evaluations),
            'total' => count($evaluations),
            'passed' => $passed,
            'assertions' => $evaluations,
        ];
    }

    /** @return list<mixed> */
    protected function successCriteriaAssertions(array $criteria): array
    {
        if ($criteria === []) {
            return [];
        }

        if (array_key_exists('assertions', $criteria)) {
            return is_array($criteria['assertions']) ? array_values($criteria['assertions']) : [$criteria['assertions']];
        }

        if (array_is_list($criteria) || array_key_exists('type', $criteria)) {
            return array_is_list($criteria) ? array_values($criteria) : [$criteria];
        }

        $assertions = [];

        foreach ($criteria as $key => $value) {
            $key = Str::lower(trim((string) $key));
            $definition = match ($key) {
                'url', 'url_contains' => ['type' => 'url', 'operator' => 'contains', 'value' => $value],
                'url_ends_with' => ['type' => 'url', 'operator' => 'ends_with', 'value' => $value],
                'url_equals' => ['type' => 'url', 'operator' => 'equals', 'value' => $value],
                'visible_text', 'text' => ['type' => 'visible_text', 'operator' => 'contains', 'value' => $value],
                'title' => ['type' => 'title', 'operator' => 'contains', 'value' => $value],
                'page_state' => ['type' => 'page_state', 'operator' => 'equals', 'value' => $value],
                'technical_status', 'business_status' => ['type' => $key, 'operator' => 'equals', 'value' => $value],
                default => ['type' => 'unsupported', 'key' => $key, 'value' => $value],
            };
            $assertions[] = $definition;
        }

        return $assertions;
    }

    protected function evaluateSuccessAssertion(
        mixed $assertion,
        int $index,
        WorkflowRun $run,
        array $observation,
    ): array {
        if (is_string($assertion)) {
            $assertion = $this->parseTextAssertion($assertion);
        }

        if (! is_array($assertion)) {
            return $this->assertionResult($index, 'unsupported', 'equals', null, false, 'Assertion besitzt kein unterstuetztes Format.');
        }

        $type = Str::lower(trim((string) ($assertion['type'] ?? 'unsupported')));
        $type = str_replace(['-', ' '], '_', $type);
        $operator = Str::lower(trim((string) ($assertion['operator'] ?? $assertion['comparison'] ?? '')));
        $operator = str_replace(['-', ' '], '_', $operator);
        $expected = $assertion['value'] ?? $assertion['expected'] ?? null;
        $actual = null;
        $path = null;

        if (in_array($type, ['url_contains', 'url_ends_with', 'url_equals'], true)) {
            $operator = str_replace('url_', '', $type);
            $type = 'url';
        }

        if (in_array($type, ['text', 'text_visible', 'contains_text'], true)) {
            $type = 'visible_text';
        }

        switch ($type) {
            case 'url':
                $actual = data_get($observation, 'page.url');
                $operator = $operator ?: 'contains';
                break;
            case 'visible_text':
                $actual = $this->visibleObservationText($observation);
                $operator = $operator ?: 'contains';
                break;
            case 'title':
                $actual = data_get($observation, 'page.title');
                $operator = $operator ?: 'contains';
                break;
            case 'page_state':
                $actual = data_get($observation, 'page.state', data_get($observation, 'page_state'));
                $operator = $operator ?: 'equals';
                break;
            case 'technical_status':
            case 'business_status':
                $actual = data_get($run->result_json, $type);
                $operator = $operator ?: 'equals';
                break;
            case 'result':
            case 'result_equals':
            case 'result_key':
                $path = trim((string) ($assertion['path'] ?? $assertion['key'] ?? ''));
                $actual = $path !== '' ? data_get($run->result_json, $path) : null;
                $operator = $operator ?: 'equals';
                $type = 'result';
                break;
            default:
                return $this->assertionResult(
                    $index,
                    $type ?: 'unsupported',
                    $operator ?: 'equals',
                    $path,
                    false,
                    'Assertion-Typ wird nicht deterministisch unterstuetzt.',
                );
        }

        if ($expected === null || (is_string($expected) && trim($expected) === '')) {
            return $this->assertionResult($index, $type, $operator, $path, false, 'Erwartungswert fehlt.');
        }

        $pass = $this->compareAssertionValue($actual, $expected, $operator);

        return $this->assertionResult(
            $index,
            $type,
            $operator,
            $path,
            $pass,
            $pass ? 'Deterministische Assertion erfuellt.' : 'Deterministische Assertion nicht erfuellt.',
        );
    }

    protected function parseTextAssertion(string $assertion): array
    {
        $assertion = trim($assertion);
        $patterns = [
            '/^(?:finale?\s+)?url\s+(?:enth(?:ae|ä)lt|contains)\s+(.+)$/iu' => ['url', 'contains'],
            '/^(?:finale?\s+)?url\s+(?:endet\s+mit|ends\s+with)\s+(.+)$/iu' => ['url', 'ends_with'],
            '/^(?:finale?\s+)?url\s+(?:ist|gleich|equals)\s+(.+)$/iu' => ['url', 'equals'],
            '/^text\s+(.+?)\s+(?:ist\s+sichtbar|is\s+visible)$/iu' => ['visible_text', 'contains'],
            '/^(.+?)\s+(?:ist\s+sichtbar|is\s+visible)$/iu' => ['visible_text', 'contains'],
        ];

        foreach ($patterns as $pattern => [$type, $operator]) {
            if (preg_match($pattern, $assertion, $matches) === 1) {
                return [
                    'type' => $type,
                    'operator' => $operator,
                    'value' => trim($matches[1], " \t\n\r\0\x0B\"'`"),
                ];
            }
        }

        if (preg_match('/^([A-Za-z0-9_.-]+)\s+(?:ist|gleich|equals)\s+(.+)$/u', $assertion, $matches) === 1) {
            return [
                'type' => 'result',
                'operator' => 'equals',
                'path' => $matches[1],
                'value' => trim($matches[2], " \t\n\r\0\x0B\"'`"),
            ];
        }

        return ['type' => 'unsupported', 'value' => $assertion];
    }

    protected function compareAssertionValue(mixed $actual, mixed $expected, string $operator): bool
    {
        if ($operator === 'equals') {
            if (is_bool($actual) || is_bool($expected)) {
                return filter_var($actual, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE)
                    === filter_var($expected, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
            }

            if (is_numeric($actual) && is_numeric($expected)) {
                return (float) $actual === (float) $expected;
            }

            return Str::lower(trim((string) $actual)) === Str::lower(trim((string) $expected));
        }

        $actual = Str::lower((string) $actual);
        $expected = Str::lower((string) $expected);

        return match ($operator) {
            'contains' => $expected !== '' && Str::contains($actual, $expected),
            'starts_with' => $expected !== '' && Str::startsWith($actual, $expected),
            'ends_with' => $expected !== '' && Str::endsWith($actual, $expected),
            default => false,
        };
    }

    protected function visibleObservationText(array $observation): string
    {
        $parts = [(string) data_get($observation, 'dom.visible_text_excerpt', '')];

        foreach (is_array($observation['interaction_map'] ?? null) ? $observation['interaction_map'] : [] as $element) {
            if (! is_array($element)) {
                continue;
            }

            foreach (['text', 'aria', 'aria_label', 'label', 'name', 'placeholder'] as $key) {
                if (is_scalar($element[$key] ?? null)) {
                    $parts[] = (string) $element[$key];
                }
            }
        }

        return implode("\n", array_filter($parts, static fn (string $part): bool => trim($part) !== ''));
    }

    protected function assertionResult(
        int $index,
        string $type,
        string $operator,
        ?string $path,
        bool $pass,
        string $reason,
    ): array {
        return array_filter([
            'index' => $index,
            'type' => $type,
            'operator' => $operator,
            'path' => $path,
            'pass' => $pass,
            'reason' => $reason,
        ], static fn (mixed $value): bool => $value !== null);
    }

    protected function canonicalValue(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalValue($item), $value);
        }

        ksort($value);

        return array_map(fn (mixed $item): mixed => $this->canonicalValue($item), $value);
    }

    protected function workflowSnapshotHash(Workflow $workflow): string
    {
        $snapshot = $this->canonicalValue($this->revisions->snapshot($workflow));

        return hash('sha256', (string) json_encode(
            $snapshot,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE,
        ));
    }

    protected function checkpointRuntimeContext(WorkflowRun $run): array
    {
        $context = is_array($run->context_json) ? $run->context_json : [];
        unset(
            $context['copilot_checkpoint'],
            $context['copilot_transient_task'],
            $context['copilot_probe_plan'],
            $context['copilot_repair_plan'],
            $context['copilot_segment_started_at'],
        );
        $encoded = json_encode(
            $context,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
        );
        $workflowVariables = is_array($context['workflow_variables'] ?? null) ? $context['workflow_variables'] : [];
        $preview = array_filter([
            'workflow_variable_keys' => array_keys($workflowVariables),
            'next_step_action_key' => $context['next_step_action_key'] ?? null,
            'next_task_key' => $context['next_task_key'] ?? null,
            'workflow_return_ok' => $context['workflow_return_ok'] ?? null,
            'workflow_return_outcome' => $context['workflow_return_outcome'] ?? null,
        ], static fn (mixed $value): bool => $value !== null);

        return [
            'version' => 1,
            'execution_target' => WorkflowCopilotSession::EXECUTION_TARGET_SYSTEM,
            'encrypted_runtime_context' => Crypt::encryptString(is_string($encoded) ? $encoded : '{}'),
            'runtime_context_preview' => $preview,
        ];
    }

    protected function restoredCheckpointRuntimeContext(WorkflowRunCheckpoint $checkpoint): array
    {
        $encrypted = trim((string) data_get($checkpoint->context_json, 'encrypted_runtime_context', ''));

        if ($encrypted === '') {
            return [];
        }

        try {
            $decoded = json_decode(Crypt::decryptString($encrypted), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }

        if (! is_array($decoded)) {
            return [];
        }

        unset(
            $decoded['copilot_checkpoint'],
            $decoded['copilot_transient_task'],
            $decoded['copilot_probe_plan'],
            $decoded['copilot_repair_plan'],
            $decoded['copilot_segment_started_at'],
            $decoded['network_node_id'],
            $decoded['device_id'],
        );

        return $decoded;
    }

    protected function storeCheckpoint(
        WorkflowCopilotSession $session,
        WorkflowRun $run,
        WorkflowStep $step,
        array $checkpoint,
        array $observation,
        array $vision,
    ): array {
        $runtimeCheckpointId = $this->runtimeCheckpointId($run, $checkpoint);
        $taskKey = trim((string) ($checkpoint['task_key'] ?? ''));
        $task = collect($step->task_cards)->firstWhere('key', $taskKey);
        $stored = $this->storedCheckpointForRuntime($session, $run, $runtimeCheckpointId);
        $attempt = $stored?->taskAttempt;

        if (! $attempt) {
            $attempt = WorkflowTaskAttempt::query()
                ->where('workflow_copilot_session_id', $session->id)
                ->where('workflow_run_id', $run->id)
                ->latest('attempt_number')
                ->limit(100)
                ->get()
                ->first(fn (WorkflowTaskAttempt $candidate): bool => data_get($candidate->input_json, 'runtime_checkpoint_id') === $runtimeCheckpointId);
        }

        if (! $attempt) {
            $attempt = $this->sessions->beginTaskAttempt($session, [
                'workflow_run_id' => $run->id,
                'workflow_step_id' => $step->id,
                'workflow_revision_id' => $this->currentRevisionId($session),
                'kind' => (string) ($checkpoint['kind'] ?? 'regular'),
                'status' => 'running',
                'task_key' => $taskKey,
                'task_title' => (string) ($checkpoint['task_title'] ?? data_get($task, 'title', $taskKey)),
                'task_definition_json' => is_array($task) ? $this->safeTask($task) : [],
                'input_json' => [
                    'execution_target' => 'system',
                    'runtime_checkpoint_id' => $runtimeCheckpointId,
                ],
                'started_at' => $checkpoint['started_at'] ?? now(),
            ]);
        }

        if (in_array($attempt->status, ['queued', 'running'], true)) {
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
        }

        if ($stored) {
            if (! $stored->workflow_task_attempt_id) {
                $stored->forceFill(['workflow_task_attempt_id' => $attempt->id])->save();
            }

            return [$attempt, $stored];
        }

        $stored = $this->sessions->createCheckpoint($session, [
            'workflow_run_id' => $run->id,
            'workflow_step_id' => $step->id,
            'workflow_task_attempt_id' => $attempt->id,
            'workflow_revision_id' => $this->currentRevisionId($session),
            'screenshot_artifact_id' => data_get($observation, 'screenshot.artifact_id'),
            'phase' => (bool) ($checkpoint['successful'] ?? false) ? 'observing' : 'repairing',
            'task_key' => $taskKey,
            'cursor_json' => [
                'runtime_checkpoint_id' => $runtimeCheckpointId,
                'step_id' => $step->id,
                'step_action_key' => $step->action_key,
                'step_name' => $step->name,
                'task_key' => $taskKey,
                'next_action' => $checkpoint['next_action'] ?? null,
                'next_task_key' => $checkpoint['next_task_key'] ?? null,
            ],
            'context_json' => $this->checkpointRuntimeContext($run),
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

    protected function markCheckpointObserved(
        WorkflowCopilotSession $session,
        array $runtimeCheckpoint,
        WorkflowRunCheckpoint $stored,
        array $observation,
        array $vision,
    ): void {
        DB::transaction(function () use ($session, $runtimeCheckpoint, $stored, $observation, $vision): void {
            $lockedSession = WorkflowCopilotSession::query()->lockForUpdate()->findOrFail($session->id);
            $state = is_array($lockedSession->state_json) ? $lockedSession->state_json : [];
            $usage = is_array($lockedSession->usage_json) ? $lockedSession->usage_json : [];
            $runtimeCheckpointId = (string) ($runtimeCheckpoint['id'] ?? '');
            $isNewObservation = ($state['observed_checkpoint_id'] ?? null) !== $runtimeCheckpointId;
            $signature = trim((string) ($observation['state_signature'] ?? ''));
            $previousSignature = trim((string) ($state['last_state_signature'] ?? ''));
            $taskFingerprint = $this->stateTaskFingerprint($runtimeCheckpoint, $state);
            $previousTaskFingerprint = trim((string) ($state['last_state_task_fingerprint'] ?? ''));

            if ($isNewObservation) {
                $usage['same_state_repeats'] = $signature !== ''
                    && $signature === $previousSignature
                    && $taskFingerprint === $previousTaskFingerprint
                    ? max(0, (int) ($usage['same_state_repeats'] ?? 0)) + 1
                    : 0;
            }

            $lockedSession->forceFill([
                'state_json' => array_replace_recursive($state, [
                    'observed_checkpoint_id' => $runtimeCheckpointId,
                    'latest_checkpoint_id' => (int) $stored->id,
                    'latest_checkpoint_sequence' => (int) $stored->sequence,
                    'last_state_signature' => $signature,
                    'last_state_task_fingerprint' => $taskFingerprint,
                    'current_step_name' => $runtimeCheckpoint['workflow_step_name'] ?? null,
                    'current_task_key' => $runtimeCheckpoint['task_key'] ?? null,
                    'latest_screenshot_url' => $observation['screenshot_url'] ?? null,
                    'page_state' => data_get($vision, 'ui_state', data_get($observation, 'page.state')),
                    'observation' => Arr::except($observation, ['screenshot_data_url', 'raw_dom', 'html']),
                    'vision' => Arr::except($vision, ['raw_response']),
                    'last_action' => ($runtimeCheckpoint['kind'] ?? null) === 'probe' ? 'Probeaktion ausgefuehrt' : 'Task ausgefuehrt',
                    'current_result' => (bool) ($runtimeCheckpoint['successful'] ?? false) ? 'Erfolgreich' : 'Fehlgeschlagen',
                    'next_action' => $runtimeCheckpoint['next_action'] ?? null,
                ]),
                'usage_json' => $usage,
                'last_activity_at' => now(),
            ])->save();
        });
    }

    protected function checkpointProcessed(WorkflowCopilotSession $session, WorkflowRun $run, array $checkpoint): bool
    {
        $runtimeCheckpointId = $this->runtimeCheckpointId($run, $checkpoint);

        return trim((string) data_get($session->state_json, 'continuation_applied_checkpoint_id', '')) !== ''
            && trim((string) data_get($session->state_json, 'continuation_applied_checkpoint_id', '')) === $runtimeCheckpointId;
    }

    protected function storedCheckpointForRuntime(
        WorkflowCopilotSession $session,
        WorkflowRun $run,
        string $runtimeCheckpointId,
    ): ?WorkflowRunCheckpoint {
        return WorkflowRunCheckpoint::query()
            ->with('taskAttempt')
            ->where('workflow_copilot_session_id', $session->id)
            ->where('workflow_run_id', $run->id)
            ->latest('sequence')
            ->limit(100)
            ->get()
            ->first(fn (WorkflowRunCheckpoint $candidate): bool => data_get($candidate->cursor_json, 'runtime_checkpoint_id') === $runtimeCheckpointId);
    }

    protected function runtimeCheckpointId(WorkflowRun $run, array $checkpoint): string
    {
        $runtimeId = trim((string) ($checkpoint['id'] ?? ''));

        if ($runtimeId !== '') {
            return Str::limit($runtimeId, 191, '');
        }

        $payload = json_encode([
            'workflow_run_id' => (int) $run->id,
            'workflow_step_id' => (int) ($checkpoint['workflow_step_id'] ?? 0),
            'task_key' => (string) ($checkpoint['task_key'] ?? ''),
            'kind' => (string) ($checkpoint['kind'] ?? 'regular'),
            'started_at' => (string) ($checkpoint['started_at'] ?? ''),
            'finished_at' => (string) ($checkpoint['finished_at'] ?? ''),
        ], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        return 'derived-'.hash('sha256', is_string($payload) ? $payload : '');
    }

    protected function stateTaskFingerprint(array $checkpoint, array $sessionState): string
    {
        $payload = [
            'kind' => (string) ($checkpoint['kind'] ?? 'regular'),
            'workflow_step_id' => (int) ($checkpoint['workflow_step_id'] ?? 0),
            'task_key' => (string) ($checkpoint['task_key'] ?? ''),
        ];

        if (($checkpoint['kind'] ?? null) === 'probe') {
            $plan = is_array($sessionState['active_repair_plan'] ?? null)
                ? $sessionState['active_repair_plan']
                : [];
            $payload['probe'] = [
                'task_catalog_key' => $plan['task_catalog_key'] ?? null,
                'changes' => is_array($plan['changes'] ?? null) ? $plan['changes'] : [],
                'probe_task' => is_array($plan['probe_task'] ?? null) ? $this->safeTask($plan['probe_task']) : [],
            ];
        }

        return hash('sha256', (string) json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
        ));
    }

    protected function markContinuationApplied(
        WorkflowCopilotSession $session,
        array $checkpoint,
        string $action,
    ): void {
        $runId = (int) ($session->active_workflow_run_id ?: data_get($checkpoint, 'workflow_run_id', 0));
        $run = $runId > 0 ? WorkflowRun::query()->find($runId) : null;

        if (! $run) {
            throw new \RuntimeException('Der fortgesetzte Copilot-Run konnte fuer den Checkpoint nicht mehr geladen werden.');
        }

        $runtimeCheckpointId = $this->runtimeCheckpointId($run, $checkpoint);

        DB::transaction(function () use ($session, $runtimeCheckpointId, $action): void {
            $lockedSession = WorkflowCopilotSession::query()->lockForUpdate()->findOrFail($session->id);

            if ($lockedSession->status === WorkflowCopilotSession::STATUS_PAUSED
                || in_array($lockedSession->status, WorkflowCopilotSession::TERMINAL_STATUSES, true)) {
                return;
            }

            $state = is_array($lockedSession->state_json) ? $lockedSession->state_json : [];
            $state['continuation_applied_checkpoint_id'] = $runtimeCheckpointId;
            $state['continuation_applied_action'] = $action;
            $state['continuation_applied_at'] = now()->toIso8601String();
            $lockedSession->forceFill([
                'state_json' => $state,
                'last_activity_at' => now(),
            ])->save();
        });
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

            if ($this->isRewindInstruction($instruction)) {
                $session->forceFill(['state_json' => $state])->save();
                $checkpoint = $this->rewindCheckpointForInstruction($session, $run, $instruction);

                if (! $checkpoint) {
                    $this->sessions->pause(
                        $session,
                        'Die Ruecksprunganweisung konnte keinem sicheren, reproduzierbaren frueheren Checkpoint zugeordnet werden.',
                    );

                    return false;
                }

                $rewound = $this->sessions->rewind(
                    $session,
                    $checkpoint,
                    'Benutzeranweisung am sicheren Task-Checkpoint: logischer Ruecksprung zu Checkpoint #'.$checkpoint->sequence.'.',
                );
                $this->sessions->appendEvent(
                    $rewound,
                    'instruction.rewind_applied',
                    'Der Ruecksprung wird ausschliesslich logisch ausgefuehrt; bereits extern ausgeloeste Wirkungen werden nicht rueckgaengig gemacht.',
                    [
                        'instruction_sequence' => (int) $event->sequence,
                        'checkpoint_id' => (int) $checkpoint->id,
                        'checkpoint_sequence' => (int) $checkpoint->sequence,
                        'logical_only' => true,
                        'external_side_effects_reverted' => false,
                    ],
                    'rewinding',
                    'warning',
                    true,
                );
                $this->processPendingControl($rewound->fresh(['activeRun']) ?? $rewound);

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

    protected function isRewindInstruction(string $instruction): bool
    {
        return (preg_match('/\b(zurueck|zurück|springe|spring|rewind)\b/iu', $instruction) === 1
                || preg_match('/\bvorherig\w*\s+checkpoint\b/iu', $instruction) === 1)
            && preg_match('/\b(von vorne|von anfang|neu starten)\b/iu', $instruction) !== 1;
    }

    protected function rewindCheckpointForInstruction(
        WorkflowCopilotSession $session,
        WorkflowRun $run,
        string $instruction,
    ): ?WorkflowRunCheckpoint {
        $checkpoints = WorkflowRunCheckpoint::query()
            ->with('workflowStep')
            ->where('workflow_copilot_session_id', $session->id)
            ->orderBy('sequence')
            ->get();

        if ($checkpoints->isEmpty()) {
            return null;
        }

        if (preg_match('/\bcheckpoint\s*#?\s*(\d+)\b/iu', $instruction, $match) === 1) {
            $requested = $checkpoints->firstWhere('sequence', (int) $match[1]);

            if ($requested?->is_reproducible) {
                return $requested;
            }

            return $requested
                ? $checkpoints->where('sequence', '<', $requested->sequence)->where('is_reproducible', true)->last()
                : null;
        }

        $normalized = Str::lower(trim($instruction));
        $beforeTarget = preg_match('/\bvor\s+(?:(?:die|den|dem|das|eine|einen)\s+)?(.+?)\s+(?:zurueck|zurück)\b/iu', $normalized, $match) === 1;
        $target = $beforeTarget ? trim((string) ($match[1] ?? '')) : '';

        if ($target === '' && preg_match('/\b(?:zu|zum|zur)\s+(.+?)\s+(?:zurueck|zurück)\b/iu', $normalized, $match) === 1) {
            $target = trim((string) ($match[1] ?? ''));
        }

        $target = trim(preg_replace('/\b(checkpoint|task|schritt)\b/iu', ' ', $target) ?? $target, " \t\n\r\0\x0B.,:;!?\"'");

        if ($target !== '') {
            $runtimeCheckpoint = is_array(data_get($run->context_json, 'copilot_checkpoint'))
                ? data_get($run->context_json, 'copilot_checkpoint')
                : [];
            $currentDescription = Str::lower(implode(' ', array_filter([
                (string) ($runtimeCheckpoint['workflow_step_name'] ?? ''),
                (string) ($runtimeCheckpoint['task_title'] ?? ''),
                (string) ($runtimeCheckpoint['task_key'] ?? ''),
            ])));

            if ($beforeTarget && Str::contains($currentDescription, $target)) {
                return $checkpoints->where('is_reproducible', true)->last();
            }

            $matched = $checkpoints->last(function (WorkflowRunCheckpoint $checkpoint) use ($target): bool {
                $cursor = is_array($checkpoint->cursor_json) ? $checkpoint->cursor_json : [];
                $description = Str::lower(implode(' ', array_filter([
                    (string) $checkpoint->task_key,
                    (string) ($cursor['task_key'] ?? ''),
                    (string) ($cursor['step_name'] ?? ''),
                    (string) ($cursor['step_action_key'] ?? ''),
                    (string) $checkpoint->workflowStep?->name,
                    (string) $checkpoint->workflowStep?->action_key,
                ])));

                return Str::contains($description, $target);
            });

            if ($matched) {
                if ($beforeTarget || ! $matched->is_reproducible) {
                    return $checkpoints
                        ->where('sequence', '<', $matched->sequence)
                        ->where('is_reproducible', true)
                        ->last();
                }

                return $matched;
            }
        }

        return $checkpoints->where('is_reproducible', true)->last();
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
        $this->startRepairRun($session->fresh() ?? $session, array_replace(
            $this->restoredCheckpointRuntimeContext($checkpoint),
            [
                'next_step_action_key' => $cursor['step_action_key'] ?? null,
                'next_task_key' => $cursor['task_key'] ?? null,
                'browser_windows' => $browser['windows'] ?? [],
            ],
        ));

        return true;
    }

    protected function redispatchQueuedRunAfterResume(WorkflowCopilotSession $session, WorkflowRun $run): void
    {
        $resumeEvent = $session->events()
            ->reorder('sequence', 'desc')
            ->limit(100)
            ->get()
            ->first(fn ($event): bool => data_get($event->payload_json, 'from') === WorkflowCopilotSession::STATUS_PAUSED
                && in_array(data_get($event->payload_json, 'to'), [
                    WorkflowCopilotSession::STATUS_RUNNING,
                    WorkflowCopilotSession::STATUS_REPAIRING,
                    WorkflowCopilotSession::STATUS_VERIFYING,
                ], true));

        if (! $resumeEvent) {
            return;
        }

        $claimed = DB::transaction(function () use ($session, $run, $resumeEvent): bool {
            $lockedSession = WorkflowCopilotSession::query()->lockForUpdate()->findOrFail($session->id);
            $lockedRun = WorkflowRun::query()->lockForUpdate()->findOrFail($run->id);
            $context = is_array($lockedRun->context_json) ? $lockedRun->context_json : [];

            if (! in_array($lockedSession->status, [
                WorkflowCopilotSession::STATUS_RUNNING,
                WorkflowCopilotSession::STATUS_REPAIRING,
                WorkflowCopilotSession::STATUS_VERIFYING,
            ], true)
                || (int) $lockedSession->active_workflow_run_id !== (int) $lockedRun->id
                || $lockedRun->status !== 'queued'
                || (int) $lockedRun->workflow_copilot_session_id !== (int) $lockedSession->id
                || ($context['execution_target'] ?? null) !== WorkflowCopilotSession::EXECUTION_TARGET_SYSTEM) {
                return false;
            }

            $state = is_array($lockedSession->state_json) ? $lockedSession->state_json : [];

            if ((int) ($state['queued_run_resume_sequence'] ?? 0) === (int) $resumeEvent->sequence
                && (int) ($state['queued_run_redispatched_run_id'] ?? 0) === (int) $lockedRun->id) {
                return false;
            }

            $state['queued_run_resume_sequence'] = (int) $resumeEvent->sequence;
            $state['queued_run_redispatched_run_id'] = (int) $lockedRun->id;
            $state['queued_run_redispatched_at'] = now()->toIso8601String();
            $lockedSession->forceFill([
                'state_json' => $state,
                'last_activity_at' => now(),
            ])->save();

            return true;
        });

        if (! $claimed) {
            return;
        }

        try {
            RunWorkflowJob::dispatch($run->id);
        } catch (Throwable $exception) {
            DB::transaction(function () use ($session, $run, $resumeEvent): void {
                $lockedSession = WorkflowCopilotSession::query()->lockForUpdate()->find($session->id);

                if (! $lockedSession) {
                    return;
                }

                $state = is_array($lockedSession->state_json) ? $lockedSession->state_json : [];

                if ((int) ($state['queued_run_resume_sequence'] ?? 0) === (int) $resumeEvent->sequence
                    && (int) ($state['queued_run_redispatched_run_id'] ?? 0) === (int) $run->id) {
                    unset(
                        $state['queued_run_resume_sequence'],
                        $state['queued_run_redispatched_run_id'],
                        $state['queued_run_redispatched_at'],
                    );
                    $lockedSession->forceFill(['state_json' => $state])->save();
                }
            });

            throw $exception;
        }

        $this->sessions->appendEvent(
            $session,
            'run.redispatched_after_resume',
            'Der pausiert konsumierte System-Run wurde nach dem Fortsetzen erneut in die Queue gestellt.',
            ['workflow_run_id' => (int) $run->id, 'resume_event_sequence' => (int) $resumeEvent->sequence],
            'executing',
        );
    }

    /**
     * Sicherheitsnetz im Steady-State: greift bewusst erst OBERHALB des Limits
     * (`>`), damit eine Sitzung nach der letzten erlaubten Iteration noch ihre
     * Verifikation abschliessen kann. Das eigentliche Gate vor einer neuen
     * Aktion sind repairBudgetReachedBeforeAction/probeBudgetReachedBeforeAction
     * mit `>=`.
     */
    protected function budgetExceeded(WorkflowCopilotSession $session): bool
    {
        $budget = is_array($session->budget_json) ? $session->budget_json : [];
        $usage = is_array($session->usage_json) ? $session->usage_json : [];

        return $this->timeBudgetExceeded($session)
            || (int) ($usage['repair_iterations'] ?? 0) > max(1, (int) ($budget['max_repair_iterations'] ?? 15))
            || (int) ($usage['probe_actions'] ?? 0) > max(1, (int) ($budget['max_probe_actions'] ?? 60))
            || (int) ($usage['same_state_repeats'] ?? 0) > max(1, (int) ($budget['max_same_state_repeats'] ?? 2));
    }

    protected function repairBudgetReachedBeforeAction(WorkflowCopilotSession $session): bool
    {
        $budget = is_array($session->budget_json) ? $session->budget_json : [];
        $usage = is_array($session->usage_json) ? $session->usage_json : [];

        return $this->timeBudgetExceeded($session)
            || (int) ($usage['repair_iterations'] ?? 0) >= max(1, (int) ($budget['max_repair_iterations'] ?? 15))
            || (int) ($usage['same_state_repeats'] ?? 0) >= max(1, (int) ($budget['max_same_state_repeats'] ?? 2));
    }

    protected function probeBudgetReachedBeforeAction(WorkflowCopilotSession $session): bool
    {
        $budget = is_array($session->budget_json) ? $session->budget_json : [];
        $usage = is_array($session->usage_json) ? $session->usage_json : [];

        return $this->timeBudgetExceeded($session)
            || (int) ($usage['probe_actions'] ?? 0) >= max(1, (int) ($budget['max_probe_actions'] ?? 60));
    }

    protected function timeBudgetExceeded(WorkflowCopilotSession $session): bool
    {
        $budget = is_array($session->budget_json) ? $session->budget_json : [];
        $maxMinutes = max(1, (int) ($budget['max_minutes'] ?? 90));

        return (bool) ($session->started_at && $session->started_at->diffInMinutes(now()) >= $maxMinutes);
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
            ->where('workflow_id', $session->workflow_id)
            ->where('revision_number', $session->current_revision)
            ->value('id');
    }

    protected function acquireSupervisorLease(int $sessionId): ?string
    {
        $token = (string) Str::uuid();

        return DB::transaction(function () use ($sessionId, $token): ?string {
            $session = WorkflowCopilotSession::query()->lockForUpdate()->find($sessionId);

            if (! $session || in_array($session->status, WorkflowCopilotSession::TERMINAL_STATUSES, true)) {
                return null;
            }

            $state = is_array($session->state_json) ? $session->state_json : [];
            $lease = is_array($state['supervisor_lease'] ?? null) ? $state['supervisor_lease'] : [];
            $expiresAt = null;

            try {
                $expiresAt = filled($lease['expires_at'] ?? null)
                    ? CarbonImmutable::parse((string) $lease['expires_at'])
                    : null;
            } catch (Throwable) {
                $expiresAt = null;
            }

            if (filled($lease['token'] ?? null) && $expiresAt?->isFuture()) {
                $state['supervisor_recheck_requested'] = true;

                if (($state['supervisor_lease_recheck_token'] ?? null) !== (string) $lease['token']) {
                    $state['supervisor_lease_recheck_token'] = (string) $lease['token'];
                    $state['supervisor_lease_recheck_at'] = $expiresAt->addSecond()->toIso8601String();
                    WorkflowCopilotSupervisorJob::dispatch($sessionId)
                        ->delay($expiresAt->addSecond())
                        ->afterCommit();
                }

                $session->forceFill(['state_json' => $state])->save();

                return null;
            }

            unset(
                $state['supervisor_recheck_requested'],
                $state['supervisor_lease_recheck_token'],
                $state['supervisor_lease_recheck_at'],
            );
            $state['supervisor_lease'] = [
                'token' => $token,
                'acquired_at' => now()->toIso8601String(),
                'expires_at' => now()->addSeconds(self::SUPERVISOR_LEASE_SECONDS)->toIso8601String(),
            ];
            $session->forceFill(['state_json' => $state])->save();

            return $token;
        });
    }

    protected function releaseSupervisorLease(int $sessionId, string $token): void
    {
        $redispatch = DB::transaction(function () use ($sessionId, $token): bool {
            $session = WorkflowCopilotSession::query()->lockForUpdate()->find($sessionId);

            if (! $session) {
                return false;
            }

            $state = is_array($session->state_json) ? $session->state_json : [];

            if (! hash_equals($token, (string) data_get($state, 'supervisor_lease.token', ''))) {
                return false;
            }

            $redispatch = (bool) ($state['supervisor_recheck_requested'] ?? false)
                && $session->status !== WorkflowCopilotSession::STATUS_PAUSED
                && ! in_array($session->status, WorkflowCopilotSession::TERMINAL_STATUSES, true);
            unset(
                $state['supervisor_lease'],
                $state['supervisor_recheck_requested'],
                $state['supervisor_lease_recheck_token'],
                $state['supervisor_lease_recheck_at'],
            );
            $session->forceFill(['state_json' => $state])->save();

            return $redispatch;
        });

        if ($redispatch) {
            WorkflowCopilotSupervisorJob::dispatch($sessionId)->delay(now()->addSecond());
        }
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
