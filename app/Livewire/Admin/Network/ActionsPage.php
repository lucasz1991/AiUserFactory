<?php

namespace App\Livewire\Admin\Network;

use App\Models\Person;
use App\Services\Simulation\PersonaNetworkPlanningService;
use App\Services\Workflows\PersonaActionWorkflowCatalog;
use Livewire\Component;

class ActionsPage extends Component
{
    public string $personFilter = '';

    public string $typeFilter = 'all';

    public int $planningDays = 7;

    public string $planningIntensity = 'balanced';

    public function render()
    {
        $catalog = app(PersonaActionWorkflowCatalog::class);
        $persons = $catalog->persons();
        $actions = $catalog->actions($persons, $this->personFilter, $this->typeFilter);

        return view('livewire.admin.network.actions-page', [
            'personOptions' => $catalog->personOptions($persons),
            'actions' => $actions,
            'summary' => $catalog->summary($actions, $persons),
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

    public function deleteAction(string $actionId): void
    {
        $catalog = app(PersonaActionWorkflowCatalog::class);

        if ($catalog->removeActionFromPlan($actionId)) {
            session()->flash('success', 'Geplante Aktion wurde geloescht.');

            return;
        }

        session()->flash('success', 'Geplante Aktion konnte nicht gefunden werden.');
    }

    public function deleteAllPlans(): void
    {
        $deleted = 0;

        foreach (Person::query()->where('platform', 'instagram')->get() as $person) {
            $metadata = is_array($person->metadata) ? $person->metadata : [];

            if (! array_key_exists('internal_activity_simulation', $metadata) && ! array_key_exists('last_network_planning_run', $metadata)) {
                continue;
            }

            unset($metadata['internal_activity_simulation'], $metadata['last_network_planning_run']);

            $person->forceFill([
                'metadata' => $metadata,
            ])->save();

            $deleted++;
        }

        session()->flash('success', sprintf(
            '%d Aktivitaetsplanung(en) wurden geloescht.',
            $deleted,
        ));
    }
}
