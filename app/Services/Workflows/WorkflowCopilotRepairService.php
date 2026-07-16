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

        $blankPageRecovery = $this->blankPageRecoveryPlan($step, $checkpoint, $observation, $vision);

        if ($blankPageRecovery !== []) {
            return $blankPageRecovery;
        }

        $configuredFailureRoute = $this->configuredFailureRoutePlan($step, $task, $checkpoint);

        if ($configuredFailureRoute !== []) {
            return $configuredFailureRoute;
        }

        $requiresVisualTarget = $this->taskRequiresVisualTarget($taskCatalogKey);
        $trustedElementRefs = $this->trustedVisionElementRefs($vision, $observation);
        $contextDomains = $this->observationDomains($observation);
        $selectors = $this->selectorCandidates($vision, $observation, $requiresVisualTarget)
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
                'Du bist das Datenanalyse- und Planungsmodell des Workflow-Copiloten. Der strukturierte Bildbefund wurde zuvor von einem getrennten Bildverstehen-Modell aus Screenshot und DOM erzeugt. Plane und repariere ausschliesslich Workflow-Konfigurationen. Antworte nur als JSON. Keine Quellcode-Aenderungen, kein JavaScript und keine Aktionen ausserhalb des vorhandenen WorkflowTaskCatalog.',
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

        if ($action === 'retry') {
            if (! $hasSafePlannerTarget) {
                return $this->unsafeVisualTargetPlan($taskKey);
            }

            return [
                'action' => 'retry',
                'task_key' => $taskKey,
                'reason' => $this->safeReason($decision['reason'] ?? 'Task erneut ausfuehren.'),
            ];
        }

        if ($action === 'continue_route') {
            return [
                'action' => 'continue_route',
                'task_key' => $taskKey,
                'reason' => $this->safeReason($decision['reason'] ?? 'Konfigurierte Fehlerroute fortsetzen.'),
            ];
        }

        if ($action === 'structural_update') {
            $operations = $this->normalizeStructuralOperations(
                $step,
                is_array($decision['operations'] ?? null) ? $decision['operations'] : [],
                $observation,
            );

            if ($operations !== []) {
                return [
                    'action' => 'restart_with_workflow_changes',
                    'task_key' => $taskKey,
                    'operations' => $operations,
                    'reason' => $this->safeReason($decision['reason'] ?? 'Fehlende Workflow-Logik wird kataloggebunden ergaenzt und von vorn getestet.'),
                    'planning_handoff' => $this->planningHandoff($vision),
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
            ];
        }

        return $this->pausePlan($taskKey, $this->safeReason($decision['reason'] ?? 'Keine sichere autonome Reparatur gefunden.'));
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

            if (! $definition
                || in_array($catalogKey, ['loop.for_each_element', 'loop.end'], true)
                || $this->taskRequiresVisualTarget($catalogKey)
                || $cardKey === ''
                || collect($step->task_cards)->contains(fn (array $task): bool => (string) ($task['key'] ?? '') === $cardKey)) {
                throw new \DomainException('Der einzufuegende Task ist nicht katalogkonform oder nicht eindeutig.');
            }

            $baseCard = $this->catalog->cardFromDefinition($catalogKey, [
                'key' => $cardKey,
                'title' => Str::limit(trim((string) ($operation['title'] ?? $definition['label'] ?? $catalogKey)), 180, ''),
                'description' => Str::limit(trim((string) ($operation['description'] ?? $definition['description'] ?? '')), 1000, ''),
            ]);
            $parameters = is_array($operation['parameters'] ?? null) ? $operation['parameters'] : [];
            $normalized = $this->normalizeChanges(
                $step,
                $baseCard,
                $parameters,
                true,
                $this->observationDomains($observation),
            );
            $card = array_replace($baseCard, $normalized);
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
        bool $requiresVisualTarget,
    ): \Illuminate\Support\Collection {
        $references = collect(array_keys($this->trustedVisionElementRefs($vision, $observation)));
        $elements = collect($observation['interaction_map'] ?? $observation['elements'] ?? [])
            ->filter(fn (mixed $element): bool => is_array($element)
                && ($element['visible'] ?? null) === true
                && ($element['enabled'] ?? true) !== false)
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
            ->values();
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
        return $selector !== ''
            && mb_strlen($selector) <= 1000
            && ! preg_match('/(?:javascript:|<script|\beval\s*\(|\bFunction\s*\()/i', $selector);
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
        $workflowStructure = $workflow?->steps
            ->map(fn (WorkflowStep $workflowStep): array => [
                'id' => (int) $workflowStep->id,
                'name' => (string) $workflowStep->name,
                'action_key' => (string) $workflowStep->action_key,
                'position' => (int) $workflowStep->position,
                'routes' => is_array(data_get($workflowStep->config_json, 'routes'))
                    ? data_get($workflowStep->config_json, 'routes')
                    : [],
                'tasks' => collect($workflowStep->task_cards)->map(fn (array $candidate): array => array_filter([
                    'key' => $candidate['key'] ?? null,
                    'task_key' => $candidate['task_key'] ?? null,
                    'title' => $candidate['title'] ?? null,
                    'url' => $candidate['url'] ?? null,
                    'selector' => $candidate['selector'] ?? $candidate['element_selector'] ?? null,
                    'next' => $candidate['next'] ?? null,
                    'on_error' => $candidate['on_error'] ?? null,
                    'status_routes' => $candidate['status_routes'] ?? null,
                ], static fn (mixed $value): bool => $value !== null && $value !== ''))->values()->all(),
            ])
            ->values()
            ->all() ?? [];
        $catalog = collect($this->catalog->options())
            ->reject(fn (array $definition): bool => in_array((string) ($definition['key'] ?? ''), ['loop.for_each_element', 'loop.end'], true))
            ->map(fn (array $definition): array => [
                'task_key' => $definition['key'] ?? null,
                'label' => $definition['label'] ?? null,
                'kind' => $definition['kind'] ?? null,
                'mutable_fields' => $this->mutableFieldsForDefinition($definition),
                'requires_visible_target' => $this->taskRequiresVisualTarget((string) ($definition['key'] ?? '')),
            ])
            ->values()
            ->all();
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
            'workflow_structure' => $workflowStructure,
            'failure' => Arr::only($checkpoint, ['outcome', 'result', 'task_key']),
            'observation' => Arr::except($observation, ['screenshot_data_url', 'raw_dom', 'html']),
            'vision' => $vision,
            'allowed_actions' => ['retry', 'update_task', 'continue_route', 'structural_update', 'pause'],
            'structural_operations' => [
                'insert_task' => [
                    'fields' => ['type', 'step_action_key', 'task_catalog_key', 'title', 'description', 'parameters', 'insert_position'],
                    'constraint' => 'Nur kataloggebundene Tasks ohne erforderliches sichtbares Ziel; fehlende Logik ergaenzen, nicht duplizieren.',
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

        return 'Waehle die kleinste sichere Reparatur, die den Workflow autonom weiter zum Ziel bringt. Wenn der aktuelle Bildschirm nur Folge fehlender oder falsch gerouteter Workflow-Logik ist, verwende structural_update statt pause. Schema: {"action":"retry|update_task|continue_route|structural_update|pause","element_ref":"el_... oder leer","changes":{},"operations":[],"reason":"konkreter Befund"}. Nach structural_update wird der Workflow revisioniert von Anfang an getestet. Zustandsveraendernde Tasks duerfen nur eine trusted_vision_element_ref verwenden; structural_update darf keine solchen Tasks neu einfuegen. Daten: '.json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    protected function normalizeStructuralOperations(
        WorkflowStep $currentStep,
        array $operations,
        array $observation,
    ): array {
        $workflow = Workflow::query()
            ->with(['steps' => fn ($query) => $query->ordered()])
            ->find($currentStep->workflow_id);

        if (! $workflow) {
            return [];
        }

        $normalized = [];
        $contextDomains = $this->observationDomains($observation);

        foreach (collect($operations)->filter(fn (mixed $operation): bool => is_array($operation))->take(4) as $operation) {
            $type = Str::lower(trim((string) ($operation['type'] ?? '')));

            if (! in_array($type, self::STRUCTURAL_OPERATION_TYPES, true)) {
                continue;
            }

            // New visual-target steps are emitted only by the deterministic
            // consent repair below, never from model-provided operations.
            if ($type === 'insert_step') {
                continue;
            }

            $targetStep = $this->structuralTargetStep($workflow, $operation);

            if (! $targetStep) {
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
                }

                continue;
            }

            if ($type === 'update_task_routes') {
                $taskKey = trim((string) ($operation['task_key'] ?? ''));
                $task = collect($targetStep->task_cards)->firstWhere('key', $taskKey);

                if (! is_array($task)) {
                    continue;
                }

                $changes = Arr::only(
                    is_array($operation['changes'] ?? null) ? $operation['changes'] : [],
                    self::ROUTE_FIELDS,
                );
                $changes = $this->normalizeChanges($targetStep, $task, $changes, true, $contextDomains);
                $changes = Arr::only($changes, self::ROUTE_FIELDS);

                if ($changes !== []) {
                    $normalized[] = [
                        'type' => $type,
                        'step_action_key' => (string) $targetStep->action_key,
                        'task_key' => $taskKey,
                        'changes' => $changes,
                    ];
                }

                continue;
            }

            $catalogKey = trim((string) ($operation['task_catalog_key'] ?? $operation['task_key'] ?? ''));
            $definition = $catalogKey !== '' ? $this->catalog->task($catalogKey) : null;

            if (! $definition
                || in_array($catalogKey, ['loop.for_each_element', 'loop.end'], true)
                || $this->taskRequiresVisualTarget($catalogKey)) {
                continue;
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
                continue;
            }

            $normalized[] = [
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
        }

        return $normalized;
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
        $target = $this->consentTargetFromEvidence($checkpoint, $observation, []);
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
            $vision['ui_state'] ?? null,
            $vision['page_state'] ?? null,
        ];

        if (collect($states)->contains(
            fn (mixed $state): bool => str_contains(Str::lower(trim((string) $state)), 'consent'),
        )) {
            return true;
        }

        $evidence = Str::lower(implode(' ', [
            (string) data_get($observation, 'dom.visible_text_excerpt', ''),
            (string) json_encode($vision, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]));

        return preg_match('/(?:consent|cookie|einwilligung|datenschutz|privacy)/u', $evidence) === 1
            && $this->canonicalConsentAction($evidence) !== null;
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
        $this->appendConsentTargetCandidate(
            $candidates,
            (string) json_encode($vision, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            [],
            (string) data_get($observation, 'page.window', 'main'),
            '',
            'vision',
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
