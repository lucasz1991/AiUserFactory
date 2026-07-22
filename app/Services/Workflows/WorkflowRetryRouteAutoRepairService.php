<?php

namespace App\Services\Workflows;

use App\Models\Workflow;
use App\Models\WorkflowStep;

class WorkflowRetryRouteAutoRepairService
{
    /**
     * Standard-Versuchslimit, das unbegrenzte Rueckwaerts-Fehlerrouten vor der
     * Startvalidierung erhalten, damit `unbounded_backward_retry_route` den
     * Teststart nicht blockiert und der Zyklus nach einem Versuch endet.
     */
    public const DEFAULT_MAX_ATTEMPTS = 1;

    /**
     * Setzt fuer alle Fehlerrouten, die ohne Versuchsbegrenzung auf eine
     * frueher liegende Liste zurueckspringen, automatisch
     * max_attempts=DEFAULT_MAX_ATTEMPTS und persistiert die betroffenen Steps.
     *
     * @return array<int,array{step:string,card:?string,field:string,target:string}>
     */
    public function repair(Workflow $workflow): array
    {
        $workflow->loadMissing(['steps' => fn ($query) => $query->ordered()]);
        $steps = $workflow->steps->filter(fn (WorkflowStep $step): bool => (bool) $step->is_enabled)->values();
        $stepPositions = $steps
            ->mapWithKeys(fn (WorkflowStep $step, int $index): array => [(string) $step->action_key => $index])
            ->all();
        $repaired = [];

        foreach ($steps as $step) {
            $config = is_array($step->config_json) ? $step->config_json : [];
            $dirty = false;

            $tasks = is_array($config['tasks'] ?? null) ? $config['tasks'] : [];
            foreach ($tasks as $index => $task) {
                if (! is_array($task)) {
                    continue;
                }

                foreach ($this->failureRouteFields($task) as $field => $route) {
                    if (! $this->isUnboundedBackwardRoute($route, $step, $stepPositions)) {
                        continue;
                    }

                    data_set($tasks[$index], $field.'.max_attempts', self::DEFAULT_MAX_ATTEMPTS);
                    $dirty = true;
                    $repaired[] = [
                        'step' => (string) $step->action_key,
                        'card' => trim((string) ($task['key'] ?? '')) ?: null,
                        'field' => $field,
                        'target' => trim((string) ($route['action_key'] ?? $route['step'] ?? '')),
                    ];
                }
            }

            if ($dirty) {
                $config['tasks'] = $tasks;
            }

            foreach (is_array($config['routes'] ?? null) ? $config['routes'] : [] as $field => $route) {
                if (! $this->isFailureRouteField('routes.'.$field)
                    || ! $this->isUnboundedBackwardRoute($route, $step, $stepPositions)
                ) {
                    continue;
                }

                $config['routes'][$field]['max_attempts'] = self::DEFAULT_MAX_ATTEMPTS;
                $dirty = true;
                $repaired[] = [
                    'step' => (string) $step->action_key,
                    'card' => null,
                    'field' => 'routes.'.$field,
                    'target' => trim((string) ($route['action_key'] ?? $route['step'] ?? '')),
                ];
            }

            if ($dirty) {
                $step->forceFill(['config_json' => $config])->save();
            }
        }

        return $repaired;
    }

    /** @return array<string,array<string,mixed>> */
    private function failureRouteFields(array $task): array
    {
        $routes = [];

        if (is_array($task['on_error'] ?? null)) {
            $routes['on_error'] = $task['on_error'];
        }

        foreach (is_array($task['status_routes'] ?? null) ? $task['status_routes'] : [] as $status => $route) {
            $field = 'status_routes.'.$status;
            if (is_array($route) && $this->isFailureRouteField($field)) {
                $routes[$field] = $route;
            }
        }

        return $routes;
    }

    private function isFailureRouteField(string $field): bool
    {
        $normalized = strtolower($field);

        return str_contains($normalized, 'on_error')
            || str_contains($normalized, 'failed')
            || str_contains($normalized, 'error');
    }

    /**
     * Spiegelt die `unbounded_backward_retry_route`-Bedingung des
     * WorkflowDefinitionValidator: Fehlerroute ohne Versuchslimit auf eine
     * frueher liegende, existierende Liste.
     */
    private function isUnboundedBackwardRoute(mixed $route, WorkflowStep $step, array $stepPositions): bool
    {
        if (! is_array($route)) {
            return false;
        }

        $type = trim((string) ($route['type'] ?? ''));
        if (in_array($type, ['end', 'fail'], true)) {
            return false;
        }

        $targetStep = trim((string) ($route['action_key'] ?? $route['step'] ?? ''));
        if (in_array($targetStep, ['', 'next', 'end', 'fail'], true)
            || $targetStep === (string) $step->action_key
        ) {
            return false;
        }

        $attemptLimit = max(0, (int) ($route['max_attempts'] ?? $route['retry_limit'] ?? 0));

        return $attemptLimit === 0
            && array_key_exists($targetStep, $stepPositions)
            && array_key_exists((string) $step->action_key, $stepPositions)
            && $stepPositions[$targetStep] < $stepPositions[(string) $step->action_key];
    }
}
