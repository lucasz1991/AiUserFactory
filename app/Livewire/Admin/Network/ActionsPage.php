<?php

namespace App\Livewire\Admin\Network;

use App\Models\Person;
use App\Services\Simulation\PersonaNetworkPlanningService;
use Illuminate\Support\Collection;
use Livewire\Component;

class ActionsPage extends Component
{
    public string $personFilter = '';

    public string $typeFilter = 'all';

    public int $planningDays = 7;

    public string $planningIntensity = 'balanced';

    public function render()
    {
        $persons = $this->personsForActions();
        $actions = $this->buildActions($persons);

        return view('livewire.admin.network.actions-page', [
            'personOptions' => $this->personOptions($persons),
            'actions' => $actions,
            'summary' => $this->summary($actions, $persons),
        ])->layout('layouts.master');
    }

    public function resetFilters(): void
    {
        $this->personFilter = '';
        $this->typeFilter = 'all';
    }

    public function planNetworkNow(PersonaNetworkPlanningService $planning): void
    {
        $validated = $this->validate([
            'planningDays' => ['required', 'integer', 'min:1', 'max:14'],
            'planningIntensity' => ['required', 'string', 'in:quiet,balanced,active,creator'],
        ]);

        $summary = $planning->planActiveNetwork(
            days: (int) $validated['planningDays'],
            intensity: $validated['planningIntensity'],
            reason: 'manual-admin-actions-page',
        );

        session()->flash('success', sprintf(
            'Netzwerkplanung abgeschlossen: %d/%d Personen geplant, %d interne Eingangsevents beruecksichtigt.',
            $summary['persons_planned'],
            $summary['persons_total'],
            $summary['incoming_events'],
        ));
    }

    protected function personsForActions(): Collection
    {
        return Person::query()
            ->where('platform', 'instagram')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->values();
    }

    protected function personOptions(Collection $persons): array
    {
        return $persons
            ->map(fn (Person $person): array => [
                'id' => (string) $person->profile_key,
                'label' => $person->display_name,
            ])
            ->values()
            ->toArray();
    }

    protected function buildActions(Collection $persons): array
    {
        $actions = [];

        foreach ($persons as $person) {
            if ($this->personFilter !== '' && $person->profile_key !== $this->personFilter) {
                continue;
            }

            $plan = data_get($person->metadata, 'internal_activity_simulation', []);
            $days = is_array($plan['days_plan'] ?? null) ? $plan['days_plan'] : [];

            foreach ($days as $dayIndex => $day) {
                if (! is_array($day)) {
                    continue;
                }

                foreach (($day['content_items'] ?? []) as $contentIndex => $contentItem) {
                    if (! is_array($contentItem) || ! $this->allowsType('content')) {
                        continue;
                    }

                    $actions[] = $this->contentAction($person, $plan, $day, $contentItem, $dayIndex, $contentIndex);
                }

                foreach (($day['sessions'] ?? []) as $sessionIndex => $session) {
                    if (! is_array($session)) {
                        continue;
                    }

                    foreach (($session['steps'] ?? []) as $stepIndex => $step) {
                        if (! is_array($step) || ! $this->allowsType('step')) {
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

    protected function summary(array $actions, Collection $persons): array
    {
        $personsWithPlans = $persons
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

    protected function allowsType(string $type): bool
    {
        return $this->typeFilter === 'all' || $this->typeFilter === $type;
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
