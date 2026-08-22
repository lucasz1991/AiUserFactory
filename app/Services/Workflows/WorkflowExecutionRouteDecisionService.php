<?php

namespace App\Services\Workflows;

use App\Enums\WorkflowRouteDisposition;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;

class WorkflowExecutionRouteDecisionService
{
    public function routeForOutcome(WorkflowStep $step, string $outcome): ?array
    {
        $routes = $step->routes;
        $route = $routes[$outcome] ?? $routes['default'] ?? null;

        return is_array($route) ? $this->normalizeRoute($step, $route) : null;
    }

    public function routeForResult(WorkflowStep $step, string $outcome, array $result): ?array
    {
        $dynamicTarget = trim((string) ($result['routeTargetKey'] ?? $result['route_target_key'] ?? ''));
        if ((bool) ($result['routeRequested'] ?? $result['route_requested'] ?? false) && $dynamicTarget !== '') {
            return $this->normalizeRoute($step, [
                'type' => 'card',
                'action_key' => $step->action_key,
                'card_key' => $dynamicTarget,
                '_source_card_key' => trim((string) ($result['completedTaskKey'] ?? $result['completed_task_key'] ?? '')),
            ]);
        }

        if ((bool) ($result['routeRequested'] ?? false)) {
            $completedTaskKey = trim((string) (
                $result['completedTaskKey']
                ?? $result['completed_task_key']
                ?? $result['failedTaskKey']
                ?? $result['failed_task_key']
                ?? ''
            ));
            $sourceTask = collect($step->task_cards)
                ->first(fn (array $task): bool => (string) ($task['key'] ?? '') === $completedTaskKey);
            $route = is_array($sourceTask)
                ? ($outcome === 'success'
                    ? ($sourceTask['next'] ?? null)
                    : ($sourceTask['on_error'] ?? data_get($sourceTask, 'status_routes.'.$outcome)))
                : null;

            if (is_array($route)) {
                $route['_source_card_key'] = $completedTaskKey;

                return $this->normalizeRoute($step, $route);
            }

            if (is_array($sourceTask) && in_array($outcome, ['failed', 'timeout'], true)) {
                return null;
            }
        }

        if (in_array($outcome, ['failed', 'timeout'], true)) {
            $failedTaskKey = trim((string) ($result['failedTaskKey'] ?? $result['failed_task_key'] ?? ''));

            if ($failedTaskKey !== '') {
                $resultTask = collect(is_array($result['tasks'] ?? null) ? $result['tasks'] : [])
                    ->first(fn (mixed $task): bool => is_array($task) && (string) ($task['key'] ?? '') === $failedTaskKey);
                $sourceTaskKey = trim((string) data_get($resultTask, 'parent_task_key', $failedTaskKey));
                $sourceTask = collect($step->task_cards)
                    ->first(fn (array $task): bool => (string) ($task['key'] ?? '') === $sourceTaskKey);

                if ($sourceTask) {
                    $route = $sourceTask['on_error'] ?? data_get($sourceTask, 'status_routes.'.$outcome);

                    if (is_array($route)) {
                        $route['_source_card_key'] = $sourceTaskKey;

                        return $this->normalizeRoute($step, $route);
                    }

                    return null;
                }
            }
        }

        return $this->routeForOutcome($step, $outcome);
    }

    public function isContinuableFailureRoute(?array $route): bool
    {
        return WorkflowRouteDisposition::fromRoute($route) === WorkflowRouteDisposition::CONTINUE;
    }

    public function hasRouteForOutcome(WorkflowStep $step, string $outcome): bool
    {
        return $this->routeForOutcome($step, $outcome) !== null;
    }

    public function linearRouteAfterStep(WorkflowRun $run, WorkflowStep $currentStep, string $outcome): array
    {
        if ($outcome === 'failed') {
            return [
                'type' => 'fail',
                'label' => 'Fehler ohne explizite Route',
            ];
        }

        $steps = $run->workflow
            ->steps
            ->filter(fn (WorkflowStep $step): bool => $step->is_enabled)
            ->values();
        $currentIndex = $steps->search(fn (WorkflowStep $step): bool => $step->id === $currentStep->id);

        if ($currentIndex === false) {
            return ['type' => 'end', 'label' => 'Kein naechster Schritt'];
        }

        $nextStep = $steps->get($currentIndex + 1);

        if (! $nextStep) {
            return ['type' => 'end', 'label' => 'Workflow abschliessen'];
        }

        return [
            'type' => 'step',
            'action_key' => $nextStep->action_key,
            'label' => $nextStep->name,
        ];
    }

    public function normalizeRoute(WorkflowStep $sourceStep, array $route): array
    {
        $type = trim((string) ($route['type'] ?? ''));
        $step = trim((string) ($route['action_key'] ?? $route['step'] ?? ''));
        $card = trim((string) ($route['card_key'] ?? $route['card'] ?? ''));

        if ($type === '') {
            $type = $card !== '' ? 'card' : 'step';
        }

        if (in_array($step, ['end', 'fail'], true)) {
            $type = $step;
        }

        if ($type === 'card') {
            $route['action_key'] = $step !== '' && ! in_array($step, ['end', 'fail', 'next'], true)
                ? $step
                : $sourceStep->action_key;
            $route['step'] = $route['action_key'];
            $route['card_key'] = $card;
            $route['card'] = $card;
        } elseif ($type === 'step' && $step !== '') {
            $route['action_key'] = $step;
            $route['step'] = $step;
        }

        $route['type'] = $type;

        return $route;
    }

    public function resultOutcome(array $result): string
    {
        $requestedRouteOutcome = strtolower(trim((string) ($result['routeOutcome'] ?? $result['route_outcome'] ?? '')));

        if ((bool) ($result['routeRequested'] ?? false) && in_array($requestedRouteOutcome, ['success', 'failed', 'timeout'], true)) {
            return $requestedRouteOutcome;
        }

        $normalized = is_array($result['normalized_result'] ?? null) ? $result['normalized_result'] : [];

        if ($normalized !== []) {
            $technicalStatus = (string) ($normalized['technical_status'] ?? '');
            $businessStatus = (string) ($normalized['business_status'] ?? '');

            if ($technicalStatus === 'timeout') {
                return 'timeout';
            }

            if (in_array($technicalStatus, ['failed', 'cancelled'], true) || $businessStatus === 'failed') {
                return 'failed';
            }

            if (in_array($businessStatus, ['partial', 'unknown'], true)) {
                return 'partial';
            }

            return 'success';
        }

        if (strtolower(trim((string) ($result['status'] ?? $result['statusLevel'] ?? ''))) === 'timeout') {
            return 'timeout';
        }

        if (! (bool) ($result['ok'] ?? false)) {
            return 'failed';
        }

        $statusLevel = strtolower(trim((string) ($result['statusLevel'] ?? '')));

        if (in_array($statusLevel, ['partial', 'waiting', 'warning'], true)) {
            return 'partial';
        }

        return 'success';
    }
}
