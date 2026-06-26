<?php

namespace App\Livewire\Admin\Network;

use App\Models\Person;
use App\Models\Workflow;
use App\Models\WorkflowStep;
use App\Services\Workflows\PersonaActionWorkflowCatalog;
use App\Services\Workflows\WorkflowExecutionService;
use App\Services\Workflows\WorkflowTemplateService;
use Illuminate\Support\Str;
use Livewire\Component;

class WorkflowManager extends Component
{
    public ?int $selectedWorkflowId = null;

    public string $workflowName = '';

    public string $workflowDescription = '';

    public bool $workflowActive = true;

    public string $newWorkflowName = '';

    public string $newWorkflowDescription = '';

    public string $newStepType = WorkflowStep::TYPE_PLANNED_ACTION;

    public string $newStepName = '';

    public string $newStepProvider = 'proton';

    public int $newStepWaitSeconds = 30;

    public string $newTaskListId = '';

    public string $newTaskTitle = '';

    public string $newTaskKind = 'browser';

    public string $newTaskDescription = '';

    public string $newTaskSuccessTarget = '';

    public string $newTaskFailedTarget = 'fail';

    public string $runPersonId = '';

    public string $actionPersonFilter = '';

    public string $actionTypeFilter = 'all';

    public function mount(): void
    {
        if (! Workflow::query()->exists()) {
            app(WorkflowTemplateService::class)->ensureDefaults();
        }

        $this->selectedWorkflowId = Workflow::query()
            ->orderBy('category')
            ->orderBy('name')
            ->value('id');

        $this->loadWorkflowForm();
    }

    public function render()
    {
        $catalog = app(PersonaActionWorkflowCatalog::class);
        $workflows = Workflow::query()
            ->withCount(['steps', 'runs'])
            ->orderBy('category')
            ->orderBy('name')
            ->get();
        $selectedWorkflow = $this->selectedWorkflow();
        $steps = $selectedWorkflow
            ? $selectedWorkflow->steps()->ordered()->get()
            : collect();
        $runs = $selectedWorkflow
            ? $selectedWorkflow->runs()->with(['stepRuns.workflowStep'])->limit(8)->get()
            : collect();
        $persons = Person::query()
            ->where('platform', 'instagram')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
        $catalogPersons = $catalog->persons();
        $actions = array_slice($catalog->actions($catalogPersons, $this->actionPersonFilter, $this->actionTypeFilter), 0, 30);

        return view('livewire.admin.network.workflow-manager', [
            'workflows' => $workflows,
            'selectedWorkflow' => $selectedWorkflow,
            'steps' => $steps,
            'runs' => $runs,
            'persons' => $persons,
            'personOptions' => $catalog->personOptions($catalogPersons),
            'actions' => $actions,
            'summary' => [
                'workflows' => $workflows->count(),
                'active_workflows' => $workflows->where('is_active', true)->count(),
                'steps' => $steps->count(),
                'runs' => $runs->count(),
            ],
        ])->layout('layouts.master');
    }

    public function selectWorkflow(int $workflowId): void
    {
        $this->selectedWorkflowId = $workflowId;
        $this->loadWorkflowForm();
    }

    public function createWorkflow(): void
    {
        $validated = $this->validate([
            'newWorkflowName' => ['required', 'string', 'max:160'],
            'newWorkflowDescription' => ['nullable', 'string', 'max:1000'],
        ]);

        $workflow = Workflow::query()->create([
            'name' => trim($validated['newWorkflowName']),
            'slug' => $this->uniqueSlug($validated['newWorkflowName']),
            'description' => trim((string) ($validated['newWorkflowDescription'] ?? '')),
            'category' => 'custom',
            'is_active' => true,
            'trigger_type' => 'manual',
            'settings_json' => [
                'created_from' => 'workflow-manager',
            ],
        ]);

        $this->newWorkflowName = '';
        $this->newWorkflowDescription = '';
        $this->selectedWorkflowId = $workflow->id;
        $this->loadWorkflowForm();

        session()->flash('success', 'Workflow wurde erstellt.');
    }

    public function saveWorkflow(): void
    {
        $workflow = $this->selectedWorkflow();

        if (! $workflow) {
            return;
        }

        $validated = $this->validate([
            'workflowName' => ['required', 'string', 'max:160'],
            'workflowDescription' => ['nullable', 'string', 'max:1000'],
            'workflowActive' => ['boolean'],
        ]);

        $workflow->forceFill([
            'name' => trim($validated['workflowName']),
            'description' => trim((string) ($validated['workflowDescription'] ?? '')),
            'is_active' => (bool) $validated['workflowActive'],
        ])->save();

        session()->flash('success', 'Workflow wurde gespeichert.');
    }

    public function deleteWorkflow(): void
    {
        $workflow = $this->selectedWorkflow();

        if (! $workflow) {
            return;
        }

        $workflow->delete();
        $this->selectedWorkflowId = Workflow::query()->orderBy('name')->value('id');
        $this->loadWorkflowForm();

        session()->flash('success', 'Workflow wurde geloescht.');
    }

    public function addStep(): void
    {
        $workflow = $this->selectedWorkflow();

        if (! $workflow) {
            return;
        }

        $validated = $this->validate([
            'newStepType' => ['required', 'string', 'in:mail_account_registration,webmail_login,planned_action,wait'],
            'newStepName' => ['nullable', 'string', 'max:160'],
            'newStepProvider' => ['required', 'string', 'in:proton,gmx'],
            'newStepWaitSeconds' => ['required', 'integer', 'min:0', 'max:3600'],
        ]);

        $type = $validated['newStepType'];
        $position = ((int) $workflow->steps()->max('position')) + 10;
        $name = trim((string) ($validated['newStepName'] ?? '')) ?: $this->defaultStepName($type);

        $workflow->steps()->create([
            'name' => $name,
            'type' => $type,
            'action_key' => Str::slug($name.'-'.$position),
            'position' => $position,
            'is_enabled' => true,
            'config_json' => $this->stepConfig($type, $validated),
            'wait_after_seconds' => $type === WorkflowStep::TYPE_WAIT ? 0 : 0,
        ]);

        $this->newStepName = '';

        session()->flash('success', 'Workflow-Liste wurde hinzugefuegt.');
    }

    public function addActionStep(string $actionId): void
    {
        $catalog = app(PersonaActionWorkflowCatalog::class);
        $workflow = $this->selectedWorkflow();
        $action = $catalog->actionById($actionId);

        if (! $workflow || ! $action) {
            session()->flash('success', 'Aktion konnte nicht gefunden werden.');

            return;
        }

        $workflow->steps()->create([
            'name' => (string) ($action['label'] ?? 'Geplante Aktion'),
            'type' => WorkflowStep::TYPE_PLANNED_ACTION,
            'action_key' => (string) ($action['id'] ?? Str::uuid()),
            'position' => ((int) $workflow->steps()->max('position')) + 10,
            'is_enabled' => true,
            'config_json' => $catalog->workflowStepConfig($action),
        ]);

        session()->flash('success', 'Aktion wurde dem Workflow hinzugefuegt.');
    }

    public function toggleStep(int $stepId): void
    {
        $step = $this->stepForSelectedWorkflow($stepId);

        if (! $step) {
            return;
        }

        $step->forceFill(['is_enabled' => ! $step->is_enabled])->save();
    }

    public function removeStep(int $stepId): void
    {
        $step = $this->stepForSelectedWorkflow($stepId);

        if (! $step) {
            return;
        }

        $step->delete();
        $this->normalizeStepPositions();

        session()->flash('success', 'Workflow-Liste wurde entfernt.');
    }

    public function reorderStep(mixed $item, mixed $position): void
    {
        $workflow = $this->selectedWorkflow();

        if (! $workflow) {
            return;
        }

        $stepId = (int) $item;
        $targetPosition = max(0, (int) $position);
        $steps = $workflow->steps()->ordered()->get();
        $moving = $steps->firstWhere('id', $stepId);

        if (! $moving) {
            return;
        }

        $ordered = $steps
            ->reject(fn (WorkflowStep $step): bool => $step->id === $stepId)
            ->values();

        $ordered->splice(min($targetPosition, $ordered->count()), 0, [$moving]);

        foreach ($ordered->values() as $index => $step) {
            $step->forceFill(['position' => ($index + 1) * 10])->save();
        }
    }

    public function addTaskCard(): void
    {
        $workflow = $this->selectedWorkflow();

        if (! $workflow) {
            return;
        }

        $validated = $this->validate([
            'newTaskListId' => ['required', 'integer'],
            'newTaskTitle' => ['required', 'string', 'max:160'],
            'newTaskKind' => ['required', 'string', 'in:browser,input,wait,data'],
            'newTaskDescription' => ['nullable', 'string', 'max:1000'],
            'newTaskSuccessTarget' => ['nullable', 'string', 'max:180'],
            'newTaskFailedTarget' => ['nullable', 'string', 'max:180'],
        ]);

        $step = $this->stepForSelectedWorkflow((int) $validated['newTaskListId']);

        if (! $step) {
            return;
        }

        $config = is_array($step->config_json) ? $step->config_json : [];
        $tasks = is_array($config['tasks'] ?? null) ? $config['tasks'] : [];
        $key = $this->uniqueTaskKey($tasks, $validated['newTaskTitle']);
        $task = [
            'key' => $key,
            'title' => trim($validated['newTaskTitle']),
            'description' => trim((string) ($validated['newTaskDescription'] ?? '')),
            'kind' => $validated['newTaskKind'],
            'status' => 'configured',
        ];

        $successRoute = $this->routeTargetFromValue((string) ($validated['newTaskSuccessTarget'] ?? ''));
        $failedRoute = $this->routeTargetFromValue((string) ($validated['newTaskFailedTarget'] ?? ''));

        if ($successRoute) {
            $task['next'] = $successRoute;
        }

        if ($failedRoute) {
            $task['on_error'] = $failedRoute;
        }

        $tasks[] = $task;
        $config['tasks'] = array_values($tasks);
        $step->forceFill(['config_json' => $config])->save();

        $this->newTaskTitle = '';
        $this->newTaskDescription = '';
        $this->newTaskSuccessTarget = '';
        $this->newTaskFailedTarget = 'fail';

        session()->flash('success', 'Step-Karte wurde hinzugefuegt.');
    }

    public function removeTaskCard(int $stepId, string $taskKey): void
    {
        $step = $this->stepForSelectedWorkflow($stepId);

        if (! $step) {
            return;
        }

        $config = is_array($step->config_json) ? $step->config_json : [];
        $tasks = collect(is_array($config['tasks'] ?? null) ? $config['tasks'] : [])
            ->reject(fn (array $task): bool => (string) ($task['key'] ?? '') === $taskKey)
            ->values()
            ->toArray();

        $config['tasks'] = $tasks;
        $step->forceFill(['config_json' => $config])->save();

        session()->flash('success', 'Step-Karte wurde entfernt.');
    }

    public function reorderTaskCard(int $stepId, mixed $item, mixed $position): void
    {
        $step = $this->stepForSelectedWorkflow($stepId);

        if (! $step) {
            return;
        }

        $taskKey = (string) $item;
        $targetPosition = max(0, (int) $position);
        $config = is_array($step->config_json) ? $step->config_json : [];
        $tasks = collect(is_array($config['tasks'] ?? null) ? $config['tasks'] : []);
        $moving = $tasks->first(fn (array $task): bool => (string) ($task['key'] ?? '') === $taskKey);

        if (! $moving) {
            return;
        }

        $ordered = $tasks
            ->reject(fn (array $task): bool => (string) ($task['key'] ?? '') === $taskKey)
            ->values();

        $ordered->splice(min($targetPosition, $ordered->count()), 0, [$moving]);
        $config['tasks'] = $ordered->values()->toArray();
        $step->forceFill(['config_json' => $config])->save();
    }

    public function runWorkflow(): void
    {
        $execution = app(WorkflowExecutionService::class);
        $workflow = $this->selectedWorkflow();

        if (! $workflow) {
            return;
        }

        $validated = $this->validate([
            'runPersonId' => ['nullable', 'integer', 'exists:persons,id'],
        ]);

        try {
            $run = $execution->start($workflow, [
                'person_id' => $validated['runPersonId'] ?: null,
                'started_from' => 'workflow-manager',
            ]);

            session()->flash('success', 'Workflow-Lauf wurde eingeplant: '.$run->run_uuid);
        } catch (\Throwable $exception) {
            session()->flash('success', 'Workflow konnte nicht gestartet werden: '.$exception->getMessage());
        }
    }

    protected function selectedWorkflow(): ?Workflow
    {
        if (! $this->selectedWorkflowId) {
            return null;
        }

        return Workflow::query()->find($this->selectedWorkflowId);
    }

    protected function loadWorkflowForm(): void
    {
        $workflow = $this->selectedWorkflow();

        $this->workflowName = (string) ($workflow?->name ?? '');
        $this->workflowDescription = (string) ($workflow?->description ?? '');
        $this->workflowActive = (bool) ($workflow?->is_active ?? true);
    }

    protected function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'workflow';
        $slug = $base;
        $counter = 2;

        while (Workflow::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$counter++;
        }

        return $slug;
    }

    protected function defaultStepName(string $type): string
    {
        return match ($type) {
            WorkflowStep::TYPE_MAIL_ACCOUNT_REGISTRATION => 'E-Mail-Postfach registrieren',
            WorkflowStep::TYPE_WEBMAIL_LOGIN => 'Webmailportal Login speichern',
            WorkflowStep::TYPE_WAIT => 'Warten',
            default => 'Geplante Aktion',
        };
    }

    protected function stepConfig(string $type, array $validated): array
    {
        return match ($type) {
            WorkflowStep::TYPE_MAIL_ACCOUNT_REGISTRATION => [
                'provider_key' => $validated['newStepProvider'],
                'allow_partial' => false,
            ],
            WorkflowStep::TYPE_WEBMAIL_LOGIN => [
                'provider' => $validated['newStepProvider'],
                'use_person_email_account' => true,
                'allow_partial' => false,
            ],
            WorkflowStep::TYPE_WAIT => [
                'seconds' => (int) $validated['newStepWaitSeconds'],
            ],
            default => [
                'source' => 'manual',
                'label' => trim($validated['newStepName'] ?? '') ?: 'Geplante Aktion',
                'tasks' => [
                    [
                        'key' => 'aktion-ausfuehren',
                        'title' => 'Aktion ausfuehren',
                        'description' => 'Geplante Aktion als Workflow-Task verarbeiten.',
                        'kind' => 'data',
                        'status' => 'configured',
                        'next' => ['step' => 'next', 'label' => 'Naechste Liste'],
                        'on_error' => ['step' => 'fail', 'label' => 'Fehlerroute'],
                    ],
                ],
            ],
        };
    }

    protected function routeTargetFromValue(string $value): ?array
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if ($value === 'end') {
            return ['step' => 'end', 'label' => 'Workflow abschliessen'];
        }

        if ($value === 'fail') {
            return ['step' => 'fail', 'label' => 'Fehlerroute'];
        }

        if (str_starts_with($value, 'step:')) {
            $actionKey = trim(substr($value, 5));
            $workflow = $this->selectedWorkflow();
            $target = $workflow
                ? $workflow->steps()->where('action_key', $actionKey)->first()
                : null;

            if (! $target) {
                return null;
            }

            return [
                'step' => $target->action_key,
                'label' => $target->name,
            ];
        }

        if (str_starts_with($value, 'card:')) {
            $parts = explode(':', $value, 3);
            $stepId = (int) ($parts[1] ?? 0);
            $taskKey = trim((string) ($parts[2] ?? ''));
            $targetStep = $this->stepForSelectedWorkflow($stepId);

            if (! $targetStep || $taskKey === '') {
                return null;
            }

            $targetTask = collect($targetStep->task_cards)
                ->first(fn (array $task): bool => (string) ($task['key'] ?? '') === $taskKey);

            if (! $targetTask) {
                return null;
            }

            return [
                'step' => $targetStep->action_key,
                'card' => $taskKey,
                'label' => $targetStep->name.' / '.(string) ($targetTask['title'] ?? $taskKey),
            ];
        }

        return null;
    }

    protected function uniqueTaskKey(array $tasks, string $title): string
    {
        $base = Str::slug($title) ?: 'task';
        $existing = collect($tasks)
            ->map(fn (array $task): string => (string) ($task['key'] ?? ''))
            ->filter()
            ->all();
        $key = $base;
        $counter = 2;

        while (in_array($key, $existing, true)) {
            $key = $base.'-'.$counter++;
        }

        return $key;
    }

    protected function stepForSelectedWorkflow(int $stepId): ?WorkflowStep
    {
        $workflow = $this->selectedWorkflow();

        if (! $workflow) {
            return null;
        }

        return WorkflowStep::query()
            ->where('workflow_id', $workflow->id)
            ->where('id', $stepId)
            ->first();
    }

    protected function normalizeStepPositions(): void
    {
        $workflow = $this->selectedWorkflow();

        if (! $workflow) {
            return;
        }

        foreach ($workflow->steps()->ordered()->get()->values() as $index => $step) {
            $step->forceFill(['position' => ($index + 1) * 10])->save();
        }
    }
}
