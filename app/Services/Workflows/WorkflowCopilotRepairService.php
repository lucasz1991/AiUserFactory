<?php

namespace App\Services\Workflows;

use App\Models\Workflow;
use App\Models\WorkflowCopilotSession;
use App\Models\WorkflowStep;
use App\Services\Ai\AiConnectionService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WorkflowCopilotRepairService
{
    private const MIN_VISUAL_CONFIDENCE = 0.55;

    private const TASKS_REQUIRING_VISUAL_TARGET = [
        'browser.click',
        'browser.hover',
        'input.fill_field',
        'input.submit',
        'mail.check_address_availability',
        'mail.fill_address',
        'mail.generate_address',
        'mail.generate_password',
    ];

    private const MUTATING_SELECTOR_PROBE_TASKS = [
        'browser.click',
        'input.fill_field',
        'input.submit',
    ];

    private const SHARED_MUTABLE_FIELDS = [
        'timeout_seconds',
        'next',
        'on_partial',
        'on_error',
        'status_routes',
    ];

    private const ROUTE_FIELDS = [
        'next',
        'on_partial',
        'on_error',
        'status_routes',
    ];

    private const ROUTE_TYPES = [
        'card',
        'step',
        'end',
        'fail',
    ];

    private const STRUCTURAL_OPERATION_TYPES = [
        'insert_step',
        'insert_task',
        'move_task',
        'update_step_routes',
        'update_task_routes',
    ];

    private const STRUCTURAL_STEP_ROUTE_OUTCOMES = [
        'success',
        'failed',
        'timeout',
        'partial',
    ];

    private const SAFE_NAVIGATION_TASKS = [
        'browser.open_url',
        'browser.open_browser_session',
    ];

    public function __construct(
        protected WorkflowTaskCatalog $catalog,
        protected AiConnectionService $ai,
        protected WorkflowCopilotObservationService $observations,
        protected WorkflowTaskOrderingService $taskOrdering,
        protected WorkflowCopilotPromptContextService $promptContexts,
        protected WorkflowSelectorProbeService $selectorProbes,
        protected WorkflowCopilotSessionService $sessions,
    ) {}

    public function plan(
        WorkflowCopilotSession $session,
        WorkflowStep $step,
        array $checkpoint,
        array $observation,
        array $vision,
        array $rejectedSelectors = [],
    ): array {
        $taskKey = trim((string) ($checkpoint['task_key'] ?? ''));

        if ($this->sessionIsVerifying($session)) {
            return $this->pausePlan($taskKey, 'Waehrend des unveraenderlichen Kontrolllaufs duerfen keine Workflow-Reparaturen geplant oder gespeichert werden.');
        }

        $task = collect($step->task_cards)->first(
            fn (array $candidate): bool => (string) ($candidate['key'] ?? '') === $taskKey,
        );

        if (! is_array($task)) {
            return $this->pausePlan($taskKey, 'Die fehlgeschlagene Task-Konfiguration wurde nicht gefunden.');
        }

        $taskCatalogKey = trim((string) ($task['task_key'] ?? ''));

        $definition = $taskCatalogKey !== '' ? $this->catalog->task($taskCatalogKey) : null;

        if ($definition === null) {
            return $this->pausePlan($taskKey, 'Die Task ist nicht im WorkflowTaskCatalog registriert und darf nicht autonom veraendert werden.');
        }

        $consentPlan = $this->consentObstaclePlan($step, $task, $checkpoint, $observation, $vision);

        if ($consentPlan !== []) {
            return $consentPlan;
        }

        $resolvedConsentPlan = $this->resolvedConsentObstaclePlan($step, $task, $checkpoint, $observation, $vision);

        if ($resolvedConsentPlan !== []) {
            return $resolvedConsentPlan;
        }

        $blankPageRecovery = $this->blankPageRecoveryPlan($step, $checkpoint, $observation, $vision);

        if ($blankPageRecovery !== []) {
            return $blankPageRecovery;
        }

        $configuredFailureRoute = $this->configuredFailureRoutePlan($step, $task, $checkpoint);

        if ($configuredFailureRoute !== []) {
            return $configuredFailureRoute;
        }

        $collectionDependencyPlan = $this->collectionDependencyPlan($session, $step, $task, $checkpoint);

        if ($collectionDependencyPlan !== []) {
            return $collectionDependencyPlan;
        }

        $missingWorkflowReturnPlan = $this->missingWorkflowReturnPlan($step, $task, $checkpoint);

        if ($missingWorkflowReturnPlan !== []) {
            return $missingWorkflowReturnPlan;
        }

        $emptyCollectionSelectorPlan = $this->emptyCollectionSelectorPlan(
            $step,
            $task,
            $checkpoint,
            $observation,
        );

        if ($emptyCollectionSelectorPlan !== []) {
            return $emptyCollectionSelectorPlan;
        }

        $selectorProbePlan = $this->deterministicSelectorProbePlan(
            $session,
            $step,
            $task,
            $checkpoint,
            $observation,
            $vision,
            $rejectedSelectors,
        );

        if ($selectorProbePlan !== []) {
            return $selectorProbePlan;
        }

        if (in_array($taskCatalogKey, self::MUTATING_SELECTOR_PROBE_TASKS, true)
            && $this->selectorProbes->classifyFailure($checkpoint) === WorkflowSelectorProbeService::FAILURE_SELECTOR_TIMEOUT) {
            return $this->pausePlan(
                $taskKey,
                'Die zustandsveraendernde Selektor-Probe wurde verworfen: Es fehlt genau eine zur aktuellen Task passende, hoch-konfidente Vision-Elementreferenz mit einem eindeutigen stabilen DOM-Selector.',
            );
        }

        $requiresVisualTarget = $this->taskRequiresVisualTarget($taskCatalogKey);
        $trustedElementRefs = $this->trustedVisionElementRefs($vision, $observation);
        $contextDomains = $this->observationDomains($observation);
        $selectors = $this->selectorCandidates($vision, $observation, $task, $requiresVisualTarget)
            ->reject(fn (string $selector): bool => in_array($selector, $rejectedSelectors, true))
            ->reject(fn (string $selector): bool => $selector === trim((string) ($task['selector'] ?? $task['element_selector'] ?? '')))
            ->values();
        $selectors = $this->prioritizeSelectorsForInstructions($selectors, $session);

        if ($selectors->isNotEmpty() && $this->taskSupportsSelector($definition)) {
            $selector = (string) $selectors->first();
            $changes = $this->normalizeChanges($step, $task, [
                'selector' => $selector,
                'element_selector' => $selector,
            ], false, $contextDomains);

            return [
                'action' => 'probe_update',
                'task_key' => $taskKey,
                'task_catalog_key' => $taskCatalogKey,
                'changes' => $changes,
                'probe_task' => array_replace($task, $changes, [
                    'key' => $taskKey.'--copilot-probe',
                    'title' => ($task['title'] ?? $taskKey).' (Copilot-Probe)',
                ]),
                'reason' => 'Ein sichtbares DOM-Element wurde mit einem sicheren Selektorkandidaten verbunden.',
                'selector_candidates' => $selectors->take(12)->all(),
                'original_task_key' => $taskKey,
            ];
        }

        $suggested = $this->suggestedChange(
            $vision,
            $taskKey,
            $definition,
            $trustedElementRefs,
            $requiresVisualTarget,
        );

        if ($suggested !== []) {
            $changes = $this->normalizeChanges($step, $task, $suggested, false, $contextDomains);

            if ($changes !== []) {
                return [
                    'action' => 'probe_update',
                    'task_key' => $taskKey,
                    'task_catalog_key' => $taskCatalogKey,
                    'changes' => $changes,
                    'probe_task' => array_replace($task, $changes, [
                        'key' => $taskKey.'--copilot-probe',
                        'title' => ($task['title'] ?? $taskKey).' (Copilot-Probe)',
                    ]),
                    'reason' => 'Die strukturierte Bildanalyse hat eine katalogkonforme Task-Anpassung vorgeschlagen.',
                    'original_task_key' => $taskKey,
                ];
            }
        }

        try {
            $decision = $this->ai->json(
                $this->plannerPrompt($session, $step, $task, $checkpoint, $observation, $vision),
                'Du bist das Datenanalyse- und Planungsmodell des Workflow-Copiloten. Der strukturierte Bildbefund wurde zuvor von einem getrennten Bildverstehen-Modell aus Screenshot und DOM erzeugt. '
                    .'Der gelieferte Ausfuehrungsvertrag, der vollstaendige Task-Katalog und der aktuelle Workflow-Graph sind verbindlich. Plane und repariere ausschliesslich Workflow-Konfigurationen. '
                    .'Task-Routen haben Vorrang vor Step-Routen; type=fail beendet den gesamten Workflow und ist keine normale Fehlerfortsetzung. Eine Reparatur muss auch im unveraenderlichen Kontrolllauf ohne Skip oder weitere Mutation funktionieren. '
                    .'Antworte nur als JSON. Keine Quellcode-Aenderungen, kein JavaScript und keine Aktionen ausserhalb des vorhandenen WorkflowTaskCatalog.',
                ['temperature' => 0.1, 'max_completion_tokens' => 2200, '_timeout' => 30],
            );
            $decision = $this->observations->sanitizeForModel($decision);
            $decision = is_array($decision) ? $decision : [];
        } catch (\Throwable) {
            $blankPageRecovery = $this->blankPageRecoveryPlan($step, $checkpoint, $observation, $vision);

            if ($blankPageRecovery !== []) {
                return $blankPageRecovery;
            }

            return $this->pausePlan($taskKey, 'Vision und DOM liefern keinen sicheren Reparaturkandidaten; die Planungs-KI war nicht verfuegbar.');
        }

        $action = trim((string) ($decision['action'] ?? 'pause'));
        $decisionElementRef = trim((string) ($decision['element_ref'] ?? ''));
        $hasSafePlannerTarget = ! $requiresVisualTarget
            || ($decisionElementRef !== '' && isset($trustedElementRefs[$decisionElementRef]));
        $requestedOperations = is_array($decision['operations'] ?? null) ? $decision['operations'] : [];
        $decisionTrace = [
            'source' => 'data_analysis',
            'requested_action' => $action,
            'requested_operation_count' => count($requestedOperations),
            'accepted_operation_count' => 0,
            'rejected_operation_count' => 0,
            'rejected_operations' => [],
            'trusted_element_refs' => array_keys($trustedElementRefs),
        ];

        if ($action === 'retry') {
            if (! $hasSafePlannerTarget) {
                return $this->unsafeVisualTargetPlan($taskKey);
            }

            return [
                'action' => 'retry',
                'task_key' => $taskKey,
                'reason' => $this->safeReason($decision['reason'] ?? 'Task erneut ausfuehren.'),
                'decision_trace' => $decisionTrace,
            ];
        }

        if ($action === 'continue_route') {
            return [
                'action' => 'continue_route',
                'task_key' => $taskKey,
                'reason' => $this->safeReason($decision['reason'] ?? 'Konfigurierte Fehlerroute fortsetzen.'),
                'decision_trace' => $decisionTrace,
            ];
        }

        if ($action === 'structural_update') {
            $rejectedOperations = [];
            $operations = $this->normalizeStructuralOperations(
                $step,
                $requestedOperations,
                $observation,
                $vision,
                $rejectedOperations,
            );
            $decisionTrace['accepted_operation_count'] = count($operations);
            $decisionTrace['rejected_operation_count'] = count($rejectedOperations);
            $decisionTrace['rejected_operations'] = $rejectedOperations;

            if ($operations !== []) {
                return [
                    'action' => 'restart_with_workflow_changes',
                    'task_key' => $taskKey,
                    'operations' => $operations,
                    'reason' => $this->safeReason($decision['reason'] ?? 'Fehlende Workflow-Logik wird kataloggebunden ergaenzt und von vorn getestet.'),
                    'planning_handoff' => $this->planningHandoff($vision),
                    'decision_trace' => $decisionTrace,
                ];
            }
        }

        $changes = $this->normalizeChanges(
            $step,
            $task,
            is_array($decision['changes'] ?? null) ? $decision['changes'] : [],
            false,
            $contextDomains,
        );

        if ($action === 'update_task' && $changes !== []) {
            if (! $hasSafePlannerTarget) {
                return $this->unsafeVisualTargetPlan($taskKey);
            }

            return [
                'action' => 'probe_update',
                'task_key' => $taskKey,
                'task_catalog_key' => $taskCatalogKey,
                'changes' => $changes,
                'probe_task' => array_replace($task, $changes, [
                    'key' => $taskKey.'--copilot-probe',
                    'title' => ($task['title'] ?? $taskKey).' (Copilot-Probe)',
                ]),
                'reason' => $this->safeReason($decision['reason'] ?? 'Katalogkonforme Task-Anpassung pruefen.'),
                'original_task_key' => $taskKey,
                'decision_trace' => $decisionTrace,
            ];
        }

        $reason = $this->safeReason($decision['reason'] ?? 'Keine sichere autonome Reparatur gefunden.');

        if ($decisionTrace['rejected_operations'] !== []) {
            $reason = $this->safeReason($reason.' Verworfen: '.collect($decisionTrace['rejected_operations'])
                ->pluck('message')
                ->filter()
                ->implode(' '));
        }

        return array_replace(
            $this->pausePlan($taskKey, $reason),
            ['decision_trace' => $decisionTrace],
        );
    }

    /**
     * Plan only offline-verifiable structure repairs before a fresh browser
     * run. Selectors and visual actions are deliberately excluded because no
     * current DOM observation exists at this point.
     *
     * @return array{operations: list<array<string, mixed>>, reason: string, rejected_operations: list<array<string, mixed>>}
     */
    public function planHistoricalPreflight(
        WorkflowCopilotSession $session,
        array $historyReport,
    ): array {
        $workflow = $session->workflow()->with(['steps' => fn ($query) => $query->ordered()])->first();
        $currentStep = $workflow?->steps->first();

        if (! $workflow || ! $currentStep) {
            return ['operations' => [], 'reason' => 'Kein bestehender Workflow-Graph fuer eine Vorab-Strukturpruefung vorhanden.', 'rejected_operations' => []];
        }

        $unresolved = collect(is_array($historyReport['error_patterns'] ?? null) ? $historyReport['error_patterns'] : [])
            ->where('resolved', false)
            ->values();
        $diagnostics = collect(is_array($historyReport['static_diagnostics'] ?? null) ? $historyReport['static_diagnostics'] : [])
            ->filter(fn (mixed $diagnostic): bool => is_array($diagnostic))
            ->values();
        $alreadyCovered = max(0, (int) ($historyReport['historically_proven_repair_count'] ?? 0));
        $requiresOfflinePlan = $unresolved->count() > $alreadyCovered
            || $diagnostics->contains(fn (array $diagnostic): bool => ($diagnostic['severity'] ?? null) === 'error');

        if (! $requiresOfflinePlan) {
            return ['operations' => [], 'reason' => 'Alle historisch belegbaren Fehler sind bereits durch sichere Task-Wiederverwendung abgedeckt.', 'rejected_operations' => []];
        }

        try {
            $decision = $this->ai->json(
                'Pruefe bekannte Lauf-, Revisions- und Diagnosefehler, bevor ein neuer Browser-Test startet. '.
                    'Es gibt absichtlich noch KEINEN aktuellen DOM- oder Screenshot-Befund. Deshalb sind Selector-Aenderungen, Klicks, Eingaben, neue visuell zielgebundene Tasks und externe Aktionen verboten. '.
                    'Erlaubt sind ausschliesslich Operationen, die identisch in history_preflight.offline_safe_operations vorgegeben sind. '.
                    'Eine explizite type=fail-Route darf insbesondere niemals ohne dort belegtes alternatives bestehendes Ziel in type=end oder eine andere Route umgebogen werden. '.
                    'Normale condition_false-/IF-Falschzweige sind fachliche Abzweigungen und keine Fehler, solange ihre aufgeloeste Route nicht type=fail oder invalid ist. '.
                    'Veraendere nur Tasks oder Listen, die in unresolved error_patterns oder static_diagnostics konkret genannt sind. '.
                    'Antworte als JSON im Schema {"action":"structural_update|none","operations":[],"reason":"..."}. Daten: '.
                    json_encode([
                        'workflow_context' => $this->promptContexts->forWorkflow($workflow, $session),
                        'history_preflight' => $historyReport,
                    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                'Du bist der sichere Vorab-Planer des Workflow-Copiloten. Nutze Lauf- und Revisionshistorie, wiederhole keine gescheiterten Aenderungen und plane ohne aktuelle Browser-Evidenz nur statisch beweisbare Routing- oder Reihenfolgekorrekturen. Antworte nur als JSON.',
                ['temperature' => 0.05, 'max_completion_tokens' => 1600, '_timeout' => 30],
            );
            $decision = $this->observations->sanitizeForModel($decision);
            $decision = is_array($decision) ? $decision : [];
        } catch (\Throwable) {
            return ['operations' => [], 'reason' => 'Der optionale Vorab-Strukturplaner war nicht verfuegbar; historisch bewiesene Task-Reparaturen bleiben davon unberuehrt.', 'rejected_operations' => []];
        }

        if (($decision['action'] ?? 'none') !== 'structural_update') {
            return [
                'operations' => [],
                'reason' => $this->safeReason($decision['reason'] ?? 'Keine weitere statisch beweisbare Vorab-Reparatur gefunden.'),
                'rejected_operations' => [],
            ];
        }

        $requested = collect(is_array($decision['operations'] ?? null) ? $decision['operations'] : [])
            ->filter(fn (mixed $operation): bool => is_array($operation)
                && in_array(($operation['type'] ?? null), ['move_task', 'update_step_routes', 'update_task_routes'], true))
            ->values()
            ->all();
        $rejections = [];
        $operations = $this->normalizeStructuralOperations($currentStep, $requested, [], [], $rejections);
        $provenOperations = collect(is_array($historyReport['offline_safe_operations'] ?? null)
            ? $historyReport['offline_safe_operations']
            : [])
            ->filter(fn (mixed $operation): bool => is_array($operation))
            ->map(fn (array $operation): string => hash('sha256', (string) json_encode(
                $this->sortOperation($operation),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
            )))
            ->values();
        $operations = collect($operations)
            ->filter(function (array $operation, int $index) use ($provenOperations, &$rejections): bool {
                $fingerprint = hash('sha256', (string) json_encode(
                    $this->sortOperation($operation),
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
                ));

                if ($provenOperations->contains($fingerprint)) {
                    return true;
                }

                $this->recordStructuralRejection(
                    $rejections,
                    $index,
                    (string) ($operation['type'] ?? ''),
                    'historical_operation_not_proven',
                    'Die Offline-Strukturaenderung ist nicht durch eine fruehere erfolgreiche Revision exakt belegt.',
                );

                return false;
            })
            ->values()
            ->all();
        $allowedTaskKeys = $unresolved->pluck('task_key')
            ->merge($diagnostics->pluck('task_key'))
            ->map(fn (mixed $key): string => trim((string) $key))
            ->filter()
            ->unique();
        $allowedStepKeys = $diagnostics->pluck('step_action_key')
            ->merge($workflow->steps
                ->filter(fn (WorkflowStep $step): bool => collect($step->task_cards)
                    ->contains(fn (array $task): bool => $allowedTaskKeys->contains((string) ($task['key'] ?? ''))))
                ->pluck('action_key'))
            ->map(fn (mixed $key): string => trim((string) $key))
            ->filter()
            ->unique();
        $operations = collect($operations)
            ->filter(function (array $operation) use ($allowedTaskKeys, $allowedStepKeys): bool {
                $taskKey = trim((string) ($operation['task_key'] ?? ''));
                $stepKey = trim((string) ($operation['step_action_key'] ?? ''));

                return ($taskKey !== '' && $allowedTaskKeys->contains($taskKey))
                    || ($stepKey !== '' && $allowedStepKeys->contains($stepKey));
            })
            ->values()
            ->all();

        return [
            'operations' => $operations,
            'reason' => $this->safeReason($decision['reason'] ?? 'Historische Routing- oder Reihenfolgefehler werden vor dem Browser-Test korrigiert.'),
            'rejected_operations' => $rejections,
        ];
    }

    protected function sortOperation(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        foreach ($value as $key => $child) {
            $value[$key] = $this->sortOperation($child);
        }

        return $value;
    }

    public function applyStructuralOperations(
        Workflow $workflow,
        array $operations,
        WorkflowCopilotSession $session,
        array $observation = [],
    ): void {
        $activeSession = $workflow->active_workflow_copilot_session_id
            ? WorkflowCopilotSession::query()->find($workflow->active_workflow_copilot_session_id)
            : null;

        if (! $activeSession
            || (int) $activeSession->getKey() !== (int) $session->getKey()
            || (int) $session->workflow_id !== (int) $workflow->getKey()
            || ! in_array($activeSession->status, [
                WorkflowCopilotSession::STATUS_RUNNING,
                WorkflowCopilotSession::STATUS_REPAIRING,
            ], true)) {
            throw new \DomainException('Nur die aktive Copilot-Sitzung darf strukturelle Workflow-Reparaturen anwenden.');
        }

        foreach ($operations as $operation) {
            if (! is_array($operation) || ! in_array($operation['type'] ?? null, self::STRUCTURAL_OPERATION_TYPES, true)) {
                throw new \DomainException('Die strukturelle Reparatur enthaelt eine nicht erlaubte Operation.');
            }

            if ($operation['type'] === 'insert_step') {
                $this->applyConsentObstacleStep($workflow, $operation, $observation);

                continue;
            }

            $step = $workflow->steps()
                ->where('action_key', (string) ($operation['step_action_key'] ?? ''))
                ->first();

            if (! $step) {
                throw new \DomainException('Die Ziel-Liste der strukturellen Reparatur wurde nicht gefunden.');
            }

            if ($operation['type'] === 'move_task') {
                $taskKey = trim((string) ($operation['task_key'] ?? ''));

                if ($taskKey === '' || ! $this->taskOrdering->moveTask(
                    $workflow,
                    $step,
                    $taskKey,
                    max(0, (int) ($operation['insert_position'] ?? 0)),
                    (int) $step->id,
                )) {
                    throw new \DomainException('Die bestehende Task konnte nicht an die geplante Position verschoben werden.');
                }

                continue;
            }

            if ($operation['type'] === 'update_step_routes') {
                $routes = is_array($operation['routes'] ?? null) ? $operation['routes'] : [];

                if ($routes === []) {
                    throw new \DomainException('Die strukturelle Reparatur enthaelt keine gueltigen Listen-Routen.');
                }

                foreach ($routes as $outcome => $route) {
                    if (! in_array($outcome, self::STRUCTURAL_STEP_ROUTE_OUTCOMES, true)
                        || ! is_array($route)
                        || ! $this->isValidRoute($step, $route)) {
                        throw new \DomainException('Die strukturelle Reparatur enthaelt eine ungueltige Listen-Route.');
                    }
                }

                $config = is_array($step->config_json) ? $step->config_json : [];
                $config['routes'] = array_replace(
                    is_array($config['routes'] ?? null) ? $config['routes'] : [],
                    $routes,
                );
                $step->forceFill(['config_json' => $config])->save();

                continue;
            }

            if ($operation['type'] === 'update_task_routes') {
                $taskKey = trim((string) ($operation['task_key'] ?? ''));
                $routeChanges = Arr::only(
                    is_array($operation['changes'] ?? null) ? $operation['changes'] : [],
                    self::ROUTE_FIELDS,
                );

                if ($taskKey === '' || $routeChanges === []) {
                    throw new \DomainException('Die strukturelle Reparatur enthaelt keine gueltigen Task-Routen.');
                }

                $this->applyChangesToLockedStep($step, $taskKey, $routeChanges);

                continue;
            }

            $catalogKey = trim((string) ($operation['task_catalog_key'] ?? ''));
            $definition = $catalogKey !== '' ? $this->catalog->task($catalogKey) : null;
            $cardKey = trim((string) ($operation['card_key'] ?? ''));
            $requiresVisualTarget = $this->taskRequiresVisualTarget($catalogKey);

            if (! $definition
                || $catalogKey === 'loop.end'
                || $cardKey === ''
                || collect($step->task_cards)->contains(fn (array $task): bool => (string) ($task['key'] ?? '') === $cardKey)) {
                throw new \DomainException('Der einzufuegende Task ist nicht katalogkonform oder nicht eindeutig.');
            }

            $parameters = is_array($operation['parameters'] ?? null) ? $operation['parameters'] : [];

            if ($requiresVisualTarget) {
                $configuredEvidence = $this->configuredCollectionSelectorEvidence($workflow, $step, $operation);

                if ($configuredEvidence !== []) {
                    $evidenceSelector = (string) $configuredEvidence['selector'];

                    if (trim((string) ($parameters['selector'] ?? '')) !== $evidenceSelector
                        || trim((string) ($parameters['element_selector'] ?? '')) !== $evidenceSelector) {
                        throw new \DomainException('Der Collection-Loop stimmt nicht mit dem bereits konfigurierten Ergebnis-Selector ueberein.');
                    }
                } else {
                    $elementRef = trim((string) ($operation['element_ref'] ?? ''));
                    $visualTarget = $this->visualTargetFromObservation($elementRef, $observation);
                    $evidenceSelector = trim((string) ($operation['evidence_selector'] ?? ''));

                    if ($visualTarget === []
                        || $evidenceSelector === ''
                        || ! hash_equals((string) $visualTarget['selector'], $evidenceSelector)
                        || trim((string) ($parameters['selector'] ?? '')) !== $evidenceSelector
                        || trim((string) ($parameters['element_selector'] ?? '')) !== $evidenceSelector) {
                        throw new \DomainException('Der visuell zielgebundene Task ist nicht durch die aktuelle DOM-Evidenz abgesichert.');
                    }
                }
            }

            if ($this->structuralTaskAlreadyExists($step, $catalogKey, $parameters)) {
                throw new \DomainException('Der einzufuegende Task ist bereits gleichartig in der Ziel-Liste konfiguriert.');
            }

            $baseCard = $this->catalog->cardFromDefinition($catalogKey, [
                'key' => $cardKey,
                'title' => Str::limit(trim((string) ($operation['title'] ?? $definition['label'] ?? $catalogKey)), 180, ''),
                'description' => Str::limit(trim((string) ($operation['description'] ?? $definition['description'] ?? '')), 1000, ''),
            ]);
            $normalized = $this->normalizeChanges(
                $step,
                $baseCard,
                $parameters,
                true,
                $this->observationDomains($observation),
            );
            $card = array_replace($baseCard, $normalized);

            if ($catalogKey === 'loop.for_each_element') {
                $pairId = 'loop-'.(string) Str::uuid();
                $endKey = $this->uniqueStructuralTaskKey($step, $cardKey.'-end');
                $card = array_replace($card, [
                    'loop_pair_id' => $pairId,
                    'loop_pair_segment' => 'start',
                    'loop_start_key' => $cardKey,
                    'loop_end_key' => $endKey,
                ]);
                $endCard = $this->catalog->cardFromDefinition('loop.end', [
                    'key' => $endKey,
                    'title' => 'Loop-Ende: '.($card['title'] ?? $cardKey),
                    'description' => 'Automatisches Endsegment fuer '.($card['title'] ?? $cardKey).'.',
                    'loop_pair_id' => $pairId,
                    'loop_pair_segment' => 'end',
                    'loop_start_key' => $cardKey,
                    'loop_end_key' => $endKey,
                ]);
                $this->taskOrdering->insertTasks(
                    $step,
                    [$card, $endCard],
                    max(0, (int) ($operation['insert_position'] ?? count($step->task_cards))),
                );

                continue;
            }

            $this->taskOrdering->insertTask(
                $step,
                $card,
                max(0, (int) ($operation['insert_position'] ?? count($step->task_cards))),
            );
        }
    }

    public function applyChangesToStep(
        WorkflowStep $step,
        string $taskKey,
        array $changes,
        ?WorkflowCopilotSession $session = null,
    ): array {
        return DB::transaction(function () use ($step, $taskKey, $changes, $session): array {
            $workflow = Workflow::query()->lockForUpdate()->findOrFail($step->workflow_id);

            if ($workflow->active_workflow_copilot_session_id !== null) {
                $activeSession = WorkflowCopilotSession::query()
                    ->lockForUpdate()
                    ->find($workflow->active_workflow_copilot_session_id);

                if ($activeSession?->status === WorkflowCopilotSession::STATUS_VERIFYING) {
                    throw new \DomainException('Waehrend des unveraenderlichen Kontrolllaufs duerfen keine Workflow-Reparaturen gespeichert werden.');
                }

                if (! $activeSession
                    || ! $session
                    || (int) $session->getKey() !== (int) $activeSession->getKey()
                    || (int) $session->workflow_id !== (int) $workflow->getKey()
                    || ! in_array($activeSession->status, [
                        WorkflowCopilotSession::STATUS_RUNNING,
                        WorkflowCopilotSession::STATUS_REPAIRING,
                    ], true)) {
                    throw new \DomainException('Nur die aktive Copilot-Sitzung darf den exklusiv gesperrten Workflow reparieren.');
                }
            }

            $lockedStep = WorkflowStep::query()
                ->where('workflow_id', $workflow->getKey())
                ->lockForUpdate()
                ->findOrFail($step->getKey());

            return $this->applyChangesToLockedStep($lockedStep, $taskKey, $changes);
        });
    }

    /**
     * Reduce a historically successful task card to the catalog-backed fields
     * that may safely be restored on the current card. The caller still has to
     * prove that the historical card really belongs to a successful execution;
     * this method only enforces the existing mutation and route policies.
     *
     * @return array<string, mixed>
     */
    public function historicalTaskChanges(
        WorkflowStep $step,
        string $taskKey,
        array $historicalTask,
    ): array {
        $currentTask = collect($step->task_cards)->first(
            fn (array $candidate): bool => (string) ($candidate['key'] ?? '') === $taskKey,
        );

        if (! is_array($currentTask)) {
            return [];
        }

        $currentCatalogKey = trim((string) ($currentTask['task_key'] ?? ''));
        $historicalCatalogKey = trim((string) ($historicalTask['task_key'] ?? ''));

        if ($currentCatalogKey === ''
            || $currentCatalogKey !== $historicalCatalogKey
            || $this->catalog->task($currentCatalogKey) === null) {
            return [];
        }

        return collect($this->normalizeChanges($step, $currentTask, $historicalTask, true))
            ->filter(fn (mixed $value, string $field): bool => ($currentTask[$field] ?? null) !== $value)
            ->all();
    }

    protected function applyChangesToLockedStep(WorkflowStep $step, string $taskKey, array $changes): array
    {
        $config = is_array($step->config_json) ? $step->config_json : [];
        $tasks = is_array($config['tasks'] ?? null) ? $config['tasks'] : [];
        $updated = false;

        foreach ($tasks as $index => $task) {
            if (! is_array($task) || (string) ($task['key'] ?? '') !== $taskKey) {
                continue;
            }

            $catalogKey = trim((string) ($task['task_key'] ?? ''));

            if ($catalogKey === '' || $this->catalog->task($catalogKey) === null) {
                throw new \DomainException('Task `'.$taskKey.'` ist nicht im WorkflowTaskCatalog registriert.');
            }

            $normalized = $this->normalizeChanges($step, $task, $changes, true);

            if ($normalized === []) {
                throw new \DomainException('Die Reparatur enthaelt keine erlaubten Taskparameter.');
            }

            $tasks[$index] = array_replace($task, $normalized);
            $updated = true;
            break;
        }

        if (! $updated) {
            throw new \DomainException('Task `'.$taskKey.'` wurde im Workflow-Schritt nicht gefunden.');
        }

        $config['tasks'] = $tasks;
        $step->forceFill(['config_json' => $config])->save();

        return collect($tasks)->firstWhere('key', $taskKey) ?? [];
    }

    /**
     * Repair the exact collection dependency instead of asking the planner to
     * restate an already observable producer/consumer ordering problem.
     *
     * @param  array<string, mixed>  $task
     * @param  array<string, mixed>  $checkpoint
     * @return array<string, mixed>
     */
    protected function collectionDependencyPlan(
        WorkflowCopilotSession $session,
        WorkflowStep $step,
        array $task,
        array $checkpoint,
    ): array {
        if (($task['task_key'] ?? null) !== 'data.append_to_array'
            || (bool) ($checkpoint['successful'] ?? false)
            || ! in_array(Str::lower(trim((string) ($checkpoint['outcome'] ?? 'failed'))), ['failed', 'timeout'], true)) {
            return [];
        }

        $sourceVariable = trim((string) ($task['value_from_variable'] ?? ''));

        if ($sourceVariable === '') {
            return [];
        }

        $tasks = collect($step->task_cards)->values();
        $consumerIndex = $tasks->search(
            fn (array $candidate): bool => (string) ($candidate['key'] ?? '') === (string) ($task['key'] ?? ''),
        );
        $producerIndex = $tasks->search(function (array $candidate) use ($sourceVariable): bool {
            return in_array((string) ($candidate['task_key'] ?? ''), [
                'browser.read_searchengine_result',
                'browser.read_element_fields',
            ], true)
                && trim((string) ($candidate['output_variable'] ?? '')) === $sourceVariable;
        });

        if ($consumerIndex === false || $producerIndex === false) {
            return [];
        }

        $producer = $tasks->get($producerIndex);

        if (! is_array($producer)) {
            return [];
        }

        $scopeVariable = trim((string) ($producer['scope_variable'] ?? $sourceVariable)) ?: $sourceVariable;
        $loopIndex = $tasks->search(function (array $candidate) use ($scopeVariable): bool {
            return ($candidate['task_key'] ?? null) === 'loop.for_each_element'
                && trim((string) ($candidate['store_current_element_as'] ?? 'current_result')) === $scopeVariable;
        });
        $loopEndIndex = false;

        if ($loopIndex !== false) {
            $loop = $tasks->get($loopIndex);
            $pairId = is_array($loop) ? trim((string) ($loop['loop_pair_id'] ?? '')) : '';

            if ($pairId === '') {
                return [];
            }

            $loopEndIndex = $tasks->search(
                fn (array $candidate): bool => ($candidate['task_key'] ?? null) === 'loop.end'
                    && trim((string) ($candidate['loop_pair_id'] ?? '')) === $pairId,
            );

            if ($loopEndIndex === false) {
                return [];
            }

            if ($producerIndex > $loopIndex
                && $consumerIndex > $producerIndex
                && $consumerIndex < $loopEndIndex) {
                return [];
            }
        }

        if ($loopIndex === false) {
            return array_replace(
                $this->pausePlan(
                    (string) ($task['key'] ?? ''),
                    'Die alte Reader-plus-Append-Sammlung besitzt keinen Legacy-DOM-Loop. Ein neuer Loop darf keine DOM-Suche mehr uebernehmen; die Sammlung muss auf den Batchmodus von Suchmaschinentreffer lesen mit list_item_selector und output_array_name migriert werden.',
                ),
                [
                    'decision_trace' => [
                        'source' => 'deterministic_collection_dependency_migration_required',
                        'requested_action' => 'pause',
                        'dependency' => [
                            'variable' => $sourceVariable,
                            'producer_index' => (int) $producerIndex,
                            'consumer_index' => (int) $consumerIndex,
                            'loop_index' => null,
                        ],
                    ],
                ],
            );
        }

        $insertPosition = (int) $loopIndex + 1;
        $operations = [[
            'type' => 'move_task',
            'step_action_key' => (string) $step->action_key,
            'task_key' => (string) ($producer['key'] ?? ''),
            'insert_position' => $insertPosition + 1,
        ]];
        $operations[] = [
            'type' => 'move_task',
            'step_action_key' => (string) $step->action_key,
            'task_key' => (string) ($task['key'] ?? ''),
            'insert_position' => $insertPosition + 2,
        ];

        return [
            'action' => 'restart_with_workflow_changes',
            'task_key' => (string) ($task['key'] ?? ''),
            'operations' => $operations,
            'reason' => 'Reader und Array-Append lagen ausserhalb oder in falscher Reihenfolge innerhalb des bereits konfigurierten Legacy-DOM-Loops. Beide Tasks werden in Producer-vor-Consumer-Reihenfolge verschoben.',
            'planning_handoff' => [
                'planner_profile' => 'deterministic_collection_dependency',
                'source_variable' => $sourceVariable,
                'producer_task_key' => $producer['key'] ?? null,
                'consumer_task_key' => $task['key'] ?? null,
            ],
            'decision_trace' => [
                'source' => 'deterministic_collection_dependency',
                'requested_action' => 'structural_update',
                'requested_operation_count' => count($operations),
                'accepted_operation_count' => count($operations),
                'rejected_operation_count' => 0,
                'rejected_operations' => [],
                'dependency' => [
                    'variable' => $sourceVariable,
                    'producer_index' => (int) $producerIndex,
                    'consumer_index' => (int) $consumerIndex,
                    'loop_index' => (int) $loopIndex,
                ],
            ],
        ];
    }

    protected function missingWorkflowReturnPlan(
        WorkflowStep $step,
        array $task,
        array $checkpoint,
    ): array {
        if (data_get($checkpoint, 'result.businessGap.reason_code') !== 'required_workflow_return_missing') {
            return [];
        }

        $sourceArray = trim((string) data_get($checkpoint, 'result.businessGap.source_array', ''));

        if ($sourceArray === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_.-]{0,119}$/', $sourceArray) !== 1) {
            return [];
        }

        $workflow = Workflow::query()
            ->with(['steps' => fn ($query) => $query->ordered()])
            ->find($step->workflow_id);

        if (! $workflow
            || $workflow->steps->contains(fn (WorkflowStep $candidateStep): bool => collect($candidateStep->task_cards)
                ->contains(fn (array $candidate): bool => (string) ($candidate['task_key'] ?? '') === 'data.workflow_return'))) {
            return [];
        }

        return [
            'action' => 'restart_with_workflow_changes',
            'task_key' => (string) ($task['key'] ?? ''),
            'operations' => [[
                'type' => 'insert_task',
                'purpose' => 'required_workflow_return',
                'step_action_key' => (string) $step->action_key,
                'task_catalog_key' => 'data.workflow_return',
                'card_key' => $this->uniqueStructuralTaskKey($step, 'Workflow Rueckgabewert'),
                'title' => 'Ergebnis-Array zurueckgeben',
                'description' => 'Setzt das erzeugte Array `'.$sourceArray.'` als expliziten Workflow-Rueckgabewert.',
                'parameters' => [
                    'selector' => $sourceArray,
                ],
                'insert_position' => count($step->task_cards),
            ]],
            'reason' => 'Das geforderte Ergebnis-Array ist bereits erzeugt, wird aber noch nicht als Workflow-Rueckgabewert gesetzt. Eine kataloggebundene data.workflow_return-Task wird hinter der Sammlung eingefuegt und danach von Anfang an verifiziert.',
            'planning_handoff' => [
                'planner_profile' => 'deterministic_required_workflow_return',
                'source_array' => $sourceArray,
            ],
            'decision_trace' => [
                'source' => 'deterministic_required_workflow_return',
                'requested_action' => 'structural_update',
                'requested_operation_count' => 1,
                'accepted_operation_count' => 1,
                'rejected_operation_count' => 0,
                'rejected_operations' => [],
            ],
        ];
    }

    protected function emptyCollectionSelectorPlan(
        WorkflowStep $step,
        array $task,
        array $checkpoint,
        array $observation,
    ): array {
        $catalogTaskKey = (string) ($task['task_key'] ?? '');
        $isLegacyLoop = $catalogTaskKey === 'loop.for_each_element'
            && filled($task['selector'] ?? $task['element_selector'] ?? null);
        $isBatchSearchReader = $catalogTaskKey === 'browser.read_searchengine_result'
            && filled($task['list_item_selector'] ?? $task['listItemSelector'] ?? null);

        if ((! $isLegacyLoop && ! $isBatchSearchReader)
            || data_get($checkpoint, 'result.businessGap.reason_code') !== 'required_collection_empty') {
            return [];
        }

        $pageState = Str::lower(trim((string) (
            data_get($observation, 'page.state')
            ?? data_get($observation, 'dom.ui_state')
            ?? ''
        )));
        $pageUrl = Str::lower(trim((string) data_get($observation, 'page.url', '')));

        if ($pageState !== 'search_results' && ! str_contains($pageUrl, '/search')) {
            return [];
        }

        $selector = $this->preferredSelectorFrom(
            collect($observation['interaction_map'] ?? [])
                ->filter(fn (mixed $element): bool => is_array($element)
                    && ($element['visible'] ?? null) === true
                    && ($element['enabled'] ?? true) !== false
                    && (string) ($element['tag'] ?? '') === 'a')
                ->flatMap(fn (array $element): array => is_array($element['selector_candidates'] ?? null)
                    ? $element['selector_candidates']
                    : [])
                ->filter(fn (mixed $candidate): bool => is_string($candidate)
                    && preg_match('/:has\(h[1-3]\)/i', $candidate) === 1)
                ->values()
                ->all(),
        );

        $currentSelector = $isBatchSearchReader
            ? trim((string) ($task['list_item_selector'] ?? $task['listItemSelector'] ?? ''))
            : trim((string) ($task['selector'] ?? $task['element_selector'] ?? ''));

        if ($selector === null || $selector === $currentSelector) {
            return [];
        }

        // Legacy-DOM-Loops werden nicht mehr im Katalog angeboten, muessen fuer
        // bereits gespeicherte Workflows aber weiterhin deterministisch
        // reparierbar bleiben. Der Selector stammt hier ausschliesslich aus der
        // beobachteten Interaktionskarte; neue Workflows aendern direkt den
        // list_item_selector der zustaendigen Reader-Task.
        $changes = $isLegacyLoop
            ? ['selector' => $selector, 'element_selector' => $selector]
            : $this->normalizeChanges(
                $step,
                $task,
                ['list_item_selector' => $selector],
                false,
                $this->observationDomains($observation),
            );

        if ($changes === []) {
            return [];
        }

        return [
            'action' => 'probe_update',
            'task_key' => (string) ($task['key'] ?? ''),
            'task_catalog_key' => $catalogTaskKey,
            'changes' => $changes,
            'probe_task' => array_replace($task, $changes, [
                'key' => (string) ($task['key'] ?? '').'--copilot-probe',
                'title' => ($task['title'] ?? ($isBatchSearchReader ? 'Suchmaschinentreffer lesen' : 'Legacy-Ergebnis-Loop')).' (Copilot-Probe)',
            ]),
            'reason' => 'Der bisherige Listenelement-Selector lieferte trotz sichtbarer Suchergebnisse keine Treffer. Die DOM-Beobachtung weist einen stabilen, ueberschriftenbasierten Selector fuer die sichtbaren Ergebnislinks aus; dieser wird direkt an der zuständigen Leser-Task geprueft.',
            'selector_candidates' => [$selector],
            'original_task_key' => (string) ($task['key'] ?? ''),
            'decision_trace' => [
                'source' => 'deterministic_empty_collection_selector',
                'page_state' => $pageState,
                'previous_selector' => $currentSelector,
                'selected_selector' => $selector,
            ],
        ];
    }

    /**
     * Deterministische Selektor-Probe (Session 24, Workflow 15): Bei einem
     * Selector-Timeout existiert das Zielelement nicht in der Beobachtung,
     * daher gibt es keine Vision-Evidenz und der Modell-Planer durfte den
     * Selector nie reparieren. Diese Reparaturklasse laeuft VOR dem
     * Modell-Planer, ist reiner Server-Code und nutzt als Evidenz die Herkunft
     * aus der eigenen DOM-Beobachtung (Evidenzklasse `selector_probe`). Das
     * strikte hash_equals-Gate fuer modellvorgeschlagene Selektoren bleibt
     * unveraendert. Zustandsveraendernde Tasks werden nur mit einer exakt zur
     * aktuellen Karte gehoerenden, hoch-konfidenten Vision-Elementreferenz
     * zugelassen. Ohne eindeutigen besten Kandidaten: kein Eingriff.
     *
     * @param  array<string, mixed>  $task
     * @param  array<string, mixed>  $checkpoint
     * @param  array<string, mixed>  $observation
     * @param  array<string, mixed>  $vision
     * @param  list<string>  $rejectedSelectors
     * @return array<string, mixed>
     */
    protected function deterministicSelectorProbePlan(
        WorkflowCopilotSession $session,
        WorkflowStep $step,
        array $task,
        array $checkpoint,
        array $observation,
        array $vision,
        array $rejectedSelectors,
    ): array {
        $taskCatalogKey = trim((string) ($task['task_key'] ?? ''));
        $definition = $taskCatalogKey !== '' ? $this->catalog->task($taskCatalogKey) : null;
        $isMutatingSelectorProbe = in_array($taskCatalogKey, self::MUTATING_SELECTOR_PROBE_TASKS, true);

        if ($definition === null
            || ! $this->taskSupportsSelector($definition)
            || ($this->taskRequiresVisualTarget($taskCatalogKey) && ! $isMutatingSelectorProbe)
            || (bool) ($checkpoint['successful'] ?? false)
            || (bool) data_get($checkpoint, 'result.irreversibleSideEffect', false)
            || (is_array(data_get($checkpoint, 'result.sideEffects'))
                && data_get($checkpoint, 'result.sideEffects') !== [])
            || ! in_array(Str::lower(trim((string) ($checkpoint['outcome'] ?? 'failed'))), ['failed', 'timeout'], true)) {
            return [];
        }

        $failureClass = $this->selectorProbes->classifyFailure($checkpoint);

        if (! in_array($failureClass, [
            WorkflowSelectorProbeService::FAILURE_SELECTOR_TIMEOUT,
            WorkflowSelectorProbeService::FAILURE_SELECTOR_NOT_FOUND,
        ], true)) {
            return [];
        }

        if ($isMutatingSelectorProbe && $failureClass !== WorkflowSelectorProbeService::FAILURE_SELECTOR_TIMEOUT) {
            return [];
        }

        $requiredElementRefs = $isMutatingSelectorProbe
            ? $this->trustedSelectorProbeElementRefs($task, $vision, $observation)
            : [];

        if ($isMutatingSelectorProbe && count($requiredElementRefs) !== 1) {
            return [];
        }

        $candidate = $this->selectorProbes->bestCandidate(
            $task,
            $observation,
            $rejectedSelectors,
            $requiredElementRefs,
        );

        if ($candidate === []) {
            return [];
        }

        $selector = (string) $candidate['selector'];
        $changes = $this->normalizeChanges($step, $task, [
            'selector' => $selector,
            'element_selector' => $selector,
        ], false, $this->observationDomains($observation));

        if (($changes['selector'] ?? null) !== $selector) {
            return [];
        }

        $taskKey = (string) ($task['key'] ?? '');
        $previousSelector = (string) $candidate['previous_selector'];

        $this->sessions->appendEvent(
            $session,
            'repair.selector_probe_applied',
            'Deterministische Selektor-Probe: Task `'.$taskKey.'` wird von `'.$previousSelector
                .'` auf den in der eigenen DOM-Beobachtung belegten Selector `'.$selector.'` aktualisiert.',
            [
                'task_key' => $taskKey,
                'task_catalog_key' => $taskCatalogKey,
                'failure_class' => $failureClass,
                'previous_selector' => $previousSelector,
                'new_selector' => $selector,
                'candidate_source' => 'dom_observation',
                'matches' => $candidate['matches'],
            ],
            'repairing',
            'info',
            true,
        );

        return [
            'action' => 'probe_update',
            'task_key' => $taskKey,
            'task_catalog_key' => $taskCatalogKey,
            'changes' => $changes,
            'probe_task' => array_replace($task, $changes, [
                'key' => $taskKey.'--copilot-probe',
                'title' => ($task['title'] ?? $taskKey).' (Copilot-Probe)',
            ]),
            'reason' => 'Der konfigurierte Selector fand kein Element ('.$failureClass.'). Die eigene DOM-Beobachtung'
                .' weist genau einen stabilen, rollen-gleichen Kandidaten aus; der Task wird deterministisch auf'
                .' diesen Selector aktualisiert und vor der Revision als Probe verifiziert.',
            'selector_candidates' => [$selector],
            'original_task_key' => $taskKey,
            'evidence' => [
                'class' => 'selector_probe',
                'previous_selector' => $previousSelector,
                'candidate_source' => 'dom_observation',
                'matches' => $candidate['matches'],
            ],
            'decision_trace' => [
                'source' => 'deterministic_selector_probe',
                'failure_class' => $failureClass,
                'previous_selector' => $previousSelector,
                'selected_selector' => $selector,
                'candidate_count' => (int) $candidate['candidate_count'],
                'expected_tags' => $candidate['expected_tags'],
            ],
            'planning_handoff' => [
                'planner_profile' => 'deterministic_selector_probe',
            ],
        ];
    }

    /**
     * @return list<string>
     */
    protected function trustedSelectorProbeElementRefs(array $task, array $vision, array $observation): array
    {
        if (! (bool) ($observation['evidence_sufficient'] ?? false)) {
            return [];
        }

        $trustedRefs = $this->trustedVisionElementRefs($vision, $observation);
        $taskCatalogKey = trim((string) ($task['task_key'] ?? ''));
        $taskKey = trim((string) ($task['key'] ?? ''));

        return collect($vision['suggested_task_actions'] ?? [])
            ->filter(function (mixed $action) use ($taskCatalogKey, $taskKey, $trustedRefs): bool {
                if (! is_array($action)) {
                    return false;
                }

                $candidateCatalogKey = trim((string) ($action['task_catalog_key'] ?? $action['task_key'] ?? ''));
                $candidateTaskKey = trim((string) ($action['card_key'] ?? $action['workflow_task_key'] ?? ''));
                $elementRef = trim((string) ($action['element_ref'] ?? $action['elementRef'] ?? ''));
                $confidence = $action['confidence'] ?? null;

                return $candidateCatalogKey === $taskCatalogKey
                    && $candidateTaskKey === $taskKey
                    && $elementRef !== ''
                    && isset($trustedRefs[$elementRef])
                    && is_numeric($confidence)
                    && (float) $confidence >= self::MIN_VISUAL_CONFIDENCE;
            })
            ->map(fn (array $action): string => trim((string) ($action['element_ref'] ?? $action['elementRef'] ?? '')))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $producer
     * @return array<string, mixed>
     */
    protected function collectionSelectorEvidenceForPlan(
        Workflow $workflow,
        WorkflowStep $targetStep,
        array $producer,
    ): array {
        $targetPosition = (int) $targetStep->position;
        $targetWindow = trim((string) ($producer['browser_window_name'] ?? $producer['browser_window'] ?? 'main')) ?: 'main';
        $candidates = [];

        foreach ($workflow->steps as $sourceStep) {
            if (! $sourceStep->is_enabled) {
                continue;
            }

            foreach ($sourceStep->task_cards as $sourceTask) {
                if (($sourceTask['task_key'] ?? null) !== 'wait.selector') {
                    continue;
                }

                $selector = trim((string) ($sourceTask['selector'] ?? $sourceTask['element_selector'] ?? ''));
                $score = $this->collectionSelectorScore($sourceStep, $sourceTask, $targetStep, $targetWindow);

                if (! $this->isSafeSelector($selector) || $score < 5) {
                    continue;
                }

                $candidates[] = [
                    'selector' => $selector,
                    'step_action_key' => (string) $sourceStep->action_key,
                    'task_key' => (string) ($sourceTask['key'] ?? ''),
                    'browser_window' => trim((string) ($sourceTask['browser_window_name'] ?? $sourceTask['browser_window'] ?? $targetWindow)) ?: $targetWindow,
                    'score' => $score,
                    'distance' => abs($targetPosition - (int) $sourceStep->position),
                ];
            }
        }

        usort($candidates, static function (array $left, array $right): int {
            return ((int) $right['score'] <=> (int) $left['score'])
                ?: ((int) $left['distance'] <=> (int) $right['distance']);
        });

        return $candidates[0] ?? [];
    }

    /**
     * @param  array<string, mixed>  $sourceTask
     */
    protected function collectionSelectorScore(
        WorkflowStep $sourceStep,
        array $sourceTask,
        WorkflowStep $targetStep,
        string $targetWindow,
    ): int {
        $selector = Str::lower(trim((string) ($sourceTask['selector'] ?? $sourceTask['element_selector'] ?? '')));
        $text = Str::lower(implode(' ', array_filter([
            $sourceStep->name,
            $sourceStep->action_key,
            $sourceTask['title'] ?? null,
            $sourceTask['description'] ?? null,
            $selector,
        ], static fn (mixed $value): bool => is_scalar($value))));
        $sourceWindow = trim((string) ($sourceTask['browser_window_name'] ?? $sourceTask['browser_window'] ?? 'main')) ?: 'main';
        $score = 0;

        if (preg_match('/(?:suchergebnis|ergebnis|treffer|result|product|produkt)/u', $text)) {
            $score += 8;
        }

        if (preg_match('/(?:search|suche)/u', $text)) {
            $score += 3;
        }

        if (preg_match('/(?:#search|data-rpos|result|product|item|\\.g(?:\\b|[.#:\\[]))/u', $selector)) {
            $score += 5;
        }

        if (preg_match('/(?:textarea|input|button|cookie|consent|\\bbody\\b)/u', $selector)) {
            $score -= 8;
        }

        if ((int) $sourceStep->position <= (int) $targetStep->position) {
            $score += 2;
        } else {
            $score -= 4;
        }

        if ($sourceWindow === $targetWindow) {
            $score += 2;
        }

        return $score;
    }

    /**
     * Revalidates deterministic selector provenance while the locked workflow
     * revision is being changed.
     *
     * @param  array<string, mixed>  $operation
     * @return array<string, mixed>
     */
    protected function configuredCollectionSelectorEvidence(
        Workflow $workflow,
        WorkflowStep $targetStep,
        array $operation,
    ): array {
        if (($operation['purpose'] ?? null) !== 'collection_dependency'
            || ($operation['task_catalog_key'] ?? null) !== 'loop.for_each_element') {
            return [];
        }

        $sourceStep = $workflow->steps()
            ->where('action_key', trim((string) ($operation['selector_source_step_action_key'] ?? '')))
            ->where('is_enabled', true)
            ->first();
        $sourceTaskKey = trim((string) ($operation['selector_source_task_key'] ?? ''));
        $sourceTask = $sourceStep
            ? collect($sourceStep->task_cards)->firstWhere('key', $sourceTaskKey)
            : null;
        $targetWindow = trim((string) data_get($operation, 'parameters.browser_window', 'main')) ?: 'main';
        $selector = is_array($sourceTask)
            ? trim((string) ($sourceTask['selector'] ?? $sourceTask['element_selector'] ?? ''))
            : '';

        if (! $sourceStep
            || ! is_array($sourceTask)
            || ($sourceTask['task_key'] ?? null) !== 'wait.selector'
            || ! $this->isSafeSelector($selector)
            || $this->collectionSelectorScore($sourceStep, $sourceTask, $targetStep, $targetWindow) < 5
            || ! hash_equals($selector, trim((string) ($operation['evidence_selector'] ?? '')))) {
            throw new \DomainException('Der konfigurierte Ergebnis-Selector der Collection-Reparatur ist nicht mehr gueltig oder eindeutig.');
        }

        return [
            'selector' => $selector,
            'browser_window' => trim((string) ($sourceTask['browser_window_name'] ?? $sourceTask['browser_window'] ?? $targetWindow)) ?: $targetWindow,
        ];
    }

    /**
     * @param  array<string, mixed>  $consumer
     * @param  array<string, mixed>  $producer
     */
    protected function collectionLimit(
        WorkflowCopilotSession $session,
        array $consumer,
        array $producer,
    ): int {
        foreach ([
            $consumer['max_items'] ?? null,
            $producer['max_items'] ?? null,
            data_get($session->workflow_inputs_json, 'search_count'),
            data_get($session->workflow_inputs_json, 'result_count'),
        ] as $candidate) {
            if (is_numeric($candidate) && (int) $candidate > 0) {
                return min(10000, (int) $candidate);
            }
        }

        $instructions = implode(' ', array_filter([
            $session->goal,
            json_encode($session->success_criteria_json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ], static fn (mixed $value): bool => is_scalar($value)));

        foreach ([
            '/\b(?:top|erste[nrsm]*|maximal)\s*[-:]?\s*(\d{1,4})\b/ui',
            '/\b(\d{1,4})\s*(?:such)?(?:treffer|ergebnisse|results)\b/ui',
        ] as $pattern) {
            if (preg_match($pattern, $instructions, $matches) === 1) {
                return min(10000, max(0, (int) $matches[1]));
            }
        }

        return 0;
    }

    /**
     * A valid explicit error route is executable workflow intent. Following it
     * is safer than replacing a failed selector with an unrelated visible
     * element from the current page.
     *
     * @param  array<string, mixed>  $task
     * @param  array<string, mixed>  $checkpoint
     * @return array<string, mixed>
     */
    protected function configuredFailureRoutePlan(
        WorkflowStep $step,
        array $task,
        array $checkpoint,
    ): array {
        if ((bool) ($checkpoint['successful'] ?? false)
            || (bool) data_get($checkpoint, 'result.irreversibleSideEffect', false)
            || (is_array(data_get($checkpoint, 'result.sideEffects'))
                && data_get($checkpoint, 'result.sideEffects') !== [])) {
            return [];
        }

        $outcome = Str::lower(trim((string) ($checkpoint['outcome'] ?? 'failed')));

        if (! in_array($outcome, ['failed', 'timeout'], true)) {
            return [];
        }

        $route = $task['on_error'] ?? data_get($task, 'status_routes.'.$outcome);

        if (! is_array($route)
            || ! $this->isValidRoute($step, $route)
            || $this->routeLoopsToSourceTask($step, $task, $route)) {
            return [];
        }

        $routeType = Str::lower(trim((string) ($route['type'] ?? '')));
        $target = Str::lower(trim((string) ($route['action_key'] ?? $route['step'] ?? '')));

        if ($routeType === 'fail' || $target === 'fail') {
            return [];
        }

        return [
            'action' => 'continue_route',
            'task_key' => (string) ($task['key'] ?? ''),
            'resume_checkpoint' => false,
            'configured_route' => $route,
            'reason' => 'Die fehlgeschlagene Task besitzt bereits eine gueltige konfigurierte Fehlerroute; sie wird vor einer unspezifischen Selektor-Probe ausgefuehrt.',
        ];
    }

    /**
     * A consent click is optional once reliable screenshot/DOM evidence shows
     * that the obstacle is no longer present. Retargeting that task to an
     * unrelated visible control would change its meaning.
     *
     * @param  array<string, mixed>  $task
     * @param  array<string, mixed>  $checkpoint
     * @return array<string, mixed>
     */
    protected function resolvedConsentObstaclePlan(
        WorkflowStep $step,
        array $task,
        array $checkpoint,
        array $observation,
        array $vision,
    ): array {
        $confidence = $vision['confidence'] ?? null;
        $outcome = Str::lower(trim((string) ($checkpoint['outcome'] ?? 'failed')));

        if (! $this->taskLooksLikeConsentClick($task)
            || (bool) ($checkpoint['successful'] ?? false)
            || ! in_array($outcome, ['failed', 'timeout'], true)
            || $this->consentBlocked($observation, $vision)
            || ! is_numeric($confidence)
            || (float) $confidence < self::MIN_VISUAL_CONFIDENCE
            || (bool) ($vision['safe_pause'] ?? false)
            || Str::lower(trim((string) ($vision['verdict'] ?? ''))) === 'pause'
            || ! (bool) ($observation['evidence_sufficient'] ?? false)) {
            return [];
        }

        $continuationRoute = $this->resolvedConsentContinuationRoute($step, $task);
        $operations = [];

        if (($task['next'] ?? null) !== $continuationRoute || ($task['on_error'] ?? null) !== $continuationRoute) {
            $operations[] = [
                'type' => 'update_task_routes',
                'step_action_key' => (string) $step->action_key,
                'task_key' => (string) ($task['key'] ?? ''),
                'changes' => [
                    'next' => $continuationRoute,
                    'on_error' => $continuationRoute,
                ],
            ];
        }

        $decisionTask = collect($step->task_cards)
            ->filter(fn (array $candidate): bool => (string) ($candidate['task_key'] ?? '') === 'decision.element_exists')
            ->filter(function (array $candidate) use ($task): bool {
                $target = trim((string) data_get($candidate, 'next.card_key', data_get($candidate, 'next.card', '')))
                    ?: trim((string) data_get($candidate, 'on_error.card_key', data_get($candidate, 'on_error.card', '')));

                return $target === (string) ($task['key'] ?? '')
                    || $this->taskLooksLikeConsentCondition($candidate);
            })
            ->sortByDesc(function (array $candidate) use ($task): int {
                $target = trim((string) data_get($candidate, 'next.card_key', data_get($candidate, 'next.card', '')))
                    ?: trim((string) data_get($candidate, 'on_error.card_key', data_get($candidate, 'on_error.card', '')));

                return $target === (string) ($task['key'] ?? '') ? 10 : 0;
            })
            ->first();

        if (is_array($decisionTask)) {
            $clickRoute = [
                'type' => 'card',
                'action_key' => (string) $step->action_key,
                'step' => (string) $step->action_key,
                'card_key' => (string) ($task['key'] ?? ''),
                'card' => (string) ($task['key'] ?? ''),
                'label' => trim((string) ($task['title'] ?? 'Consent behandeln')),
            ];

            if (($decisionTask['next'] ?? null) !== $clickRoute
                || ($decisionTask['on_error'] ?? null) !== $continuationRoute) {
                $operations[] = [
                    'type' => 'update_task_routes',
                    'step_action_key' => (string) $step->action_key,
                    'task_key' => (string) ($decisionTask['key'] ?? ''),
                    'changes' => [
                        'next' => $clickRoute,
                        'on_error' => $continuationRoute,
                    ],
                ];
            }
        }

        if ($operations !== []) {
            return [
                'action' => 'restart_with_workflow_changes',
                'task_key' => (string) ($task['key'] ?? ''),
                'reason' => 'Der Consent-Dialog ist nicht mehr sichtbar, aber die statischen IF-/Klick-Routen bilden eine Ruecksprungschleife. Gefunden fuehrt kuenftig zum Consent-Klick; nicht gefunden sowie ein bereits erledigter Klick fuehren zur normalen Workflow-Fortsetzung. Damit funktioniert derselbe Pfad auch im unveraenderlichen Kontrolllauf.',
                'operations' => array_slice($operations, 0, 4),
                'planning_handoff' => [
                    'vision_profile' => 'image_understanding',
                    'vision_model' => $vision['model'] ?? null,
                    'planner_profile' => 'deterministic_optional_obstacle_routing',
                ],
                'evidence' => [
                    'page_state' => data_get($observation, 'page.state'),
                    'dom_state' => data_get($observation, 'dom.ui_state'),
                    'vision_state' => $vision['ui_state'] ?? null,
                    'vision_confidence' => (float) $confidence,
                    'vision_verdict' => $vision['verdict'] ?? null,
                ],
            ];
        }

        return [
            'action' => 'skip_resolved_obstacle',
            'task_key' => (string) ($task['key'] ?? ''),
            'reason' => 'Der Consent-Task schlug fehl, aber die aktuelle Bild- und DOM-Evidenz zeigt keinen aktiven Consent-Dialog mehr. Das bereits erledigte Hindernis wird uebersprungen, ohne den Task auf ein fachlich fremdes Element umzubiegen.',
            'evidence' => [
                'page_state' => data_get($observation, 'page.state'),
                'dom_state' => data_get($observation, 'dom.ui_state'),
                'vision_state' => $vision['ui_state'] ?? null,
                'vision_confidence' => (float) $confidence,
                'vision_verdict' => $vision['verdict'] ?? null,
            ],
        ];
    }

    protected function resolvedConsentContinuationRoute(WorkflowStep $step, array $task): array
    {
        $taskNext = is_array($task['next'] ?? null) ? $task['next'] : null;

        if (is_array($taskNext)
            && ! $this->routeTargetsConsentClick($step, $taskNext)
            && ! $this->routeLoopsToSourceTask($step, $task, $taskNext)
            && Str::lower(trim((string) ($taskNext['type'] ?? ''))) !== 'fail') {
            return $taskNext;
        }

        $stepSuccess = data_get($step->config_json, 'routes.success');

        if (is_array($stepSuccess)
            && ! $this->routeTargetsConsentClick($step, $stepSuccess)
            && Str::lower(trim((string) ($stepSuccess['type'] ?? ''))) !== 'fail') {
            return $stepSuccess;
        }

        return [
            'type' => 'step',
            'action_key' => 'next',
            'step' => 'next',
            'label' => 'Naechste Liste',
        ];
    }

    protected function taskLooksLikeConsentCondition(array $task): bool
    {
        if (($task['task_key'] ?? null) !== 'decision.element_exists') {
            return false;
        }

        return $this->canonicalConsentAction(implode(' ', array_filter([
            $task['title'] ?? null,
            $task['description'] ?? null,
            $task['selector'] ?? null,
            $task['element_selector'] ?? null,
        ], static fn (mixed $value): bool => is_scalar($value)))) !== null;
    }

    /**
     * @param  array<string, mixed>  $task
     * @param  array<string, mixed>  $route
     */
    protected function routeLoopsToSourceTask(
        WorkflowStep $step,
        array $task,
        array $route,
    ): bool {
        $type = Str::lower(trim((string) ($route['type'] ?? '')));
        $targetStep = trim((string) ($route['action_key'] ?? $route['step'] ?? ''));
        $targetTask = trim((string) ($route['card_key'] ?? $route['card'] ?? ''));

        if ($type === '') {
            $type = $targetTask !== '' ? 'card' : 'step';
        }

        if ($type === 'card') {
            $sameStep = $targetStep === '' || $targetStep === $step->action_key;

            return $sameStep && $targetTask === trim((string) ($task['key'] ?? ''));
        }

        return $type === 'step' && $targetStep === $step->action_key;
    }

    protected function selectorCandidates(
        array $vision,
        array $observation,
        array $task,
        bool $requiresVisualTarget,
    ): \Illuminate\Support\Collection {
        $references = collect(array_keys($this->trustedVisionElementRefs($vision, $observation)));
        $elements = collect($observation['interaction_map'] ?? $observation['elements'] ?? [])
            ->filter(fn (mixed $element): bool => is_array($element)
                && ($element['visible'] ?? null) === true
                && ($element['enabled'] ?? true) !== false
                && $this->elementMatchesTaskIntent($task, $element))
            ->values();

        if ($requiresVisualTarget && $references->isEmpty()) {
            return collect();
        }

        if (! $requiresVisualTarget && $references->isEmpty() && $elements->count() !== 1) {
            return collect();
        }

        $candidates = $elements
            ->filter(function (mixed $element) use ($references): bool {
                if (! is_array($element)) {
                    return false;
                }

                if ($references->isEmpty()) {
                    return true;
                }

                $ref = trim((string) ($element['element_ref'] ?? $element['ref'] ?? $element['id'] ?? ''));

                return $references->contains($ref);
            })
            ->flatMap(function (array $element): array {
                return array_filter([
                    ...(is_array($element['selector_candidates'] ?? null) ? $element['selector_candidates'] : []),
                    $element['selector'] ?? null,
                ]);
            });

        return $candidates
            ->map(fn (mixed $selector): string => trim((string) $selector))
            ->filter(fn (string $selector): bool => $this->isSafeSelector($selector))
            ->unique()
            ->sortByDesc(fn (string $selector): int => $this->selectorStabilityPriority($selector))
            ->values();
    }

    protected function elementMatchesTaskIntent(array $task, array $element): bool
    {
        if (! $this->taskLooksLikeConsentClick($task)) {
            return true;
        }

        return $this->canonicalConsentAction(implode(' ', array_filter([
            $element['text'] ?? null,
            $element['aria'] ?? null,
            $element['name'] ?? null,
            ...(is_array($element['selector_candidates'] ?? null) ? $element['selector_candidates'] : []),
        ], static fn (mixed $value): bool => is_scalar($value)))) !== null;
    }

    protected function prioritizeSelectorsForInstructions(
        \Illuminate\Support\Collection $selectors,
        WorkflowCopilotSession $session,
    ): \Illuminate\Support\Collection {
        if ($selectors->count() < 2) {
            return $selectors;
        }

        $instructions = collect(data_get($session->state_json, 'active_instructions', []))
            ->filter(fn (mixed $instruction): bool => is_scalar($instruction))
            ->map(fn (mixed $instruction): string => Str::lower((string) $instruction))
            ->implode("\n");

        if (! preg_match('/\b(?:zweite[nsr]?|2\.|second)\b/u', $instructions)) {
            return $selectors;
        }

        $second = $selectors->get(1);

        return collect([$second])
            ->concat($selectors->reject(fn (string $selector): bool => $selector === $second))
            ->values();
    }

    protected function suggestedChange(
        array $vision,
        string $taskKey,
        array $definition,
        array $trustedElementRefs,
        bool $requiresVisualTarget,
    ): array {
        $taskCatalogKey = trim((string) ($definition['task_key'] ?? ''));
        $suggestion = collect($vision['suggested_task_actions'] ?? [])
            ->first(function (mixed $candidate) use ($taskKey, $taskCatalogKey, $trustedElementRefs, $requiresVisualTarget): bool {
                if (! is_array($candidate)) {
                    return false;
                }

                $candidateCatalogKey = trim((string) ($candidate['task_catalog_key'] ?? $candidate['task_key'] ?? ''));
                $candidateCardKey = trim((string) ($candidate['card_key'] ?? $candidate['workflow_task_key'] ?? ''));

                if ($candidateCatalogKey !== $taskCatalogKey || ($candidateCardKey !== '' && $candidateCardKey !== $taskKey)) {
                    return false;
                }

                if (! $requiresVisualTarget) {
                    return true;
                }

                $elementRef = trim((string) ($candidate['element_ref'] ?? $candidate['elementRef'] ?? ''));
                $confidence = $candidate['confidence'] ?? null;

                return $elementRef !== ''
                    && isset($trustedElementRefs[$elementRef])
                    && is_numeric($confidence)
                    && (float) $confidence >= self::MIN_VISUAL_CONFIDENCE;
            });

        if (! is_array($suggestion)) {
            return [];
        }

        $parameters = is_array($suggestion['parameters'] ?? null) ? $suggestion['parameters'] : [];

        foreach ($this->mutableFieldsForDefinition($definition) as $field) {
            if (array_key_exists($field, $suggestion)) {
                $parameters[$field] = $suggestion[$field];
            }
        }

        if ($taskCatalogKey === 'input.fill_field') {
            $valueReference = $parameters['value_reference'] ?? null;

            if (is_scalar($valueReference) && trim((string) $valueReference) !== '') {
                $parameters['value_source'] = 'workflow_variable';
                $parameters['workflow_variable'] = trim((string) $valueReference);
            }

            $fallbackValue = $parameters['fallback_value'] ?? null;

            if (is_scalar($fallbackValue) && trim((string) $fallbackValue) !== '') {
                $parameters['value_fallback'] = trim((string) $fallbackValue);
            }

            unset($parameters['value_reference'], $parameters['fallback_value']);
        }

        return $parameters;
    }

    protected function normalizeChanges(
        WorkflowStep $step,
        array $task,
        array $changes,
        bool $strictRoutes = false,
        array $contextDomains = [],
    ): array {
        $catalogKey = trim((string) ($task['task_key'] ?? ''));
        $definition = $catalogKey !== '' ? $this->catalog->task($catalogKey) : null;

        if ($definition === null) {
            return [];
        }

        $changes = Arr::only($changes, $this->mutableFieldsForDefinition($definition));
        $trustedDomains = $this->trustedWorkflowDomains($step, $task, $contextDomains);
        $proposedTargetDomain = $this->normalizeHost((string) ($changes['target_domain'] ?? ''));

        if ($trustedDomains === [] && $proposedTargetDomain !== null && $this->isSafeNetworkHost($proposedTargetDomain)) {
            $trustedDomains[] = $proposedTargetDomain;
        }

        $normalized = [];

        foreach ($changes as $key => $value) {
            if ($key === 'value_source') {
                $value = Str::lower(trim((string) $value));

                if (! in_array($value, ['fixed', 'workflow_variable'], true)) {
                    continue;
                }
            }

            if ($key === 'workflow_variable') {
                $value = trim((string) $value);

                if ($value === '' || preg_match('/^[A-Za-z0-9_.-]+$/', $value) !== 1) {
                    continue;
                }
            }

            if ($key === 'value_fallback') {
                if (! is_scalar($value)) {
                    continue;
                }

                $value = (string) $value;
            }

            if ($this->isSelectorField($key, $definition)) {
                $value = trim((string) $value);

                if (! $this->isSafeSelector($value)) {
                    continue;
                }
            }

            if ($key === 'timeout_seconds') {
                $value = max(0, min(3600, (int) $value));
            }

            if ($key === 'url') {
                $value = trim((string) $value);

                if (! $this->isSafeUrlConfiguration($value, $trustedDomains)) {
                    continue;
                }
            }

            if ($key === 'target_domain') {
                $value = $this->normalizeHost((string) $value);

                if ($value === null
                    || ! $this->isSafeNetworkHost($value)
                    || ($trustedDomains !== [] && ! $this->hostMatchesTrustedDomains($value, $trustedDomains))) {
                    continue;
                }
            }

            if (in_array($key, self::ROUTE_FIELDS, true) && ! $this->isValidRouteChange($step, $key, $value)) {
                if ($strictRoutes) {
                    throw new \DomainException('Die Reparatur enthaelt fuer `'.$key.'` eine ungueltige oder nicht aufloesbare Workflow-Route.');
                }

                continue;
            }

            if (($task[$key] ?? null) !== $value) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

    protected function taskSupportsSelector(array $definition): bool
    {
        return data_get($definition, 'form.selector') === true;
    }

    protected function mutableFieldsForDefinition(array $definition): array
    {
        $form = is_array($definition['form'] ?? null) ? $definition['form'] : [];
        $fields = self::SHARED_MUTABLE_FIELDS;

        if (($form['selector'] ?? false) === true) {
            $fields = [...$fields, 'selector', 'element_selector', 'input_selector'];
        }

        if (($form['value'] ?? false) === true) {
            $fields = [...$fields, 'value', 'input'];
        }

        if (($form['url'] ?? false) === true) {
            $fields[] = 'url';
        }

        if (($form['browser_window'] ?? false) === true) {
            $fields = [...$fields, 'browser_window', 'browser_window_name'];
        }

        foreach (is_array($form['extra_fields'] ?? null) ? $form['extra_fields'] : [] as $field) {
            if (! is_array($field)) {
                continue;
            }

            $name = preg_replace('/[^A-Za-z0-9_.-]+/', '', (string) ($field['name'] ?? '')) ?: '';

            if ($name !== '') {
                $fields[] = $name;
            }
        }

        return array_values(array_unique($fields));
    }

    protected function isSelectorField(string $field, array $definition): bool
    {
        if (in_array($field, ['selector', 'element_selector', 'input_selector'], true)) {
            return true;
        }

        $extraFields = collect(data_get($definition, 'form.extra_fields', []));

        return $extraFields->contains(function (mixed $extra) use ($field): bool {
            return is_array($extra)
                && (string) ($extra['name'] ?? '') === $field
                && (str_ends_with($field, '_selector') || str_ends_with($field, '_selectors'));
        });
    }

    protected function isValidRouteChange(WorkflowStep $sourceStep, string $field, mixed $value): bool
    {
        if (! is_array($value)) {
            return false;
        }

        if ($field !== 'status_routes') {
            return $this->isValidRoute($sourceStep, $value);
        }

        foreach ($value as $outcome => $route) {
            if (! is_string($outcome) || trim($outcome) === '' || ! is_array($route) || ! $this->isValidRoute($sourceStep, $route)) {
                return false;
            }
        }

        return true;
    }

    protected function isValidRoute(WorkflowStep $sourceStep, array $route): bool
    {
        $explicitType = strtolower(trim((string) ($route['type'] ?? '')));
        $actionKey = trim((string) ($route['action_key'] ?? ''));
        $stepKey = trim((string) ($route['step'] ?? ''));
        $cardKey = trim((string) ($route['card_key'] ?? $route['card'] ?? ''));

        if ($actionKey !== '' && $stepKey !== '' && $actionKey !== $stepKey) {
            return false;
        }

        $targetStepKey = $actionKey !== '' ? $actionKey : $stepKey;
        $type = $explicitType;

        if ($type === '') {
            $type = $cardKey !== ''
                ? 'card'
                : (in_array($targetStepKey, ['end', 'fail'], true) ? $targetStepKey : 'step');
        }

        if (! in_array($type, self::ROUTE_TYPES, true)) {
            return false;
        }

        if (in_array($type, ['end', 'fail'], true)) {
            return $cardKey === '' && ($targetStepKey === '' || $targetStepKey === $type);
        }

        if ($type === 'step') {
            if ($cardKey !== '' || $targetStepKey === '') {
                return false;
            }

            if ($targetStepKey === 'next') {
                return true;
            }

            return WorkflowStep::query()
                ->where('workflow_id', $sourceStep->workflow_id)
                ->where('action_key', $targetStepKey)
                ->exists();
        }

        if ($cardKey === '' || in_array($targetStepKey, ['next', 'end', 'fail'], true)) {
            return false;
        }

        $targetStep = $targetStepKey === ''
            ? $sourceStep
            : WorkflowStep::query()
                ->where('workflow_id', $sourceStep->workflow_id)
                ->where('action_key', $targetStepKey)
                ->first();

        return $targetStep instanceof WorkflowStep
            && collect($targetStep->task_cards)->contains(
                fn (array $task): bool => (string) ($task['key'] ?? '') === $cardKey,
            );
    }

    protected function sessionIsVerifying(WorkflowCopilotSession $session): bool
    {
        return WorkflowCopilotSession::query()
            ->whereKey($session->getKey())
            ->where('status', WorkflowCopilotSession::STATUS_VERIFYING)
            ->exists();
    }

    protected function isSafeSelector(string $selector): bool
    {
        return $this->selectorProbes->isSafeSelector($selector);
    }

    protected function isSafeUrlConfiguration(string $url, array $trustedDomains): bool
    {
        if ($url === '' || mb_strlen($url) > 4096 || preg_match('/[\r\n\x00]/', $url)) {
            return false;
        }

        if (preg_match('/^\s*(?:javascript|data|file|vbscript):/i', $url) || preg_match('/<script/i', $url)) {
            return false;
        }

        if ($url === 'about:blank') {
            return true;
        }

        if (str_starts_with($url, '//')) {
            return false;
        }

        $parts = parse_url($url);

        if (! is_array($parts)) {
            return false;
        }

        $scheme = strtolower(trim((string) ($parts['scheme'] ?? '')));

        if ($scheme === '') {
            return ! str_contains($url, '\\') && ! str_contains($url, '@');
        }

        if (! in_array($scheme, ['http', 'https'], true)
            || isset($parts['user'])
            || isset($parts['pass'])
            || ! isset($parts['host'])) {
            return false;
        }

        $host = $this->normalizeHost((string) $parts['host']);

        if ($host === null
            || ! $this->isSafeNetworkHost($host)
            || ! $this->hostMatchesTrustedDomains($host, $trustedDomains)) {
            return false;
        }

        $port = $parts['port'] ?? null;

        return $port === null || ((int) $port >= 1 && (int) $port <= 65535);
    }

    protected function trustedVisionElementRefs(array $vision, array $observation): array
    {
        $confidence = $vision['confidence'] ?? null;

        if (! is_numeric($confidence)
            || (float) $confidence < self::MIN_VISUAL_CONFIDENCE
            || (bool) ($vision['safe_pause'] ?? false)
            || Str::lower(trim((string) ($vision['verdict'] ?? ''))) === 'pause') {
            return [];
        }

        $observedElements = collect($observation['interaction_map'] ?? $observation['elements'] ?? [])
            ->filter(fn (mixed $element): bool => is_array($element))
            ->keyBy(fn (array $element): string => trim((string) ($element['element_ref'] ?? $element['ref'] ?? '')));
        $trusted = [];

        foreach ($vision['relevant_elements'] ?? [] as $element) {
            if (! is_array($element)) {
                continue;
            }

            $ref = trim((string) ($element['element_ref'] ?? $element['elementRef'] ?? ''));
            $elementConfidence = $element['confidence'] ?? null;
            $observed = $observedElements->get($ref);

            if (! preg_match('/^(?:el|element|node)[_.:-][A-Za-z0-9_.:-]{1,70}$/', $ref)
                || ! is_numeric($elementConfidence)
                || (float) $elementConfidence < self::MIN_VISUAL_CONFIDENCE
                || ! is_array($observed)
                || ($observed['visible'] ?? null) !== true
                || ($observed['enabled'] ?? true) === false
                || collect($observed['selector_candidates'] ?? [])
                    ->filter(fn (mixed $selector): bool => is_string($selector) && $this->isSafeSelector(trim($selector)))
                    ->isEmpty()) {
                continue;
            }

            $trusted[$ref] = $observed;
        }

        return $trusted;
    }

    protected function taskRequiresVisualTarget(string $taskCatalogKey): bool
    {
        return in_array($taskCatalogKey, self::TASKS_REQUIRING_VISUAL_TARGET, true);
    }

    protected function unsafeVisualTargetPlan(string $taskKey): array
    {
        return $this->pausePlan(
            $taskKey,
            'Eine zustandsveraendernde Probe braucht eine sichtbare, eindeutig zugeordnete Vision-Elementreferenz mit ausreichender Konfidenz.',
        );
    }

    protected function safeReason(mixed $reason): string
    {
        $safe = $this->observations->sanitizeForModel($reason);

        return Str::limit(trim(is_scalar($safe) ? (string) $safe : ''), 1000, '')
            ?: 'Keine sichere autonome Reparatur gefunden.';
    }

    protected function observationDomains(array $observation): array
    {
        $urls = [data_get($observation, 'page.url')];

        foreach ($observation['browser_windows'] ?? [] as $window) {
            if (is_array($window)) {
                $urls[] = $window['url'] ?? null;
            }
        }

        return collect($urls)
            ->filter(fn (mixed $url): bool => is_string($url))
            ->map(fn (string $url): ?string => $this->normalizeHost((string) parse_url($url, PHP_URL_HOST)))
            ->filter(fn (?string $host): bool => $host !== null && $this->isSafeNetworkHost($host))
            ->unique()
            ->values()
            ->all();
    }

    protected function trustedWorkflowDomains(WorkflowStep $step, array $task, array $contextDomains): array
    {
        $domains = [];

        foreach ($contextDomains as $domain) {
            $this->appendTrustedDomain($domains, $domain);
        }

        $this->collectTrustedDomains($task, $domains);
        $workflow = Workflow::query()->find($step->workflow_id);

        if ($workflow) {
            $this->collectTrustedDomains($workflow->settings_json, $domains);

            foreach ($workflow->steps()->get(['config_json']) as $workflowStep) {
                $this->collectTrustedDomains($workflowStep->config_json, $domains);
            }
        }

        return array_values(array_unique($domains));
    }

    protected function collectTrustedDomains(mixed $value, array &$domains, string $parentKey = ''): void
    {
        if (! is_array($value)) {
            if (! is_string($value)) {
                return;
            }

            $key = Str::lower($parentKey);

            if ($key === 'url' || str_ends_with($key, '_url')) {
                $this->appendTrustedDomain($domains, parse_url($value, PHP_URL_HOST));
            } elseif ($key === 'domain' || $key === 'host' || str_ends_with($key, '_domain') || str_ends_with($key, '_host')) {
                $this->appendTrustedDomain($domains, $value);
            }

            return;
        }

        foreach ($value as $key => $item) {
            $this->collectTrustedDomains($item, $domains, (string) $key);
        }
    }

    protected function appendTrustedDomain(array &$domains, mixed $value): void
    {
        if (! is_scalar($value)) {
            return;
        }

        $host = $this->normalizeHost((string) $value);

        if ($host !== null && $this->isSafeNetworkHost($host)) {
            $domains[] = $host;
        }
    }

    protected function normalizeHost(string $host): ?string
    {
        $host = Str::lower(rtrim(trim($host), '.'));

        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }

        if ($host === '' || mb_strlen($host) > 253 || str_contains($host, '%')) {
            return null;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return $host;
        }

        return preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])$/', $host)
            ? $host
            : null;
    }

    protected function isSafeNetworkHost(string $host): bool
    {
        $host = $this->normalizeHost($host);

        if ($host === null
            || preg_match('/^(?:localhost|metadata|instance-data)(?:\.|$)/', $host)
            || preg_match('/(?:^|\.)(?:localhost|local|internal|lan|home|corp)$/', $host)
            || in_array($host, ['metadata.google.internal', 'metadata.azure.internal', '169.254.169.254'], true)
            || preg_match('/^(?:0x[0-9a-f]+|[0-9]+)$/i', $host)) {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return filter_var(
                $host,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
            ) !== false;
        }

        // DNS answers are checked when available. An unresolved hostname is not
        // promoted to trusted on its own; it still has to match workflow scope.
        $addresses = @gethostbynamel($host);

        if (is_array($addresses)) {
            foreach ($addresses as $address) {
                if (filter_var(
                    $address,
                    FILTER_VALIDATE_IP,
                    FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
                ) === false) {
                    return false;
                }
            }
        }

        return true;
    }

    protected function preferredSelectorFrom(array $selectors): ?string
    {
        $selector = collect($selectors)
            ->map(fn (mixed $candidate): string => trim((string) $candidate))
            ->filter(fn (string $candidate): bool => $this->isSafeSelector($candidate))
            ->unique()
            ->sortByDesc(fn (string $candidate): int => $this->selectorStabilityPriority($candidate))
            ->first();

        return is_string($selector) && $selector !== '' ? $selector : null;
    }

    protected function selectorStabilityPriority(string $selector): int
    {
        return $this->selectorProbes->stabilityPriority($selector);
    }

    protected function hostMatchesTrustedDomains(string $host, array $trustedDomains): bool
    {
        if ($trustedDomains === []) {
            return false;
        }

        $scope = $this->domainScope($host);

        foreach ($trustedDomains as $trustedDomain) {
            $trustedDomain = $this->normalizeHost((string) $trustedDomain);

            if ($trustedDomain === null) {
                continue;
            }

            if ($host === $trustedDomain
                || str_ends_with($host, '.'.$trustedDomain)
                || str_ends_with($trustedDomain, '.'.$host)
                || ($scope !== null && $scope === $this->domainScope($trustedDomain))) {
                return true;
            }
        }

        return false;
    }

    protected function domainScope(string $host): ?string
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return $host;
        }

        $parts = explode('.', $host);

        if (count($parts) < 2) {
            return null;
        }

        $lastTwo = implode('.', array_slice($parts, -2));
        $commonSecondLevelSuffixes = ['co.uk', 'org.uk', 'com.au', 'net.au', 'co.nz', 'co.jp', 'com.br'];

        if (in_array($lastTwo, $commonSecondLevelSuffixes, true) && count($parts) >= 3) {
            return implode('.', array_slice($parts, -3));
        }

        return $lastTwo;
    }

    protected function plannerPrompt(
        WorkflowCopilotSession $session,
        WorkflowStep $step,
        array $task,
        array $checkpoint,
        array $observation,
        array $vision,
    ): string {
        $workflow = Workflow::query()
            ->with(['steps' => fn ($query) => $query->ordered()])
            ->find($step->workflow_id);
        $workflowContext = $workflow
            ? $this->promptContexts->forWorkflow($workflow, $session, $step, $checkpoint)
            : [
                'execution_contract' => $this->promptContexts->executionContract(),
                'workflow_structure' => $this->promptContexts->workflowStructureDocumentation(),
                'workflow_task_catalog' => $this->promptContexts->taskCatalogSnapshot(),
            ];
        $workflowStructure = data_get($workflowContext, 'workflow.steps', []);
        $catalog = data_get($workflowContext, 'workflow_task_catalog', []);
        $payload = $this->observations->sanitizeForModel([
            'goal' => $session->goal,
            'success_criteria' => $session->success_criteria_json,
            'active_user_instructions' => array_slice(
                is_array(data_get($session->state_json, 'active_instructions'))
                    ? data_get($session->state_json, 'active_instructions')
                    : [],
                -20,
            ),
            'step' => ['id' => $step->id, 'name' => $step->name, 'action_key' => $step->action_key],
            'task' => Arr::except($task, ['value', 'input']),
            'workflow_context' => $workflowContext,
            'workflow_structure' => $workflowStructure,
            'current_step_execution' => $this->stepExecutionTrace($step, $checkpoint),
            'failure' => Arr::only($checkpoint, ['outcome', 'result', 'task_key']),
            'observation' => Arr::except($observation, ['screenshot_data_url', 'raw_dom', 'html']),
            'vision' => $vision,
            'allowed_actions' => ['retry', 'update_task', 'continue_route', 'structural_update', 'pause'],
            'structural_operations' => [
                'insert_task' => [
                    'fields' => ['type', 'step_action_key', 'task_catalog_key', 'title', 'description', 'parameters', 'insert_position', 'element_ref'],
                    'constraint' => 'Kataloggebundene Tasks nicht duplizieren. Fuer Tasks mit sichtbarem Ziel ist eine von Vision vorgeschlagene trusted element_ref Pflicht; Selector und Browserfenster werden serverseitig aus DOM-Evidenz abgeleitet. loop.for_each_element erzeugt sein Loop-Ende automatisch und kann Reader-Ausgaben mit collect_to_array sammeln. Fuer input.fill_field setzt parameters.value_reference eine Workflow-Variable; optional definiert parameters.fallback_value den festen Ersatzwert.',
                ],
                'move_task' => [
                    'fields' => ['type', 'step_action_key', 'task_key', 'insert_position'],
                    'constraint' => 'Verschiebt eine bereits konfigurierte Task innerhalb derselben Liste. Loop-Paare bleiben atomar gekoppelt.',
                ],
                'update_step_routes' => [
                    'fields' => ['type', 'step_action_key', 'routes'],
                    'constraint' => 'routes enthaelt nur success|failed|timeout|partial und bestehende step/card/end/fail-Ziele.',
                ],
                'update_task_routes' => [
                    'fields' => ['type', 'step_action_key', 'task_key', 'changes'],
                    'constraint' => 'changes enthaelt nur next|on_partial|on_error|status_routes und bestehende Ziele.',
                ],
            ],
            'workflow_task_catalog' => $catalog,
            'trusted_vision_element_refs' => array_keys($this->trustedVisionElementRefs($vision, $observation)),
            'mutable_fields' => $this->mutableFieldsForDefinition(
                $this->catalog->task((string) ($task['task_key'] ?? '')) ?? [],
            ),
        ]);

        return 'Waehle die kleinste sichere Reparatur, die den Workflow autonom weiter zum Ziel bringt. '
            .'Lies zuerst execution_contract, workflow_structure, workflow_diagnostics, den vollstaendigen workflow_task_catalog und danach den aktuellen Workflow-Graph. '
            .'Als configured_but_not_executed markierte Tasks sind vorhanden und wurden nur noch nicht ausgefuehrt; fuege sie nicht doppelt ein, sondern repariere zuerst Fortsetzung, Reihenfolge oder Routen. '
            .'Wenn der aktuelle Bildschirm Folge fehlender oder falsch gerouteter Workflow-Logik ist, verwende structural_update statt pause. '
            .'Eine optionale IF-Pruefung braucht getrennte Found-/Not-Found-Routen; ein Handler darf bei bereits verschwundenem Hindernis nicht zur IF-Pruefung zurueckspringen. '
            .'Verwende type=fail nur, wenn der gesamte Workflow bewusst terminal scheitern soll. Behebbare Fehler routen zu einer vorhandenen card oder einem step. '
            .'Fuer input.fill_field setzt eine Workflow-Variable immer gemeinsam changes.value_source=workflow_variable und changes.workflow_variable; ein fester Wert setzt changes.value_source=fixed sowie changes.value und changes.input. '
            .'loop.for_each_element ist ausschliesslich ein nicht-visueller Kontroll-Loop mit iteration_count oder source_array. Er darf niemals Selector-, DOM-, Limit- oder Sammelparameter erhalten. Suchtrefferlisten werden direkt mit browser.read_searchengine_result, list_container_selector, list_item_selector und output_array_name gelesen. '
            .'Schema: {"action":"retry|update_task|continue_route|structural_update|pause","element_ref":"el_... oder leer","changes":{},"operations":[],"reason":"konkreter Befund"}. '
            .'Nach structural_update wird der Workflow revisioniert von Anfang an getestet. Die Aenderung muss danach auch im unveraenderlichen Kontrolllauf ohne Copilot-Skip funktionieren. '
            .'Zustandsveraendernde Tasks und entsprechende insert_task-Operationen duerfen nur eine passende trusted_vision_element_ref verwenden; deren Selector wird nicht vom Modell uebernommen. '
            .'Daten: '.json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    protected function normalizeStructuralOperations(
        WorkflowStep $currentStep,
        array $operations,
        array $observation,
        array $vision,
        array &$rejections,
    ): array {
        $workflow = Workflow::query()
            ->with(['steps' => fn ($query) => $query->ordered()])
            ->find($currentStep->workflow_id);

        if (! $workflow) {
            $this->recordStructuralRejection(
                $rejections,
                null,
                '',
                'workflow_not_found',
                'Der Workflow fuer die vorgeschlagene Strukturaenderung wurde nicht gefunden.',
            );

            return [];
        }

        $normalized = [];
        $contextDomains = $this->observationDomains($observation);
        $operationList = collect($operations)->values();

        $legacyDomLoopOperation = $operationList->search(function (mixed $operation): bool {
            if (! is_array($operation)
                || Str::lower(trim((string) ($operation['type'] ?? ''))) !== 'insert_task'
                || trim((string) ($operation['task_catalog_key'] ?? $operation['task_key'] ?? '')) !== 'loop.for_each_element') {
                return false;
            }

            $parameters = is_array($operation['parameters'] ?? null) ? $operation['parameters'] : [];

            return collect([
                'selector',
                'element_selector',
                'input_selector',
                'store_current_element_as',
                'collect_to_array',
                'limit',
                'offset',
                'only_visible',
            ])->contains(fn (string $field): bool => array_key_exists($field, $parameters));
        });

        if ($legacyDomLoopOperation !== false) {
            $this->recordStructuralRejection(
                $rejections,
                (int) $legacyDomLoopOperation,
                'insert_task',
                'loop_dom_parameters_forbidden',
                'Loop-Start ist reiner Kontrollfluss und darf keine DOM-, Selector- oder Sammelparameter erhalten. Suchlisten muessen direkt durch browser.read_searchengine_result gelesen werden.',
            );

            return [];
        }

        if ($operationList->count() > 4) {
            $this->recordStructuralRejection(
                $rejections,
                4,
                '',
                'operation_limit',
                'Mehr als vier Strukturaenderungen in einer Reparaturrunde sind nicht erlaubt.',
            );
        }

        foreach ($operationList->take(4) as $index => $operation) {
            if (! is_array($operation)) {
                $this->recordStructuralRejection(
                    $rejections,
                    (int) $index,
                    '',
                    'invalid_operation',
                    'Die vorgeschlagene Strukturaenderung ist kein gueltiges Objekt.',
                );

                continue;
            }

            $type = Str::lower(trim((string) ($operation['type'] ?? '')));

            if (! in_array($type, self::STRUCTURAL_OPERATION_TYPES, true)) {
                $this->recordStructuralRejection(
                    $rejections,
                    (int) $index,
                    $type,
                    'operation_type_not_allowed',
                    'Der Operationstyp `'.($type ?: 'leer').'` ist nicht erlaubt.',
                );

                continue;
            }

            if ($type === 'insert_step') {
                $this->recordStructuralRejection(
                    $rejections,
                    (int) $index,
                    $type,
                    'model_step_insertion_blocked',
                    'Neue Listen duerfen waehrend einer Reparatur nur durch eine deterministische, evidenzgebundene Spezialreparatur erzeugt werden.',
                );

                continue;
            }

            $targetStep = $this->structuralTargetStep($workflow, $operation);

            if (! $targetStep) {
                $this->recordStructuralRejection(
                    $rejections,
                    (int) $index,
                    $type,
                    'target_step_not_found',
                    'Die angegebene Ziel-Liste existiert im aktuellen Workflow nicht.',
                );

                continue;
            }

            if ($type === 'move_task') {
                $taskKey = trim((string) ($operation['task_key'] ?? ''));
                $task = collect($targetStep->task_cards)->firstWhere('key', $taskKey);

                if (! is_array($task)) {
                    $this->recordStructuralRejection(
                        $rejections,
                        (int) $index,
                        $type,
                        'target_task_not_found',
                        'Die zu verschiebende Task existiert in der Ziel-Liste nicht.',
                    );

                    continue;
                }

                $normalized[] = [
                    'type' => $type,
                    'step_action_key' => (string) $targetStep->action_key,
                    'task_key' => $taskKey,
                    'insert_position' => min(
                        max(0, (int) ($operation['insert_position'] ?? 0)),
                        count($targetStep->task_cards),
                    ),
                ];

                continue;
            }

            if ($type === 'update_step_routes') {
                $routes = [];

                foreach (is_array($operation['routes'] ?? null) ? $operation['routes'] : [] as $outcome => $route) {
                    $outcome = Str::lower(trim((string) $outcome));

                    if (in_array($outcome, self::STRUCTURAL_STEP_ROUTE_OUTCOMES, true)
                        && is_array($route)
                        && $this->isValidRoute($targetStep, $route)
                        && data_get($targetStep->config_json, 'routes.'.$outcome) !== $route) {
                        $routes[$outcome] = $route;
                    }
                }

                if ($routes !== []) {
                    $normalized[] = [
                        'type' => $type,
                        'step_action_key' => (string) $targetStep->action_key,
                        'routes' => $routes,
                    ];
                } else {
                    $this->recordStructuralRejection(
                        $rejections,
                        (int) $index,
                        $type,
                        'no_valid_route_change',
                        'Die vorgeschlagenen Listen-Routen sind ungueltig, nicht aufloesbar oder bereits genauso konfiguriert.',
                    );
                }

                continue;
            }

            if ($type === 'update_task_routes') {
                $taskKey = trim((string) ($operation['task_key'] ?? ''));
                $task = collect($targetStep->task_cards)->firstWhere('key', $taskKey);

                if (! is_array($task)) {
                    $this->recordStructuralRejection(
                        $rejections,
                        (int) $index,
                        $type,
                        'target_task_not_found',
                        'Die Task fuer die vorgeschlagene Routen-Aenderung existiert nicht.',
                    );

                    continue;
                }

                $changes = Arr::only(
                    is_array($operation['changes'] ?? null) ? $operation['changes'] : [],
                    self::ROUTE_FIELDS,
                );
                try {
                    $changes = $this->normalizeChanges($targetStep, $task, $changes, true, $contextDomains);
                } catch (\DomainException $exception) {
                    $this->recordStructuralRejection(
                        $rejections,
                        (int) $index,
                        $type,
                        'invalid_task_route',
                        $this->safeReason($exception->getMessage()),
                    );

                    continue;
                }
                $changes = Arr::only($changes, self::ROUTE_FIELDS);

                if ($changes !== []) {
                    $normalized[] = [
                        'type' => $type,
                        'step_action_key' => (string) $targetStep->action_key,
                        'task_key' => $taskKey,
                        'changes' => $changes,
                    ];
                } else {
                    $this->recordStructuralRejection(
                        $rejections,
                        (int) $index,
                        $type,
                        'no_task_route_change',
                        'Die vorgeschlagenen Task-Routen enthalten keine neue gueltige Aenderung.',
                    );
                }

                continue;
            }

            $catalogKey = trim((string) ($operation['task_catalog_key'] ?? $operation['task_key'] ?? ''));
            $definition = $catalogKey !== '' ? $this->catalog->task($catalogKey) : null;

            if (! $definition) {
                $this->recordStructuralRejection(
                    $rejections,
                    (int) $index,
                    $type,
                    'catalog_task_not_found',
                    'Der einzufuegende Task-Key `'.($catalogKey ?: 'leer').'` ist nicht im WorkflowTaskCatalog registriert.',
                );

                continue;
            }

            if ($catalogKey === 'loop.end') {
                $this->recordStructuralRejection(
                    $rejections,
                    (int) $index,
                    $type,
                    'loop_pair_requires_atomic_repair',
                    'Ein Loop-Ende darf nicht einzeln eingefuegt werden; es wird automatisch mit loop.for_each_element erzeugt.',
                );

                continue;
            }

            $visualTarget = [];

            if ($this->taskRequiresVisualTarget($catalogKey)) {
                $visualTarget = $this->trustedStructuralVisualTarget($catalogKey, $operation, $vision, $observation);

                if ($visualTarget === []) {
                    $this->recordStructuralRejection(
                        $rejections,
                        (int) $index,
                        $type,
                        'visual_target_not_trusted',
                        'Der visuell zielgebundene Task `'.$catalogKey.'` besitzt keine passende, ausreichend sichere Vision- und DOM-Elementreferenz.',
                    );

                    continue;
                }
            }

            $title = Str::limit(trim((string) ($operation['title'] ?? $definition['label'] ?? $catalogKey)), 180, '');
            $cardKey = $this->uniqueStructuralTaskKey($targetStep, $title ?: $catalogKey);
            $baseCard = $this->catalog->cardFromDefinition($catalogKey, [
                'key' => $cardKey,
                'title' => $title,
                'description' => Str::limit(trim((string) ($operation['description'] ?? $definition['description'] ?? '')), 1000, ''),
            ]);
            $parameters = is_array($operation['parameters'] ?? null) ? $operation['parameters'] : [];
            $allowedFields = $this->mutableFieldsForDefinition($definition);

            if ($visualTarget !== []) {
                $parameters = Arr::except($parameters, ['selector', 'element_selector', 'input_selector', 'browser_window', 'browser_window_name']);
                $parameters['selector'] = $visualTarget['selector'];
                $parameters['element_selector'] = $visualTarget['selector'];
                $parameters['input_selector'] = $visualTarget['selector'];

                if ($catalogKey === 'input.fill_field') {
                    $valueReference = data_get($parameters, 'value_reference')
                        ?? data_get($visualTarget, 'suggested_parameters.value_reference');

                    if (is_scalar($valueReference) && trim((string) $valueReference) !== '') {
                        $parameters['value_source'] = 'workflow_variable';
                        $parameters['workflow_variable'] = trim((string) $valueReference);
                    }

                    $fallbackValue = data_get($parameters, 'fallback_value')
                        ?? data_get($visualTarget, 'suggested_parameters.fallback_value');

                    if (is_scalar($fallbackValue) && trim((string) $fallbackValue) !== '') {
                        $parameters['value_fallback'] = trim((string) $fallbackValue);
                    }

                    unset($parameters['value_reference'], $parameters['fallback_value']);
                }
            }

            if ($catalogKey === 'loop.for_each_element') {
                unset($parameters['success_target'], $parameters['empty_target']);
            }

            $parameters = Arr::only($parameters, $allowedFields);

            $normalizedParameters = $this->normalizeChanges(
                $targetStep,
                $baseCard,
                $parameters,
                true,
                $contextDomains,
            );
            $invalidParameter = collect($parameters)->contains(function (mixed $value, string $key) use ($baseCard, $normalizedParameters): bool {
                return ($baseCard[$key] ?? null) !== $value && ! array_key_exists($key, $normalizedParameters);
            });

            if ($invalidParameter) {
                $this->recordStructuralRejection(
                    $rejections,
                    (int) $index,
                    $type,
                    'invalid_task_parameters',
                    'Mindestens ein vorgeschlagener Taskparameter ist fuer `'.$catalogKey.'` nicht erlaubt oder nicht sicher.',
                );

                continue;
            }

            if ($this->structuralTaskAlreadyExists($targetStep, $catalogKey, $normalizedParameters)) {
                $this->recordStructuralRejection(
                    $rejections,
                    (int) $index,
                    $type,
                    'task_already_configured',
                    'Ein gleichartiger Task `'.$catalogKey.'` mit demselben Ziel ist in der Ziel-Liste bereits konfiguriert.',
                );

                continue;
            }

            $normalizedOperation = [
                'type' => $type,
                'step_action_key' => (string) $targetStep->action_key,
                'task_catalog_key' => $catalogKey,
                'card_key' => $cardKey,
                'title' => $title,
                'description' => Str::limit(trim((string) ($operation['description'] ?? $definition['description'] ?? '')), 1000, ''),
                'parameters' => $normalizedParameters,
                'insert_position' => min(
                    max(0, (int) ($operation['insert_position'] ?? count($targetStep->task_cards))),
                    count($targetStep->task_cards),
                ),
            ];

            if ($visualTarget !== []) {
                $normalizedOperation['element_ref'] = $visualTarget['element_ref'];
                $normalizedOperation['evidence_selector'] = $visualTarget['selector'];
                $normalizedOperation['evidence_window'] = $visualTarget['window'];
            }

            $normalized[] = $normalizedOperation;
        }

        return $normalized;
    }

    protected function recordStructuralRejection(
        array &$rejections,
        ?int $index,
        string $type,
        string $reasonCode,
        string $message,
    ): void {
        $rejections[] = array_filter([
            'index' => $index,
            'type' => $type !== '' ? $type : null,
            'reason_code' => $reasonCode,
            'message' => $this->safeReason($message),
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /** @return array<string, mixed> */
    protected function trustedStructuralVisualTarget(
        string $catalogKey,
        array $operation,
        array $vision,
        array $observation,
    ): array {
        $elementRef = trim((string) ($operation['element_ref'] ?? $operation['elementRef'] ?? ''));
        $trusted = $this->trustedVisionElementRefs($vision, $observation);
        $observed = $elementRef !== '' ? ($trusted[$elementRef] ?? null) : null;

        if (! is_array($observed)) {
            return [];
        }

        $suggestion = collect($vision['suggested_task_actions'] ?? [])
            ->first(function (mixed $candidate) use ($catalogKey, $elementRef): bool {
                if (! is_array($candidate)) {
                    return false;
                }

                $candidateCatalogKey = trim((string) ($candidate['task_catalog_key'] ?? $candidate['task_key'] ?? ''));
                $candidateElementRef = trim((string) ($candidate['element_ref'] ?? $candidate['elementRef'] ?? ''));
                $confidence = $candidate['confidence'] ?? null;

                return $candidateCatalogKey === $catalogKey
                    && $candidateElementRef === $elementRef
                    && is_numeric($confidence)
                    && (float) $confidence >= self::MIN_VISUAL_CONFIDENCE;
            });

        if (! is_array($suggestion)) {
            return [];
        }

        $selector = $this->preferredSelectorFrom([
            ...(is_array($observed['selector_candidates'] ?? null) ? $observed['selector_candidates'] : []),
            $observed['selector'] ?? null,
        ]);

        if ($selector === null) {
            return [];
        }

        return [
            'element_ref' => $elementRef,
            'selector' => $selector,
            'window' => trim((string) ($observed['window'] ?? data_get($observation, 'page.window', 'main'))) ?: 'main',
            'suggested_parameters' => is_array($suggestion['parameters'] ?? null) ? $suggestion['parameters'] : [],
        ];
    }

    /** @return array<string, mixed> */
    protected function visualTargetFromObservation(string $elementRef, array $observation): array
    {
        $element = collect($observation['interaction_map'] ?? $observation['elements'] ?? [])
            ->first(function (mixed $candidate) use ($elementRef): bool {
                return is_array($candidate)
                    && trim((string) ($candidate['element_ref'] ?? $candidate['ref'] ?? '')) === $elementRef
                    && ($candidate['visible'] ?? null) === true
                    && ($candidate['enabled'] ?? true) !== false;
            });

        if (! is_array($element)) {
            return [];
        }

        $selector = $this->preferredSelectorFrom([
            ...(is_array($element['selector_candidates'] ?? null) ? $element['selector_candidates'] : []),
            $element['selector'] ?? null,
        ]);

        return $selector !== null
            ? [
                'element_ref' => $elementRef,
                'selector' => $selector,
                'window' => trim((string) ($element['window'] ?? data_get($observation, 'page.window', 'main'))) ?: 'main',
            ]
            : [];
    }

    protected function structuralTaskAlreadyExists(
        WorkflowStep $step,
        string $catalogKey,
        array $parameters,
    ): bool {
        if ($parameters === []) {
            return false;
        }

        return collect($step->task_cards)->contains(function (array $task) use ($catalogKey, $parameters): bool {
            if (trim((string) ($task['task_key'] ?? '')) !== $catalogKey) {
                return false;
            }

            $targetSelector = trim((string) (
                $parameters['selector']
                ?? $parameters['element_selector']
                ?? $parameters['input_selector']
                ?? ''
            ));
            $existingSelector = trim((string) (
                $task['selector']
                ?? $task['element_selector']
                ?? $task['input_selector']
                ?? ''
            ));

            if ($targetSelector !== '' && $existingSelector === $targetSelector) {
                return true;
            }

            foreach ($parameters as $key => $value) {
                if (($task[$key] ?? null) !== $value) {
                    return false;
                }
            }

            return true;
        });
    }

    /** @return array<string, mixed> */
    protected function stepExecutionTrace(WorkflowStep $step, array $checkpoint): array
    {
        $configuredTasks = collect($step->task_cards)->values();
        $resultTasks = collect(is_array(data_get($checkpoint, 'result.tasks'))
            ? data_get($checkpoint, 'result.tasks')
            : []);
        $executedTaskKeys = $resultTasks
            ->filter(fn (mixed $task): bool => is_array($task))
            ->flatMap(fn (array $task): array => array_filter([
                trim((string) ($task['key'] ?? '')),
                trim((string) ($task['parent_task_key'] ?? '')),
            ]))
            ->push(trim((string) data_get($checkpoint, 'result.completedTaskKey', '')))
            ->push(trim((string) data_get($checkpoint, 'result.failedTaskKey', '')))
            ->push(trim((string) ($checkpoint['task_key'] ?? '')))
            ->filter()
            ->unique()
            ->values();
        $currentTaskKey = trim((string) ($checkpoint['task_key'] ?? ''));
        $currentIndex = $configuredTasks->search(
            fn (array $task): bool => trim((string) ($task['key'] ?? '')) === $currentTaskKey,
        );
        $tasks = $configuredTasks
            ->map(fn (array $task, int $index): array => [
                'index' => $index,
                'key' => $task['key'] ?? null,
                'task_key' => $task['task_key'] ?? null,
                'title' => $task['title'] ?? null,
                'scope_variable' => $task['scope_variable'] ?? null,
                'output_variable' => $task['output_variable'] ?? null,
                'value_from_variable' => $task['value_from_variable'] ?? null,
                'array_name' => $task['array_name'] ?? null,
                'store_current_element_as' => $task['store_current_element_as'] ?? null,
                'loop_pair_id' => $task['loop_pair_id'] ?? null,
                'loop_pair_segment' => $task['loop_pair_segment'] ?? null,
                'executed' => $executedTaskKeys->contains(trim((string) ($task['key'] ?? ''))),
                'current' => trim((string) ($task['key'] ?? '')) === $currentTaskKey,
            ])
            ->values();

        return [
            'current_task_key' => $currentTaskKey,
            'current_task_index' => $currentIndex === false ? null : (int) $currentIndex,
            'configured_task_count' => $configuredTasks->count(),
            'executed_task_keys' => $executedTaskKeys->all(),
            'configured_but_not_executed' => $tasks
                ->where('executed', false)
                ->pluck('key')
                ->filter()
                ->values()
                ->all(),
            'tasks' => $tasks->all(),
        ];
    }

    protected function structuralTargetStep(Workflow $workflow, array $operation): ?WorkflowStep
    {
        $actionKey = trim((string) ($operation['step_action_key'] ?? ''));
        $stepId = (int) ($operation['step_id'] ?? 0);

        return $workflow->steps->first(function (WorkflowStep $step) use ($actionKey, $stepId): bool {
            return ($actionKey !== '' && (string) $step->action_key === $actionKey)
                || ($stepId > 0 && (int) $step->id === $stepId);
        });
    }

    protected function uniqueStructuralTaskKey(WorkflowStep $step, string $title): string
    {
        $base = Str::slug($title) ?: 'copilot-task';
        $candidate = $base;
        $suffix = 2;
        $existing = collect($step->task_cards)
            ->map(fn (array $task): string => (string) ($task['key'] ?? ''))
            ->all();

        while (in_array($candidate, $existing, true)) {
            $candidate = $base.'-'.$suffix++;
        }

        return $candidate;
    }

    protected function consentObstaclePlan(
        WorkflowStep $step,
        array $task,
        array $checkpoint,
        array $observation,
        array $vision,
    ): array {
        if (! $this->consentBlocked($observation, $vision)) {
            return [];
        }

        $target = $this->consentTargetFromEvidence($checkpoint, $observation, $vision);

        if ($target === []) {
            return [];
        }

        if ($this->taskLooksLikeConsentClick($task)) {
            return [];
        }

        [$originalRoute] = $this->routeAfterTask($step, $task);

        if ($this->routeTargetsConsentClick($step, $originalRoute)) {
            return [
                'action' => 'continue_route',
                'task_key' => (string) ($task['key'] ?? ''),
                'resume_checkpoint' => true,
                'reason' => 'Die konfigurierte Folgeroute fuehrt bereits zu einer eigenen Consent-Klick-Liste und wird jetzt ausgefuehrt.',
            ];
        }

        $decisionLabel = $target['decision'] === 'reject' ? 'ablehnen' : 'akzeptieren';

        return [
            'action' => 'restart_with_workflow_changes',
            'task_key' => (string) ($task['key'] ?? ''),
            'reason' => 'Der technisch erfolgreiche Task liess einen sichtbaren Consent-Dialog aktiv. Eine eigene kataloggebundene Browser-Klick-Liste wird zwischen Quelle und bisheriger Folgeroute eingefuegt.',
            'operations' => [[
                'type' => 'insert_step',
                'purpose' => 'consent_obstacle',
                'source_step_action_key' => (string) $step->action_key,
                'source_task_key' => (string) ($task['key'] ?? ''),
                'task_catalog_key' => 'browser.click',
                'name' => 'Consent-Banner '.$decisionLabel,
                'title' => 'Consent '.$decisionLabel,
                'description' => 'Bestaetigt den sichtbaren Consent-Dialog vor der bisherigen Workflow-Fortsetzung.',
                'selector' => $target['selector'],
                'label' => $target['label'],
                'decision' => $target['decision'],
                'browser_window' => $target['window'],
                'element_ref' => $target['element_ref'],
            ]],
            'planning_handoff' => [
                'vision_profile' => 'image_understanding',
                'vision_model' => $vision['model'] ?? null,
                'planner_profile' => 'deterministic_consent_repair',
            ],
        ];
    }

    protected function applyConsentObstacleStep(Workflow $workflow, array $operation, array $observation): void
    {
        if (($operation['purpose'] ?? null) !== 'consent_obstacle'
            || ($operation['task_catalog_key'] ?? null) !== 'browser.click') {
            throw new \DomainException('Die einzufuegende Liste ist keine erlaubte Consent-Reparatur.');
        }

        $sourceStep = $workflow->steps()
            ->where('action_key', trim((string) ($operation['source_step_action_key'] ?? '')))
            ->first();
        $sourceTaskKey = trim((string) ($operation['source_task_key'] ?? ''));
        $sourceTask = $sourceStep
            ? collect($sourceStep->task_cards)->firstWhere('key', $sourceTaskKey)
            : null;

        if (! $sourceStep || ! is_array($sourceTask) || $sourceTaskKey === '') {
            throw new \DomainException('Die Quell-Task der Consent-Reparatur wurde nicht gefunden.');
        }

        $checkpoint = is_array($observation['copilot_checkpoint'] ?? null)
            ? $observation['copilot_checkpoint']
            : [];
        $vision = is_array($observation['copilot_vision'] ?? null)
            ? $observation['copilot_vision']
            : [];
        $target = $this->consentTargetFromEvidence($checkpoint, $observation, $vision);
        $selector = trim((string) ($operation['selector'] ?? ''));

        if ($target === []
            || ! $this->isSafeSelector($selector)
            || ! hash_equals((string) $target['selector'], $selector)) {
            throw new \DomainException('Der Consent-Klick ist nicht durch sichtbare Laufzeitevidenz abgesichert.');
        }

        [$originalRoute, $sourceRouteField] = $this->routeAfterTask($sourceStep, $sourceTask);

        if (! $this->isValidRoute($sourceStep, $originalRoute)) {
            throw new \DomainException('Die bisherige Folgeroute der Consent-Reparatur ist ungueltig.');
        }

        if ($this->routeTargetsConsentClick($sourceStep, $originalRoute)) {
            throw new \DomainException('Die Folgeroute enthaelt bereits eine Consent-Klick-Liste.');
        }

        $definition = $this->catalog->task('browser.click');

        if (! is_array($definition)) {
            throw new \DomainException('Die Browser-Klick-Task ist nicht im WorkflowTaskCatalog registriert.');
        }

        $actionKey = $this->uniqueWorkflowStepActionKey($workflow, 'consent-banner-'.$target['decision']);
        $name = Str::limit(trim((string) ($operation['name'] ?? 'Consent-Banner behandeln')), 180, '') ?: 'Consent-Banner behandeln';
        $position = max(0, (int) $workflow->steps()->max('position')) + 10;
        $newStep = $workflow->steps()->create([
            'name' => $name,
            'type' => WorkflowStep::TYPE_BROWSER_TASK,
            'action_key' => $actionKey,
            'position' => $position,
            'is_enabled' => true,
            'config_json' => ['tasks' => [], 'routes' => []],
            'retry_attempts' => 0,
            'wait_after_seconds' => 0,
        ]);
        $insertedRoute = [
            'type' => 'step',
            'action_key' => $actionKey,
            'step' => $actionKey,
            'label' => $name,
        ];
        $failureRoute = ['type' => 'fail', 'step' => 'fail', 'label' => 'Consent-Klick fehlgeschlagen'];
        $card = $this->catalog->cardFromDefinition('browser.click', [
            'key' => 'consent-'.$target['decision'],
            'title' => Str::limit(trim((string) ($operation['title'] ?? 'Consent behandeln')), 180, ''),
            'description' => Str::limit(trim((string) ($operation['description'] ?? 'Sichtbaren Consent-Dialog behandeln.')), 1000, ''),
        ]);
        $card = array_replace($card, [
            'selector' => $selector,
            'element_selector' => $selector,
            'browser_window' => $target['window'],
            'browser_window_name' => $target['window'],
            'timeout_seconds' => 15,
            'next' => $originalRoute,
            'on_error' => $failureRoute,
        ]);
        $this->taskOrdering->appendTask($newStep, $card);

        $newConfig = is_array($newStep->fresh()?->config_json) ? $newStep->fresh()->config_json : [];
        $newConfig['routes'] = [
            'success' => $originalRoute,
            'failed' => $failureRoute,
        ];
        $newStep->forceFill(['config_json' => $newConfig])->save();

        $orderedSteps = $workflow->steps()->ordered()->get();
        $sourceIndex = $orderedSteps->search(fn (WorkflowStep $candidate): bool => (int) $candidate->id === (int) $sourceStep->id);

        if ($sourceIndex === false || ! $this->taskOrdering->sortSteps($workflow, (int) $newStep->id, (int) $sourceIndex + 1)) {
            throw new \DomainException('Die Consent-Liste konnte nicht hinter ihrer Quell-Liste einsortiert werden.');
        }

        if ($sourceRouteField === 'task_next') {
            $this->applyChangesToLockedStep($sourceStep->fresh(), $sourceTaskKey, ['next' => $insertedRoute]);
        } else {
            $sourceConfig = is_array($sourceStep->fresh()?->config_json) ? $sourceStep->fresh()->config_json : [];
            $sourceConfig['routes'] = array_replace(
                is_array($sourceConfig['routes'] ?? null) ? $sourceConfig['routes'] : [],
                ['success' => $insertedRoute],
            );
            $sourceStep->forceFill(['config_json' => $sourceConfig])->save();
        }
    }

    /** @return array{0:array<string, mixed>, 1:string} */
    protected function routeAfterTask(WorkflowStep $step, array $task): array
    {
        if (is_array($task['next'] ?? null)) {
            return [$task['next'], 'task_next'];
        }

        $successRoute = data_get($step->config_json, 'routes.success');

        if (is_array($successRoute)) {
            return [$successRoute, 'step_success'];
        }

        return [[
            'type' => 'step',
            'action_key' => 'next',
            'step' => 'next',
            'label' => 'Naechste Liste',
        ], 'step_success'];
    }

    protected function routeTargetsConsentClick(WorkflowStep $sourceStep, array $route): bool
    {
        $type = Str::lower(trim((string) ($route['type'] ?? '')));
        $targetKey = trim((string) ($route['action_key'] ?? $route['step'] ?? ''));
        $cardKey = trim((string) ($route['card_key'] ?? $route['card'] ?? ''));

        if (in_array($type, ['end', 'fail'], true) || in_array($targetKey, ['end', 'fail'], true)) {
            return false;
        }

        if ($targetKey === '' && $cardKey !== '') {
            $targetStep = $sourceStep;
        } elseif ($targetKey === 'next') {
            $steps = WorkflowStep::query()
                ->where('workflow_id', $sourceStep->workflow_id)
                ->ordered()
                ->get();
            $sourceIndex = $steps->search(fn (WorkflowStep $candidate): bool => (int) $candidate->id === (int) $sourceStep->id);
            $targetStep = $sourceIndex === false ? null : $steps->get((int) $sourceIndex + 1);
        } else {
            $targetStep = WorkflowStep::query()
                ->where('workflow_id', $sourceStep->workflow_id)
                ->where('action_key', $targetKey)
                ->first();
        }

        if (! $targetStep instanceof WorkflowStep) {
            return false;
        }

        $targetTask = $cardKey !== ''
            ? collect($targetStep->task_cards)->firstWhere('key', $cardKey)
            : collect($targetStep->task_cards)->first();

        return is_array($targetTask) && $this->taskLooksLikeConsentClick($targetTask);
    }

    protected function taskLooksLikeConsentClick(array $task): bool
    {
        if (($task['task_key'] ?? null) !== 'browser.click') {
            return false;
        }

        return $this->canonicalConsentAction(implode(' ', array_filter([
            $task['title'] ?? null,
            $task['description'] ?? null,
            $task['selector'] ?? null,
            $task['element_selector'] ?? null,
        ], static fn (mixed $value): bool => is_scalar($value)))) !== null;
    }

    protected function consentBlocked(array $observation, array $vision): bool
    {
        $states = [
            data_get($observation, 'page.state'),
            $observation['page_state'] ?? null,
            data_get($observation, 'dom.ui_state'),
        ];

        $interactionEvidence = collect($observation['interaction_map'] ?? [])
            ->filter(fn (mixed $element): bool => is_array($element)
                && ($element['visible'] ?? null) === true
                && ($element['enabled'] ?? true) !== false)
            ->map(fn (array $element): string => implode(' ', array_filter([
                $element['text'] ?? null,
                $element['aria'] ?? null,
                $element['name'] ?? null,
                ...(is_array($element['selector_candidates'] ?? null) ? $element['selector_candidates'] : []),
            ], static fn (mixed $value): bool => is_scalar($value))))
            ->implode(' ');
        $domEvidence = (string) data_get($observation, 'dom.visible_text_excerpt', '');
        $currentEvidence = Str::lower(trim($interactionEvidence.' '.$domEvidence));
        $stateSaysConsent = collect($states)->contains(
            fn (mixed $state): bool => str_contains(Str::lower(trim((string) $state)), 'consent'),
        );
        $hasCurrentConsentAction = $this->canonicalConsentAction($currentEvidence) !== null
            && ($stateSaysConsent
                || preg_match('/(?:consent|cookie|einwilligung|datenschutz|privacy)/u', $currentEvidence) === 1);

        if (! $hasCurrentConsentAction) {
            return false;
        }

        if ($stateSaysConsent) {
            return true;
        }

        return true;
    }

    /** @return array<string, mixed> */
    protected function consentTargetFromEvidence(array $checkpoint, array $observation, array $vision): array
    {
        if (! $this->consentBlocked($observation, $vision)) {
            return [];
        }

        $candidates = [];

        foreach ($observation['interaction_map'] ?? [] as $element) {
            if (! is_array($element)
                || ($element['visible'] ?? null) !== true
                || ($element['enabled'] ?? true) === false) {
                continue;
            }

            $this->appendConsentTargetCandidate(
                $candidates,
                implode(' ', array_filter([
                    $element['text'] ?? null,
                    $element['aria'] ?? null,
                    $element['name'] ?? null,
                ], static fn (mixed $value): bool => is_scalar($value))),
                is_array($element['selector_candidates'] ?? null) ? $element['selector_candidates'] : [],
                (string) ($element['window'] ?? data_get($observation, 'page.window', 'main')),
                (string) ($element['element_ref'] ?? ''),
                'interaction_map',
            );
        }

        $result = is_array($checkpoint['result'] ?? null) ? $checkpoint['result'] : [];
        $resultElement = is_array($result['element'] ?? null) ? $result['element'] : [];
        $resultSelectors = array_filter([
            $result['matchedCandidate'] ?? null,
            $result['matched_candidate'] ?? null,
            $result['selector'] ?? null,
            $resultElement['selector'] ?? null,
        ], static fn (mixed $value): bool => is_scalar($value));
        $this->appendConsentTargetCandidate(
            $candidates,
            implode(' ', array_filter([
                $resultElement['text'] ?? null,
                $resultElement['aria'] ?? null,
                $result['statusMessage'] ?? null,
                ...$resultSelectors,
            ], static fn (mixed $value): bool => is_scalar($value))),
            $resultSelectors,
            (string) ($result['browserWindow'] ?? $result['browser_window'] ?? data_get($observation, 'page.window', 'main')),
            (string) ($resultElement['element_ref'] ?? ''),
            'checkpoint_result',
        );

        $this->appendConsentTargetCandidate(
            $candidates,
            (string) data_get($observation, 'dom.visible_text_excerpt', ''),
            [],
            (string) data_get($observation, 'page.window', 'main'),
            '',
            'visible_text',
        );
        usort($candidates, static function (array $left, array $right): int {
            return ((int) $right['score']) <=> ((int) $left['score']);
        });

        return $candidates[0] ?? [];
    }

    protected function appendConsentTargetCandidate(
        array &$candidates,
        string $evidence,
        array $selectors,
        string $window,
        string $elementRef,
        string $source,
    ): void {
        $action = $this->canonicalConsentAction($evidence);

        if ($action === null) {
            return;
        }

        $selectors = collect($selectors)
            ->map(fn (mixed $selector): string => trim((string) $selector))
            ->filter(fn (string $selector): bool => $this->isSpecificConsentSelector($selector))
            ->values();
        $selector = (string) ($selectors->first() ?: $this->consentSelectorForLabel($action['label']));

        if (! $this->isSafeSelector($selector)) {
            return;
        }

        $sourceBonus = match ($source) {
            'checkpoint_result' => 40,
            'interaction_map' => 30,
            'visible_text' => 20,
            default => 10,
        };
        $candidates[] = [
            'selector' => $selector,
            'label' => $action['label'],
            'decision' => $action['decision'],
            'window' => trim($window) ?: 'main',
            'element_ref' => trim($elementRef) ?: null,
            'source' => $source,
            'score' => $action['score'] + $sourceBonus,
        ];
    }

    /** @return array{label:string, decision:string, score:int}|null */
    protected function canonicalConsentAction(string $evidence): ?array
    {
        $evidence = Str::lower($evidence);
        $actions = [
            ['/(?:alle\s+ablehnen)/u', 'Alle ablehnen', 'reject', 1000],
            ['/(?:reject\s+all)/u', 'Reject all', 'reject', 990],
            ['/(?:decline\s+all)/u', 'Decline all', 'reject', 980],
            ['/(?:refuse\s+all)/u', 'Refuse all', 'reject', 970],
            ['/(?:nur\s+notwendige)/u', 'Nur notwendige', 'reject', 960],
            ['/(?:nur\s+erforderliche)/u', 'Nur erforderliche', 'reject', 950],
            ['/(?:only\s+necessary)/u', 'Only necessary', 'reject', 940],
            ['/(?:only\s+required)/u', 'Only required', 'reject', 930],
            ['/\bablehnen\b/u', 'Ablehnen', 'reject', 900],
            ['/\breject\b/u', 'Reject', 'reject', 890],
            ['/\bdecline\b/u', 'Decline', 'reject', 880],
            ['/(?:alle\s+akzeptieren)/u', 'Alle akzeptieren', 'accept', 600],
            ['/(?:accept\s+all)/u', 'Accept all', 'accept', 590],
            ['/(?:allow\s+all)/u', 'Allow all', 'accept', 580],
            ['/\bakzeptieren\b/u', 'Akzeptieren', 'accept', 560],
            ['/\baccept\b/u', 'Accept', 'accept', 550],
            ['/\ballow\b/u', 'Allow', 'accept', 540],
        ];

        foreach ($actions as [$pattern, $label, $decision, $score]) {
            if (preg_match($pattern, $evidence) === 1) {
                return compact('label', 'decision', 'score');
            }
        }

        return null;
    }

    protected function isSpecificConsentSelector(string $selector): bool
    {
        if (! $this->isSafeSelector($selector)) {
            return false;
        }

        $normalized = Str::lower(preg_replace('/\s+/', '', $selector) ?? $selector);

        return ! in_array($normalized, ['button', 'a', 'input', '*'], true)
            && (str_contains($normalized, ':has-text(')
                || str_contains($normalized, 'text=')
                || str_contains($normalized, '#')
                || str_contains($normalized, '['));
    }

    protected function consentSelectorForLabel(string $label): string
    {
        $label = str_replace(['\\', '"'], ['\\\\', '\\"'], $label);

        return 'button:has-text("'.$label.'")';
    }

    protected function uniqueWorkflowStepActionKey(Workflow $workflow, string $name): string
    {
        $base = Str::slug($name) ?: 'consent-banner';
        $candidate = $base;
        $suffix = 2;

        while ($workflow->steps()->where('action_key', $candidate)->exists()) {
            $candidate = $base.'-'.$suffix++;
        }

        return $candidate;
    }

    protected function blankPageRecoveryPlan(
        WorkflowStep $failedStep,
        array $checkpoint,
        array $observation,
        array $vision = [],
    ): array {
        $pageUrl = Str::lower(trim((string) data_get($observation, 'page.url', '')));

        if (! in_array($pageUrl, ['', 'about:blank'], true)) {
            return [];
        }

        $workflow = Workflow::query()
            ->with(['steps' => fn ($query) => $query->ordered()])
            ->find($failedStep->workflow_id);

        if (! $workflow) {
            return [];
        }

        $failedTaskKey = trim((string) ($checkpoint['task_key'] ?? ''));
        $navigationTarget = $workflow->steps
            ->where('is_enabled', true)
            ->flatMap(function (WorkflowStep $step) use ($failedTaskKey): array {
                return collect($step->task_cards)
                    ->filter(function (array $task) use ($failedTaskKey): bool {
                        $catalogKey = trim((string) ($task['task_key'] ?? ''));
                        $taskKey = trim((string) ($task['key'] ?? ''));
                        $url = Str::lower(trim((string) ($task['url'] ?? '')));

                        return $taskKey !== ''
                            && $taskKey !== $failedTaskKey
                            && in_array($catalogKey, self::SAFE_NAVIGATION_TASKS, true)
                            && $url !== ''
                            && $url !== 'about:blank';
                    })
                    ->map(fn (array $task): array => [
                        'step' => $step,
                        'task' => $task,
                        'priority' => trim((string) ($task['task_key'] ?? '')) === 'browser.open_url' ? 0 : 1,
                    ])
                    ->all();
            })
            ->sortBy(fn (array $target): string => sprintf(
                '%d-%010d-%010d',
                (int) $target['priority'],
                (int) ($target['step']->position ?? 0),
                (int) data_get($target, 'task.position', data_get($target, 'task.order_id', 0)),
            ))
            ->first();

        if (! is_array($navigationTarget)
            || ! ($navigationTarget['step'] ?? null) instanceof WorkflowStep
            || ! is_array($navigationTarget['task'] ?? null)) {
            return [];
        }

        /** @var WorkflowStep $navigationStep */
        $navigationStep = $navigationTarget['step'];
        $navigationTask = $navigationTarget['task'];
        $route = [
            'type' => 'card',
            'action_key' => (string) $navigationStep->action_key,
            'step' => (string) $navigationStep->action_key,
            'card_key' => (string) $navigationTask['key'],
            'card' => (string) $navigationTask['key'],
            'label' => (string) $navigationStep->name.' / '.(string) ($navigationTask['title'] ?? $navigationTask['key']),
        ];
        $outcome = Str::lower(trim((string) ($checkpoint['outcome'] ?? 'failed')));
        $outcome = in_array($outcome, ['failed', 'timeout'], true) ? $outcome : 'failed';
        $operations = [];
        $entryStep = $workflow->steps->where('is_enabled', true)->first();

        if ($entryStep instanceof WorkflowStep) {
            $entryTask = collect($entryStep->task_cards)->first();

            if (is_array($entryTask)
                && (string) ($entryTask['key'] ?? '') !== (string) $navigationTask['key']
                && is_array($entryTask['next'] ?? null)
                && $entryTask['next'] !== $route) {
                $operations[] = [
                    'type' => 'update_task_routes',
                    'step_action_key' => (string) $entryStep->action_key,
                    'task_key' => (string) $entryTask['key'],
                    'changes' => ['next' => $route],
                ];
            }

            if ((int) $entryStep->id !== (int) $navigationStep->id
                && data_get($entryStep->config_json, 'routes.success') !== $route) {
                $operations[] = [
                    'type' => 'update_step_routes',
                    'step_action_key' => (string) $entryStep->action_key,
                    'routes' => ['success' => $route],
                ];
            }
        }

        $failedTask = collect($failedStep->task_cards)->firstWhere('key', $failedTaskKey);

        if (is_array($failedTask) && ($failedTask['on_error'] ?? null) !== $route) {
            $operations[] = [
                'type' => 'update_task_routes',
                'step_action_key' => (string) $failedStep->action_key,
                'task_key' => $failedTaskKey,
                'changes' => ['on_error' => $route],
            ];
        }

        $failedRoutes = [];

        foreach (['failed', 'timeout'] as $failedOutcome) {
            if (data_get($failedStep->config_json, 'routes.'.$failedOutcome) !== $route) {
                $failedRoutes[$failedOutcome] = $route;
            }
        }

        if ($failedRoutes !== []) {
            $operations[] = [
                'type' => 'update_step_routes',
                'step_action_key' => (string) $failedStep->action_key,
                'routes' => $failedRoutes,
            ];
        }

        if ($operations === []) {
            return [
                'action' => 'continue_route',
                'task_key' => $failedTaskKey,
                'reason' => 'Der Browser ist leer; die bereits konfigurierte Fehlerroute zur konkreten Navigationskarte wird jetzt ausgefuehrt.',
                'planning_handoff' => $this->planningHandoff($vision),
            ];
        }

        return [
            'action' => 'restart_with_workflow_changes',
            'task_key' => $failedTaskKey,
            'operations' => array_slice($operations, 0, 4),
            'reason' => 'Der Test steht auf about:blank, obwohl eine konkrete kataloggebundene URL-Navigation vorhanden ist. Start- und Fehlerpfad werden direkt mit dieser Navigationskarte verbunden und der Workflow von Anfang an neu getestet.',
            'planning_handoff' => $this->planningHandoff($vision),
        ];
    }

    protected function planningHandoff(array $vision): array
    {
        return array_filter([
            'vision_profile' => 'image_understanding',
            'vision_model' => trim((string) ($vision['model'] ?? '')) ?: null,
            'vision_analysis_source' => trim((string) ($vision['analysis_source'] ?? '')) ?: null,
            'planner_profile' => 'data_analysis',
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    protected function pausePlan(string $taskKey, string $reason): array
    {
        return [
            'action' => 'pause',
            'task_key' => $taskKey,
            'reason' => $reason,
        ];
    }
}
