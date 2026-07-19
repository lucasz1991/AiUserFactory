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
use App\Models\WorkflowStepRun;
use App\Models\WorkflowTaskAttempt;
use App\Services\Ai\WorkflowCopilotAiUsageTracker;
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
        protected WorkflowCopilotPlanningService $planning,
        protected WorkflowCopilotRepairService $repairs,
        protected WorkflowCopilotPromptContextService $promptContexts,
        protected WorkflowRevisionService $revisions,
        protected WorkflowCopilotAiUsageTracker $aiUsage,
        protected WorkflowStudioAuthorizationService $studioAuthorization,
        protected WorkflowOptimizationPlanService $optimizationPlans,
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
            $session = $this->createInitialWorkflowDefinition($session);
            $this->startRepairRun($session, $this->optimizationPlans->resumeContext($session));

            return;
        }

        $run->refresh();
        $context = is_array($run->context_json) ? $run->context_json : [];
        $checkpoint = is_array($context['copilot_checkpoint'] ?? null) ? $context['copilot_checkpoint'] : [];

        if ($checkpoint !== []
            && ! $this->checkpointProcessed($session, $run, $checkpoint)
            && $this->checkpointAlreadyObserved($session, $run, $checkpoint)) {
            $this->recoverObservedSuccessfulCheckpoint($session, $run, $checkpoint);

            return;
        }

        if ($checkpoint !== [] && ! $this->checkpointProcessed($session, $run, $checkpoint)) {
            $this->processCheckpoint($session, $run, $checkpoint);

            return;
        }

        if ($checkpoint !== []
            && $run->status === 'waiting'
            && $this->checkpointProcessed($session, $run, $checkpoint)) {
            $this->recoverProcessedWaitingCheckpoint($session, $run, $checkpoint);

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
            $advance = $this->optimizationPlans->advanceAfterCompletedRun($session, $run);

            if ($advance['continued']) {
                $this->startRepairRun($session->fresh() ?? $session, $advance['resume_context']);

                return;
            }

            if ($advance['finalized']) {
                $this->startVerification($session->fresh() ?? $session, $run);

                return;
            }

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
     * A successful checkpoint with a recorded continuation failure must be
     * resumed, not sent through another screenshot and model analysis.
     *
     * @param  array<string, mixed>  $checkpoint
     */
    protected function checkpointAlreadyObserved(
        WorkflowCopilotSession $session,
        WorkflowRun $run,
        array $checkpoint,
    ): bool {
        if (! (bool) ($checkpoint['successful'] ?? false)
            || ($checkpoint['kind'] ?? 'regular') !== 'regular'
            || trim((string) data_get($session->state_json, 'observed_checkpoint_id', '')) !== $this->runtimeCheckpointId($run, $checkpoint)) {
            return false;
        }

        $runtimeCheckpointId = $this->runtimeCheckpointId($run, $checkpoint);

        return $session->events()
            ->where('event_type', 'checkpoint.continuation_deferred')
            ->latest('sequence')
            ->limit(50)
            ->get()
            ->contains(fn ($event): bool => data_get($event->payload_json, 'runtime_checkpoint_id') === $runtimeCheckpointId);
    }

    /**
     * @param  array<string, mixed>  $checkpoint
     */
    protected function recoverObservedSuccessfulCheckpoint(
        WorkflowCopilotSession $session,
        WorkflowRun $run,
        array $checkpoint,
    ): void {
        if (! $this->execution->resumeCopilotCheckpoint($run)) {
            $freshSession = $session->fresh();

            if (! $freshSession
                || $freshSession->status === WorkflowCopilotSession::STATUS_PAUSED
                || in_array($freshSession->status, WorkflowCopilotSession::TERMINAL_STATUSES, true)) {
                return;
            }

            $this->appendContinuationDeferredEvent($freshSession, $run, $checkpoint);

            return;
        }

        $this->markContinuationApplied($session, $checkpoint, 'resume_recovered_after_observation');
        $this->sessions->appendEvent(
            $session->fresh() ?? $session,
            'checkpoint.continuation_recovered',
            'Der bereits analysierte erfolgreiche Checkpoint wurde ohne weitere Bildanalyse fortgesetzt.',
            [
                'workflow_run_id' => (int) $run->id,
                'checkpoint_id' => $this->runtimeCheckpointId($run, $checkpoint),
                'checkpoint_kind' => $checkpoint['kind'] ?? 'regular',
                'task_key' => $checkpoint['task_key'] ?? null,
                'next_action' => $checkpoint['next_action'] ?? null,
                'next_task_key' => $checkpoint['next_task_key'] ?? null,
            ],
            'executing',
            'warning',
            true,
        );
    }

    /**
     * A processed checkpoint must not remain attached to a waiting run. This
     * state means a previous continuation was recorded but never committed to
     * the run, so queue recovery must replay the continuation itself.
     *
     * @param  array<string, mixed>  $checkpoint
     */
    protected function recoverProcessedWaitingCheckpoint(
        WorkflowCopilotSession $session,
        WorkflowRun $run,
        array $checkpoint,
    ): void {
        $context = is_array($run->context_json) ? $run->context_json : [];
        $continuationAction = trim((string) data_get($session->state_json, 'continuation_applied_action', ''));
        $originalTaskKey = null;

        if ($continuationAction === 'probe') {
            $plan = is_array(data_get($session->state_json, 'active_repair_plan'))
                ? data_get($session->state_json, 'active_repair_plan')
                : (is_array($context['copilot_repair_plan'] ?? null) ? $context['copilot_repair_plan'] : []);
            $taskKey = trim((string) ($plan['task_key'] ?? ''));
            $probeTask = is_array($plan['probe_task'] ?? null) ? $plan['probe_task'] : [];

            if (($plan['action'] ?? null) !== 'probe_update' || $taskKey === '' || $probeTask === []) {
                $this->sessions->appendEvent(
                    $session,
                    'checkpoint.probe_recovery_failed',
                    'Die als gestartet markierte Probe wartet weiterhin am alten Checkpoint, aber ihr gespeicherter Reparaturplan ist unvollstaendig.',
                    [
                        'workflow_run_id' => (int) $run->id,
                        'checkpoint_id' => $this->runtimeCheckpointId($run, $checkpoint),
                        'continuation_action' => $continuationAction,
                        'has_probe_task' => $probeTask !== [],
                        'task_key' => $taskKey ?: null,
                    ],
                    'queue_recovery',
                    'error',
                    true,
                );
                $this->sessions->pause(
                    $session,
                    'Die verwaiste Browser-Probe konnte ohne vollstaendigen Reparaturplan nicht sicher neu gestartet werden.',
                );

                return;
            }

            $this->execution->retryCopilotTask($run, $taskKey, $probeTask, $plan);
            $this->sessions->appendEvent(
                $session->fresh() ?? $session,
                'checkpoint.probe_recovered',
                'Eine als gestartet markierte, aber nicht ausgefuehrte Browser-Probe wurde mit ihrer gespeicherten Task-Konfiguration erneut eingeplant.',
                [
                    'workflow_run_id' => (int) $run->id,
                    'checkpoint_id' => $this->runtimeCheckpointId($run, $checkpoint),
                    'task_key' => $taskKey,
                    'task_catalog_key' => $plan['task_catalog_key'] ?? null,
                    'continuation_action' => $continuationAction,
                ],
                'probing',
                'warning',
                true,
            );

            return;
        }

        if (($checkpoint['kind'] ?? null) === 'probe'
            || $continuationAction === 'revision_continue_after_probe') {
            $plan = is_array($context['copilot_repair_plan'] ?? null)
                ? $context['copilot_repair_plan']
                : (is_array($context['copilot_probe_plan'] ?? null) ? $context['copilot_probe_plan'] : []);
            $originalTaskKey = trim((string) ($plan['original_task_key'] ?? $plan['task_key'] ?? ''));

            if ($originalTaskKey === '') {
                $this->sessions->appendEvent(
                    $session,
                    'checkpoint.continuation_recovery_failed',
                    'Der verarbeitete Probe-Checkpoint wartet weiter, aber die Original-Task der Probe fehlt.',
                    [
                        'workflow_run_id' => (int) $run->id,
                        'checkpoint_id' => $this->runtimeCheckpointId($run, $checkpoint),
                        'continuation_action' => $continuationAction,
                    ],
                    'queue_recovery',
                    'error',
                    true,
                );
                $this->sessions->pause(
                    $session,
                    'Die wartende Probe konnte ohne gespeicherte Original-Task nicht sicher fortgesetzt werden.',
                );

                return;
            }
        }

        if (! $this->execution->resumeCopilotCheckpoint($run, $originalTaskKey)) {
            return;
        }

        $this->sessions->appendEvent(
            $session->fresh() ?? $session,
            'checkpoint.continuation_recovered',
            'Ein bereits verarbeiteter, aber weiterhin wartender Checkpoint wurde durch die Queue-Ueberwachung fortgesetzt.',
            [
                'workflow_run_id' => (int) $run->id,
                'checkpoint_id' => $this->runtimeCheckpointId($run, $checkpoint),
                'checkpoint_kind' => $checkpoint['kind'] ?? 'regular',
                'original_task_key' => $originalTaskKey,
                'continuation_action' => $continuationAction,
            ],
            'executing',
            'warning',
            true,
        );
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

    protected function repairFailureSignature(WorkflowStep $step, array $checkpoint, array $observation): string
    {
        $error = trim((string) data_get(
            $checkpoint,
            'result.statusMessage',
            data_get($checkpoint, 'result.error', data_get($checkpoint, 'error', '')),
        ));
        $error = trim((string) preg_replace('/\d+/', '#', $error));
        $payload = [
            'workflow_step_id' => (int) $step->id,
            'task_key' => trim((string) ($checkpoint['task_key'] ?? '')),
            'state_signature' => trim((string) ($observation['state_signature'] ?? '')),
            'page_url' => Str::lower(trim((string) data_get($observation, 'page.url', ''))),
            'error' => $error,
        ];

        return hash('sha256', (string) json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
        ));
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

        $this->sessions->appendEvent(
            $session,
            'observation.started',
            'Aktueller Browserzustand, Screenshot und DOM werden fuer den sicheren Checkpoint erfasst.',
            [
                'workflow_run_id' => (int) $run->id,
                'workflow_step_run_id' => (int) $stepRun->id,
                'task_key' => $checkpoint['task_key'] ?? null,
                'checkpoint_kind' => $checkpoint['kind'] ?? 'regular',
            ],
            'observing',
            'info',
            false,
        );
        $session = $session->fresh() ?? $session;
        $observation = $this->observations->observe($run, $stepRun);
        $vision = [];
        $runContext = is_array($run->context_json) ? $run->context_json : [];
        $isVerificationCheckpoint = (bool) ($runContext['copilot_verification_run'] ?? false)
            && ($runContext['copilot_mutations_allowed'] ?? null) === false;
        $pageState = Str::lower(trim((string) data_get($observation, 'page.state', data_get($observation, 'page_state', ''))));
        $shouldAnalyze = ! (bool) ($checkpoint['successful'] ?? false)
            || (string) ($checkpoint['kind'] ?? '') === 'probe'
            || $isVerificationCheckpoint
            || str_ends_with($pageState, '_blocked')
            || in_array($pageState, ['', 'unknown', 'unknown_browser_state'], true);

        if ($shouldAnalyze) {
            $this->sessions->appendEvent(
                $session,
                'vision.analysis_started',
                'Die Bildanalyse des aktuellen Browserzustands laeuft.',
                [
                    'workflow_run_id' => (int) $run->id,
                    'workflow_step_run_id' => (int) $stepRun->id,
                    'task_key' => $checkpoint['task_key'] ?? null,
                    'analysis_purpose' => $isVerificationCheckpoint ? 'verification_vision' : 'checkpoint_vision',
                    'screenshot_artifact_id' => data_get($observation, 'screenshot.artifact_id'),
                ],
                $isVerificationCheckpoint ? 'verification_vision' : 'visual_analysis',
                'info',
                false,
            );
            $session = $session->fresh() ?? $session;
            $workflowContext = $this->promptContexts->forWorkflow(
                $session->workflow,
                $session,
                $stepRun->workflowStep,
                $checkpoint,
            );
            $vision = $this->captureCopilotAiUsage(
                $session,
                fn (): array => $this->vision->analyze($observation, (string) $session->goal, $workflowContext),
                $isVerificationCheckpoint ? 'verification_vision' : 'checkpoint_vision',
            );
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

        if ($vision !== []) {
            $this->appendVisionAnalysisCompletedEvent(
                $session->fresh() ?? $session,
                $run,
                $stepRun,
                $checkpoint,
                $storedCheckpoint,
                $observation,
                $vision,
            );
        }

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

        $session = $session->fresh() ?? $session;

        if ($this->costBudgetReached($session)) {
            $this->exhaustBudget($session);

            return;
        }

        if ($isVerificationCheckpoint) {
            $this->processVerificationCheckpoint($session, $run, $checkpoint, $attempt->id, $storedCheckpoint->id, $vision);

            return;
        }

        if ((string) ($checkpoint['kind'] ?? '') === 'probe') {
            $this->processProbeResult($session->fresh() ?? $session, $run->fresh() ?? $run, $stepRun->workflowStep, $checkpoint, $observation, $vision);

            return;
        }

        $resolvedPageState = Str::lower(trim((string) data_get($vision, 'ui_state', $pageState)));

        if ((bool) ($checkpoint['successful'] ?? false) && str_contains($resolvedPageState, 'consent')) {
            $this->sessions->appendEvent(
                $session,
                'checkpoint.consent_blocked',
                'Die Task war technisch erfolgreich, der aktuelle Bildschirm bleibt jedoch durch einen sichtbaren Consent-Dialog blockiert.',
                [
                    'workflow_run_id' => (int) $run->id,
                    'task_attempt_id' => (int) $attempt->id,
                    'checkpoint_id' => (int) $storedCheckpoint->id,
                    'task_key' => $checkpoint['task_key'] ?? null,
                    'page_state' => $resolvedPageState,
                    'technical_success' => true,
                    'requires_workflow_repair' => true,
                ],
                'repairing',
                'warning',
                true,
            );
            $blockedCheckpoint = $checkpoint;
            $blockedCheckpoint['successful'] = false;
            $blockedCheckpoint['outcome'] = 'blocked';
            $blockedResult = is_array($blockedCheckpoint['result'] ?? null) ? $blockedCheckpoint['result'] : [];
            $blockedResult['technicalSuccess'] = true;
            $blockedResult['statusMessage'] = 'Task technisch erfolgreich, Seite weiterhin durch Consent-Banner blockiert.';
            $blockedCheckpoint['result'] = $blockedResult;
            $this->repairFailedCheckpoint(
                $session->fresh() ?? $session,
                $run->fresh() ?? $run,
                $stepRun->workflowStep,
                $blockedCheckpoint,
                $observation,
                $vision,
            );

            return;
        }

        $businessGap = $this->successfulCheckpointBusinessGap(
            $session,
            $stepRun->workflowStep,
            $checkpoint,
        );

        if ($businessGap !== []) {
            $this->sessions->appendEvent(
                $session,
                'checkpoint.business_gap',
                (string) $businessGap['message'],
                [
                    'workflow_run_id' => (int) $run->id,
                    'task_attempt_id' => (int) $attempt->id,
                    'checkpoint_id' => (int) $storedCheckpoint->id,
                    'task_key' => $checkpoint['task_key'] ?? null,
                    ...$businessGap['payload'],
                ],
                'repairing',
                'warning',
                true,
            );
            $gapCheckpoint = $checkpoint;
            $gapCheckpoint['successful'] = false;
            $gapCheckpoint['outcome'] = 'failed';
            $gapResult = is_array($gapCheckpoint['result'] ?? null) ? $gapCheckpoint['result'] : [];
            $gapResult['technicalSuccess'] = true;
            $gapResult['businessGap'] = $businessGap['payload'];
            $gapResult['statusMessage'] = $businessGap['message'];
            $gapCheckpoint['result'] = $gapResult;
            $this->repairFailedCheckpoint(
                $session->fresh() ?? $session,
                $run->fresh() ?? $run,
                $stepRun->workflowStep,
                $gapCheckpoint,
                $observation,
                $vision,
            );

            return;
        }

        if ((bool) ($checkpoint['successful'] ?? false)) {
            // Maskierte Task-Fehler sichtbar machen: Ein Step meldet auch dann "ok",
            // wenn eine Task fehlschlug und lediglich eine Fehlerroute gefolgt ist.
            // Ohne diesen Hinweis sieht die Optimierung nur "erfolgreich" und kann
            // die eigentliche Ursache weder erkennen noch reparieren. Die
            // Routen-Semantik bleibt unveraendert – dies ist reine Diagnose.
            $maskedFailures = $this->maskedTaskFailures($checkpoint);

            if ($maskedFailures !== []) {
                $this->sessions->appendEvent(
                    $session,
                    'checkpoint.task_failure_masked',
                    'Der Step meldet Erfolg, obwohl '.count($maskedFailures).' Task(s) fehlgeschlagen sind: '.implode('; ', array_map(
                        static fn (array $item): string => trim(($item['key'] ?: $item['task_key']).' – '.$item['status_message']),
                        $maskedFailures,
                    )),
                    [
                        'workflow_run_id' => (int) $run->id,
                        'task_attempt_id' => (int) $attempt->id,
                        'checkpoint_id' => (int) $storedCheckpoint->id,
                        'failed_tasks' => $maskedFailures,
                        'technical_success' => true,
                    ],
                    'executing',
                    'warning',
                );
            }

            // No-Progress-Sperre: Auch wenn Tasks technisch "erfolgreich" melden, darf
            // eine unveraenderte Seite nicht endlos fortgesetzt werden. Erreicht der
            // Stillstands-Zaehler das Limit, wird der Checkpoint als fachlicher Fehler
            // in die Strukturreparatur umgeleitet (analog Consent-/Business-Gap), statt
            // den Kreislauf 77x zu wiederholen.
            $progressSession = $session->fresh() ?? $session;
            $maxSameStateRepeats = max(1, (int) data_get($progressSession->budget_json, 'max_same_state_repeats', 2));
            $sameStateRepeats = (int) data_get($progressSession->usage_json, 'same_state_repeats', 0);

            if ($sameStateRepeats >= $maxSameStateRepeats) {
                $this->sessions->appendEvent(
                    $session,
                    'checkpoint.no_progress',
                    'Kein Fortschritt: Dieselbe Seite wurde '.$sameStateRepeats.'x ohne Aenderung erneut erreicht, obwohl die Tasks technisch erfolgreich meldeten. Es wird eine Strukturreparatur ausgeloest.',
                    [
                        'workflow_run_id' => (int) $run->id,
                        'task_attempt_id' => (int) $attempt->id,
                        'checkpoint_id' => (int) $storedCheckpoint->id,
                        'task_key' => $checkpoint['task_key'] ?? null,
                        'same_state_repeats' => $sameStateRepeats,
                        'max_same_state_repeats' => $maxSameStateRepeats,
                        'technical_success' => true,
                        'requires_workflow_repair' => true,
                    ],
                    'repairing',
                    'warning',
                    true,
                );
                $stalledCheckpoint = $checkpoint;
                $stalledCheckpoint['successful'] = false;
                $stalledCheckpoint['outcome'] = 'no_progress';
                $stalledResult = is_array($stalledCheckpoint['result'] ?? null) ? $stalledCheckpoint['result'] : [];
                $stalledResult['technicalSuccess'] = true;
                $stalledResult['noProgress'] = true;
                $stalledResult['sameStateRepeats'] = $sameStateRepeats;
                $stalledResult['statusMessage'] = 'Kein Fortschritt: gleiche Seite wiederholt sich ohne Aenderung.';
                $stalledCheckpoint['result'] = $stalledResult;
                $this->repairFailedCheckpoint(
                    $session->fresh() ?? $session,
                    $run->fresh() ?? $run,
                    $stepRun->workflowStep,
                    $stalledCheckpoint,
                    $observation,
                    $vision,
                );

                return;
            }

            if ($this->timeBudgetExceeded($session->fresh() ?? $session)) {
                $this->exhaustBudget($session->fresh() ?? $session);

                return;
            }

            if (! $this->execution->resumeCopilotCheckpoint($run)) {
                $freshSession = $session->fresh();

                if ($freshSession
                    && $freshSession->status !== WorkflowCopilotSession::STATUS_PAUSED
                    && ! in_array($freshSession->status, WorkflowCopilotSession::TERMINAL_STATUSES, true)) {
                    $this->appendContinuationDeferredEvent($freshSession, $run, $checkpoint, $storedCheckpoint->id);
                }

                return;
            }

            $this->markContinuationApplied($session, $checkpoint, 'resume');
            $message = $this->checkpointContinuationMessage($checkpoint);
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

            return;
        }

        $this->repairFailedCheckpoint($session->fresh() ?? $session, $run->fresh() ?? $run, $stepRun->workflowStep, $checkpoint, $observation, $vision);
    }

    /**
     * Tasks, die im Checkpoint-Ergebnis als fehlgeschlagen gemeldet sind, obwohl
     * der Step insgesamt Erfolg meldet (z. B. weil eine Fehlerroute gefolgt ist).
     *
     * @return array<int, array<string, string>>
     */
    protected function maskedTaskFailures(array $checkpoint): array
    {
        $result = is_array($checkpoint['result'] ?? null) ? $checkpoint['result'] : [];
        $failures = [];

        foreach (is_array($result['tasks'] ?? null) ? $result['tasks'] : [] as $task) {
            if (! is_array($task)) {
                continue;
            }

            $status = strtolower(trim((string) ($task['status'] ?? '')));

            if ($status !== 'failed' && ($task['ok'] ?? null) !== false) {
                continue;
            }

            $failures[] = [
                'key' => (string) ($task['key'] ?? ''),
                'task_key' => (string) ($task['task_key'] ?? ''),
                'status_message' => (string) ($task['statusMessage'] ?? $task['status_message'] ?? ''),
            ];
        }

        return $failures;
    }

    protected function successfulCheckpointBusinessGap(
        WorkflowCopilotSession $session,
        WorkflowStep $step,
        array $checkpoint,
    ): array {
        if (! (bool) ($checkpoint['successful'] ?? false)) {
            return [];
        }

        $resumeTaskKey = trim((string) ($checkpoint['resume_task_key'] ?? $checkpoint['task_key'] ?? ''));
        $failureTaskKey = trim((string) ($checkpoint['failure_task_key'] ?? ''));
        $taskKey = $failureTaskKey !== '' ? $failureTaskKey : $resumeTaskKey;
        $task = collect($step->task_cards)->firstWhere('key', $taskKey);

        if (! is_array($task) || (string) ($task['task_key'] ?? '') !== 'loop.for_each_element') {
            return $this->missingRequiredWorkflowReturnGap($session, $checkpoint);
        }

        $result = is_array($checkpoint['result'] ?? null) ? $checkpoint['result'] : [];
        $taskResult = collect(is_array($result['tasks'] ?? null) ? $result['tasks'] : [])
            ->first(fn (mixed $candidate): bool => is_array($candidate)
                && (string) ($candidate['key'] ?? '') === $taskKey);
        $matchedCount = is_array($taskResult) && is_numeric($taskResult['matched_count'] ?? null)
            ? (int) $taskResult['matched_count']
            : (is_numeric($result['matched_count'] ?? null) ? (int) $result['matched_count'] : null);

        if ($matchedCount !== 0) {
            return $this->missingRequiredWorkflowReturnGap($session, $checkpoint);
        }

        $collectsArray = collect($step->task_cards)->contains(
            fn (array $candidate): bool => (string) ($candidate['task_key'] ?? '') === 'data.append_to_array',
        );
        $expectation = Str::lower(implode(' ', array_filter([
            (string) $session->goal,
            json_encode($session->success_criteria_json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ], static fn (mixed $value): bool => is_scalar($value))));
        $expectsCollection = preg_match('/(?:array|liste|list|treffer|ergebnis|result)/u', $expectation) === 1;

        if (! $collectsArray || ! $expectsCollection) {
            return [];
        }

        return [
            'message' => 'Der Ergebnis-Loop wurde technisch beendet, hat aber keinen einzigen Treffer gefunden. Da dieser Step ein Ergebnis-Array erzeugen soll, wird der leere Loop als fachlicher Fehler repariert statt als Erfolg fortgesetzt.',
            'payload' => [
                'reason_code' => 'required_collection_empty',
                'matched_count' => 0,
                'selector' => $task['selector'] ?? $task['element_selector'] ?? null,
                'array_consumers' => collect($step->task_cards)
                    ->filter(fn (array $candidate): bool => (string) ($candidate['task_key'] ?? '') === 'data.append_to_array')
                    ->pluck('array_name')
                    ->filter()
                    ->values()
                    ->all(),
            ],
        ];
    }

    protected function missingRequiredWorkflowReturnGap(
        WorkflowCopilotSession $session,
        array $checkpoint,
    ): array {
        $expectation = Str::lower(implode(' ', array_filter([
            (string) $session->goal,
            json_encode($session->success_criteria_json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ], static fn (mixed $value): bool => is_scalar($value))));

        if (preg_match('/(?:rueckgabewert|rückgabewert|return).*?(?:array|liste|list)/u', $expectation) !== 1) {
            return [];
        }

        $result = is_array($checkpoint['result'] ?? null) ? $checkpoint['result'] : [];

        if (is_array($result['workflow_return'] ?? $result['workflowReturn'] ?? null)) {
            return [];
        }

        $workflow = $session->workflow;
        $workflow?->loadMissing(['steps' => fn ($query) => $query->ordered()]);
        $tasks = $workflow
            ? $workflow->steps->flatMap(fn (WorkflowStep $workflowStep) => collect($workflowStep->task_cards))
            : collect();

        if ($tasks->contains(fn (array $candidate): bool => (string) ($candidate['task_key'] ?? '') === 'data.workflow_return')) {
            return [];
        }

        $sourceArray = $tasks
            ->filter(fn (array $candidate): bool => (string) ($candidate['task_key'] ?? '') === 'data.append_to_array')
            ->pluck('array_name')
            ->map(fn (mixed $name): string => trim((string) $name))
            ->filter()
            ->first(function (string $name) use ($result): bool {
                return is_array(data_get($result, 'workflow_variables.'.$name))
                    || is_array(data_get($result, 'workflowVariables.'.$name));
            });

        if (! is_string($sourceArray) || $sourceArray === '') {
            return [];
        }

        return [
            'message' => 'Das Ergebnis-Array `'.$sourceArray.'` ist vorhanden, aber der Workflow setzt keinen expliziten Rueckgabewert. Fuer die feste Array-Erfolgsaussage wird eine kataloggebundene Rueckgabe-Task benoetigt.',
            'payload' => [
                'reason_code' => 'required_workflow_return_missing',
                'source_array' => $sourceArray,
                'expected_type' => 'array',
            ],
        ];
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
        if ($this->execution->resumeCopilotCheckpoint($run)) {
            $this->markContinuationApplied($session, $checkpoint, 'verification_resume');
        }
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

        if ($newRepairIteration) {
            $failureSignature = $this->repairFailureSignature($step, $checkpoint, $observation);
            $previousFailureSignature = trim((string) ($state['last_repair_failure_signature'] ?? ''));
            $failureRepeats = $failureSignature !== '' && hash_equals($previousFailureSignature, $failureSignature)
                ? max(0, (int) ($state['repair_failure_repeats'] ?? 0)) + 1
                : 0;
            $state['last_repair_failure_signature'] = $failureSignature;
            $state['repair_failure_repeats'] = $failureRepeats;
            $usage['same_state_repeats'] = $failureRepeats;
            $session->forceFill([
                'state_json' => $state,
                'usage_json' => $usage,
                'last_activity_at' => now(),
            ])->save();

            $maxSameFailureRepeats = max(1, (int) data_get($session->budget_json, 'max_same_state_repeats', 2));

            if ($failureRepeats >= $maxSameFailureRepeats) {
                $this->sessions->appendEvent(
                    $session,
                    'repair.no_progress',
                    'Dieselbe Task scheitert nach mehreren Reparaturrevisionen weiterhin im gleichen Zustand; weitere Routen-Aenderungen ohne neue Evidenz werden beendet.',
                    [
                        'workflow_run_id' => (int) $run->id,
                        'workflow_step_id' => (int) $step->id,
                        'task_key' => $checkpoint['task_key'] ?? null,
                        'state_signature' => $observation['state_signature'] ?? null,
                        'failure_repeats' => $failureRepeats,
                    ],
                    'repairing',
                    'error',
                    true,
                );
                $this->exhaustBudget($session->fresh() ?? $session);

                return;
            }
        }

        if ($newRepairIteration && $this->repairBudgetReachedBeforeAction($session->fresh() ?? $session)) {
            $this->exhaustBudget($session->fresh() ?? $session);

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
            $evidenceSummary = $this->repairEvidenceSummary($step, $checkpoint, $observation, $vision);
            $this->sessions->appendEvent(
                $session,
                'repair.evidence_evaluated',
                (string) $evidenceSummary['message'],
                $evidenceSummary['payload'],
                'visual_analysis',
                'info',
                true,
            );
        }

        $rejectedSelectors = is_array($state['rejected_selectors'] ?? null) ? $state['rejected_selectors'] : [];
        $pendingStudioPlan = is_array($state['studio_pending_repair_plan'] ?? null)
            ? $state['studio_pending_repair_plan']
            : [];
        $plan = $pendingStudioPlan !== []
            ? $pendingStudioPlan
            : $this->captureCopilotAiUsage(
                $session,
                fn (): array => $this->repairs->plan($session, $step, $checkpoint, $observation, $vision, $rejectedSelectors),
                'repair_planning',
            );
        $session = $session->fresh() ?? $session;
        $planFingerprint = $this->repairPlanFingerprint($session, $step, $checkpoint, $observation, $plan);
        $state = is_array($session->state_json) ? $session->state_json : [];
        $planHistory = collect(is_array($state['repair_plan_fingerprints'] ?? null) ? $state['repair_plan_fingerprints'] : []);
        $duplicatePlan = $planFingerprint !== '' && $planHistory->contains(
            fn (mixed $entry): bool => is_array($entry)
                && hash_equals((string) ($entry['fingerprint'] ?? ''), $planFingerprint)
                && ($pendingStudioPlan === []
                    || (string) ($entry['runtime_checkpoint_id'] ?? '') !== $runtimeCheckpointId),
        );

        if ($duplicatePlan) {
            $this->sessions->appendEvent(
                $session,
                'repair.plan_rejected_duplicate',
                'Derselbe Reparaturplan wurde im unveraenderten Fehlerzustand bereits versucht und wird nicht erneut angewendet.',
                [
                    'repair_plan_fingerprint' => $planFingerprint,
                    'runtime_checkpoint_id' => $runtimeCheckpointId,
                    'task_key' => $checkpoint['failure_task_key'] ?? $checkpoint['task_key'] ?? null,
                    'state_signature' => $observation['state_signature'] ?? null,
                ],
                'repairing',
                'error',
                true,
            );
            $this->sessions->pause(
                $session->fresh() ?? $session,
                'Die Reparatur wurde pausiert, weil derselbe wirkungslose Plan ohne neue Evidenz erneut vorgeschlagen wurde.',
            );

            return;
        }

        if ($planFingerprint !== '') {
            $state['repair_plan_fingerprints'] = $planHistory
                ->push([
                    'fingerprint' => $planFingerprint,
                    'runtime_checkpoint_id' => $runtimeCheckpointId,
                    'recorded_at' => now()->toIso8601String(),
                ])
                ->take(-30)
                ->values()
                ->all();
            $session->forceFill(['state_json' => $state, 'last_activity_at' => now()])->save();
            $plan['repair_plan_fingerprint'] = $planFingerprint;
        }

        $decisionSummary = $this->repairDecisionSummary($plan, $vision);
        $this->sessions->appendEvent(
            $session,
            'repair.decision_planned',
            (string) $decisionSummary['message'],
            $decisionSummary['payload'],
            'repairing',
            ($plan['action'] ?? null) === 'pause' ? 'warning' : 'info',
            true,
        );

        if ($this->costBudgetReached($session)) {
            $this->exhaustBudget($session);

            return;
        }

        if (! $this->authorizeStudioRepairPlan($session, $run, $checkpoint, $plan)) {
            return;
        }

        if ($plan['action'] === 'skip_resolved_obstacle') {
            $this->sessions->appendEvent(
                $session,
                'repair.obstacle_resolved',
                (string) $plan['reason'],
                $plan,
                'repairing',
                'success',
                true,
            );
            $this->sessions->transition($session, WorkflowCopilotSession::STATUS_RUNNING, 'executing');

            if ($this->execution->skipResolvedCopilotTask($run, (string) $plan['task_key'])) {
                $this->markContinuationApplied($session, $checkpoint, 'skip_resolved_obstacle');
            }

            return;
        }

        if ($plan['action'] === 'continue_route') {
            if (! (bool) ($plan['resume_checkpoint'] ?? false)) {
                $context = is_array($run->context_json) ? $run->context_json : [];
                $pending = is_array($context['copilot_checkpoint'] ?? null) ? $context['copilot_checkpoint'] : [];
                $pending['next_action'] = 'complete_step';

                if (is_array($plan['configured_route'] ?? null)) {
                    $pendingResult = is_array($pending['result'] ?? null) ? $pending['result'] : [];
                    $pendingResult['failedTaskKey'] = (string) ($plan['task_key'] ?? $checkpoint['task_key'] ?? '');
                    $pendingResult['failed_task_key'] = $pendingResult['failedTaskKey'];
                    $pending['result'] = $pendingResult;
                }

                $context['copilot_checkpoint'] = $pending;
                $run->forceFill(['context_json' => $context])->save();
            }
            $this->sessions->appendEvent($session, 'repair.route_selected', $plan['reason'], $plan, 'repairing', 'info', true);
            $this->sessions->transition($session, WorkflowCopilotSession::STATUS_RUNNING, 'executing');
            if ($this->execution->resumeCopilotCheckpoint($run)) {
                $this->markContinuationApplied($session, $checkpoint, 'continue_route');
            }

            return;
        }

        if ($plan['action'] === 'retry') {
            $this->sessions->appendEvent($session, 'repair.retry', $plan['reason'], $plan, 'repairing', 'info', true);
            $this->sessions->transition($session, WorkflowCopilotSession::STATUS_RUNNING, 'executing');
            $this->execution->retryCopilotTask($run, (string) $plan['task_key']);
            $this->markContinuationApplied($session, $checkpoint, 'retry');

            return;
        }

        if ($plan['action'] === 'restart_with_workflow_changes') {
            $operations = is_array($plan['operations'] ?? null) ? $plan['operations'] : [];
            $recordedSideEffects = $this->recordedRunSideEffects($session, $run);

            if ($operations === []) {
                $this->sessions->pause($session, 'Die strukturelle Reparatur enthielt keine gueltige Workflow-Aenderung.');

                return;
            }

            if ($recordedSideEffects !== []) {
                $this->sessions->appendEvent(
                    $session,
                    'repair.restart_blocked_after_side_effect',
                    'Der Workflow wird nach der Strukturreparatur nicht automatisch neu gestartet, weil der aktuelle Lauf bereits externe Wirkungen protokolliert hat.',
                    [
                        'workflow_run_id' => (int) $run->id,
                        'side_effect_ledger' => $recordedSideEffects,
                        'external_side_effects_reverted' => false,
                    ],
                    'repairing',
                    'warning',
                    true,
                );
                $this->sessions->pause($session, 'Ein automatischer Neustart koennte bereits ausgefuehrte externe Wirkungen wiederholen.');

                return;
            }

            try {
                $revision = $this->revisions->apply(
                    $session,
                    (int) $session->current_revision,
                    (string) ($plan['reason'] ?? 'Kataloggebundene strukturelle Workflow-Reparatur.'),
                    function (Workflow $workflow) use ($operations, $session, $observation, $checkpoint, $vision): void {
                        $this->repairs->applyStructuralOperations(
                            $workflow,
                            $operations,
                            $session,
                            array_replace($observation, [
                                'copilot_checkpoint' => $checkpoint,
                                'copilot_vision' => $vision,
                            ]),
                        );
                    },
                );
            } catch (Throwable $exception) {
                $message = Str::limit(trim($exception->getMessage()), 1000, '') ?: 'Die strukturelle Workflow-Reparatur konnte nicht gespeichert werden.';
                $this->sessions->appendEvent(
                    $session,
                    'repair.structural_update_failed',
                    $message,
                    ['operation_count' => count($operations)],
                    'repairing',
                    'error',
                    true,
                );
                $this->sessions->pause($session, 'Die validierte Strukturreparatur konnte nicht revisioniert gespeichert werden.');

                return;
            }

            $this->sessions->appendEvent(
                $session->fresh() ?? $session,
                'repair.structural_update_applied',
                'Fehlende oder falsch geroutete Workflow-Logik wurde als Revision gespeichert; der naechste Test startet von Anfang an.',
                [
                    'workflow_run_id' => (int) $run->id,
                    'workflow_revision_id' => (int) $revision->id,
                    'revision_number' => (int) $revision->revision_number,
                    'operation_count' => count($operations),
                    'operations' => $operations,
                    'planning_handoff' => is_array($plan['planning_handoff'] ?? null)
                        ? $plan['planning_handoff']
                        : [
                            'vision_profile' => 'image_understanding',
                            'vision_model' => $vision['model'] ?? null,
                            'planner_profile' => 'data_analysis',
                        ],
                ],
                'repairing',
                'success',
                true,
            );
            $this->execution->cancel($run, 'Copilot startet nach einer strukturellen Workflow-Reparatur einen frischen Testlauf.');
            $this->startRepairRun($session->fresh() ?? $session);

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
            $pauseReason = Str::limit(
                trim((string) ($plan['reason'] ?? 'Keine sichere autonome Reparatur gefunden.')),
                900,
                '',
            );
            $this->sessions->pause($session, 'Copilot pausiert: '.$pauseReason);

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

    protected function authorizeStudioRepairPlan(
        WorkflowCopilotSession $session,
        WorkflowRun $run,
        array $checkpoint,
        array $plan,
    ): bool {
        $studio = \App\Models\WorkflowStudioSession::query()
            ->where('workflow_copilot_session_id', $session->getKey())
            ->latest('id')
            ->first();
        if (! $studio || ($plan['action'] ?? 'pause') === 'pause') {
            return true;
        }

        $action = $this->studioPermissionActionForRepairPlan($plan);
        $parameters = [
            'workflow_copilot_session_id' => (int) $session->getKey(),
            'workflow_run_id' => (int) $run->getKey(),
            'runtime_checkpoint_id' => $this->runtimeCheckpointId($run, $checkpoint),
            'plan' => $plan,
        ];
        $sessionState = is_array($session->state_json) ? $session->state_json : [];
        $confirmationId = trim((string) ($sessionState['studio_authorization_confirmation_id'] ?? '')) ?: null;
        $decision = $this->studioAuthorization->decide($studio, $action, $parameters, $confirmationId);

        if ($decision['allowed']) {
            if ($confirmationId) {
                $this->studioAuthorization->consume($studio, $confirmationId);
            }
            unset($sessionState['studio_pending_repair_plan'], $sessionState['studio_authorization_confirmation_id']);
            $session->forceFill(['state_json' => $sessionState, 'last_activity_at' => now()])->save();
            $freshStudio = $studio->fresh();
            $studioState = is_array($freshStudio?->state_json) ? $freshStudio->state_json : [];
            $studioState['pending_copilot_confirmation'] = null;
            $studio->forceFill(['state_json' => $studioState, 'status' => 'running', 'last_activity_at' => now()])->save();

            return true;
        }

        $pending = [
            'type' => 'copilot_plan',
            'action' => $action,
            'confirmation_id' => $decision['confirmation_id'],
            'message' => 'Copilot moechte den geplanten Reparaturschritt „'.($plan['action'] ?? 'repair').'“ anwenden.',
            'parameters' => $parameters,
            'workflow_run_id' => (int) $run->getKey(),
            'workflow_copilot_session_id' => (int) $session->getKey(),
            'created_at' => now()->toIso8601String(),
        ];
        $sessionState['studio_pending_repair_plan'] = $plan;
        $sessionState['studio_authorization_confirmation_id'] = $decision['confirmation_id'];
        $session->forceFill(['state_json' => $sessionState, 'last_activity_at' => now()])->save();
        $studioState = is_array($studio->state_json) ? $studio->state_json : [];
        $studioState['pending_copilot_confirmation'] = $pending;
        $studio->forceFill(['state_json' => $studioState, 'status' => 'confirmation_required', 'last_activity_at' => now()])->save();
        app(WorkflowStudioSessionService::class)->appendEvent($studio, 'authorization.requested', $pending['message'], [
            'action' => $action,
            'confirmation_id' => $decision['confirmation_id'],
            'workflow_run_id' => (int) $run->getKey(),
        ], 'warning');
        $this->sessions->pause($session->fresh() ?? $session, $decision['message']);

        return false;
    }

    protected function studioPermissionActionForRepairPlan(array $plan): string
    {
        $planAction = (string) ($plan['action'] ?? '');
        if ($planAction === 'restart_with_workflow_changes') {
            $operations = array_values(array_filter((array) ($plan['operations'] ?? []), 'is_array'));
            if (count($operations) > 1) {
                return 'workflow.replace';
            }
            $operationType = (string) data_get($operations, '0.type', '');

            return in_array($operationType, ['insert_task', 'insert_step'], true) ? 'task.add' : 'task.update';
        }
        if ($planAction === 'probe_update') {
            $catalogKey = Str::lower(trim((string) data_get($plan, 'probe_task.task_key', '')));
            if ($catalogKey === 'input.submit' || str_contains($catalogKey, 'send') || str_contains($catalogKey, 'persist')) {
                return 'external.send';
            }
            if (str_contains($catalogKey, 'delete')) {
                return 'external.delete';
            }

            return 'selector.search';
        }

        return 'workflow.execute_task';
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
        if (! $this->execution->resumeCopilotCheckpoint($run, $originalTaskKey)) {
            return;
        }

        $this->sessions->appendEvent(
            $session->fresh() ?? $session,
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
            $state = is_array($lockedSession->state_json) ? $lockedSession->state_json : [];
            $revisionAlreadyApplied = ($state['revision_applied_checkpoint_id'] ?? null) === $runtimeCheckpointId;
            $revision = null;

            if ($revisionAlreadyApplied) {
                $revisionNumber = max(0, (int) ($state['revision_applied_number'] ?? 0));
                $revision = WorkflowRevision::query()
                    ->where('workflow_id', $lockedSession->workflow_id)
                    ->where('workflow_copilot_session_id', $lockedSession->id)
                    ->where('revision_number', $revisionNumber)
                    ->lockForUpdate()
                    ->first();

                if (! $revision) {
                    throw new \RuntimeException('Die bereits markierte Probe-Revision konnte nicht mehr geladen werden.');
                }
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
            }

            $storedCheckpoint->forceFill(['workflow_revision_id' => $revision->id])->save();

            if ($storedCheckpoint->workflow_task_attempt_id) {
                WorkflowTaskAttempt::query()
                    ->whereKey($storedCheckpoint->workflow_task_attempt_id)
                    ->update(['workflow_revision_id' => $revision->id]);
            }

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

    protected function createInitialWorkflowDefinition(WorkflowCopilotSession $session): WorkflowCopilotSession
    {
        $workflow = $session->workflow()->with('steps')->firstOrFail();

        if ($plan = $this->optimizationPlans->active($session)) {
            $testing = $plan->items->firstWhere('status', 'testing');
            $item = $testing ?: $this->optimizationPlans->materializeNext($session);

            if ($item) {
                $this->sessions->appendEvent(
                    $session->fresh() ?? $session,
                    'planning.task_materialized',
                    'Task `'.$item->task_key.'` wurde aus dem Gesamtplan als naechster einzeln testbarer Baustein eingesetzt.',
                    ['plan_id' => $plan->id, 'plan_item_id' => $item->id, 'sequence' => $item->sequence],
                    'planning',
                    'success',
                    true,
                );
            }

            return $session->fresh(['workflow.steps']) ?? $session;
        }

        if (! $this->planning->needsInitialPlan($workflow)) {
            return $session;
        }

        $this->sessions->appendEvent(
            $session,
            'planning.started',
            'Der leere Workflow wird aus Ziel, Eingaben und Task-Katalog vollstaendig mit Listen, Tasks und Routen aufgebaut.',
            ['workflow_id' => (int) $workflow->id],
            'planning',
            'info',
            true,
        );
        $plan = [];
        $revision = $this->captureCopilotAiUsage(
            $session,
            function () use ($session, &$plan): array {
                $revision = $this->revisions->apply(
                    $session,
                    (int) $session->current_revision,
                    'Vollstaendige kataloggebundene Erstdefinition fuer den leeren Workflow.',
                    function (Workflow $workflow) use ($session, &$plan): void {
                        $plan = $this->planning->planAndApply(
                            $workflow,
                            (string) $session->goal,
                            is_array($session->success_criteria_json) ? $session->success_criteria_json : [],
                            is_array($session->workflow_inputs_json) ? $session->workflow_inputs_json : [],
                        );
                    },
                );

                return [
                    'workflow_revision_id' => (int) $revision->id,
                    'revision_number' => (int) $revision->revision_number,
                ];
            },
            'initial_planning',
        );
        $session = $session->fresh(['workflow.steps']) ?? $session;
        $this->sessions->appendEvent(
            $session,
            'planning.completed',
            'Der leere Workflow wurde als ausfuehrbare Erstdefinition gespeichert; der erste System-Test startet jetzt.',
            [
                ...$revision,
                'step_count' => count($plan['steps'] ?? []),
                'task_count' => (int) ($plan['task_count'] ?? 0),
                'summary' => $plan['summary'] ?? null,
            ],
            'planning',
            'success',
            true,
        );

        return $this->sessions->transition(
            $session,
            WorkflowCopilotSession::STATUS_RUNNING,
            'executing',
            [
                'current_step_name' => data_get($plan, 'steps.0.name'),
                'current_task_key' => data_get($plan, 'steps.0.tasks.0.key'),
                'last_action' => 'Workflow-Erstdefinition erstellt',
                'next_action' => 'Ersten System-Test starten',
            ],
            'Die automatisch erstellte Workflow-Erstdefinition wird jetzt getestet.',
        );
    }

    protected function recordedRunSideEffects(
        WorkflowCopilotSession $session,
        WorkflowRun $run,
    ): array {
        return WorkflowRunCheckpoint::query()
            ->where('workflow_copilot_session_id', $session->id)
            ->where('workflow_run_id', $run->id)
            ->orderBy('sequence')
            ->get(['id', 'sequence', 'side_effect_ledger_json'])
            ->flatMap(function (WorkflowRunCheckpoint $checkpoint): array {
                $ledger = is_array($checkpoint->side_effect_ledger_json)
                    ? $checkpoint->side_effect_ledger_json
                    : [];

                return collect($ledger)
                    ->filter(fn (mixed $entry): bool => is_array($entry))
                    ->map(fn (array $entry): array => [
                        'checkpoint_id' => (int) $checkpoint->id,
                        'checkpoint_sequence' => (int) $checkpoint->sequence,
                        ...$entry,
                    ])
                    ->all();
            })
            ->values()
            ->all();
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
        $latestStepRun = $run->stepRuns()->with('workflowStep')->latest('id')->first();
        $observation = $this->observations->observe($run, $latestStepRun);
        $criteriaEvaluation = $this->evaluateSuccessCriteria($criteria, $run, $observation);
        $workflowContext = $this->promptContexts->forWorkflow(
            $session->workflow,
            $session,
            $latestStepRun?->workflowStep,
            is_array(data_get($run->context_json, 'copilot_checkpoint'))
                ? data_get($run->context_json, 'copilot_checkpoint')
                : [],
        );
        $vision = $this->vision->analyze(
            $observation,
            $this->verificationGoal($session, $criteria),
            $workflowContext,
        );
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
                'return_type', 'workflow_return_type' => ['type' => 'result_type', 'operator' => 'type_is', 'path' => 'workflow_return', 'value' => $value],
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
            return $this->assertionResult($index, 'unsupported', 'equals', null, false, 'Erfolgskriterium besitzt kein pruefbares Format und kann daher nie bestehen. Bitte pruefbar formulieren, z. B. "URL enthaelt /erfolg" oder "Text X ist sichtbar".');
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
            case 'result_type':
            case 'return_type':
            case 'workflow_return_type':
                $path = trim((string) ($assertion['path'] ?? $assertion['key'] ?? 'workflow_return')) ?: 'workflow_return';
                $actual = data_get($run->result_json, $path);

                if ($actual === null && $path === 'workflow_return') {
                    $actual = data_get($run->result_json, 'workflowReturn');
                }

                $operator = $operator ?: 'type_is';
                $type = 'result_type';
                break;
            default:
                return $this->assertionResult(
                    $index,
                    $type ?: 'unsupported',
                    $operator ?: 'equals',
                    $path,
                    false,
                    'Erfolgskriterium ist nicht automatisch pruefbar und kann daher nie bestehen. Bitte pruefbar formulieren, z. B. "URL enthaelt /erfolg", "Titel enthaelt X", "Text X ist sichtbar", "Seitenzustand ist Y" oder "Rueckgabewert = array".',
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

        if (preg_match('/^(?:rueckgabewert|rückgabewert|workflow[-\s]?rueckgabewert|workflow[-\s]?rückgabewert|return\s+value)\s*(?:=|ist|gleich|equals)\s*(array|liste|list|object|objekt|string|text|number|zahl|integer|boolean|bool|null)$/iu', $assertion, $matches) === 1) {
            return [
                'type' => 'result_type',
                'operator' => 'type_is',
                'path' => 'workflow_return',
                'value' => trim($matches[1]),
            ];
        }

        $patterns = [
            '/^(?:finale?\s+)?url\s+(?:enth(?:ae|ä)lt|contains)\s+(.+)$/iu' => ['url', 'contains'],
            '/^(?:finale?\s+)?url\s+(?:endet\s+mit|ends\s+with)\s+(.+)$/iu' => ['url', 'ends_with'],
            '/^(?:finale?\s+)?url\s+(?:ist|gleich|equals)\s+(.+)$/iu' => ['url', 'equals'],
            // Die folgenden Typen wertet evaluateSuccessAssertion bereits aus, es
            // fehlten bisher nur die Freitext-Muster – dadurch landeten normal
            // formulierte Kriterien auf `unsupported` und die Verifikation konnte
            // strukturell nie bestehen.
            '/^(?:seiten)?titel\s+(?:enth(?:ae|ä)lt|contains)\s+(.+)$/iu' => ['title', 'contains'],
            '/^title\s+(?:contains|enth(?:ae|ä)lt)\s+(.+)$/iu' => ['title', 'contains'],
            '/^(?:seiten)?titel\s+(?:ist|gleich|equals)\s+(.+)$/iu' => ['title', 'equals'],
            '/^(?:seitenzustand|seiten[-\s]?status|page[-\s]?state)\s+(?:ist|gleich|=|equals)\s+(.+)$/iu' => ['page_state', 'equals'],
            '/^technischer?\s+status\s+(?:ist|gleich|=|equals)\s+(.+)$/iu' => ['technical_status', 'equals'],
            '/^technical[-\s]?status\s+(?:is|=|equals)\s+(.+)$/iu' => ['technical_status', 'equals'],
            '/^(?:fachlicher?\s+status|business[-\s]?status)\s+(?:ist|is|gleich|=|equals)\s+(.+)$/iu' => ['business_status', 'equals'],
            '/^text\s+(.+?)\s+(?:ist\s+sichtbar|is\s+visible)$/iu' => ['visible_text', 'contains'],
            '/^(?:seite|page)\s+(?:zeigt|enth(?:ae|ä)lt|shows|contains)\s+(.+)$/iu' => ['visible_text', 'contains'],
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

        if (preg_match('/^([A-Za-z0-9_.-]+)\s+(?:=|ist|gleich|equals)\s+(.+)$/u', $assertion, $matches) === 1) {
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
        if ($operator === 'type_is') {
            $expectedType = Str::lower(trim((string) $expected));
            $expectedType = match ($expectedType) {
                'liste', 'list' => 'array',
                'objekt' => 'object',
                'text' => 'string',
                'zahl' => 'number',
                'bool' => 'boolean',
                default => $expectedType,
            };

            return match ($expectedType) {
                'array' => is_array($actual),
                'object' => is_object($actual) || (is_array($actual) && ! array_is_list($actual)),
                'string' => is_string($actual),
                'number' => is_int($actual) || is_float($actual),
                'integer' => is_int($actual),
                'boolean' => is_bool($actual),
                'null' => $actual === null,
                default => false,
            };
        }

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

            foreach (['text', 'semantic_label', 'title', 'aria', 'aria_label', 'label', 'name', 'placeholder'] as $key) {
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
        $resumeTaskKey = trim((string) ($checkpoint['resume_task_key'] ?? $taskKey)) ?: $taskKey;
        $failureTaskKey = trim((string) ($checkpoint['failure_task_key'] ?? data_get($checkpoint, 'result.failedTaskKey', '')));
        $definitionTaskKey = $failureTaskKey !== '' ? $failureTaskKey : $taskKey;
        $task = collect($step->task_cards)->firstWhere('key', $definitionTaskKey);
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
                'resume_task_key' => $resumeTaskKey,
                'failure_task_key' => $failureTaskKey !== '' ? $failureTaskKey : null,
                'task_title' => (string) data_get($task, 'title', $checkpoint['task_title'] ?? $definitionTaskKey),
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
            'resume_task_key' => $resumeTaskKey,
            'failure_task_key' => $failureTaskKey !== '' ? $failureTaskKey : null,
            'cursor_json' => [
                'runtime_checkpoint_id' => $runtimeCheckpointId,
                'step_id' => $step->id,
                'step_action_key' => $step->action_key,
                'step_name' => $step->name,
                'task_key' => $taskKey,
                'resume_task_key' => $resumeTaskKey,
                'failure_task_key' => $failureTaskKey !== '' ? $failureTaskKey : null,
                'next_action' => $checkpoint['next_action'] ?? null,
                'next_task_key' => $checkpoint['next_task_key'] ?? null,
            ],
            'context_json' => $this->checkpointRuntimeContext($run),
            'browser_state_json' => [
                'windows' => $observation['browser_windows'] ?? [],
                'page' => $observation['page'] ?? [],
                'evidence_provenance' => $observation['evidence_provenance'] ?? [],
            ],
            'dom_snapshot_json' => [
                'interaction_map' => $observation['interaction_map'] ?? [],
                'sensitive_fields_removed' => $observation['sensitive_fields_removed'] ?? [],
                'vision' => Arr::except($vision, ['raw_response']),
                'captured_at' => $observation['captured_at'] ?? null,
                'evidence_provenance' => $observation['evidence_provenance'] ?? [],
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

    protected function repairPlanFingerprint(
        WorkflowCopilotSession $session,
        WorkflowStep $step,
        array $checkpoint,
        array $observation,
        array $plan,
    ): string {
        if (($plan['action'] ?? 'pause') === 'pause') {
            return '';
        }

        return hash('sha256', json_encode([
            'workflow_revision' => (int) $session->current_revision,
            'step_action_key' => (string) $step->action_key,
            'resume_task_key' => (string) ($checkpoint['resume_task_key'] ?? $checkpoint['task_key'] ?? ''),
            'failure_task_key' => (string) ($checkpoint['failure_task_key'] ?? ''),
            'failure_reason_code' => (string) ($checkpoint['failure_reason_code'] ?? data_get($checkpoint, 'result.reason_code', '')),
            'state_signature' => (string) ($observation['state_signature'] ?? ''),
            'action' => (string) ($plan['action'] ?? ''),
            'task_key' => (string) ($plan['task_key'] ?? ''),
            'changes' => $plan['changes'] ?? null,
            'operations' => $plan['operations'] ?? null,
            'probe_task' => $plan['probe_task'] ?? null,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION) ?: '');
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

            // Stillstands-/Zyklus-Erkennung: Solange sich die Seite (state_signature)
            // nicht aendert, sammeln wir die Task-Fingerprints. Kommt eine Task-Signatur
            // unter derselben Seite ein zweites Mal (Revisit), ist das ein echter
            // Kreislauf ohne Fortschritt – auch wenn mehrere Tasks rotieren. Frueher
            // wurde nur bei identischem Vorgaenger-Fingerprint gezaehlt, sodass ein
            // 3-Task-Zyklus den Zaehler jedes Mal auf 0 zuruecksetzte (Endlosschleife).
            $signatureFingerprints = is_array($state['state_signature_task_fingerprints'] ?? null)
                ? array_values($state['state_signature_task_fingerprints'])
                : [];

            if ($isNewObservation) {
                if ($signature !== '' && $signature === $previousSignature) {
                    if (in_array($taskFingerprint, $signatureFingerprints, true)) {
                        $usage['same_state_repeats'] = max(0, (int) ($usage['same_state_repeats'] ?? 0)) + 1;
                    } else {
                        $signatureFingerprints[] = $taskFingerprint;
                    }
                } else {
                    // Seite hat sich geaendert -> Fortschritt -> Zaehler und Set zuruecksetzen.
                    $usage['same_state_repeats'] = 0;
                    $signatureFingerprints = [$taskFingerprint];
                }
            }

            $newState = array_replace_recursive($state, [
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
            ]);
            // Liste explizit setzen (nicht rekursiv mergen), damit das Zuruecksetzen greift.
            $newState['state_signature_task_fingerprints'] = array_values($signatureFingerprints);

            $lockedSession->forceFill([
                'state_json' => $newState,
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

    /** @return array{message:string, payload:array<string, mixed>} */
    protected function repairEvidenceSummary(
        WorkflowStep $step,
        array $checkpoint,
        array $observation,
        array $vision,
    ): array {
        $configuredTasks = collect($step->task_cards)->values();
        $resultTasks = collect(is_array(data_get($checkpoint, 'result.tasks'))
            ? data_get($checkpoint, 'result.tasks')
            : [])
            ->filter(fn (mixed $task): bool => is_array($task))
            ->values();
        $executedKeys = $resultTasks
            ->flatMap(fn (array $task): array => array_filter([
                trim((string) ($task['key'] ?? '')),
                trim((string) ($task['parent_task_key'] ?? '')),
            ]))
            ->push(trim((string) ($checkpoint['task_key'] ?? '')))
            ->filter()
            ->unique()
            ->values();
        $remaining = $configuredTasks
            ->reject(fn (array $task): bool => $executedKeys->contains(trim((string) ($task['key'] ?? ''))))
            ->map(fn (array $task): array => [
                'key' => $task['key'] ?? null,
                'task_key' => $task['task_key'] ?? null,
                'title' => $task['title'] ?? null,
                'scope_variable' => $task['scope_variable'] ?? null,
                'output_variable' => $task['output_variable'] ?? null,
                'value_from_variable' => $task['value_from_variable'] ?? null,
                'array_name' => $task['array_name'] ?? null,
                'store_current_element_as' => $task['store_current_element_as'] ?? null,
                'loop_pair_segment' => $task['loop_pair_segment'] ?? null,
            ])
            ->values();
        $relevantRefs = collect($vision['relevant_elements'] ?? [])
            ->filter(fn (mixed $element): bool => is_array($element))
            ->pluck('element_ref')
            ->map(fn (mixed $ref): string => trim((string) $ref))
            ->filter()
            ->unique();
        $interactionMap = collect($observation['interaction_map'] ?? [])
            ->filter(fn (mixed $element): bool => is_array($element))
            ->values();
        $relevantElements = $interactionMap
            ->filter(function (array $element) use ($relevantRefs): bool {
                return $relevantRefs->isEmpty()
                    || $relevantRefs->contains(trim((string) ($element['element_ref'] ?? '')));
            })
            ->take(8)
            ->map(fn (array $element): array => array_filter([
                'element_ref' => $element['element_ref'] ?? null,
                'tag' => $element['tag'] ?? null,
                'text' => Str::limit(trim((string) ($element['text'] ?? '')), 160, ''),
                'aria' => Str::limit(trim((string) ($element['aria'] ?? '')), 160, ''),
                'title' => Str::limit(trim((string) ($element['title'] ?? '')), 160, ''),
                'placeholder' => Str::limit(trim((string) ($element['placeholder'] ?? '')), 160, ''),
                'name' => Str::limit(trim((string) ($element['name'] ?? '')), 120, ''),
                'semantic_label' => Str::limit(trim((string) ($element['semantic_label'] ?? '')), 160, ''),
                'visible' => $element['visible'] ?? null,
                'enabled' => $element['enabled'] ?? null,
                'selector_candidates' => array_slice(
                    is_array($element['selector_candidates'] ?? null) ? $element['selector_candidates'] : [],
                    0,
                    4,
                ),
                'window' => $element['window'] ?? null,
            ], static fn (mixed $value): bool => $value !== null && $value !== ''))
            ->values();
        $uiState = trim((string) (
            $vision['ui_state']
            ?? data_get($observation, 'dom.ui_state')
            ?? data_get($observation, 'page.state')
            ?? 'unbekannt'
        ));
        $confidence = is_numeric($vision['confidence'] ?? null) ? (float) $vision['confidence'] : null;
        $confidenceLabel = $confidence === null ? 'ohne Konfidenzwert' : ((int) round($confidence * 100)).' %';
        $remainingLabel = $remaining
            ->pluck('key')
            ->filter()
            ->take(5)
            ->implode(', ');
        $message = 'Erkannt: '.$uiState.' ('.$confidenceLabel.'). DOM: '.$interactionMap->count()
            .' interaktive Elemente. Liste: '.$configuredTasks->count().' Tasks konfiguriert, '
            .$executedKeys->count().' im aktuellen Ergebnis erfasst';

        if ($remainingLabel !== '') {
            $message .= '; noch nicht ausgefuehrt: '.$remainingLabel;
        }

        return [
            'message' => $message.'.',
            'payload' => [
                'workflow_step_id' => (int) $step->id,
                'workflow_step_action_key' => (string) $step->action_key,
                'current_task_key' => $checkpoint['task_key'] ?? null,
                'page' => [
                    'url' => data_get($observation, 'page.url'),
                    'title' => data_get($observation, 'page.title'),
                    'state' => data_get($observation, 'page.state'),
                ],
                'vision' => [
                    'page_type' => $vision['page_type'] ?? null,
                    'ui_state' => $vision['ui_state'] ?? null,
                    'confidence' => $confidence,
                    'verdict' => $vision['verdict'] ?? null,
                    'safe_pause' => (bool) ($vision['safe_pause'] ?? false),
                    'blockers' => array_slice(is_array($vision['blockers'] ?? null) ? $vision['blockers'] : [], 0, 8),
                    'suggested_task_actions' => array_slice(
                        is_array($vision['suggested_task_actions'] ?? null) ? $vision['suggested_task_actions'] : [],
                        0,
                        8,
                    ),
                ],
                'dom' => [
                    'ui_state' => data_get($observation, 'dom.ui_state'),
                    'evidence_sufficient' => (bool) ($observation['evidence_sufficient'] ?? false),
                    'interaction_count' => $interactionMap->count(),
                    'relevant_elements' => $relevantElements->all(),
                ],
                'execution' => [
                    'configured_task_count' => $configuredTasks->count(),
                    'configured_task_sequence' => $configuredTasks
                        ->map(fn (array $task, int $index): array => array_filter([
                            'index' => $index,
                            'key' => $task['key'] ?? null,
                            'task_key' => $task['task_key'] ?? null,
                            'title' => $task['title'] ?? null,
                            'scope_variable' => $task['scope_variable'] ?? null,
                            'output_variable' => $task['output_variable'] ?? null,
                            'value_from_variable' => $task['value_from_variable'] ?? null,
                            'array_name' => $task['array_name'] ?? null,
                            'store_current_element_as' => $task['store_current_element_as'] ?? null,
                            'loop_pair_segment' => $task['loop_pair_segment'] ?? null,
                            'executed' => $executedKeys->contains(trim((string) ($task['key'] ?? ''))),
                        ], static fn (mixed $value): bool => $value !== null && $value !== ''))
                        ->all(),
                    'executed_task_keys' => $executedKeys->all(),
                    'configured_but_not_executed' => $remaining->all(),
                    'result_tasks' => $resultTasks
                        ->map(fn (array $task): array => Arr::only($task, [
                            'key',
                            'parent_task_key',
                            'task_key',
                            'status',
                            'statusMessage',
                        ]))
                        ->all(),
                ],
            ],
        ];
    }

    /** @return array{message:string, payload:array<string, mixed>} */
    protected function repairDecisionSummary(array $plan, array $vision): array
    {
        $action = trim((string) ($plan['action'] ?? 'pause')) ?: 'pause';
        $reason = Str::limit(
            trim((string) ($plan['reason'] ?? 'Keine Begruendung geliefert.')),
            1000,
            '',
        );
        $trace = is_array($plan['decision_trace'] ?? null) ? $plan['decision_trace'] : [];
        $accepted = max(0, (int) ($trace['accepted_operation_count'] ?? count(is_array($plan['operations'] ?? null) ? $plan['operations'] : [])));
        $rejected = max(0, (int) ($trace['rejected_operation_count'] ?? 0));
        $rejectionCodes = collect($trace['rejected_operations'] ?? [])
            ->filter(fn (mixed $item): bool => is_array($item))
            ->pluck('reason_code')
            ->filter()
            ->unique()
            ->values()
            ->all();
        $message = 'Entscheidung `'.$action.'`: '.$reason;

        if ($accepted > 0 || $rejected > 0) {
            $message .= ' Strukturvorschlaege: '.$accepted.' akzeptiert, '.$rejected.' verworfen';

            if ($rejectionCodes !== []) {
                $message .= ' ('.implode(', ', $rejectionCodes).')';
            }
        }

        return [
            'message' => $message,
            'payload' => array_filter([
                'action' => $action,
                'task_key' => $plan['task_key'] ?? null,
                'reason' => $reason,
                'changes' => is_array($plan['changes'] ?? null) ? $plan['changes'] : null,
                'selector_candidates' => is_array($plan['selector_candidates'] ?? null) ? $plan['selector_candidates'] : null,
                'operations' => is_array($plan['operations'] ?? null) ? $plan['operations'] : null,
                'evidence' => is_array($plan['evidence'] ?? null) ? $plan['evidence'] : null,
                'decision_trace' => $trace !== [] ? $trace : null,
                'planning_handoff' => is_array($plan['planning_handoff'] ?? null)
                    ? $plan['planning_handoff']
                    : [
                        'vision_profile' => 'image_understanding',
                        'vision_model' => $vision['model'] ?? null,
                        'planner_profile' => 'deterministic_or_data_analysis',
                    ],
            ], static fn (mixed $value): bool => $value !== null && $value !== []),
        ];
    }

    protected function captureCopilotAiUsage(
        WorkflowCopilotSession $session,
        callable $callback,
        string $source,
    ): mixed {
        $this->aiUsage->beginCapture();

        try {
            return $callback();
        } finally {
            $records = $this->aiUsage->finishCapture();

            if ($records !== []) {
                $this->sessions->recordAiUsage($session, $records, $source);
            }
        }
    }

    protected function appendVisionAnalysisCompletedEvent(
        WorkflowCopilotSession $session,
        WorkflowRun $run,
        WorkflowStepRun $stepRun,
        array $checkpoint,
        WorkflowRunCheckpoint $storedCheckpoint,
        array $observation,
        array $vision,
    ): void {
        $runtimeCheckpointId = $this->runtimeCheckpointId($run, $checkpoint);
        $observedElements = collect(is_array($observation['interaction_map'] ?? null) ? $observation['interaction_map'] : [])
            ->filter(fn (mixed $element): bool => is_array($element))
            ->keyBy(fn (array $element): string => trim((string) ($element['element_ref'] ?? '')));
        $elements = collect(is_array($vision['relevant_elements'] ?? null) ? $vision['relevant_elements'] : [])
            ->take(8)
            ->map(function (mixed $element) use ($observedElements): array {
                $elementRef = Str::limit(trim((string) data_get($element, 'element_ref', '')), 191, '');
                $observed = $observedElements->get($elementRef);

                return [
                    'element_ref' => $elementRef,
                    'reason' => Str::limit(trim((string) data_get($element, 'reason', '')), 300, ''),
                    'confidence' => is_numeric(data_get($element, 'confidence'))
                        ? round(max(0, min(1, (float) data_get($element, 'confidence'))), 3)
                        : null,
                    'semantic_label' => is_array($observed)
                        ? Str::limit(trim((string) (
                            $observed['semantic_label']
                            ?? $observed['title']
                            ?? $observed['aria']
                            ?? $observed['placeholder']
                            ?? $observed['name']
                            ?? $observed['text']
                            ?? ''
                        )), 180, '')
                        : null,
                    'selector' => is_array($observed)
                        ? Str::limit(trim((string) data_get($observed, 'selector_candidates.0', '')), 300, '')
                        : null,
                ];
            })
            ->filter(fn (array $element): bool => $element['element_ref'] !== '')
            ->values()
            ->all();
        $actions = collect(is_array($vision['suggested_task_actions'] ?? null) ? $vision['suggested_task_actions'] : [])
            ->take(8)
            ->map(function (mixed $action) use ($observedElements): array {
                $elementRef = Str::limit(trim((string) data_get($action, 'element_ref', '')), 191, '');
                $observed = $observedElements->get($elementRef);

                return [
                    'task_key' => Str::limit(trim((string) data_get($action, 'task_key', '')), 191, ''),
                    'element_ref' => $elementRef,
                    'reason' => Str::limit(trim((string) data_get($action, 'reason', '')), 300, ''),
                    'confidence' => is_numeric(data_get($action, 'confidence'))
                        ? round(max(0, min(1, (float) data_get($action, 'confidence'))), 3)
                        : null,
                    'target_label' => is_array($observed)
                        ? Str::limit(trim((string) (
                            $observed['semantic_label']
                            ?? $observed['title']
                            ?? $observed['aria']
                            ?? $observed['placeholder']
                            ?? $observed['name']
                            ?? $observed['text']
                            ?? ''
                        )), 180, '')
                        : null,
                    'target_selector' => is_array($observed)
                        ? Str::limit(trim((string) data_get($observed, 'selector_candidates.0', '')), 300, '')
                        : null,
                ];
            })
            ->filter(fn (array $action): bool => $action['task_key'] !== '')
            ->values()
            ->all();
        $blockers = collect(is_array($vision['blockers'] ?? null) ? $vision['blockers'] : [])
            ->map(fn (mixed $blocker): string => Str::limit(trim((string) $blocker), 300, ''))
            ->filter()
            ->take(8)
            ->values()
            ->all();
        $payload = [
            'runtime_checkpoint_id' => $runtimeCheckpointId,
            'workflow_run_id' => (int) $run->id,
            'workflow_step_run_id' => (int) $stepRun->id,
            'checkpoint_id' => (int) $storedCheckpoint->id,
            'task_key' => $checkpoint['task_key'] ?? null,
            'page_type' => Str::limit(trim((string) ($vision['page_type'] ?? 'unknown')), 120, ''),
            'ui_state' => Str::limit(trim((string) ($vision['ui_state'] ?? 'unknown_browser_state')), 160, ''),
            'goal_progress' => $vision['goal_progress'] ?? null,
            'confidence' => is_numeric($vision['confidence'] ?? null)
                ? round(max(0, min(1, (float) $vision['confidence'])), 3)
                : null,
            'verdict' => Str::lower(trim((string) ($vision['verdict'] ?? 'pause'))),
            'safe_pause' => (bool) ($vision['safe_pause'] ?? false),
            'needs_screenshot' => (bool) ($vision['needs_screenshot'] ?? false),
            'blockers' => $blockers,
            'relevant_elements' => $elements,
            'suggested_task_actions' => $actions,
            'model' => Str::limit(trim((string) ($vision['model'] ?? '')), 200, ''),
            'analysis_source' => Str::limit(trim((string) ($vision['analysis_source'] ?? '')), 80, ''),
            'fallback_used' => (bool) ($vision['fallback_used'] ?? false),
            'duration_ms' => max(0, (int) ($vision['duration_ms'] ?? 0)),
            'state_signature' => Str::limit(trim((string) ($observation['state_signature'] ?? '')), 191, ''),
        ];
        $payload['analysis_signature'] = hash('sha256', (string) json_encode(
            Arr::only($payload, [
                'runtime_checkpoint_id',
                'state_signature',
                'page_type',
                'ui_state',
                'goal_progress',
                'confidence',
                'verdict',
                'blockers',
                'relevant_elements',
                'suggested_task_actions',
                'model',
                'analysis_source',
                'fallback_used',
            ]),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
        ));
        $alreadyReported = $session->events()
            ->where('event_type', 'vision.analysis_completed')
            ->latest('sequence')
            ->limit(100)
            ->get()
            ->contains(fn ($event): bool => hash_equals(
                $payload['analysis_signature'],
                (string) data_get($event->payload_json, 'analysis_signature', ''),
            ));

        if ($alreadyReported) {
            return;
        }

        $this->sessions->appendEvent(
            $session,
            'vision.analysis_completed',
            $this->visionAnalysisMessage($payload),
            $payload,
            'visual_analysis',
            ($payload['verdict'] ?? null) === 'pause' ? 'warning' : 'success',
            true,
        );
    }

    protected function visionAnalysisMessage(array $analysis): string
    {
        $confidence = is_numeric($analysis['confidence'] ?? null)
            ? number_format((float) $analysis['confidence'] * 100, 0, ',', '.').' %'
            : 'ohne Konfidenzwert';
        $verdict = match ($analysis['verdict'] ?? 'pause') {
            'pass' => 'Ziel erreicht',
            'continue' => 'Workflow fortsetzen',
            default => 'vor weiterer Aktion pruefen',
        };
        $parts = [
            'Bildanalyse abgeschlossen: '.($analysis['page_type'] ?: 'unbekannte Seite')
                .' / '.($analysis['ui_state'] ?: 'unbekannter Zustand')
                .' ('.$confidence.').',
            'Entscheidung: '.$verdict.'.',
        ];
        $elements = collect($analysis['relevant_elements'] ?? [])
            ->map(function (array $element): string {
                $label = trim((string) ($element['semantic_label'] ?? ''));
                $selector = trim((string) ($element['selector'] ?? ''));
                $reason = trim((string) ($element['reason'] ?? ''));
                $target = $label !== '' ? '`'.$label.'`' : '`'.($element['element_ref'] ?? 'Element').'`';

                return $target
                    .($selector !== '' ? ' ueber `'.$selector.'`' : '')
                    .($reason !== '' ? ' ('.$reason.')' : '');
            })
            ->filter()
            ->take(4)
            ->implode('; ');

        if ($elements !== '') {
            $parts[] = 'Erkannt: '.$elements.'.';
        }

        $actions = collect($analysis['suggested_task_actions'] ?? [])
            ->map(function (array $action): string {
                $target = trim((string) ($action['target_label'] ?? $action['element_ref'] ?? ''));
                $selector = trim((string) ($action['target_selector'] ?? ''));

                return '`'.$action['task_key'].'`'
                    .($target !== '' ? ' an `'.$target.'`' : '')
                    .($selector !== '' ? ' ueber `'.$selector.'`' : '');
            })
            ->filter()
            ->take(4)
            ->implode('; ');

        if ($actions !== '') {
            $parts[] = 'Vorgeschlagene Tasks: '.$actions.'.';
        }

        $blockers = collect($analysis['blockers'] ?? [])->filter()->take(3)->implode('; ');

        if ($blockers !== '') {
            $parts[] = 'Hinweise: '.$blockers.'.';
        }

        return Str::limit(implode(' ', $parts), 2000, '');
    }

    protected function checkpointContinuationMessage(array $checkpoint): string
    {
        $task = trim((string) ($checkpoint['task_title'] ?? $checkpoint['task_key'] ?? 'Task')) ?: 'Task';
        $nextTask = trim((string) ($checkpoint['next_task_key'] ?? ''));

        if (($checkpoint['next_action'] ?? null) === 'next_task' && $nextTask !== '') {
            return 'Task `'.$task.'` war erfolgreich. Fortsetzung angewendet: Task `'.$nextTask.'` wird jetzt ausgefuehrt.';
        }

        return 'Task `'.$task.'` war erfolgreich. Fortsetzung angewendet: Der Step wird abgeschlossen und die konfigurierte Folgeroute gestartet.';
    }

    protected function appendContinuationDeferredEvent(
        WorkflowCopilotSession $session,
        WorkflowRun $run,
        array $checkpoint,
        ?int $storedCheckpointId = null,
    ): void {
        $runtimeCheckpointId = $this->runtimeCheckpointId($run, $checkpoint);
        $alreadyReported = $session->events()
            ->where('event_type', 'checkpoint.continuation_deferred')
            ->latest('sequence')
            ->limit(50)
            ->get()
            ->contains(fn ($event): bool => data_get($event->payload_json, 'runtime_checkpoint_id') === $runtimeCheckpointId);

        if ($alreadyReported) {
            return;
        }

        $this->sessions->appendEvent(
            $session,
            'checkpoint.continuation_deferred',
            'Der erfolgreiche Checkpoint wurde analysiert, aber die Fortsetzung noch nicht angewendet. Die Queue-Ueberwachung versucht denselben Checkpoint ohne erneute Bildanalyse wiederaufzunehmen.',
            [
                'runtime_checkpoint_id' => $runtimeCheckpointId,
                'workflow_run_id' => (int) $run->id,
                'checkpoint_id' => $storedCheckpointId,
                'task_key' => $checkpoint['task_key'] ?? null,
                'next_action' => $checkpoint['next_action'] ?? null,
                'next_task_key' => $checkpoint['next_task_key'] ?? null,
                'run_status' => (string) ($run->fresh()?->status ?? $run->status),
                'automatic_retry_after_seconds' => 2,
            ],
            'queue_recovery',
            'warning',
            true,
        );
        WorkflowCopilotSupervisorJob::dispatch((int) $session->id)->delay(now()->addSeconds(2));
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
            || $this->costBudgetReached($session)
            || (int) ($usage['repair_iterations'] ?? 0) > max(1, (int) ($budget['max_repair_iterations'] ?? 15))
            || (int) ($usage['probe_actions'] ?? 0) > max(1, (int) ($budget['max_probe_actions'] ?? 60))
            || (int) ($usage['same_state_repeats'] ?? 0) > max(1, (int) ($budget['max_same_state_repeats'] ?? 2));
    }

    protected function repairBudgetReachedBeforeAction(WorkflowCopilotSession $session): bool
    {
        $budget = is_array($session->budget_json) ? $session->budget_json : [];
        $usage = is_array($session->usage_json) ? $session->usage_json : [];

        return $this->timeBudgetExceeded($session)
            || $this->costBudgetReached($session)
            || (int) ($usage['repair_iterations'] ?? 0) >= max(1, (int) ($budget['max_repair_iterations'] ?? 15))
            || (int) ($usage['same_state_repeats'] ?? 0) >= max(1, (int) ($budget['max_same_state_repeats'] ?? 2));
    }

    protected function probeBudgetReachedBeforeAction(WorkflowCopilotSession $session): bool
    {
        $budget = is_array($session->budget_json) ? $session->budget_json : [];
        $usage = is_array($session->usage_json) ? $session->usage_json : [];

        return $this->timeBudgetExceeded($session)
            || $this->costBudgetReached($session)
            || (int) ($usage['probe_actions'] ?? 0) >= max(1, (int) ($budget['max_probe_actions'] ?? 60));
    }

    protected function costBudgetReached(WorkflowCopilotSession $session): bool
    {
        $budget = is_array($session->budget_json) ? $session->budget_json : [];
        $usage = is_array($session->usage_json) ? $session->usage_json : [];
        $maximum = max(0, (float) ($budget['max_cost_usd'] ?? 0));

        return $maximum > 0 && max(0, (float) ($usage['cost_usd'] ?? 0)) >= $maximum;
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
