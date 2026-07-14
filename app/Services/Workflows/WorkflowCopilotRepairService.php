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

    public function __construct(
        protected WorkflowTaskCatalog $catalog,
        protected AiConnectionService $ai,
        protected WorkflowCopilotObservationService $observations,
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
                'Du reparierst ausschliesslich Workflow-Konfigurationen. Antworte nur als JSON. Keine Quellcode-Aenderungen, kein JavaScript und keine Aktionen ausserhalb des vorhandenen WorkflowTaskCatalog.',
                ['temperature' => 0.1, 'max_completion_tokens' => 1200, '_timeout' => 30],
            );
            $decision = $this->observations->sanitizeForModel($decision);
            $decision = is_array($decision) ? $decision : [];
        } catch (\Throwable) {
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

    protected function selectorCandidates(
        array $vision,
        array $observation,
        bool $requiresVisualTarget,
    ): \Illuminate\Support\Collection
    {
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
            'failure' => Arr::only($checkpoint, ['outcome', 'result', 'task_key']),
            'observation' => Arr::except($observation, ['screenshot_data_url', 'raw_dom', 'html']),
            'vision' => $vision,
            'allowed_actions' => ['retry', 'update_task', 'continue_route', 'pause'],
            'trusted_vision_element_refs' => array_keys($this->trustedVisionElementRefs($vision, $observation)),
            'mutable_fields' => $this->mutableFieldsForDefinition(
                $this->catalog->task((string) ($task['task_key'] ?? '')) ?? [],
            ),
        ]);

        return 'Waehle die kleinste sichere Reparatur. Schema: {"action":"retry|update_task|continue_route|pause","element_ref":"el_... oder leer","changes":{},"reason":"sichtbarer Befund"}. Zustandsveraendernde Tasks duerfen nur eine trusted_vision_element_ref verwenden. Daten: '.json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
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
