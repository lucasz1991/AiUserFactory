<?php

namespace App\Livewire\Admin\Config;

use App\Models\Person;
use App\Models\PersonWorkflowSchedule;
use App\Models\WorkflowRun;
use App\Services\Automation\AutomationLimitSettings;
use App\Services\Automation\PersonWorkflowDispatcher;
use App\Services\Automation\PersonWorkflowScheduleService;
use App\Services\Automation\WorkflowBrowserBinding;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Profil-Tab "Automatisierung".
 *
 * Zeigt die Zeitplaene dieser Person und ihre echte Lauf-Historie. Letztere ist
 * erst moeglich, seit `workflow_runs.person_id` als indizierter Spiegel existiert.
 */
class PersonAutomation extends Component
{
    public int $personId;

    public bool $automationEnabled = true;

    public int $maxConcurrentRuns = 1;

    public function mount(int $personId): void
    {
        $this->personId = $personId;

        $person = $this->person();

        if ($person) {
            $this->automationEnabled = (bool) $person->automation_enabled;
            $this->maxConcurrentRuns = max(1, (int) ($person->max_concurrent_workflow_runs ?: 1));
        }
    }

    public function render()
    {
        $person = $this->person();

        if (! $person) {
            return view('livewire.admin.config.person-automation', [
                'person' => null,
                'schedules' => collect(),
                'runs' => collect(),
                'stats' => [],
                'globalEnabled' => false,
                'browserBound' => [],
            ]);
        }

        $schedules = $person->workflowSchedules()->with(['workflow', 'lastWorkflowRun'])->get();

        $runs = WorkflowRun::query()
            ->where('person_id', $person->id)
            ->with('workflow')
            ->latest('id')
            ->limit(25)
            ->get();

        $binding = app(WorkflowBrowserBinding::class);

        return view('livewire.admin.config.person-automation', [
            'person' => $person,
            'schedules' => $schedules,
            'runs' => $runs,
            'globalEnabled' => (bool) app(AutomationLimitSettings::class)->get()['enabled'],
            'browserBound' => $schedules->pluck('workflow_id')->unique()->filter()
                ->mapWithKeys(fn (int $id): array => [$id => $binding->requiresBrowserById($id)])
                ->all(),
            'stats' => [
                'schedules' => $schedules->count(),
                'active' => $schedules->where('is_active', true)->count(),
                'runs_total' => WorkflowRun::query()->where('person_id', $person->id)->count(),
                'runs_failed' => WorkflowRun::query()->where('person_id', $person->id)->where('status', 'failed')->count(),
                'running' => app(PersonWorkflowDispatcher::class)->activeRunsOfPerson($person)->count(),
                'next' => $schedules->where('is_active', true)->pluck('next_run_at')->filter()->sort()->first(),
            ],
        ]);
    }

    public function savePersonSettings(): void
    {
        $person = $this->person();

        if (! $person) {
            return;
        }

        $validated = $this->validate([
            'automationEnabled' => ['boolean'],
            'maxConcurrentRuns' => ['required', 'integer', 'min:1', 'max:20'],
        ]);

        $person->forceFill([
            'automation_enabled' => (bool) $validated['automationEnabled'],
            'max_concurrent_workflow_runs' => $validated['maxConcurrentRuns'],
        ])->save();

        $this->dispatch('showAlert', 'Automatisierungseinstellungen der Person gespeichert.', 'success');
    }

    public function toggleSchedule(int $scheduleId): void
    {
        $person = $this->person();
        $schedule = PersonWorkflowSchedule::query()
            ->where('person_id', $this->personId)
            ->whereKey($scheduleId)
            ->first();

        if (! $person || ! $schedule) {
            return;
        }

        $schedule->forceFill([
            'is_active' => ! $schedule->is_active,
            'paused_until' => null,
            'consecutive_failures' => 0,
        ])->save();

        if ($schedule->is_active) {
            $schedule->forceFill([
                'next_run_at' => app(PersonWorkflowScheduleService::class)->computeNextRunAt($schedule, $person),
            ])->save();
        }
    }

    /**
     * Setzt einen Zeitplan auf "jetzt faellig". Der Dispatcher prueft dann beim
     * naechsten Durchlauf regulaer alle Bedingungen — es wird nichts uebergangen.
     */
    public function runNow(int $scheduleId): void
    {
        $schedule = PersonWorkflowSchedule::query()
            ->where('person_id', $this->personId)
            ->whereKey($scheduleId)
            ->first();

        if (! $schedule) {
            return;
        }

        $schedule->forceFill(['next_run_at' => now()->subSecond(), 'is_active' => true])->save();

        $this->dispatch('showAlert', 'Zeitplan ist jetzt faellig und wird beim naechsten Durchlauf geprueft.', 'success');
    }

    #[On('refreshPersonDetail')]
    public function refreshFromParent(): void
    {
        // Das Rendern liest ohnehin frisch; der Listener haelt den Tab aktuell,
        // wenn Geschwisterkomponenten etwas veraendern.
    }

    protected function person(): ?Person
    {
        return Person::query()->find($this->personId);
    }
}
