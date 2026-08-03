<?php

namespace App\Livewire\Admin\Network;

use App\Models\Person;
use App\Models\PersonWorkflowSchedule;
use App\Models\Workflow;
use App\Services\Automation\AutomationLimitSettings;
use App\Services\Automation\PersonWorkflowDispatcher;
use App\Services\Automation\PersonWorkflowScheduleService;
use App\Services\Automation\WorkflowBrowserBinding;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * Netzwerk -> Automatisierung.
 *
 * Verwaltet die Zeitplaene aller Personen, die globalen Grenzen und den Not-Aus.
 * Der Not-Aus schaltet nur die Ausfuehrung ab; kein Zeitplan wird geloescht.
 */
class AutomationIndex extends Component
{
    public string $activeTab = 'schedules';

    // --- Filter ---
    public string $filterPerson = '';

    public string $filterWorkflow = '';

    public string $filterState = 'all';

    // --- Globale Grenzen ---
    public bool $enabled = false;

    public int $maxConcurrentRuns = 5;

    public int $maxStartsPerTick = 5;

    public int $maxRunsPerPersonPerDay = 0;

    public int $pauseAfterFailures = 5;

    public int $failureBackoffMinutes = 15;

    // --- Zeitplan-Formular ---
    public bool $showScheduleModal = false;

    public ?int $editingScheduleId = null;

    public ?int $formPersonId = null;

    public ?int $formWorkflowId = null;

    public string $formLabel = '';

    public bool $formIsActive = true;

    public string $formCadence = PersonWorkflowSchedule::CADENCE_INTERVAL;

    public int $formIntervalMinutes = 240;

    public string $formDailyTimes = '';

    public string $formActivitySessionTypes = '';

    public array $formWeekdays = [];

    public string $formWindowStart = '';

    public string $formWindowEnd = '';

    public int $formJitterSeconds = 300;

    public ?int $formMaxRunsPerDay = null;

    public int $formMinGapMinutes = 0;

    public int $formPriority = 0;

    public string $formContext = '';

    // --- Massenzuweisung ---
    public bool $showBulkModal = false;

    public ?int $bulkWorkflowId = null;

    public string $bulkCadence = PersonWorkflowSchedule::CADENCE_INTERVAL;

    public int $bulkIntervalMinutes = 720;

    public string $bulkWindowStart = '09:00';

    public string $bulkWindowEnd = '21:00';

    public int $bulkJitterSeconds = 900;

    public bool $bulkOnlyActivePersons = true;

    public bool $bulkSkipExisting = true;

    public function mount(): void
    {
        $this->loadLimits();
    }

    public function render()
    {
        $schedules = PersonWorkflowSchedule::query()
            ->with(['person', 'workflow', 'lastWorkflowRun'])
            ->when($this->filterPerson !== '', fn ($query) => $query->where('person_id', (int) $this->filterPerson))
            ->when($this->filterWorkflow !== '', fn ($query) => $query->where('workflow_id', (int) $this->filterWorkflow))
            ->when($this->filterState === 'active', fn ($query) => $query->where('is_active', true))
            ->when($this->filterState === 'paused', fn ($query) => $query->where('is_active', false))
            ->orderByDesc('is_active')
            ->orderBy('next_run_at')
            ->orderBy('id')
            ->limit(300)
            ->get();

        $binding = app(WorkflowBrowserBinding::class);

        return view('livewire.admin.network.automation-index', [
            'schedules' => $schedules,
            'persons' => Person::query()
                ->where('approval_status', '!=', 'rejected')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(['id', 'profile_label', 'person_first_name', 'person_last_name', 'person_alias', 'is_active', 'automation_enabled', 'person_timezone', 'max_concurrent_workflow_runs']),
            'workflows' => Workflow::query()->orderBy('name')->get(['id', 'name', 'is_active']),
            'cadences' => PersonWorkflowSchedule::CADENCES,
            'browserBound' => $schedules->pluck('workflow_id')->unique()->filter()
                ->mapWithKeys(fn (int $id): array => [$id => $binding->requiresBrowserById($id)])
                ->all(),
            'stats' => [
                'total' => PersonWorkflowSchedule::query()->count(),
                'active' => PersonWorkflowSchedule::query()->where('is_active', true)->count(),
                'due' => PersonWorkflowSchedule::query()->due()->count(),
                'paused' => PersonWorkflowSchedule::query()->where('is_active', false)->count(),
            ],
        ])->layout('layouts.master');
    }

    // ==================================================================
    // Grenzen
    // ==================================================================

    public function saveLimits(): void
    {
        $validated = $this->validate([
            'enabled' => ['boolean'],
            'maxConcurrentRuns' => ['required', 'integer', 'min:1', 'max:200'],
            'maxStartsPerTick' => ['required', 'integer', 'min:1', 'max:100'],
            'maxRunsPerPersonPerDay' => ['required', 'integer', 'min:0', 'max:500'],
            'pauseAfterFailures' => ['required', 'integer', 'min:0', 'max:50'],
            'failureBackoffMinutes' => ['required', 'integer', 'min:1', 'max:1440'],
        ]);

        $current = app(AutomationLimitSettings::class)->get();

        app(AutomationLimitSettings::class)->save([
            ...$current,
            'enabled' => (bool) $validated['enabled'],
            'max_concurrent_runs' => $validated['maxConcurrentRuns'],
            'max_starts_per_tick' => $validated['maxStartsPerTick'],
            'max_runs_per_person_per_day' => $validated['maxRunsPerPersonPerDay'],
            'pause_after_failures' => $validated['pauseAfterFailures'],
            'failure_backoff_minutes' => $validated['failureBackoffMinutes'],
        ]);

        $this->loadLimits();
        $this->dispatch('showAlert', 'Grenzen wurden gespeichert.', 'success');
    }

    public function toggleEmergencyStop(): void
    {
        $settings = app(AutomationLimitSettings::class)->get();
        $settings['enabled'] = ! $settings['enabled'];
        app(AutomationLimitSettings::class)->save($settings);

        $this->loadLimits();
        $this->dispatch(
            'showAlert',
            $settings['enabled'] ? 'Automatisierung laeuft.' : 'Not-Aus aktiv: es werden keine Workflows mehr gestartet.',
            $settings['enabled'] ? 'success' : 'warning',
        );
    }

    protected function loadLimits(): void
    {
        $settings = app(AutomationLimitSettings::class)->get();

        $this->enabled = (bool) $settings['enabled'];
        $this->maxConcurrentRuns = (int) $settings['max_concurrent_runs'];
        $this->maxStartsPerTick = (int) $settings['max_starts_per_tick'];
        $this->maxRunsPerPersonPerDay = (int) $settings['max_runs_per_person_per_day'];
        $this->pauseAfterFailures = (int) $settings['pause_after_failures'];
        $this->failureBackoffMinutes = (int) $settings['failure_backoff_minutes'];
    }

    // ==================================================================
    // Zeitplaene
    // ==================================================================

    public function newSchedule(): void
    {
        $this->resetScheduleForm();
        $this->showScheduleModal = true;
    }

    public function editSchedule(int $scheduleId): void
    {
        $schedule = PersonWorkflowSchedule::query()->find($scheduleId);

        if (! $schedule) {
            return;
        }

        $this->editingScheduleId = $schedule->id;
        $this->formPersonId = $schedule->person_id;
        $this->formWorkflowId = $schedule->workflow_id;
        $this->formLabel = (string) $schedule->label;
        $this->formIsActive = (bool) $schedule->is_active;
        $this->formCadence = $schedule->cadence_type;
        $this->formIntervalMinutes = (int) ($schedule->interval_minutes ?: 240);
        $this->formDailyTimes = implode(', ', is_array($schedule->daily_times) ? $schedule->daily_times : []);
        $this->formActivitySessionTypes = implode(', ', is_array($schedule->activity_plan_session_types) ? $schedule->activity_plan_session_types : []);
        $this->formWeekdays = array_map('strval', is_array($schedule->weekdays) ? $schedule->weekdays : []);
        $this->formWindowStart = (string) ($schedule->window_start ? substr((string) $schedule->window_start, 0, 5) : '');
        $this->formWindowEnd = (string) ($schedule->window_end ? substr((string) $schedule->window_end, 0, 5) : '');
        $this->formJitterSeconds = (int) $schedule->jitter_seconds;
        $this->formMaxRunsPerDay = $schedule->max_runs_per_day;
        $this->formMinGapMinutes = (int) $schedule->min_gap_minutes;
        $this->formPriority = (int) $schedule->priority;
        $this->formContext = is_array($schedule->context_json) && $schedule->context_json !== []
            ? json_encode($schedule->context_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
            : '';
        $this->resetErrorBag();
        $this->showScheduleModal = true;
    }

    public function saveSchedule(): void
    {
        $validated = $this->validate([
            'formPersonId' => ['required', 'integer', Rule::exists('persons', 'id')],
            'formWorkflowId' => ['required', 'integer', Rule::exists('workflows', 'id')],
            'formLabel' => ['nullable', 'string', 'max:120'],
            'formCadence' => ['required', Rule::in(array_keys(PersonWorkflowSchedule::CADENCES))],
            'formIntervalMinutes' => ['required', 'integer', 'min:5', 'max:20160'],
            'formDailyTimes' => ['nullable', 'string', 'max:500'],
            'formActivitySessionTypes' => ['nullable', 'string', 'max:500'],
            'formWindowStart' => ['nullable', 'string', 'max:5'],
            'formWindowEnd' => ['nullable', 'string', 'max:5'],
            'formJitterSeconds' => ['required', 'integer', 'min:0', 'max:7200'],
            'formMaxRunsPerDay' => ['nullable', 'integer', 'min:1', 'max:500'],
            'formMinGapMinutes' => ['required', 'integer', 'min:0', 'max:20160'],
            'formPriority' => ['required', 'integer', 'min:-100', 'max:100'],
            'formContext' => ['nullable', 'string', 'max:4000'],
        ]);

        $person = Person::query()->find($validated['formPersonId']);

        if (! $person) {
            return;
        }

        $service = app(PersonWorkflowScheduleService::class);

        $normalized = $service->normalize([
            'label' => $validated['formLabel'] ?? '',
            'is_active' => $this->formIsActive,
            'cadence_type' => $validated['formCadence'],
            'interval_minutes' => $validated['formIntervalMinutes'],
            'daily_times' => $validated['formDailyTimes'] ?? '',
            'activity_plan_session_types' => $validated['formActivitySessionTypes'] ?? '',
            'weekdays' => array_map('intval', $this->formWeekdays),
            'window_start' => $validated['formWindowStart'] ?? null,
            'window_end' => $validated['formWindowEnd'] ?? null,
            'jitter_seconds' => $validated['formJitterSeconds'],
            'max_runs_per_day' => $validated['formMaxRunsPerDay'],
            'min_gap_minutes' => $validated['formMinGapMinutes'],
            'priority' => $validated['formPriority'],
            'context_json' => $validated['formContext'] ?? '',
        ]);

        $schedule = $this->editingScheduleId
            ? PersonWorkflowSchedule::query()->find($this->editingScheduleId)
            : new PersonWorkflowSchedule;

        if (! $schedule) {
            return;
        }

        $schedule->forceFill([
            ...$normalized,
            'person_id' => $person->id,
            'workflow_id' => $validated['formWorkflowId'],
        ])->save();

        $schedule->forceFill([
            'next_run_at' => $service->computeNextRunAt($schedule, $person),
        ])->save();

        $this->showScheduleModal = false;
        $this->resetScheduleForm();
        $this->dispatch('showAlert', 'Zeitplan wurde gespeichert.', 'success');
    }

    public function toggleSchedule(int $scheduleId): void
    {
        $schedule = PersonWorkflowSchedule::query()->with('person')->find($scheduleId);

        if (! $schedule) {
            return;
        }

        $schedule->forceFill([
            'is_active' => ! $schedule->is_active,
            'paused_until' => null,
            'consecutive_failures' => 0,
        ])->save();

        if ($schedule->is_active && $schedule->person) {
            $schedule->forceFill([
                'next_run_at' => app(PersonWorkflowScheduleService::class)->computeNextRunAt($schedule, $schedule->person),
            ])->save();
        }
    }

    public function deleteSchedule(int $scheduleId): void
    {
        PersonWorkflowSchedule::query()->whereKey($scheduleId)->delete();

        $this->dispatch('showAlert', 'Zeitplan wurde entfernt.', 'success');
    }

    /**
     * Fuehrt einen Dispatcher-Durchlauf sofort aus, damit sich die Wirkung ohne
     * Warten auf den Minutentakt pruefen laesst.
     */
    public function runDispatcherNow(): void
    {
        $summary = app(PersonWorkflowDispatcher::class)->dispatchDue();

        if (! $summary['enabled']) {
            $this->dispatch('showAlert', 'Not-Aus ist aktiv, es wurde nichts gestartet.', 'warning');

            return;
        }

        $this->dispatch(
            'showAlert',
            sprintf('%d gestartet, %d verschoben, %d fehlgeschlagen.', $summary['started'], $summary['skipped'], $summary['failed']),
            $summary['failed'] > 0 ? 'warning' : 'success',
        );
    }

    protected function resetScheduleForm(): void
    {
        $this->editingScheduleId = null;
        $this->formPersonId = null;
        $this->formWorkflowId = null;
        $this->formLabel = '';
        $this->formIsActive = true;
        $this->formCadence = PersonWorkflowSchedule::CADENCE_INTERVAL;
        $this->formIntervalMinutes = 240;
        $this->formDailyTimes = '';
        $this->formActivitySessionTypes = '';
        $this->formWeekdays = [];
        $this->formWindowStart = '';
        $this->formWindowEnd = '';
        $this->formJitterSeconds = 300;
        $this->formMaxRunsPerDay = null;
        $this->formMinGapMinutes = 0;
        $this->formPriority = 0;
        $this->formContext = '';
        $this->resetErrorBag();
    }

    // ==================================================================
    // Massenzuweisung
    // ==================================================================

    public function openBulkModal(): void
    {
        $this->bulkWorkflowId = null;
        $this->resetErrorBag();
        $this->showBulkModal = true;
    }

    public function applyBulk(): void
    {
        $validated = $this->validate([
            'bulkWorkflowId' => ['required', 'integer', Rule::exists('workflows', 'id')],
            'bulkCadence' => ['required', Rule::in(array_keys(PersonWorkflowSchedule::CADENCES))],
            'bulkIntervalMinutes' => ['required', 'integer', 'min:5', 'max:20160'],
            'bulkWindowStart' => ['nullable', 'string', 'max:5'],
            'bulkWindowEnd' => ['nullable', 'string', 'max:5'],
            'bulkJitterSeconds' => ['required', 'integer', 'min:0', 'max:7200'],
        ]);

        $service = app(PersonWorkflowScheduleService::class);

        $persons = Person::query()
            ->where('approval_status', 'approved')
            ->when($this->bulkOnlyActivePersons, fn ($query) => $query->where('is_active', true))
            ->get();

        $created = 0;
        $skipped = 0;

        foreach ($persons as $person) {
            $exists = PersonWorkflowSchedule::query()
                ->where('person_id', $person->id)
                ->where('workflow_id', $validated['bulkWorkflowId'])
                ->exists();

            if ($exists && $this->bulkSkipExisting) {
                $skipped++;

                continue;
            }

            $normalized = $service->normalize([
                'label' => 'Massenzuweisung',
                'is_active' => true,
                'cadence_type' => $validated['bulkCadence'],
                'interval_minutes' => $validated['bulkIntervalMinutes'],
                'daily_times' => '',
                'window_start' => $validated['bulkWindowStart'] ?? null,
                'window_end' => $validated['bulkWindowEnd'] ?? null,
                'jitter_seconds' => $validated['bulkJitterSeconds'],
            ]);

            $schedule = new PersonWorkflowSchedule;
            $schedule->forceFill([
                ...$normalized,
                'person_id' => $person->id,
                'workflow_id' => $validated['bulkWorkflowId'],
            ])->save();

            $schedule->forceFill([
                'next_run_at' => $service->computeNextRunAt($schedule, $person),
            ])->save();

            $created++;
        }

        $this->showBulkModal = false;
        $this->dispatch('showAlert', sprintf('%d Zeitplaene angelegt, %d uebersprungen.', $created, $skipped), 'success');
    }
}
