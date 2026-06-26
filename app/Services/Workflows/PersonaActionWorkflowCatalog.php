<?php

namespace App\Services\Workflows;

use App\Models\Person;
use Illuminate\Support\Collection;

class PersonaActionWorkflowCatalog
{
    public function persons(): Collection
    {
        return Person::query()
            ->where('platform', 'instagram')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->values();
    }

    public function personOptions(?Collection $persons = null): array
    {
        return ($persons ?? $this->persons())
            ->map(fn (Person $person): array => [
                'id' => (string) $person->profile_key,
                'database_id' => $person->id,
                'label' => $person->display_name,
            ])
            ->values()
            ->toArray();
    }

    public function actions(?Collection $persons = null, string $personFilter = '', string $typeFilter = 'all'): array
    {
        $actions = [];

        foreach (($persons ?? $this->persons()) as $person) {
            if ($personFilter !== '' && $person->profile_key !== $personFilter) {
                continue;
            }

            $plan = data_get($person->metadata, 'internal_activity_simulation', []);
            $days = is_array($plan['days_plan'] ?? null) ? $plan['days_plan'] : [];

            foreach ($days as $dayIndex => $day) {
                if (! is_array($day)) {
                    continue;
                }

                foreach (($day['content_items'] ?? []) as $contentIndex => $contentItem) {
                    if (! is_array($contentItem) || ! $this->allowsType($typeFilter, 'content')) {
                        continue;
                    }

                    $actions[] = $this->contentAction($person, $plan, $day, $contentItem, $dayIndex, $contentIndex);
                }

                foreach (($day['sessions'] ?? []) as $sessionIndex => $session) {
                    if (! is_array($session)) {
                        continue;
                    }

                    foreach (($session['steps'] ?? []) as $stepIndex => $step) {
                        if (! is_array($step) || ! $this->allowsType($typeFilter, 'step')) {
                            continue;
                        }

                        $actions[] = $this->stepAction($person, $plan, $day, $session, $step, $dayIndex, $sessionIndex, $stepIndex);
                    }
                }
            }
        }

        usort($actions, static fn (array $left, array $right): int => [
            $left['date'],
            $left['time'],
            $left['person_name'],
            $left['sort'],
        ] <=> [
            $right['date'],
            $right['time'],
            $right['person_name'],
            $right['sort'],
        ]);

        return $actions;
    }

    public function actionById(string $actionId): ?array
    {
        foreach ($this->actions() as $action) {
            if (($action['id'] ?? null) === $actionId) {
                return $action;
            }
        }

        return null;
    }

    public function summary(array $actions, ?Collection $persons = null): array
    {
        $personsWithPlans = ($persons ?? $this->persons())
            ->filter(fn (Person $person): bool => is_array(data_get($person->metadata, 'internal_activity_simulation.days_plan')))
            ->count();

        return [
            'persons_with_plans' => $personsWithPlans,
            'visible_actions' => count($actions),
            'content_actions' => count(array_filter($actions, static fn (array $action): bool => $action['type'] === 'content')),
            'step_actions' => count(array_filter($actions, static fn (array $action): bool => $action['type'] === 'step')),
            'review_actions' => count(array_filter($actions, static fn (array $action): bool => $action['risk_level'] === 'review')),
        ];
    }

    public function workflowStepConfig(array $action): array
    {
        return [
            'source' => 'persona_activity_plan',
            'action_id' => (string) ($action['id'] ?? ''),
            'person_id' => (int) ($action['person_id'] ?? 0),
            'person_key' => (string) ($action['person_key'] ?? ''),
            'person_name' => (string) ($action['person_name'] ?? ''),
            'date' => (string) ($action['date'] ?? ''),
            'time' => (string) ($action['time'] ?? ''),
            'type' => (string) ($action['type'] ?? ''),
            'session_type' => (string) ($action['session_type'] ?? ''),
            'action' => (string) ($action['action'] ?? ''),
            'label' => (string) ($action['label'] ?? 'Aktion'),
            'details' => (string) ($action['details'] ?? ''),
            'risk_score' => (int) ($action['risk_score'] ?? 0),
            'risk_level' => (string) ($action['risk_level'] ?? 'low'),
        ];
    }

    public function removeActionFromPlan(string $actionId): bool
    {
        $parts = explode('-', $actionId);

        if (count($parts) < 4 || ! in_array($parts[0], ['content', 'step'], true)) {
            return false;
        }

        $person = Person::query()->find((int) $parts[1]);

        if (! $person) {
            return false;
        }

        $metadata = is_array($person->metadata) ? $person->metadata : [];
        $plan = data_get($metadata, 'internal_activity_simulation');

        if (! is_array($plan) || ! is_array($plan['days_plan'] ?? null)) {
            return false;
        }

        $removed = $parts[0] === 'content'
            ? $this->removeContentAction($plan, $parts)
            : $this->removeStepAction($plan, $parts);

        if (! $removed) {
            return false;
        }

        $metadata['internal_activity_simulation'] = $plan;

        $person->forceFill([
            'metadata' => $metadata,
        ])->save();

        return true;
    }

    protected function contentAction(Person $person, array $plan, array $day, array $contentItem, int $dayIndex, int $contentIndex): array
    {
        return [
            'id' => 'content-'.$person->id.'-'.$dayIndex.'-'.$contentIndex,
            'person_id' => $person->id,
            'person_key' => $person->profile_key,
            'person_name' => $person->display_name,
            'date' => (string) ($day['date'] ?? ''),
            'weekday' => (string) ($day['weekday'] ?? ''),
            'time' => (string) ($contentItem['planned_time_local'] ?? ''),
            'type' => 'content',
            'type_label' => 'Content',
            'session_type' => 'content_plan',
            'action' => (string) ($contentItem['type'] ?? 'content'),
            'label' => (string) ($contentItem['theme'] ?? 'Content planen'),
            'details' => (string) ($contentItem['prompt'] ?? ''),
            'risk_score' => (int) data_get($day, 'metrics.risk_score', 0),
            'risk_level' => (string) data_get($day, 'metrics.risk_level', 'low'),
            'plan_status' => (string) ($plan['status'] ?? 'draft'),
            'intensity_label' => (string) ($plan['intensity_label'] ?? ''),
            'sort' => $contentIndex,
        ];
    }

    protected function stepAction(Person $person, array $plan, array $day, array $session, array $step, int $dayIndex, int $sessionIndex, int $stepIndex): array
    {
        return [
            'id' => 'step-'.$person->id.'-'.$dayIndex.'-'.$sessionIndex.'-'.$stepIndex,
            'person_id' => $person->id,
            'person_key' => $person->profile_key,
            'person_name' => $person->display_name,
            'date' => (string) ($day['date'] ?? ''),
            'weekday' => (string) ($day['weekday'] ?? ''),
            'time' => $this->stepTime((string) ($session['starts_at_local'] ?? ''), (int) ($step['offset_minutes'] ?? 0)),
            'type' => 'step',
            'type_label' => 'Session-Schritt',
            'session_type' => (string) ($session['session_type'] ?? ''),
            'action' => (string) ($step['action'] ?? ''),
            'label' => (string) ($step['label'] ?? $step['action'] ?? 'Aktion'),
            'details' => (string) ($step['details'] ?? ''),
            'risk_score' => (int) data_get($day, 'metrics.risk_score', 0),
            'risk_level' => (string) data_get($day, 'metrics.risk_level', 'low'),
            'plan_status' => (string) ($plan['status'] ?? 'draft'),
            'intensity_label' => (string) ($plan['intensity_label'] ?? ''),
            'sort' => ($sessionIndex * 1000) + $stepIndex,
        ];
    }

    protected function allowsType(string $filter, string $type): bool
    {
        return $filter === 'all' || $filter === $type;
    }

    protected function removeContentAction(array &$plan, array $parts): bool
    {
        if (count($parts) !== 4) {
            return false;
        }

        $dayIndex = (int) $parts[2];
        $contentIndex = (int) $parts[3];

        if (! isset($plan['days_plan'][$dayIndex]['content_items'][$contentIndex])) {
            return false;
        }

        array_splice($plan['days_plan'][$dayIndex]['content_items'], $contentIndex, 1);

        return true;
    }

    protected function removeStepAction(array &$plan, array $parts): bool
    {
        if (count($parts) !== 5) {
            return false;
        }

        $dayIndex = (int) $parts[2];
        $sessionIndex = (int) $parts[3];
        $stepIndex = (int) $parts[4];

        if (! isset($plan['days_plan'][$dayIndex]['sessions'][$sessionIndex]['steps'][$stepIndex])) {
            return false;
        }

        array_splice($plan['days_plan'][$dayIndex]['sessions'][$sessionIndex]['steps'], $stepIndex, 1);

        if (($plan['days_plan'][$dayIndex]['sessions'][$sessionIndex]['steps'] ?? []) === []) {
            array_splice($plan['days_plan'][$dayIndex]['sessions'], $sessionIndex, 1);
        }

        return true;
    }

    protected function stepTime(string $sessionStart, int $offsetMinutes): string
    {
        if (! preg_match('/^(\d{2}):(\d{2})$/', $sessionStart, $matches)) {
            return $sessionStart;
        }

        $minutes = (((int) $matches[1]) * 60) + ((int) $matches[2]) + max(0, $offsetMinutes);
        $minutes %= 24 * 60;

        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }
}
