<?php

namespace App\Services\Workflows;

use App\Models\WorkflowCopilotSession;
use App\Models\WorkflowStep;
use App\Services\Ai\AiConnectionService;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class WorkflowCopilotRepairService
{
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
    ) {}

    public function plan(
        WorkflowCopilotSession $session,
        WorkflowStep $step,
        array $checkpoint,
        array $observation,
        array $vision,
        array $rejectedSelectors = [],
    ): array {
        if ($this->sessionIsVerifying($session)) {
            return $this->pausePlan('', 'Waehrend des unveraenderlichen Kontrolllaufs duerfen keine Workflow-Reparaturen geplant oder gespeichert werden.');
        }

        $taskKey = trim((string) ($checkpoint['task_key'] ?? ''));
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

        $selectors = $this->selectorCandidates($vision, $observation)
            ->reject(fn (string $selector): bool => in_array($selector, $rejectedSelectors, true))
            ->reject(fn (string $selector): bool => $selector === trim((string) ($task['selector'] ?? $task['element_selector'] ?? '')))
            ->values();

        if ($selectors->isNotEmpty() && $this->taskSupportsSelector($definition)) {
            $selector = (string) $selectors->first();
            $changes = $this->normalizeChanges($step, $task, [
                'selector' => $selector,
                'element_selector' => $selector,
            ]);

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

        $suggested = $this->suggestedChange($vision, $taskKey, $definition);

        if ($suggested !== []) {
            $changes = $this->normalizeChanges($step, $task, $suggested);

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
                ['temperature' => 0.1, 'max_completion_tokens' => 1200],
            );
        } catch (\Throwable $exception) {
            return $this->pausePlan($taskKey, 'Vision und DOM liefern keinen sicheren Reparaturkandidaten; die Planungs-KI war nicht verfuegbar: '.$exception->getMessage());
        }

        $action = trim((string) ($decision['action'] ?? 'pause'));

        if ($action === 'retry') {
            return [
                'action' => 'retry',
                'task_key' => $taskKey,
                'reason' => Str::limit(trim((string) ($decision['reason'] ?? 'Task erneut ausfuehren.')), 1000, ''),
            ];
        }

        if ($action === 'continue_route') {
            return [
                'action' => 'continue_route',
                'task_key' => $taskKey,
                'reason' => Str::limit(trim((string) ($decision['reason'] ?? 'Konfigurierte Fehlerroute fortsetzen.')), 1000, ''),
            ];
        }

        $changes = $this->normalizeChanges(
            $step,
            $task,
            is_array($decision['changes'] ?? null) ? $decision['changes'] : [],
        );

        if ($action === 'update_task' && $changes !== []) {
            return [
                'action' => 'probe_update',
                'task_key' => $taskKey,
                'task_catalog_key' => $taskCatalogKey,
                'changes' => $changes,
                'probe_task' => array_replace($task, $changes, [
                    'key' => $taskKey.'--copilot-probe',
                    'title' => ($task['title'] ?? $taskKey).' (Copilot-Probe)',
                ]),
                'reason' => Str::limit(trim((string) ($decision['reason'] ?? 'Katalogkonforme Task-Anpassung pruefen.')), 1000, ''),
                'original_task_key' => $taskKey,
            ];
        }

        return $this->pausePlan($taskKey, Str::limit(trim((string) ($decision['reason'] ?? 'Keine sichere autonome Reparatur gefunden.')), 1000, ''));
    }

    public function applyChangesToStep(WorkflowStep $step, string $taskKey, array $changes): array
    {
        $this->assertWorkflowMayBeMutated($step);

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

    protected function selectorCandidates(array $vision, array $observation): \Illuminate\Support\Collection
    {
        $references = collect($vision['relevant_elements'] ?? [])
            ->flatMap(function (mixed $element): array {
                if (is_string($element)) {
                    return [$element];
                }

                if (! is_array($element)) {
                    return [];
                }

                return array_filter([
                    $element['element_ref'] ?? null,
                    $element['ref'] ?? null,
                    $element['id'] ?? null,
                    $element['selector'] ?? null,
                ]);
            })
            ->map(fn (mixed $value): string => trim((string) $value))
            ->filter();
        $elements = collect($observation['interaction_map'] ?? $observation['elements'] ?? []);

        $candidates = $elements
            ->filter(function (mixed $element) use ($references): bool {
                if (! is_array($element)) {
                    return false;
                }

                if ($references->isEmpty()) {
                    return (bool) ($element['visible'] ?? false) && (bool) ($element['enabled'] ?? true);
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

    protected function suggestedChange(array $vision, string $taskKey, array $definition): array
    {
        $suggestion = collect($vision['suggested_task_actions'] ?? [])
            ->first(function (mixed $candidate) use ($taskKey): bool {
                return is_array($candidate)
                    && (! filled($candidate['task_key'] ?? null) || (string) $candidate['task_key'] === $taskKey);
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
    ): array
    {
        $catalogKey = trim((string) ($task['task_key'] ?? ''));
        $definition = $catalogKey !== '' ? $this->catalog->task($catalogKey) : null;

        if ($definition === null) {
            return [];
        }

        $changes = Arr::only($changes, $this->mutableFieldsForDefinition($definition));
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

    protected function assertWorkflowMayBeMutated(WorkflowStep $step): void
    {
        $activeSessionId = $step->workflow()
            ->value('active_workflow_copilot_session_id');

        if ($activeSessionId !== null && WorkflowCopilotSession::query()
            ->whereKey($activeSessionId)
            ->where('status', WorkflowCopilotSession::STATUS_VERIFYING)
            ->exists()) {
            throw new \DomainException('Waehrend des unveraenderlichen Kontrolllaufs duerfen keine Workflow-Reparaturen gespeichert werden.');
        }
    }

    protected function isSafeSelector(string $selector): bool
    {
        return $selector !== ''
            && mb_strlen($selector) <= 1000
            && ! preg_match('/(?:javascript:|<script|\beval\s*\(|\bFunction\s*\()/i', $selector);
    }

    protected function plannerPrompt(
        WorkflowCopilotSession $session,
        WorkflowStep $step,
        array $task,
        array $checkpoint,
        array $observation,
        array $vision,
    ): string {
        $payload = [
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
            'mutable_fields' => $this->mutableFieldsForDefinition(
                $this->catalog->task((string) ($task['task_key'] ?? '')) ?? [],
            ),
        ];

        return 'Waehle die kleinste sichere Reparatur. Schema: {"action":"retry|update_task|continue_route|pause","changes":{},"reason":"sichtbarer Befund"}. Daten: '.json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
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
