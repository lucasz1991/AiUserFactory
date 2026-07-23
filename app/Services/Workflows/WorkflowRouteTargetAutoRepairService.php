<?php

namespace App\Services\Workflows;

use App\Models\Workflow;
use App\Models\WorkflowStep;

/**
 * Findet und repariert Routen, deren Ziel es nicht mehr gibt.
 *
 * Wird eine Task-Karte oder eine Liste geloescht, zeigen die Routen anderer
 * Karten weiterhin auf deren Key. Der Teststart bricht dann mit
 * `route_task_missing` bzw. `route_step_missing` ab
 * (`WorkflowDefinitionValidator.php:408`, `:413`).
 *
 * Anders als der `WorkflowRetryRouteAutoRepairService`, der ein fehlendes
 * Versuchslimit still ergaenzt, repariert dieser Service **nicht automatisch**:
 * `analyze()` liefert die Befunde samt vorgeschlagener Standardroute, damit die
 * Oberflaeche sie zur Bestaetigung anzeigen kann; erst `repair()` schreibt.
 * Ein verlorenes Routenziel ist ein echter Strukturverlust und soll dem Nutzer
 * bewusst werden.
 *
 * Siehe README-Abschnitt „Feature R1".
 */
class WorkflowRouteTargetAutoRepairService
{
    /**
     * Routenfelder, deren Ausfall fachlich ein Fehler ist. Fuer sie ist die
     * Standardroute `type: fail` — stilles Weiterlaufen waere gefaehrlicher als
     * ein expliziter, terminaler Abbruch.
     */
    private const FAILURE_FIELD_MARKERS = [
        'on_error',
        'error',
        'failed',
        'fail',
        'timeout',
        'timed_out',
        'cancelled',
        'aborted',
    ];

    /**
     * Liefert alle Routen mit fehlendem Ziel und die jeweils vorgeschlagene
     * Standardroute — ohne etwas zu speichern.
     *
     * @return list<array{
     *     step:string, step_name:string, card:?string, card_title:string,
     *     field:string, field_label:string, code:string,
     *     current_target:string, default_route:array<string,mixed>, default_label:string
     * }>
     */
    public function analyze(Workflow $workflow): array
    {
        $steps = $this->enabledSteps($workflow);
        $stepKeys = $steps->pluck('action_key')->filter()->map(fn ($key): string => (string) $key)->all();
        $taskKeysByStep = $steps->mapWithKeys(fn (WorkflowStep $step): array => [
            (string) $step->action_key => $this->cardKeys($step),
        ])->all();

        $findings = [];

        foreach ($steps as $stepIndex => $step) {
            $nextStep = $steps->get($stepIndex + 1);
            $cardKeys = $taskKeysByStep[(string) $step->action_key] ?? [];

            foreach ($this->rawTasks($step) as $task) {
                $cardKey = trim((string) ($task['key'] ?? ''));

                foreach ($this->routeFields($task) as $field => $route) {
                    $problem = $this->routeProblem($route, $step, $stepKeys, $taskKeysByStep);

                    if ($problem === null) {
                        continue;
                    }

                    $findings[] = $this->finding(
                        $step,
                        $cardKey,
                        trim((string) ($task['title'] ?? '')),
                        $field,
                        $route,
                        $problem,
                        $this->defaultRouteForCard($field, $cardKey, $cardKeys, $nextStep),
                    );
                }
            }

            foreach ($this->stepRoutes($step) as $field => $route) {
                $problem = $this->routeProblem($route, $step, $stepKeys, $taskKeysByStep);

                if ($problem === null) {
                    continue;
                }

                $findings[] = $this->finding(
                    $step,
                    null,
                    '',
                    'routes.'.$field,
                    $route,
                    $problem,
                    $this->defaultRouteForStep('routes.'.$field, $nextStep),
                );
            }
        }

        return $findings;
    }

    /**
     * Setzt alle von `analyze()` gefundenen Routen auf ihre Standardroute und
     * persistiert die betroffenen Listen.
     *
     * @return list<array<string,mixed>> die tatsaechlich angewendeten Befunde
     */
    public function repair(Workflow $workflow): array
    {
        $findings = $this->analyze($workflow);

        if ($findings === []) {
            return [];
        }

        $byStep = [];

        foreach ($findings as $finding) {
            $byStep[$finding['step']][] = $finding;
        }

        foreach ($this->enabledSteps($workflow) as $step) {
            $stepFindings = $byStep[(string) $step->action_key] ?? [];

            if ($stepFindings === []) {
                continue;
            }

            $config = is_array($step->config_json) ? $step->config_json : [];
            $tasks = is_array($config['tasks'] ?? null) ? $config['tasks'] : [];

            foreach ($stepFindings as $finding) {
                if ($finding['card'] === null) {
                    $field = substr($finding['field'], strlen('routes.'));
                    $config['routes'][$field] = $finding['default_route'];

                    continue;
                }

                foreach ($tasks as $index => $task) {
                    if (! is_array($task) || trim((string) ($task['key'] ?? '')) !== $finding['card']) {
                        continue;
                    }

                    data_set($tasks[$index], $finding['field'], $finding['default_route']);

                    break;
                }
            }

            $config['tasks'] = $tasks;
            $step->forceFill(['config_json' => $config])->save();
        }

        // Der Workflow haelt die Listen zwischengespeichert; ohne Neuladen
        // wuerde eine anschliessende Validierung noch den alten Stand sehen.
        $workflow->unsetRelation('steps');

        return $findings;
    }

    /**
     * @return \Illuminate\Support\Collection<int, WorkflowStep>
     */
    private function enabledSteps(Workflow $workflow): \Illuminate\Support\Collection
    {
        $workflow->loadMissing(['steps' => fn ($query) => $query->ordered()]);

        return $workflow->steps->filter(fn (WorkflowStep $step): bool => (bool) $step->is_enabled)->values();
    }

    /** @return list<string> */
    private function cardKeys(WorkflowStep $step): array
    {
        return collect($this->rawTasks($step))
            ->pluck('key')
            ->filter()
            ->map(fn ($key): string => (string) $key)
            ->values()
            ->all();
    }

    /** @return list<array<string,mixed>> */
    private function rawTasks(WorkflowStep $step): array
    {
        $tasks = data_get($step->config_json, 'tasks', []);

        return is_array($tasks)
            ? array_values(array_filter($tasks, fn (mixed $task): bool => is_array($task)))
            : [];
    }

    /** @return array<string,mixed> */
    private function stepRoutes(WorkflowStep $step): array
    {
        $routes = data_get($step->config_json, 'routes', []);

        return is_array($routes) ? $routes : [];
    }

    /** @return array<string,array<string,mixed>> */
    private function routeFields(array $task): array
    {
        $fields = [];

        foreach (['next', 'on_partial', 'on_error'] as $field) {
            if (is_array($task[$field] ?? null)) {
                $fields[$field] = $task[$field];
            }
        }

        foreach (is_array($task['status_routes'] ?? null) ? $task['status_routes'] : [] as $outcome => $route) {
            if (is_array($route)) {
                $fields['status_routes.'.$outcome] = $route;
            }
        }

        return $fields;
    }

    /**
     * Spiegelt `WorkflowDefinitionValidator::validateRoute()`: liefert den
     * Diagnosecode, wenn das Ziel fehlt, sonst null.
     *
     * @param  array<string,list<string>>  $taskKeysByStep
     * @param  list<string>  $stepKeys
     */
    private function routeProblem(mixed $route, WorkflowStep $step, array $stepKeys, array $taskKeysByStep): ?string
    {
        if (! is_array($route)) {
            return null;
        }

        $type = trim((string) ($route['type'] ?? ''));

        if (in_array($type, ['end', 'fail'], true)) {
            return null;
        }

        $targetStep = trim((string) ($route['action_key'] ?? $route['step'] ?? ''));
        $targetTask = trim((string) ($route['card_key'] ?? $route['card'] ?? ''));

        if ($targetTask === '' && in_array($targetStep, ['end', 'fail'], true)) {
            return null;
        }

        if ($targetStep !== '' && $targetStep !== 'next' && ! in_array($targetStep, $stepKeys, true)) {
            return 'route_step_missing';
        }

        if ($targetTask !== '' && $targetStep !== 'next') {
            $effectiveStep = $targetStep === '' ? (string) $step->action_key : $targetStep;

            if (! in_array($targetTask, $taskKeysByStep[$effectiveStep] ?? [], true)) {
                return 'route_task_missing';
            }
        }

        return null;
    }

    private function isFailureField(string $field): bool
    {
        $normalized = strtolower($field);

        foreach (self::FAILURE_FIELD_MARKERS as $marker) {
            if (str_contains($normalized, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Standardroute einer Karten-Route: das, was die Runtime ohne Route ohnehin
     * tun wuerde — die naechste Karte derselben Liste, sonst die naechste Liste,
     * sonst Workflow-Ende. Fehlerrouten enden explizit mit `fail`.
     *
     * @param  list<string>  $cardKeys
     * @return array<string,mixed>
     */
    private function defaultRouteForCard(string $field, string $cardKey, array $cardKeys, ?WorkflowStep $nextStep): array
    {
        if ($this->isFailureField($field)) {
            return ['type' => 'fail'];
        }

        $position = array_search($cardKey, $cardKeys, true);

        if ($position !== false && isset($cardKeys[$position + 1])) {
            $nextCard = $cardKeys[$position + 1];

            return ['type' => 'card', 'card_key' => $nextCard, 'card' => $nextCard];
        }

        return $this->defaultRouteForStep($field, $nextStep);
    }

    /** @return array<string,mixed> */
    private function defaultRouteForStep(string $field, ?WorkflowStep $nextStep): array
    {
        if ($this->isFailureField($field)) {
            return ['type' => 'fail'];
        }

        return $nextStep instanceof WorkflowStep
            ? ['step' => 'next']
            : ['type' => 'end'];
    }

    /**
     * @param  array<string,mixed>  $route
     * @param  array<string,mixed>  $defaultRoute
     * @return array<string,mixed>
     */
    private function finding(
        WorkflowStep $step,
        ?string $cardKey,
        string $cardTitle,
        string $field,
        array $route,
        string $code,
        array $defaultRoute,
    ): array {
        $targetStep = trim((string) ($route['action_key'] ?? $route['step'] ?? ''));
        $targetCard = trim((string) ($route['card_key'] ?? $route['card'] ?? ''));

        return [
            'step' => (string) $step->action_key,
            'step_name' => (string) $step->name,
            'card' => $cardKey === '' ? null : $cardKey,
            'card_title' => $cardTitle,
            'field' => $field,
            'field_label' => $this->fieldLabel($field),
            'code' => $code,
            // Rohziele zusaetzlich zum lesbaren Label, damit Aufrufer einen
            // Treffer einem konkreten geloeschten Key zuordnen koennen (R2).
            'target_step' => $targetStep === '' ? null : $targetStep,
            'target_card' => $targetCard === '' ? null : $targetCard,
            'current_target' => $this->routeLabel($route),
            'default_route' => $defaultRoute,
            'default_label' => $this->routeLabel($defaultRoute),
        ];
    }

    private function fieldLabel(string $field): string
    {
        return match (true) {
            $field === 'next' => 'Erfolgsroute',
            $field === 'on_partial' => 'Teilerfolg-Route',
            $field === 'on_error' => 'Fehlerroute',
            $field === 'routes.success' => 'Listen-Erfolgsroute',
            $field === 'routes.failed' => 'Listen-Fehlerroute',
            str_starts_with($field, 'status_routes.') => 'Status-Route „'.substr($field, strlen('status_routes.')).'"',
            str_starts_with($field, 'routes.') => 'Listen-Route „'.substr($field, strlen('routes.')).'"',
            default => $field,
        };
    }

    /** @param array<string,mixed> $route */
    private function routeLabel(array $route): string
    {
        $type = trim((string) ($route['type'] ?? ''));

        if ($type === 'end') {
            return 'Workflow beenden';
        }

        if ($type === 'fail') {
            return 'Workflow mit Fehler beenden';
        }

        $targetStep = trim((string) ($route['action_key'] ?? $route['step'] ?? ''));
        $targetTask = trim((string) ($route['card_key'] ?? $route['card'] ?? ''));

        if ($targetStep === 'next') {
            return 'Naechste Liste';
        }

        if ($targetTask !== '' && $targetStep !== '') {
            return $targetStep.' / '.$targetTask;
        }

        if ($targetTask !== '') {
            return 'Karte '.$targetTask;
        }

        return $targetStep !== '' ? 'Liste '.$targetStep : 'Unbestimmt';
    }
}
