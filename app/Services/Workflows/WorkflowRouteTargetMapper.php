<?php

namespace App\Services\Workflows;

use App\Models\WorkflowStep;
use Illuminate\Support\Collection;

/**
 * Maps the workflow editor's compact form values to persisted route targets.
 *
 * Model lookup stays with the caller through lazy resolvers. That keeps this
 * mapper deterministic while preserving the manager's edit-access checks.
 */
class WorkflowRouteTargetMapper
{
    /**
     * @param  callable(string): ?WorkflowStep  $stepByActionKey
     * @param  callable(int): ?WorkflowStep  $stepById
     * @return array<string, mixed>|null
     */
    public function fromValue(string $value, callable $stepByActionKey, callable $stepById): ?array
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if ($value === 'end') {
            return ['type' => 'end', 'step' => 'end', 'label' => 'Workflow abschliessen'];
        }

        if ($value === 'fail') {
            return ['type' => 'fail', 'step' => 'fail', 'label' => 'Fehlerroute'];
        }

        if (str_starts_with($value, 'step:')) {
            $target = $stepByActionKey(trim(substr($value, 5)));

            if (! $target) {
                return null;
            }

            return [
                'type' => 'step',
                'action_key' => $target->action_key,
                'step' => $target->action_key,
                'label' => $target->name,
            ];
        }

        if (! str_starts_with($value, 'card:')) {
            return null;
        }

        $parts = explode(':', $value, 3);
        $targetStep = $stepById((int) ($parts[1] ?? 0));
        $taskKey = trim((string) ($parts[2] ?? ''));

        if (! $targetStep || $taskKey === '') {
            return null;
        }

        $targetTask = collect($targetStep->task_cards)
            ->first(fn (array $task): bool => (string) ($task['key'] ?? '') === $taskKey);

        if (! $targetTask) {
            return null;
        }

        return [
            'type' => 'card',
            'action_key' => $targetStep->action_key,
            'step' => $targetStep->action_key,
            'card_key' => $taskKey,
            'card' => $taskKey,
            'label' => $targetStep->name.' / '.(string) ($targetTask['title'] ?? $taskKey),
        ];
    }

    /**
     * @param  callable(): Collection<int, WorkflowStep>  $orderedSteps
     * @return array<string, mixed>
     */
    public function nextTarget(
        WorkflowStep $sourceStep,
        ?string $sourceTaskKey,
        ?int $insertPosition,
        callable $orderedSteps,
    ): array {
        $tasks = collect($sourceStep->task_cards)->values();
        $nextTask = null;

        if ($sourceTaskKey !== null) {
            $sourceIndex = $tasks->search(
                fn (array $task): bool => (string) ($task['key'] ?? '') === $sourceTaskKey,
            );
            $nextTask = $sourceIndex !== false ? $tasks->get($sourceIndex + 1) : null;
        } elseif ($insertPosition !== null) {
            $nextTask = $tasks->get(max(0, $insertPosition));
        }

        if (is_array($nextTask)) {
            $taskKey = trim((string) ($nextTask['key'] ?? ''));

            if ($taskKey !== '') {
                return $this->cardTarget($sourceStep, $taskKey);
            }
        }

        $steps = $orderedSteps()->values();
        $sourceStepIndex = $steps->search(
            fn (WorkflowStep $step): bool => (int) $step->id === (int) $sourceStep->id,
        );
        $nextStep = $sourceStepIndex !== false ? $steps->get($sourceStepIndex + 1) : null;

        if (! $nextStep) {
            return ['type' => 'end', 'step' => 'end', 'label' => 'Workflow abschliessen'];
        }

        $firstTask = collect($nextStep->task_cards)->first();
        $firstTaskKey = is_array($firstTask) ? trim((string) ($firstTask['key'] ?? '')) : '';

        if ($firstTaskKey !== '') {
            return $this->cardTarget($nextStep, $firstTaskKey);
        }

        return [
            'type' => 'step',
            'action_key' => $nextStep->action_key,
            'step' => $nextStep->action_key,
            'label' => $nextStep->name,
        ];
    }

    /**
     * @param  array<string, mixed>  $routes
     * @param  array<string, mixed>|null  $target
     * @return array<string, mixed>
     */
    public function withRoute(
        array $routes,
        string $outcome,
        ?array $target,
        string $reason = '',
        int $maxAttempts = 0,
    ): array {
        if (! $target) {
            unset($routes[$outcome]);

            return $routes;
        }

        $reason = trim($reason);

        if ($reason !== '') {
            $target['reason'] = $reason;
        } else {
            unset($target['reason']);
        }

        if ($outcome === 'failed' && $maxAttempts > 0) {
            $target['max_attempts'] = $maxAttempts;
        } else {
            unset($target['max_attempts']);
        }

        $routes[$outcome] = $target;

        return $routes;
    }

    /**
     * @param  callable(string): ?WorkflowStep  $stepByActionKey
     */
    public function toValue(mixed $route, callable $stepByActionKey): string
    {
        if (! is_array($route)) {
            return '';
        }

        $type = trim((string) ($route['type'] ?? ''));
        $step = trim((string) ($route['action_key'] ?? $route['step'] ?? ''));
        $card = trim((string) ($route['card_key'] ?? $route['card'] ?? ''));

        if ($type === 'end' || $step === 'end') {
            return 'end';
        }

        if ($type === 'fail' || $step === 'fail') {
            return 'fail';
        }

        if ($card !== '') {
            $targetStep = $stepByActionKey($step);

            return $targetStep ? 'card:'.$targetStep->id.':'.$card : '';
        }

        return $step !== '' && $step !== 'next' ? 'step:'.$step : '';
    }

    /** @return array<string, mixed> */
    private function cardTarget(WorkflowStep $step, string $taskKey): array
    {
        return [
            'type' => 'card',
            'action_key' => $step->action_key,
            'step' => $step->action_key,
            'card_key' => $taskKey,
            'card' => $taskKey,
            'label' => 'Naechste Karte',
        ];
    }
}
