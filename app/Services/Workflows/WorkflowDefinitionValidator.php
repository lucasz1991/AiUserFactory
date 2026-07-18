<?php

namespace App\Services\Workflows;

use App\Models\Workflow;
use App\Models\WorkflowStep;
use DomainException;
use Illuminate\Support\Collection;

class WorkflowDefinitionValidator
{
    public function __construct(
        protected WorkflowTaskCatalog $catalog,
    ) {}

    /** @return array{valid:bool,diagnostics:array<int,array<string,mixed>>} */
    public function validate(Workflow $workflow, array $successCriteria = [], array $workflowInputs = []): array
    {
        $workflow->loadMissing(['steps' => fn ($query) => $query->ordered()]);
        $steps = $workflow->steps->filter(fn (WorkflowStep $step): bool => (bool) $step->is_enabled)->values();
        $stepKeys = $steps->pluck('action_key')->filter()->map(fn ($key): string => (string) $key)->all();
        $taskKeysByStep = $steps->mapWithKeys(fn (WorkflowStep $step): array => [
            (string) $step->action_key => collect($step->task_cards)
                ->pluck('key')
                ->filter()
                ->map(fn ($key): string => (string) $key)
                ->values()
                ->all(),
        ])->all();
        $diagnostics = [];
        $returnConfigured = false;
        [$producerVariables, $arrayVariables] = $this->producerVariables($steps, $workflowInputs);

        if ($steps->isEmpty()) {
            $diagnostics[] = $this->diagnostic('error', 'workflow_empty', null, null, 'steps', 'Der Workflow besitzt keine aktivierte Liste mit ausfuehrbaren Tasks.', 'Mindestens eine aktivierte Liste mit einer Katalog-Task erstellen.');
        }

        foreach ($steps as $step) {
            $tasks = collect($step->task_cards)->filter(fn (mixed $task): bool => is_array($task))->values();
            $taskKeys = $tasks->pluck('key')->filter()->map(fn ($key): string => (string) $key)->all();
            $duplicates = collect($taskKeys)->duplicates()->unique()->values();

            foreach ($duplicates as $duplicate) {
                $diagnostics[] = $this->diagnostic('error', 'duplicate_task_key', $step, (string) $duplicate, 'key', 'Der Task-Key ist innerhalb der Liste nicht eindeutig.', 'Einen stabilen eindeutigen Karten-Key vergeben.');
            }

            $openLoop = null;
            $loopBody = collect();

            foreach ($tasks as $index => $task) {
                $cardKey = trim((string) ($task['key'] ?? ''));
                $catalogKey = trim((string) ($task['task_key'] ?? ''));
                $definition = $catalogKey !== '' ? $this->catalog->task($catalogKey) : null;

                if (! $definition && $this->isValidEmbeddedWorkflowTask($workflow, $task, $catalogKey)) {
                    // Embedded workflows are stored as synthetic workflow.include.<id>
                    // cards and therefore intentionally have no catalog entry.
                    $definition = ['documentation' => ['inputs' => []]];
                }

                if ($cardKey === '') {
                    $diagnostics[] = $this->diagnostic('error', 'task_key_missing', $step, null, 'key', 'Eine Task besitzt keinen stabilen Karten-Key.', 'Einen eindeutigen key setzen.');
                }
                if (! $definition) {
                    $diagnostics[] = $this->diagnostic('error', 'unknown_catalog_task', $step, $cardKey, 'task_key', 'Die Task verweist auf keinen bekannten Katalogeintrag: '.$catalogKey, 'Eine Task aus dem WorkflowTaskCatalog verwenden.');

                    continue;
                }

                foreach (data_get($definition, 'documentation.inputs', []) as $field) {
                    if (! is_array($field) || ! (bool) ($field['required'] ?? false)) {
                        continue;
                    }
                    $fieldName = trim((string) ($field['name'] ?? ''));
                    if ($fieldName !== '' && blank($task[$fieldName] ?? null)) {
                        $diagnostics[] = $this->diagnostic('error', 'required_configuration_missing', $step, $cardKey, $fieldName, 'Die erforderliche Task-Konfiguration `'.$fieldName.'` fehlt.', 'Das Pflichtfeld anhand der Katalogdokumentation konfigurieren.');
                    }
                }

                foreach ($this->routes($task) as $field => $route) {
                    $this->validateRoute($diagnostics, $step, $cardKey, $field, $route, $stepKeys, $taskKeysByStep);
                }

                if ($catalogKey === 'data.workflow_return') {
                    $returnConfigured = true;
                    $source = trim((string) ($task['selector'] ?? 'workflow_return')) ?: 'workflow_return';
                    $literal = $task['value'] ?? $task['input'] ?? null;

                    if (blank($literal) && $source !== 'workflow_return' && ! $producerVariables->contains($source)) {
                        $diagnostics[] = $this->diagnostic('error', 'workflow_return_source_missing', $step, $cardKey, 'selector', 'Die Rueckgabe liest aus `'.$source.'`, aber keine Workflow-Eingabe oder vorhersehbare Task erzeugt diese Variable.', 'Eine vorhandene Ausgabevariable verwenden oder die fehlende Producer-Task ergaenzen.');
                    }
                    if ($this->expectsArrayReturn($successCriteria)
                        && ! $this->isArrayLiteral($literal)
                        && ! $arrayVariables->contains($source)
                    ) {
                        $diagnostics[] = $this->diagnostic('error', 'workflow_return_array_source_missing', $step, $cardKey, 'selector', 'Die Erfolgskriterien verlangen ein Array, aber die Rueckgabe verweist auf kein ueberpruefbares Array-Ziel.', 'Ein durch Loop, Array-Task oder Workflow-Eingabe erzeugtes Array zurueckgeben.');
                    }
                }

                if ($catalogKey === 'data.append_to_array') {
                    $arrayName = trim((string) ($task['array_name'] ?? $task['arrayName'] ?? ''));
                    $source = trim((string) ($task['value_from_variable'] ?? $task['valueFromVariable'] ?? ''));
                    if ($arrayName === '') {
                        $diagnostics[] = $this->diagnostic('error', 'collection_array_target_missing', $step, $cardKey, 'array_name', 'Die Collection-Task besitzt kein benanntes Array-Ziel.', 'array_name auf einen stabilen Workflow-Variablennamen setzen.');
                    }
                    if ($source === '') {
                        $diagnostics[] = $this->diagnostic('error', 'collection_source_missing', $step, $cardKey, 'value_from_variable', 'Die Collection-Task besitzt keine Quellvariable.', 'value_from_variable auf die Ausgabe einer Reader- oder Daten-Task setzen.');
                    } elseif (! $producerVariables->contains($source)) {
                        $diagnostics[] = $this->diagnostic('error', 'collection_producer_missing', $step, $cardKey, 'value_from_variable', 'Die Collection liest aus `'.$source.'`, aber keine Workflow-Eingabe oder Task erzeugt diese Variable.', 'Eine Producer-Task mit passender output_variable vor dem Consumer konfigurieren.');
                    }
                }

                if ($catalogKey === 'decision.array_length') {
                    $arrayName = trim((string) ($task['array_name'] ?? $task['arrayName'] ?? ''));
                    if ($arrayName === '' || ! $arrayVariables->contains($arrayName)) {
                        $diagnostics[] = $this->diagnostic('error', 'array_producer_missing', $step, $cardKey, 'array_name', 'Die Array-Pruefung verweist auf kein ueberpruefbar erzeugtes Array.', 'Ein vorhandenes Array-Ziel aus Loop, Workflow-Eingabe oder data.append_to_array verwenden.');
                    }
                }

                if ($catalogKey === 'loop.for_each_element') {
                    if ($openLoop !== null) {
                        $diagnostics[] = $this->diagnostic('error', 'nested_loop_unsupported', $step, $cardKey, 'loop_pair_id', 'Ein neuer Loop beginnt, bevor der vorherige Loop beendet wurde.', 'Loops in getrennte Listen verschieben oder nacheinander vollstaendig schliessen.');
                    }
                    $openLoop = $task;
                    $loopBody = collect();

                    continue;
                }

                if ($catalogKey === 'loop.end') {
                    if ($openLoop === null) {
                        $diagnostics[] = $this->diagnostic('error', 'orphan_loop_end', $step, $cardKey, 'loop_start_key', 'Das Loop-Ende besitzt keinen vorangehenden Loop-Start.', 'Loop-Ende nur gemeinsam mit loop.for_each_element verwenden.');

                        continue;
                    }

                    if ($loopBody->isEmpty()) {
                        $diagnostics[] = $this->diagnostic('error', 'loop_body_empty', $step, (string) ($openLoop['key'] ?? ''), 'loop_end_key', 'Zwischen Loop-Start und Loop-Ende befindet sich keine Reader- oder Consumer-Task.', 'Mindestens einen Reader und den benoetigten Array-Consumer in den Loop-Body setzen.');
                    }
                    $this->validateLoopCollection($diagnostics, $step, $openLoop, $loopBody);
                    $openLoop = null;
                    $loopBody = collect();

                    continue;
                }

                if ($openLoop !== null) {
                    $loopBody->push($task);
                }

                if (str_starts_with($catalogKey, 'decision.')) {
                    $trueTarget = $this->routeIdentity($task['next'] ?? null);
                    $falseTarget = $this->routeIdentity($task['on_error'] ?? data_get($task, 'status_routes.failed'));
                    if ($trueTarget !== '' && $trueTarget === $falseTarget) {
                        $diagnostics[] = $this->diagnostic('error', 'decision_routes_identical', $step, $cardKey, 'next', 'Wahr- und Falschroute der Entscheidung zeigen auf dasselbe Ziel.', 'Fachlich unterschiedliche Ziele fuer beide Ausgaenge konfigurieren.');
                    }
                }
            }

            if ($openLoop !== null) {
                $diagnostics[] = $this->diagnostic('error', 'loop_end_missing', $step, (string) ($openLoop['key'] ?? ''), 'loop_end_key', 'Zum Loop-Start fehlt das gekoppelte loop.end.', 'Das gekoppelte Loop-Ende hinter dem Body einfuegen.');
            }

            foreach (is_array(data_get($step->config_json, 'routes')) ? data_get($step->config_json, 'routes') : [] as $field => $route) {
                $this->validateRoute($diagnostics, $step, null, 'routes.'.$field, $route, $stepKeys, $taskKeysByStep);
            }
        }

        $expectsReturn = $this->expectsReturn($successCriteria);
        if ($expectsReturn && ! $returnConfigured) {
            $diagnostics[] = $this->diagnostic('error', 'workflow_return_missing', null, null, 'success_criteria', 'Die Erfolgskriterien verlangen eine Ausgabe, aber der Workflow besitzt keine data.workflow_return-Task.', 'Das fertige Ergebnis explizit mit data.workflow_return zurueckgeben.');
        }

        return [
            'valid' => ! collect($diagnostics)->contains(fn (array $diagnostic): bool => $diagnostic['severity'] === 'error'),
            'diagnostics' => $diagnostics,
        ];
    }

    public function assertValid(Workflow $workflow, array $successCriteria = [], array $workflowInputs = []): array
    {
        $result = $this->validate($workflow, $successCriteria, $workflowInputs);
        if (! $result['valid']) {
            $messages = collect($result['diagnostics'])
                ->where('severity', 'error')
                ->pluck('message')
                ->take(5)
                ->implode(' ');
            throw new DomainException('Workflow-Definition ist nicht ausfuehrbar: '.$messages);
        }

        return $result;
    }

    private function validateLoopCollection(array &$diagnostics, WorkflowStep $step, array $loop, Collection $body): void
    {
        $arrayName = trim((string) ($loop['collect_to_array'] ?? $loop['collectToArray'] ?? ''));
        if ($arrayName === '') {
            return;
        }

        $source = trim((string) ($loop['collect_from_variable'] ?? $loop['collectFromVariable'] ?? 'current_result')) ?: 'current_result';
        $hasProducer = $body->contains(function (array $task) use ($source): bool {
            $output = trim((string) ($task['output_variable'] ?? $task['outputVariable'] ?? ''));
            if ($output === '' && in_array((string) ($task['task_key'] ?? ''), ['browser.read_element_fields', 'browser.read_searchengine_result'], true)) {
                $output = 'current_result';
            }

            return $output === $source;
        });
        if (! $hasProducer) {
            $diagnostics[] = $this->diagnostic('error', 'loop_collection_producer_missing', $step, (string) ($loop['key'] ?? ''), 'collect_from_variable', 'Der Loop sammelt aus `'.$source.'`, aber im Loop-Body erzeugt keine Reader- oder Daten-Task diese Variable.', 'Eine Reader-Task mit passender output_variable vor dem Loop-Ende einfuegen.');
        }

        $duplicateConsumer = $body->contains(function (array $task) use ($arrayName): bool {
            return ($task['task_key'] ?? null) === 'data.append_to_array'
                && trim((string) ($task['array_name'] ?? $task['arrayName'] ?? '')) === $arrayName;
        });
        if ($duplicateConsumer) {
            $diagnostics[] = $this->diagnostic('error', 'duplicate_collection_strategy', $step, (string) ($loop['key'] ?? ''), 'collect_to_array', 'Der Loop und data.append_to_array sammeln gleichzeitig in `'.$arrayName.'`.', 'Genau eine Collection-Strategie verwenden.');
        }
    }

    /** @return array{Collection<int,string>,Collection<int,string>} */
    private function producerVariables(Collection $steps, array $workflowInputs): array
    {
        $variables = collect(array_keys($workflowInputs))->map(fn (mixed $key): string => trim((string) $key))->filter();
        $arrays = collect($workflowInputs)
            ->filter(fn (mixed $value): bool => is_array($value))
            ->keys()
            ->map(fn (mixed $key): string => trim((string) $key))
            ->filter();

        foreach ($steps as $step) {
            foreach (collect($step->task_cards)->filter(fn (mixed $task): bool => is_array($task)) as $task) {
                $catalogKey = (string) ($task['task_key'] ?? '');
                foreach (['output_variable', 'output_group'] as $field) {
                    $name = trim((string) ($task[$field] ?? ''));
                    if ($name !== '') {
                        $variables->push($name);
                    }
                }
                foreach (['output_array_name', 'collect_to_array'] as $field) {
                    $name = trim((string) ($task[$field] ?? ''));
                    if ($name !== '') {
                        $variables->push($name);
                        $arrays->push($name);
                    }
                }
                if ($catalogKey === 'loop.for_each_element') {
                    $variables->push(trim((string) ($task['store_current_element_as'] ?? 'current_result')) ?: 'current_result');
                    $variables->push(trim((string) ($task['store_index_as'] ?? 'result_index')) ?: 'result_index');
                }
                if ($catalogKey === 'data.append_to_array') {
                    $name = trim((string) ($task['array_name'] ?? $task['arrayName'] ?? ''));
                    if ($name !== '') {
                        $variables->push($name);
                        $arrays->push($name);
                    }
                }
                if (in_array($catalogKey, ['browser.read_element_fields', 'browser.read_searchengine_result'], true)
                    && blank($task['output_variable'] ?? null)
                ) {
                    $variables->push('current_result');
                }
            }
        }

        return [$variables->unique()->values(), $arrays->unique()->values()];
    }

    private function expectsReturn(array $successCriteria): bool
    {
        return collect($successCriteria)->contains(function (mixed $criterion): bool {
            $text = is_scalar($criterion)
                ? (string) $criterion
                : (json_encode($criterion, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');

            return preg_match('/(?:return|rueckgabe|rueckgabewert|ausgabe|array|ergebnis)/iu', $text) === 1;
        });
    }

    private function expectsArrayReturn(array $successCriteria): bool
    {
        return collect($successCriteria)->contains(function (mixed $criterion): bool {
            $text = is_scalar($criterion)
                ? (string) $criterion
                : (json_encode($criterion, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');

            return preg_match('/\barray\b/iu', $text) === 1;
        });
    }

    private function isArrayLiteral(mixed $value): bool
    {
        if (is_array($value)) {
            return true;
        }
        if (! is_string($value) || trim($value) === '') {
            return false;
        }

        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE && is_array($decoded);
    }

    private function validateRoute(array &$diagnostics, WorkflowStep $step, ?string $cardKey, string $field, mixed $route, array $stepKeys, array $taskKeysByStep): void
    {
        if (! is_array($route)) {
            return;
        }
        $type = trim((string) ($route['type'] ?? ''));
        if (in_array($type, ['end', 'fail'], true)) {
            return;
        }
        $targetStep = trim((string) ($route['action_key'] ?? $route['step'] ?? ''));
        $targetTask = trim((string) ($route['card_key'] ?? $route['card'] ?? ''));

        // Older cards store terminal routes as {"step":"fail"} or
        // {"step":"end"} without an explicit type. The runtime accepts both.
        if ($targetTask === '' && in_array($targetStep, ['end', 'fail'], true)) {
            return;
        }

        $effectiveStep = $targetStep === '' ? (string) $step->action_key : $targetStep;

        if ($targetStep !== '' && $targetStep !== 'next' && ! in_array($targetStep, $stepKeys, true)) {
            $diagnostics[] = $this->diagnostic('error', 'route_step_missing', $step, $cardKey, $field, 'Das Routenziel `'.$targetStep.'` existiert nicht.', 'Eine vorhandene action_key oder type=end/fail verwenden.');
        }
        if ($targetTask !== '' && $targetStep !== 'next') {
            $targetTaskKeys = $taskKeysByStep[$effectiveStep] ?? [];
            if (! in_array($targetTask, $targetTaskKeys, true)) {
                $diagnostics[] = $this->diagnostic('error', 'route_task_missing', $step, $cardKey, $field, 'Die Ziel-Task `'.$targetTask.'` existiert in der Zielliste nicht.', 'Einen vorhandenen Karten-Key der Zielliste verwenden.');
            }
        }
        if ($cardKey !== null
            && $targetTask === $cardKey
            && $effectiveStep === (string) $step->action_key
            && max(0, (int) ($route['max_attempts'] ?? $route['retry_limit'] ?? 0)) === 0
        ) {
            $diagnostics[] = $this->diagnostic('error', 'unsafe_self_route', $step, $cardKey, $field, 'Die Task routet ohne nachweisbare Zustandsaenderung auf sich selbst.', 'Ein anderes Ziel verwenden oder einen begrenzten Retry-Mechanismus konfigurieren.');
        }
    }

    private function routes(array $task): array
    {
        $routes = collect(['next', 'on_partial', 'on_error'])
            ->filter(fn (string $field): bool => is_array($task[$field] ?? null))
            ->mapWithKeys(fn (string $field): array => [$field => $task[$field]])
            ->all();
        foreach (is_array($task['status_routes'] ?? null) ? $task['status_routes'] : [] as $status => $route) {
            if (is_array($route)) {
                $routes['status_routes.'.$status] = $route;
            }
        }

        return $routes;
    }

    private function routeIdentity(mixed $route): string
    {
        return is_array($route) ? implode(':', [
            (string) ($route['type'] ?? ''),
            (string) ($route['action_key'] ?? $route['step'] ?? ''),
            (string) ($route['card_key'] ?? $route['card'] ?? ''),
        ]) : '';
    }

    private function isValidEmbeddedWorkflowTask(Workflow $parent, array $task, string $catalogKey): bool
    {
        if (! preg_match('/^workflow\.include\.(\d+)$/', $catalogKey, $matches)) {
            return false;
        }

        $referencedId = (int) ($task['workflow_id'] ?? $matches[1]);

        if ($referencedId <= 0 || $referencedId !== (int) $matches[1] || $referencedId === (int) $parent->getKey()) {
            return false;
        }

        $embedded = Workflow::query()->find($referencedId);

        return $embedded !== null
            && (bool) $embedded->is_active
            && ! $embedded->includesWorkflow((int) $parent->getKey());
    }

    private function diagnostic(string $severity, string $code, ?WorkflowStep $step, ?string $taskKey, string $field, string $message, string $repairHint): array
    {
        return [
            'severity' => $severity,
            'code' => $code,
            'step_action_key' => $step?->action_key,
            'task_key' => $taskKey,
            'field' => $field,
            'message' => $message,
            'repair_hint' => $repairHint,
        ];
    }
}
