<?php

namespace App\Services\Workflows;

use App\Enums\WorkflowCopilotPermissionMode;
use App\Models\Workflow;
use App\Models\WorkflowCopilotSession;
use App\Models\WorkflowRevision;
use App\Models\WorkflowRevisionEvidence;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use App\Models\WorkflowStudioRevision;
use App\Models\WorkflowStudioSession;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

class WorkflowCopilotPreflightService
{
    private const MAX_RUNS = 20;

    private const MAX_EVIDENCE = 200;

    private const MAX_REVISIONS = 80;

    private const SENSITIVE_RESTORE_FIELDS = [
        'value',
        'input',
        'value_fallback',
        'fallback_value',
        'password',
        'username',
        'email',
        'body',
        'message',
        'token',
        'secret',
    ];

    public function __construct(
        protected WorkflowCopilotSessionService $sessions,
        protected WorkflowCopilotPromptContextService $promptContexts,
        protected WorkflowCopilotRepairService $repairs,
        protected WorkflowRevisionService $revisions,
        protected WorkflowStudioAuthorizationService $studioAuthorization,
    ) {}

    /**
     * Analyze durable evidence before a new autonomous run and restore only
     * task fields that have both a proven successful revision and an unchanged
     * later failing configuration.
     *
     * @return array{ready: bool, session: WorkflowCopilotSession, report: array<string, mixed>, revision: ?WorkflowRevision}
     */
    public function prepare(WorkflowCopilotSession $session, bool $allowRepairs = true): array
    {
        $session = WorkflowCopilotSession::query()
            ->with(['workflow.steps', 'activeRun'])
            ->findOrFail($session->getKey());
        $history = $this->history($session);
        $candidates = $allowRepairs
            ? $this->historicalRepairCandidates($session, $history['evidence'])
            : [];
        $report = $this->report($session, $history, $candidates, $allowRepairs);
        $offlineSafeOperations = collect($report['offline_safe_operations'] ?? [])
            ->filter(fn (mixed $operation): bool => is_array($operation))
            ->values()
            ->all();
        $hasUncoveredOfflineRepairNeed = (int) ($report['unresolved_error_pattern_count'] ?? 0) > count($candidates)
            || collect($report['static_diagnostics'] ?? [])
                ->contains(fn (mixed $diagnostic): bool => is_array($diagnostic)
                    && ($diagnostic['severity'] ?? null) === 'error');
        $needsOfflinePlan = $offlineSafeOperations !== [] && $hasUncoveredOfflineRepairNeed;
        $skippedOfflinePlanRejections = $allowRepairs
            && $hasUncoveredOfflineRepairNeed
            && $offlineSafeOperations === []
                ? [[
                    'reason_code' => 'historical_operation_not_proven',
                    'message' => 'Der Offline-Strukturplan wurde verworfen, weil keine exakt historisch belegte Operation freigegeben ist.',
                ]]
                : [];
        $offlinePlan = $allowRepairs && $needsOfflinePlan
            ? $this->repairs->planHistoricalPreflight($session, $report)
            : [
                'operations' => [],
                'reason' => ! $allowRepairs
                    ? 'Checkpoint-/Kontrolllauf wird nur analysiert und nicht vorab veraendert.'
                    : ($offlineSafeOperations === []
                        ? 'Keine offline historisch belegte Strukturmutation vorhanden; kein KI-Reparaturaufruf ausgefuehrt.'
                        : 'Die Historie erfordert keine zusaetzliche Strukturplanung.'),
                'rejected_operations' => $skippedOfflinePlanRejections,
            ];
        $operations = is_array($offlinePlan['operations'] ?? null) ? $offlinePlan['operations'] : [];
        $report['offline_plan'] = [
            'operation_count' => count($operations),
            'operations' => $operations,
            'reason' => $this->safeText((string) ($offlinePlan['reason'] ?? '')),
            'rejected_operations' => is_array($offlinePlan['rejected_operations'] ?? null)
                ? $offlinePlan['rejected_operations']
                : [],
        ];
        $report['planned_repair_count'] = count($candidates) + count($operations);

        $this->sessions->appendEvent(
            $session,
            'preflight.history_analyzed',
            $this->analysisMessage($report),
            $report,
            'preflight',
            ($report['unresolved_error_pattern_count'] ?? 0) > 0 ? 'warning' : 'info',
            true,
        );

        if ($candidates !== [] || $operations !== []) {
            $authorization = $this->authorizeRepairs($session, $candidates, $operations);

            if (! $authorization['allowed']) {
                $report['authorization'] = $authorization;
                $session = $this->persistReport($session, $report);

                return ['ready' => false, 'session' => $session, 'report' => $report, 'revision' => null];
            }
        }

        $revision = null;

        try {
            if ($candidates !== [] || $operations !== []) {
                $revision = $this->applyPreflightRepairs($session, $candidates, $operations, $offlinePlan);
                $session = $session->fresh(['workflow.steps']) ?? $session;
                $report['revision_before'] = (int) ($report['revision_before'] ?? 0);
                $report['revision_after'] = (int) $revision->revision_number;
                $report['applied_repair_count'] = count($candidates) + count($operations);
                $report['applied_repairs'] = collect($candidates)
                    ->map(fn (array $candidate): array => $this->candidateSummary($candidate))
                    ->values()
                    ->all();
                $report['applied_structural_operations'] = $operations;
                $this->sessions->appendEvent(
                    $session,
                    'preflight.repair_applied',
                    $report['applied_repair_count'].' historisch belegte Vorab-Reparatur(en) wurden vor dem Browser-Test als Revision '.
                        $revision->revision_number.' gespeichert.',
                    [
                        'workflow_revision_id' => (int) $revision->id,
                        'revision_number' => (int) $revision->revision_number,
                        'repairs' => $report['applied_repairs'],
                        'structural_operations' => $operations,
                    ],
                    'preflight',
                    'success',
                    true,
                );
            }
        } catch (Throwable $exception) {
            $message = Str::limit(trim($exception->getMessage()), 900, '');
            $report['repair_failure'] = [
                'type' => class_basename($exception),
                'message' => $this->safeText($message),
            ];
            $session = $this->persistReport($session->fresh() ?? $session, $report);
            $this->sessions->appendEvent(
                $session,
                'preflight.repair_failed',
                'Die historisch belegte Vorab-Reparatur konnte nicht sicher revisioniert werden. Der Browser-Test wurde nicht gestartet.',
                $report['repair_failure'],
                'preflight',
                'error',
                true,
            );
            $session = $this->sessions->pause(
                $session->fresh() ?? $session,
                'Die Vorab-Reparatur aus der Lauf- und Revisionshistorie konnte nicht sicher gespeichert werden.',
            );

            return ['ready' => false, 'session' => $session, 'report' => $report, 'revision' => null];
        }

        $session = $this->persistReport($session->fresh() ?? $session, $report);

        return ['ready' => true, 'session' => $session, 'report' => $report, 'revision' => $revision];
    }

    /** @return array{runs: Collection, evidence: Collection, revisions: Collection, diagnostics: array<int, array<string, mixed>>} */
    protected function history(WorkflowCopilotSession $session): array
    {
        $workflowId = (int) $session->workflow_id;
        $runs = WorkflowRun::query()
            ->where('workflow_id', $workflowId)
            ->latest('id')
            ->limit(self::MAX_RUNS)
            ->get();
        $evidence = WorkflowRevisionEvidence::query()
            ->where('workflow_id', $workflowId)
            ->latest('id')
            ->limit(self::MAX_EVIDENCE)
            ->get()
            ->sortBy('id')
            ->values();
        $copilotRevisions = WorkflowRevision::query()
            ->where('workflow_id', $workflowId)
            ->latest('revision_number')
            ->limit(self::MAX_REVISIONS)
            ->get()
            ->map(fn (WorkflowRevision $revision): array => $this->revisionRecord($revision, 'copilot'));
        $studioRevisions = WorkflowStudioRevision::query()
            ->where('workflow_id', $workflowId)
            ->latest('revision_number')
            ->limit(self::MAX_REVISIONS)
            ->get()
            ->map(fn (WorkflowStudioRevision $revision): array => $this->revisionRecord($revision, 'studio'));
        $workflow = $session->workflow instanceof Workflow
            ? $session->workflow
            : Workflow::query()->with('steps')->findOrFail($workflowId);

        return [
            'runs' => $runs,
            'evidence' => $evidence,
            'revisions' => $copilotRevisions
                ->concat($studioRevisions)
                ->sortByDesc(fn (array $revision): string => sprintf(
                    '%020d-%s-%020d',
                    (int) $revision['revision'],
                    (string) ($revision['created_at'] ?? ''),
                    (int) $revision['id'],
                ))
                ->take(self::MAX_REVISIONS)
                ->values(),
            'diagnostics' => $this->promptContexts->diagnostics($workflow),
        ];
    }

    /**
     * @param  array{runs: Collection, evidence: Collection, revisions: Collection, diagnostics: array}  $history
     * @param  list<array<string, mixed>>  $candidates
     * @return array<string, mixed>
     */
    protected function report(
        WorkflowCopilotSession $session,
        array $history,
        array $candidates,
        bool $allowRepairs,
    ): array {
        $runs = $history['runs'];
        $evidence = $history['evidence'];
        $patterns = $this->errorPatterns($runs, $evidence);
        $revisionOutcomes = $history['revisions']
            ->map(fn (array $revision): array => $this->revisionOutcome($revision, $runs, $evidence))
            ->values();
        $unresolved = $patterns->where('resolved', false)->values();

        $report = [
            'analyzed_at' => now()->toIso8601String(),
            'workflow_id' => (int) $session->workflow_id,
            'session_id' => (int) $session->id,
            'revision_before' => (int) $session->current_revision,
            'analysis_fingerprint' => hash('sha256', implode('|', [
                (int) $session->current_revision,
                (int) ($runs->max('id') ?? 0),
                (int) ($evidence->max('id') ?? 0),
                (int) ($history['revisions']->max('revision') ?? 0),
            ])),
            'recent_run_count' => $runs->count(),
            'revision_count' => $history['revisions']->count(),
            'evidence_count' => $evidence->count(),
            'unresolved_error_pattern_count' => $unresolved->count(),
            'static_diagnostic_count' => count($history['diagnostics']),
            'historically_proven_repair_count' => count($candidates),
            'repairs_allowed_for_this_start' => $allowRepairs,
            'recent_runs' => $runs->take(10)->map(fn (WorkflowRun $run): array => [
                'run_id' => (int) $run->id,
                'revision' => (int) $run->workflow_revision,
                'status' => (string) $run->status,
                'requested_by' => Str::limit((string) $run->requested_by, 80, ''),
                'error_signature' => $this->runErrorSignature($run),
                'error_summary' => $this->safeText((string) $run->error_message),
                'started_at' => $run->started_at?->toIso8601String(),
                'finished_at' => $run->finished_at?->toIso8601String(),
            ])->values()->all(),
            'error_patterns' => $patterns->take(30)->values()->all(),
            'revision_outcomes' => $revisionOutcomes->take(40)->all(),
            'successful_repair_count' => $revisionOutcomes->whereIn('outcome', ['verified', 'successful'])->count(),
            'failed_repair_count' => $revisionOutcomes->where('outcome', 'failed')->count(),
            'static_diagnostics' => collect($history['diagnostics'])
                ->take(40)
                ->map(fn (mixed $diagnostic): mixed => $this->sanitizeValue($diagnostic))
                ->values()
                ->all(),
            'repair_candidates' => collect($candidates)
                ->map(fn (array $candidate): array => $this->candidateSummary($candidate))
                ->values()
                ->all(),
            'offline_safe_operations' => [],
            'offline_mutation_policy' => 'Nur exakt aus erfolgreicher, step-sicher korrelierter Historie belegte Operationen duerfen einen KI-Vorabplan ausloesen. Ohne Whitelist erfolgt kein KI-Aufruf.',
            'instruction' => 'Erfolgreiche Konfigurationen wiederverwenden, fehlgeschlagene Revisionen und Fehlersignaturen nicht wiederholen und normale condition_false-/IF-Abzweigungen niemals als Fehler reparieren.',
        ];

        return (array) $this->sanitizeValue($report);
    }

    protected function errorPatterns(Collection $runs, Collection $evidence): Collection
    {
        $patterns = $evidence
            ->filter(fn (WorkflowRevisionEvidence $item): bool => $this->isFailureEvidence($item))
            ->groupBy(fn (WorkflowRevisionEvidence $item): string => implode('|', [
                'task',
                (int) $item->workflow_step_id,
                trim((string) $item->task_key),
                trim((string) $item->error_signature) ?: $item->logical_outcome.'-'.$item->route_disposition,
            ]))
            ->map(function (Collection $items) use ($evidence): array {
                /** @var WorkflowRevisionEvidence $latest */
                $latest = $items->sortByDesc('id')->first();
                $resolvedBy = $evidence
                    ->filter(fn (WorkflowRevisionEvidence $candidate): bool => (int) $candidate->id > (int) $latest->id
                        && (int) $candidate->workflow_step_id === (int) $latest->workflow_step_id
                        && (string) $candidate->task_key === (string) $latest->task_key
                        && (bool) $candidate->successful)
                    ->sortBy('id')
                    ->first();

                return [
                    'scope' => 'task',
                    'workflow_step_id' => (int) $latest->workflow_step_id,
                    'task_key' => $latest->task_key,
                    'error_signature' => $latest->error_signature,
                    'logical_outcome' => (string) $latest->logical_outcome,
                    'route_disposition' => (string) $latest->route_disposition,
                    'occurrences' => $items->count(),
                    'revisions' => $items->pluck('workflow_revision')->unique()->values()->all(),
                    'latest_evidence_id' => (int) $latest->id,
                    'last_message' => $this->safeText((string) data_get($latest->evidence_json, 'message', '')),
                    'resolved' => $resolvedBy !== null,
                    'resolved_by_evidence_id' => $resolvedBy?->id,
                    'resolved_by_revision' => $resolvedBy?->workflow_revision,
                ];
            });

        $coveredRunIds = $evidence
            ->filter(fn (WorkflowRevisionEvidence $item): bool => $this->isFailureEvidence($item))
            ->pluck('workflow_run_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->filter()
            ->unique();
        $runPatterns = $runs
            ->filter(fn (WorkflowRun $run): bool => in_array($run->status, ['failed', 'timed_out', 'lost'], true))
            ->reject(fn (WorkflowRun $run): bool => $coveredRunIds->contains((int) $run->id))
            ->filter(fn (WorkflowRun $run): bool => $this->runErrorSignature($run) !== null)
            ->groupBy(fn (WorkflowRun $run): string => 'run|'.$this->runErrorSignature($run))
            ->map(function (Collection $items) use ($runs): array {
                /** @var WorkflowRun $latest */
                $latest = $items->sortByDesc('id')->first();
                $resolvedBy = $runs
                    ->filter(fn (WorkflowRun $candidate): bool => (int) $candidate->id > (int) $latest->id
                        && $candidate->status === 'completed')
                    ->sortBy('id')
                    ->first();

                return [
                    'scope' => 'run',
                    'task_key' => null,
                    'error_signature' => $this->runErrorSignature($latest),
                    'logical_outcome' => 'technical_error',
                    'route_disposition' => 'fail',
                    'occurrences' => $items->count(),
                    'revisions' => $items->pluck('workflow_revision')->unique()->values()->all(),
                    'latest_run_id' => (int) $latest->id,
                    'last_message' => $this->safeText((string) $latest->error_message),
                    'resolved' => $resolvedBy !== null,
                    'resolved_by_evidence_id' => null,
                    'resolved_by_revision' => $resolvedBy?->workflow_revision,
                    'resolved_by_run_id' => $resolvedBy?->id,
                ];
            });

        return $patterns
            ->concat($runPatterns)
            ->sortByDesc(fn (array $pattern): int => (int) ($pattern['latest_evidence_id'] ?? $pattern['latest_run_id'] ?? 0))
            ->values();
    }

    /** @return array<string, mixed> */
    protected function revisionOutcome(array $revision, Collection $runs, Collection $evidence): array
    {
        $number = (int) $revision['revision'];
        $revisionRuns = $runs->where('workflow_revision', $number);
        $revisionEvidence = $evidence->where('workflow_revision', $number);
        $hasFailure = $revisionEvidence->contains(
            fn (WorkflowRevisionEvidence $item): bool => $this->isFailureEvidence($item),
        ) || $revisionRuns->contains(fn (WorkflowRun $run): bool => in_array($run->status, ['failed', 'timed_out', 'lost'], true));
        $hasSuccess = $revisionEvidence->contains(fn (WorkflowRevisionEvidence $item): bool => (bool) $item->successful)
            || $revisionRuns->contains(fn (WorkflowRun $run): bool => $run->status === 'completed');
        $outcome = (bool) $revision['verified']
            ? 'verified'
            : ($hasFailure && $hasSuccess
                ? 'mixed'
                : ($hasFailure ? 'failed' : ($hasSuccess ? 'successful' : 'untested')));

        return [
            ...$revision,
            'outcome' => $outcome,
            'run_ids' => $revisionRuns->pluck('id')->map(fn (mixed $id): int => (int) $id)->values()->all(),
            'successful_evidence_count' => $revisionEvidence->where('successful', true)->count(),
            'failed_evidence_count' => $revisionEvidence
                ->filter(fn (WorkflowRevisionEvidence $item): bool => $this->isFailureEvidence($item))
                ->count(),
        ];
    }

    /** @return list<array<string, mixed>> */
    protected function historicalRepairCandidates(WorkflowCopilotSession $session, Collection $evidence): array
    {
        $workflow = $session->workflow;
        if (! $workflow instanceof Workflow) {
            return [];
        }
        $workflow->loadMissing('steps');
        $failures = $evidence
            ->filter(fn (WorkflowRevisionEvidence $item): bool => $this->isFailureEvidence($item))
            ->sortByDesc('id');
        $candidates = [];
        $seenTasks = [];

        foreach ($failures as $failure) {
            $taskKey = trim((string) $failure->task_key);
            $taskIdentity = (int) $failure->workflow_step_id.'|'.$taskKey;

            if ($taskKey === '' || isset($seenTasks[$taskIdentity])) {
                continue;
            }

            $resolvedLater = $evidence->contains(fn (WorkflowRevisionEvidence $item): bool => (int) $item->id > (int) $failure->id
                && (int) $item->workflow_step_id === (int) $failure->workflow_step_id
                && (string) $item->task_key === $taskKey
                && (bool) $item->successful);
            if ($resolvedLater) {
                $seenTasks[$taskIdentity] = true;

                continue;
            }

            $success = $evidence
                ->filter(fn (WorkflowRevisionEvidence $item): bool => (int) $item->id < (int) $failure->id
                    && (int) $item->workflow_step_id === (int) $failure->workflow_step_id
                    && (string) $item->task_key === $taskKey
                    && (bool) $item->successful)
                ->sortByDesc('id')
                ->first();
            if (! $success) {
                $seenTasks[$taskIdentity] = true;

                continue;
            }

            [$step, $currentTask] = $this->currentTask($workflow, $taskKey, $failure->workflow_step_id);
            if (! $step || ! is_array($currentTask)) {
                $seenTasks[$taskIdentity] = true;

                continue;
            }

            if ((int) $failure->workflow_revision !== (int) $session->current_revision) {
                $failedTask = $this->taskFromRevision(
                    (int) $workflow->id,
                    (int) $failure->workflow_revision,
                    $taskKey,
                    $failure->workflow_step_id,
                );

                if (! is_array($failedTask) || ! hash_equals($this->definitionHash($failedTask), $this->definitionHash($currentTask))) {
                    $seenTasks[$taskIdentity] = true;

                    continue;
                }
            }

            $successfulTask = $this->taskFromRevision(
                (int) $workflow->id,
                (int) $success->workflow_revision,
                $taskKey,
                $success->workflow_step_id,
            );
            if (! is_array($successfulTask)) {
                $seenTasks[$taskIdentity] = true;

                continue;
            }

            $changes = collect($this->repairs->historicalTaskChanges($step, $taskKey, $successfulTask))
                ->reject(fn (mixed $value, string $field): bool => $this->sensitiveRestoreField($field))
                ->all();
            if ($changes === []) {
                $seenTasks[$taskIdentity] = true;

                continue;
            }

            $candidates[] = [
                'workflow_step_id' => (int) $step->id,
                'step_action_key' => (string) $step->action_key,
                'task_key' => $taskKey,
                'changes' => $changes,
                'change_fields' => array_keys($changes),
                'failure_evidence_id' => (int) $failure->id,
                'failure_revision' => (int) $failure->workflow_revision,
                'failure_signature' => $failure->error_signature,
                'successful_evidence_id' => (int) $success->id,
                'successful_revision' => (int) $success->workflow_revision,
            ];
            $seenTasks[$taskIdentity] = true;
        }

        return $candidates;
    }

    /** @param list<array<string, mixed>> $candidates */
    protected function applyPreflightRepairs(
        WorkflowCopilotSession $session,
        array $candidates,
        array $operations,
        array $offlinePlan,
    ): WorkflowRevision {
        return $this->revisions->apply(
            $session,
            (int) $session->current_revision,
            $this->safeText((string) ($offlinePlan['reason'] ?? ''))
                ?: 'Vorab-Reparatur aus erfolgreichen und fehlgeschlagenen Lauf-/Revisionsevidenzen vor dem Browser-Test.',
            function (Workflow $workflow) use ($session, $candidates, $operations): void {
                foreach ($candidates as $candidate) {
                    $step = WorkflowStep::query()
                        ->where('workflow_id', $session->workflow_id)
                        ->findOrFail((int) $candidate['workflow_step_id']);
                    $this->repairs->applyChangesToStep(
                        $step,
                        (string) $candidate['task_key'],
                        is_array($candidate['changes'] ?? null) ? $candidate['changes'] : [],
                        $session,
                    );
                }

                if ($operations !== []) {
                    $this->repairs->applyStructuralOperations($workflow, $operations, $session);
                }
            },
            'copilot-preflight',
        );
    }

    /**
     * @param  list<array<string, mixed>>  $candidates
     * @param  list<array<string, mixed>>  $operations
     */
    protected function authorizeRepairs(
        WorkflowCopilotSession $session,
        array $candidates,
        array $operations,
    ): array {
        $componentCount = count($candidates) + count($operations);
        $action = $componentCount > 1 ? 'workflow.replace' : 'task.update';
        $parameters = [
            'workflow_copilot_session_id' => (int) $session->id,
            'workflow_revision' => (int) $session->current_revision,
            'repairs' => collect($candidates)->map(fn (array $candidate): array => [
                ...$this->candidateSummary($candidate),
                'changes_hash' => hash('sha256', (string) json_encode(
                    $this->sortRecursive($candidate['changes'] ?? []),
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
                )),
            ])->values()->all(),
            'structural_operations_hash' => hash('sha256', (string) json_encode(
                $this->sortRecursive($operations),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
            )),
        ];
        $studio = WorkflowStudioSession::query()
            ->where('workflow_copilot_session_id', $session->id)
            ->latest('id')
            ->first();
        if (! $studio) {
            return $this->authorizeWithoutStudio($session, $action, $parameters);
        }

        $state = is_array($session->state_json) ? $session->state_json : [];
        $pending = is_array($state['preflight_pending_repairs'] ?? null) ? $state['preflight_pending_repairs'] : [];
        $confirmationId = trim((string) ($pending['confirmation_id'] ?? '')) ?: null;
        $decision = $this->studioAuthorization->decide($studio, $action, $parameters, $confirmationId);

        if ($decision['allowed']) {
            if ($confirmationId) {
                $this->studioAuthorization->consume($studio, $confirmationId);
            }
            unset($state['preflight_pending_repairs']);
            $session->forceFill(['state_json' => $state, 'last_activity_at' => now()])->save();
            $studioState = is_array($studio->state_json) ? $studio->state_json : [];
            $studioState['pending_copilot_confirmation'] = null;
            $studio->forceFill(['state_json' => $studioState, 'status' => 'running', 'last_activity_at' => now()])->save();

            return $decision;
        }

        $pending = [
            'type' => 'copilot_plan',
            'action' => $action,
            'confirmation_id' => $decision['confirmation_id'],
            'message' => 'Der Copilot moechte historisch belegte Vorab-Reparaturen vor dem Browser-Test anwenden.',
            'parameters' => $parameters,
            'workflow_run_id' => null,
            'workflow_copilot_session_id' => (int) $session->id,
            'created_at' => now()->toIso8601String(),
        ];
        $state['preflight_pending_repairs'] = $pending;
        $session->forceFill(['state_json' => $state, 'last_activity_at' => now()])->save();
        $studioState = is_array($studio->state_json) ? $studio->state_json : [];
        $studioState['pending_copilot_confirmation'] = $pending;
        $studio->forceFill(['state_json' => $studioState, 'status' => 'confirmation_required', 'last_activity_at' => now()])->save();
        app(WorkflowStudioSessionService::class)->appendEvent(
            $studio,
            'authorization.requested',
            $pending['message'],
            ['action' => $action, 'confirmation_id' => $decision['confirmation_id']],
            'warning',
        );
        $this->sessions->pause($session->fresh() ?? $session, $decision['message']);

        return $decision;
    }

    protected function authorizeWithoutStudio(
        WorkflowCopilotSession $session,
        string $action,
        array $parameters,
    ): array {
        $requestedMode = WorkflowCopilotPermissionMode::normalize(
            data_get($session->budget_json, 'permission_mode', WorkflowCopilotPermissionMode::ASK_CRITICAL->value),
        );
        // Ohne Studio fehlen sowohl Admin-Nachweis als auch die einmalige
        // Aktivierungswarnung. `unrestricted` darf hier deshalb nie greifen.
        $effectiveMode = $requestedMode === WorkflowCopilotPermissionMode::UNRESTRICTED
            ? WorkflowCopilotPermissionMode::ASK_CRITICAL
            : $requestedMode;
        $requiresConfirmation = $effectiveMode === WorkflowCopilotPermissionMode::ASK_ALL
            || ($effectiveMode === WorkflowCopilotPermissionMode::ASK_CRITICAL && $action === 'workflow.replace');

        if (! $requiresConfirmation) {
            return [
                'allowed' => true,
                'requires_confirmation' => false,
                'confirmation_id' => null,
                'action' => $action,
                'permission_mode' => $effectiveMode->value,
                'authorization_source' => 'copilot_session_budget',
                'message' => 'Die einzelne historisch belegte Task-Reparatur ist im Sitzungsmodus freigegeben.',
            ];
        }

        $confirmationId = hash_hmac(
            'sha256',
            $session->session_uuid.'|'.$action.'|'.json_encode(
                $this->sortRecursive($parameters),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
            ),
            (string) config('app.key'),
        );
        $decision = [
            'allowed' => false,
            'requires_confirmation' => true,
            'confirmation_id' => $confirmationId,
            'action' => $action,
            'permission_mode' => $effectiveMode->value,
            'authorization_source' => 'copilot_session_budget',
            'message' => 'Die historische Vorab-Reparatur benoetigt eine bestaetigbare Studio-Sitzung.',
        ];
        $state = is_array($session->state_json) ? $session->state_json : [];
        $state['preflight_pending_repairs'] = [
            'type' => 'copilot_plan',
            'action' => $action,
            'confirmation_id' => $confirmationId,
            'message' => $decision['message'],
            'parameters' => $parameters,
            'workflow_run_id' => null,
            'workflow_copilot_session_id' => (int) $session->id,
            'requires_studio_link' => true,
            'created_at' => now()->toIso8601String(),
        ];
        $session->forceFill(['state_json' => $state, 'last_activity_at' => now()])->save();
        $this->sessions->appendEvent(
            $session->fresh() ?? $session,
            'authorization.requested',
            $decision['message'],
            [
                'action' => $action,
                'confirmation_id' => $confirmationId,
                'permission_mode' => $effectiveMode->value,
                'requires_studio_link' => true,
            ],
            'preflight',
            'warning',
            true,
        );
        $this->sessions->pause($session->fresh() ?? $session, $decision['message']);

        return $decision;
    }

    protected function persistReport(WorkflowCopilotSession $session, array $report): WorkflowCopilotSession
    {
        return $this->sessions->updateState(
            $session,
            ['history_preflight' => $report],
            $session->phase,
        );
    }

    protected function analysisMessage(array $report): string
    {
        return 'Vor dem Browser-Test wurden '.(int) ($report['recent_run_count'] ?? 0).' letzte Runs, '.
            (int) ($report['revision_count'] ?? 0).' Revisionen und '.
            (int) ($report['evidence_count'] ?? 0).' Evidenzeintraege ausgewertet. '.
            (int) ($report['unresolved_error_pattern_count'] ?? 0).' Fehlermuster sind noch offen; '.
            (int) ($report['historically_proven_repair_count'] ?? 0).' Vorab-Reparaturen sind historisch belegt.';
    }

    protected function isFailureEvidence(WorkflowRevisionEvidence $evidence): bool
    {
        $logicalOutcome = Str::lower(trim((string) $evidence->logical_outcome));
        $routeDisposition = Str::lower(trim((string) $evidence->route_disposition));

        if (in_array($logicalOutcome, ['condition_false', 'false', 'branch_false'], true)
            && ! in_array($routeDisposition, ['fail', 'invalid'], true)) {
            return false;
        }

        return ! (bool) $evidence->successful
            || in_array($logicalOutcome, ['technical_error', 'timeout'], true)
            || in_array($routeDisposition, ['fail', 'invalid'], true);
    }

    protected function runErrorSignature(WorkflowRun $run): ?string
    {
        $message = trim((string) $run->error_message);
        if ($message === '') {
            return null;
        }

        $normalized = mb_strtolower((string) preg_replace('/\d+/', '#', $message));

        return hash('sha256', $normalized);
    }

    protected function safeText(string $value): string
    {
        $value = Str::limit(trim($value), 1000, '');
        $replacements = [
            '#\b(?:wss?|cdp)://[^\s"\']+#i' => '[WEBSOCKET REDACTED]',
            '/\bBearer\s+[A-Za-z0-9._~+\/-]+=*/i' => 'Bearer [REDACTED]',
            '/\b(password|passwd|pwd|secret|token|cookie|authorization|signature|credential|session(?:_?id)?|api[_-]?key)\s*[:=]\s*[^\s,;]+/i' => '$1=[REDACTED]',
            '/\b[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}\b/i' => '[EMAIL REDACTED]',
        ];

        foreach ($replacements as $pattern => $replacement) {
            $value = (string) preg_replace($pattern, $replacement, $value);
        }

        return $value;
    }

    protected function sanitizeValue(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 8) {
            return '[TRUNCATED]';
        }

        if (is_string($value)) {
            return $this->safeText($value);
        }

        if (! is_array($value)) {
            return is_scalar($value) || $value === null ? $value : null;
        }

        $safe = [];

        foreach (array_slice($value, 0, 100, true) as $key => $child) {
            $normalizedKey = Str::lower(preg_replace('/[^a-z0-9]/i', '', (string) $key) ?? '');

            if ((bool) preg_match('/(?:password|passwd|secret|token|authorization|cookie|credential|browserws|websocket)/', $normalizedKey)) {
                $safe[$key] = '[REDACTED]';
            } else {
                $safe[$key] = $this->sanitizeValue($child, $depth + 1);
            }
        }

        return $safe;
    }

    /** @return array<string, mixed> */
    protected function revisionRecord(WorkflowRevision|WorkflowStudioRevision $revision, string $source): array
    {
        return [
            'id' => (int) $revision->id,
            'source' => $source,
            'revision' => (int) $revision->revision_number,
            'parent_revision' => $revision->parent_revision_number,
            'actor' => Str::limit((string) $revision->actor, 100, ''),
            'reason' => $this->safeText((string) $revision->reason),
            'verified' => (bool) $revision->is_verified,
            'diff_operation_count' => count(is_array($revision->diff_json) ? $revision->diff_json : []),
            'changed_paths' => collect(is_array($revision->diff_json) ? $revision->diff_json : [])
                ->pluck('path')
                ->filter(fn (mixed $path): bool => is_string($path))
                ->take(30)
                ->values()
                ->all(),
            'created_at' => $revision->created_at?->toIso8601String(),
        ];
    }

    /** @return array{0: ?WorkflowStep, 1: ?array} */
    protected function currentTask(Workflow $workflow, string $taskKey, mixed $preferredStepId): array
    {
        $steps = $workflow->steps;
        $preferred = (int) $preferredStepId;
        if ($preferred > 0) {
            $steps = $steps->where('id', $preferred);
        }

        foreach ($steps as $step) {
            $tasks = is_array(data_get($step->config_json, 'tasks')) ? data_get($step->config_json, 'tasks') : [];
            foreach ($tasks as $task) {
                if (is_array($task) && (string) ($task['key'] ?? '') === $taskKey) {
                    return [$step, $task];
                }
            }
        }

        return [null, null];
    }

    protected function taskFromRevision(
        int $workflowId,
        int $revisionNumber,
        string $taskKey,
        mixed $preferredStepId,
    ): ?array {
        $revision = WorkflowRevision::query()
            ->where('workflow_id', $workflowId)
            ->where('revision_number', $revisionNumber)
            ->latest('id')
            ->first();
        $snapshot = $revision?->after_snapshot_json;
        if (! is_array($snapshot)) {
            $revision = WorkflowStudioRevision::query()
                ->where('workflow_id', $workflowId)
                ->where('revision_number', $revisionNumber)
                ->latest('id')
                ->first();
            $snapshot = $revision?->after_snapshot_json;
        }
        if (! is_array($snapshot)) {
            return null;
        }

        $steps = collect(is_array($snapshot['steps'] ?? null) ? $snapshot['steps'] : []);
        $preferred = (int) $preferredStepId;
        if ($preferred > 0) {
            $steps = $steps->filter(fn (mixed $step): bool => is_array($step)
                && (int) ($step['id'] ?? 0) === $preferred);
        }
        foreach ($steps as $step) {
            if (! is_array($step)) {
                continue;
            }
            $tasks = is_array(data_get($step, 'config_json.tasks')) ? data_get($step, 'config_json.tasks') : [];
            foreach ($tasks as $task) {
                if (is_array($task) && (string) ($task['key'] ?? '') === $taskKey) {
                    return $task;
                }
            }
        }

        return null;
    }

    protected function definitionHash(array $definition): string
    {
        return hash('sha256', (string) json_encode(
            $this->sortRecursive($definition),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
        ));
    }

    protected function sortRecursive(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $child) {
            $value[$key] = $this->sortRecursive($child);
        }

        return $value;
    }

    protected function sensitiveRestoreField(string $field): bool
    {
        $normalized = Str::lower(trim($field));

        return in_array($normalized, self::SENSITIVE_RESTORE_FIELDS, true)
            || (bool) preg_match('/(?:password|secret|token|credential|authorization|cookie)/', $normalized);
    }

    /** @return array<string, mixed> */
    protected function candidateSummary(array $candidate): array
    {
        return [
            'workflow_step_id' => (int) ($candidate['workflow_step_id'] ?? 0),
            'step_action_key' => (string) ($candidate['step_action_key'] ?? ''),
            'task_key' => (string) ($candidate['task_key'] ?? ''),
            'change_fields' => array_values(array_filter(
                is_array($candidate['change_fields'] ?? null) ? $candidate['change_fields'] : [],
                'is_string',
            )),
            'failure_evidence_id' => (int) ($candidate['failure_evidence_id'] ?? 0),
            'failure_revision' => (int) ($candidate['failure_revision'] ?? 0),
            'failure_signature' => $candidate['failure_signature'] ?? null,
            'successful_evidence_id' => (int) ($candidate['successful_evidence_id'] ?? 0),
            'successful_revision' => (int) ($candidate['successful_revision'] ?? 0),
        ];
    }
}
