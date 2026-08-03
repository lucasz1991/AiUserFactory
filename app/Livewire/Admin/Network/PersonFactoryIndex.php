<?php

namespace App\Livewire\Admin\Network;

use App\Models\Person;
use App\Models\PersonBlueprint;
use App\Models\PersonWorkflowSchedule;
use App\Models\Workflow;
use App\Services\Automation\AutomationLimitSettings;
use App\Services\Persons\PersonAccountRegistry;
use App\Services\Persons\PersonFactoryService;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * Netzwerk -> Personen-Fabrik.
 *
 * Bauplaene erzeugen Personen ausschliesslich als Entwuerfe. Erst die Freigabe
 * setzt eine Person aktiv, legt ihre Zeitplaene an und startet den
 * Onboarding-Workflow.
 */
class PersonFactoryIndex extends Component
{
    public string $activeTab = 'queue';

    public bool $factoryEnabled = false;

    public int $factoryMaxPerDay = 5;

    // --- Bauplan-Formular ---
    public bool $showBlueprintModal = false;

    public ?int $editingBlueprintId = null;

    public string $formName = '';

    public string $formDescription = '';

    public bool $formIsActive = false;

    public int $formTargetCount = 10;

    public int $formPerDay = 2;

    public string $formCountries = '';

    public string $formLanguages = '';

    public string $formGenders = '';

    public int $formAgeMin = 24;

    public int $formAgeMax = 38;

    public string $formProfilePrompt = '';

    public bool $formGenerateAvatar = false;

    public array $formAccountTypes = [];

    public ?int $formOnboardingWorkflowId = null;

    public ?int $formScheduleWorkflowId = null;

    public int $formScheduleIntervalMinutes = 720;

    public string $formScheduleWindowStart = '09:00';

    public string $formScheduleWindowEnd = '21:00';

    public function mount(): void
    {
        $this->loadSettings();
    }

    public function render()
    {
        return view('livewire.admin.network.person-factory-index', [
            'blueprints' => PersonBlueprint::query()->with('onboardingWorkflow')->orderBy('id')->get(),
            'drafts' => Person::query()
                ->where('approval_status', 'draft')
                ->with('blueprint')
                ->orderByDesc('id')
                ->limit(200)
                ->get(),
            'workflows' => Workflow::query()->orderBy('name')->get(['id', 'name', 'is_active']),
            'accountTypes' => PersonAccountRegistry::TYPES,
            'stats' => [
                'blueprints' => PersonBlueprint::query()->count(),
                'active' => PersonBlueprint::query()->where('is_active', true)->count(),
                'drafts' => Person::query()->where('approval_status', 'draft')->count(),
                'approved' => Person::query()->whereNotNull('person_blueprint_id')->where('approval_status', 'approved')->count(),
            ],
        ])->layout('layouts.master');
    }

    // ==================================================================
    // Einstellungen
    // ==================================================================

    public function saveSettings(): void
    {
        $validated = $this->validate([
            'factoryEnabled' => ['boolean'],
            'factoryMaxPerDay' => ['required', 'integer', 'min:0', 'max:500'],
        ]);

        $current = app(AutomationLimitSettings::class)->get();

        app(AutomationLimitSettings::class)->save([
            ...$current,
            'factory_enabled' => (bool) $validated['factoryEnabled'],
            'factory_max_per_day' => $validated['factoryMaxPerDay'],
        ]);

        $this->loadSettings();
        $this->dispatch('showAlert', 'Einstellungen der Fabrik gespeichert.', 'success');
    }

    protected function loadSettings(): void
    {
        $settings = app(AutomationLimitSettings::class)->get();

        $this->factoryEnabled = (bool) $settings['factory_enabled'];
        $this->factoryMaxPerDay = (int) $settings['factory_max_per_day'];
    }

    // ==================================================================
    // Bauplaene
    // ==================================================================

    public function newBlueprint(): void
    {
        $this->resetBlueprintForm();
        $this->showBlueprintModal = true;
    }

    public function editBlueprint(int $blueprintId): void
    {
        $blueprint = PersonBlueprint::query()->find($blueprintId);

        if (! $blueprint) {
            return;
        }

        $template = is_array($blueprint->schedule_templates) ? ($blueprint->schedule_templates[0] ?? []) : [];

        $this->editingBlueprintId = $blueprint->id;
        $this->formName = (string) $blueprint->name;
        $this->formDescription = (string) $blueprint->description;
        $this->formIsActive = (bool) $blueprint->is_active;
        $this->formTargetCount = (int) $blueprint->target_count;
        $this->formPerDay = (int) $blueprint->per_day;
        $this->formCountries = implode(', ', $blueprint->countries ?? []);
        $this->formLanguages = implode(', ', $blueprint->languages ?? []);
        $this->formGenders = implode(', ', $blueprint->genders ?? []);
        $this->formAgeMin = (int) $blueprint->age_min;
        $this->formAgeMax = (int) $blueprint->age_max;
        $this->formProfilePrompt = (string) $blueprint->profile_prompt;
        $this->formGenerateAvatar = (bool) $blueprint->generate_avatar;
        $this->formAccountTypes = array_map('strval', $blueprint->account_types ?? []);
        $this->formOnboardingWorkflowId = $blueprint->onboarding_workflow_id;
        $this->formScheduleWorkflowId = isset($template['workflow_id']) ? (int) $template['workflow_id'] : null;
        $this->formScheduleIntervalMinutes = (int) ($template['interval_minutes'] ?? 720);
        $this->formScheduleWindowStart = (string) ($template['window_start'] ?? '09:00');
        $this->formScheduleWindowEnd = (string) ($template['window_end'] ?? '21:00');
        $this->resetErrorBag();
        $this->showBlueprintModal = true;
    }

    public function saveBlueprint(): void
    {
        $validated = $this->validate([
            'formName' => ['required', 'string', 'max:160'],
            'formDescription' => ['nullable', 'string', 'max:2000'],
            'formTargetCount' => ['required', 'integer', 'min:0', 'max:10000'],
            'formPerDay' => ['required', 'integer', 'min:1', 'max:100'],
            'formCountries' => ['nullable', 'string', 'max:500'],
            'formLanguages' => ['nullable', 'string', 'max:500'],
            'formGenders' => ['nullable', 'string', 'max:200'],
            'formAgeMin' => ['required', 'integer', 'min:16', 'max:90'],
            'formAgeMax' => ['required', 'integer', 'min:16', 'max:90', 'gte:formAgeMin'],
            'formProfilePrompt' => ['nullable', 'string', 'max:4000'],
            'formOnboardingWorkflowId' => ['nullable', 'integer', Rule::exists('workflows', 'id')],
            'formScheduleWorkflowId' => ['nullable', 'integer', Rule::exists('workflows', 'id')],
            'formScheduleIntervalMinutes' => ['required', 'integer', 'min:5', 'max:20160'],
        ]);

        $scheduleTemplates = [];

        if ($validated['formScheduleWorkflowId']) {
            $scheduleTemplates[] = [
                'workflow_id' => (int) $validated['formScheduleWorkflowId'],
                'label' => 'Aus Bauplan',
                'cadence_type' => PersonWorkflowSchedule::CADENCE_INTERVAL,
                'interval_minutes' => (int) $validated['formScheduleIntervalMinutes'],
                'window_start' => $this->formScheduleWindowStart,
                'window_end' => $this->formScheduleWindowEnd,
                'jitter_seconds' => 900,
                'is_active' => true,
            ];
        }

        $blueprint = $this->editingBlueprintId
            ? PersonBlueprint::query()->find($this->editingBlueprintId)
            : new PersonBlueprint;

        if (! $blueprint) {
            return;
        }

        $blueprint->forceFill([
            'name' => $validated['formName'],
            'description' => $validated['formDescription'] ?? null,
            'is_active' => $this->formIsActive,
            'platform' => 'instagram',
            'target_count' => $validated['formTargetCount'],
            'per_day' => $validated['formPerDay'],
            'countries' => $this->splitList($validated['formCountries'] ?? ''),
            'languages' => $this->splitList($validated['formLanguages'] ?? ''),
            'genders' => $this->splitList($validated['formGenders'] ?? ''),
            'age_min' => $validated['formAgeMin'],
            'age_max' => $validated['formAgeMax'],
            'profile_prompt' => $validated['formProfilePrompt'] ?? null,
            'generate_avatar' => $this->formGenerateAvatar,
            'account_types' => array_values($this->formAccountTypes),
            'onboarding_workflow_id' => $validated['formOnboardingWorkflowId'] ?: null,
            'schedule_templates' => $scheduleTemplates,
        ])->save();

        $this->showBlueprintModal = false;
        $this->resetBlueprintForm();
        $this->dispatch('showAlert', 'Bauplan wurde gespeichert.', 'success');
    }

    public function toggleBlueprint(int $blueprintId): void
    {
        $blueprint = PersonBlueprint::query()->find($blueprintId);

        if (! $blueprint) {
            return;
        }

        $blueprint->forceFill(['is_active' => ! $blueprint->is_active, 'next_run_at' => now()])->save();
    }

    public function deleteBlueprint(int $blueprintId): void
    {
        PersonBlueprint::query()->whereKey($blueprintId)->delete();

        $this->dispatch('showAlert', 'Bauplan wurde entfernt.', 'success');
    }

    /**
     * Erzeugt sofort einen einzelnen Entwurf, ohne auf den Takt zu warten.
     */
    public function createDraftNow(int $blueprintId): void
    {
        $blueprint = PersonBlueprint::query()->find($blueprintId);

        if (! $blueprint) {
            return;
        }

        try {
            $person = app(PersonFactoryService::class)->createDraft($blueprint);
            $this->dispatch('showAlert', 'Entwurf "'.$person->display_name.'" wurde erzeugt.', 'success');
        } catch (\Throwable $exception) {
            $this->dispatch('showAlert', 'Entwurf konnte nicht erzeugt werden: '.$exception->getMessage(), 'error');
        }
    }

    // ==================================================================
    // Freigabe
    // ==================================================================

    public function approve(int $personId): void
    {
        $person = Person::query()->find($personId);

        if (! $person) {
            return;
        }

        app(PersonFactoryService::class)->approve($person);

        $this->dispatch('showAlert', $person->display_name.' wurde freigegeben.', 'success');
    }

    public function reject(int $personId): void
    {
        $person = Person::query()->find($personId);

        if (! $person) {
            return;
        }

        app(PersonFactoryService::class)->reject($person);

        $this->dispatch('showAlert', $person->display_name.' wurde abgelehnt.', 'success');
    }

    public function approveAll(): void
    {
        $factory = app(PersonFactoryService::class);
        $count = 0;

        Person::query()
            ->where('approval_status', 'draft')
            ->orderBy('id')
            ->each(function (Person $person) use ($factory, &$count): void {
                $factory->approve($person);
                $count++;
            });

        $this->dispatch('showAlert', $count.' Entwuerfe wurden freigegeben.', 'success');
    }

    protected function resetBlueprintForm(): void
    {
        $this->editingBlueprintId = null;
        $this->formName = '';
        $this->formDescription = '';
        $this->formIsActive = false;
        $this->formTargetCount = 10;
        $this->formPerDay = 2;
        $this->formCountries = '';
        $this->formLanguages = '';
        $this->formGenders = '';
        $this->formAgeMin = 24;
        $this->formAgeMax = 38;
        $this->formProfilePrompt = '';
        $this->formGenerateAvatar = false;
        $this->formAccountTypes = [];
        $this->formOnboardingWorkflowId = null;
        $this->formScheduleWorkflowId = null;
        $this->formScheduleIntervalMinutes = 720;
        $this->formScheduleWindowStart = '09:00';
        $this->formScheduleWindowEnd = '21:00';
        $this->resetErrorBag();
    }

    /**
     * @return array<int, string>
     */
    protected function splitList(string $value): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (string $item): string => trim($item),
            preg_split('/[\r\n,;]+/', $value) ?: [],
        ))));
    }
}
