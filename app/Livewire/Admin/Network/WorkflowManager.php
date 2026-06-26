<?php

namespace App\Livewire\Admin\Network;

use App\Models\Person;
use App\Models\Workflow;
use App\Models\WorkflowStep;
use App\Services\Workflows\PersonaActionWorkflowCatalog;
use App\Services\Workflows\WorkflowExecutionService;
use App\Services\Workflows\WorkflowTaskCatalog;
use Illuminate\Support\Str;
use Livewire\Component;

class WorkflowManager extends Component
{
    public ?int $selectedWorkflowId = null;

    public string $workflowName = '';

    public string $workflowDescription = '';

    public bool $workflowActive = true;

    public string $newStepType = WorkflowStep::TYPE_PREPARATION;

    public string $newStepName = '';

    public string $newTaskListId = '';

    public string $newTaskCatalogKey = 'data.resolve_person';

    public string $newTaskTitle = '';

    public string $newTaskKind = 'data';

    public string $newTaskDescription = '';

    public string $newTaskElementSelector = '';

    public string $newTaskInputSelector = '';

    public string $newTaskInputValue = '';

    public string $newTaskSuccessPayload = '';

    public string $newTaskFailurePayload = '';

    public string $newTaskSuccessTarget = '';

    public string $newTaskPartialTarget = '';

    public string $newTaskFailedTarget = 'fail';

    public string $runPersonId = '';

    public string $actionPersonFilter = '';

    public string $actionTypeFilter = 'all';

    public bool $showWorkflowModal = false;

    public bool $showRunModal = false;

    public bool $showAddStepModal = false;

    public bool $showAddTaskModal = false;

    public bool $showActionLibraryModal = false;

    public bool $showEditStepModal = false;

    public bool $showEditTaskModal = false;

    public ?int $editingStepId = null;

    public string $editingStepName = '';

    public string $editingStepDescription = '';

    public bool $editingStepEnabled = true;

    public int $editingStepWaitAfterSeconds = 0;

    public string $editingStepSuccessTarget = '';

    public string $editingStepPartialTarget = '';

    public string $editingStepFailedTarget = '';

    public ?int $editingTaskStepId = null;

    public string $editingTaskKey = '';

    public string $editingTaskCatalogKey = '';

    public string $editingTaskTitle = '';

    public string $editingTaskKind = 'browser';

    public string $editingTaskDescription = '';

    public string $editingTaskElementSelector = '';

    public string $editingTaskInputSelector = '';

    public string $editingTaskInputValue = '';

    public string $editingTaskSuccessPayload = '';

    public string $editingTaskFailurePayload = '';

    public int $editingTaskTimeoutSeconds = 0;

    public string $editingTaskSuccessTarget = '';

    public string $editingTaskPartialTarget = '';

    public string $editingTaskFailedTarget = '';

    public function mount(Workflow $workflow): void
    {
        $this->selectedWorkflowId = $workflow->id;
        $this->loadWorkflowForm();
    }

    public function render()
    {
        $catalog = app(PersonaActionWorkflowCatalog::class);
        $taskCatalog = app(WorkflowTaskCatalog::class);
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
            'selectedWorkflow' => $selectedWorkflow,
            'steps' => $steps,
            'runs' => $runs,
            'persons' => $persons,
            'personOptions' => $catalog->personOptions($catalogPersons),
            'actions' => $actions,
            'taskDefinitions' => $taskCatalog->options(),
            'summary' => [
                'actions' => $steps->filter(fn (WorkflowStep $step): bool => $step->type !== WorkflowStep::TYPE_WAIT)->count(),
                'lists' => $steps->count(),
                'task_cards' => $steps->sum(fn (WorkflowStep $step): int => count($step->task_cards)),
                'runs' => $selectedWorkflow?->runs()->count() ?? 0,
            ],
        ])->layout('layouts.master');
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

        $this->showWorkflowModal = false;

        session()->flash('success', 'Workflow wurde gespeichert.');
    }

    public function deleteWorkflow(): void
    {
        $workflow = $this->selectedWorkflow();

        if (! $workflow) {
            return;
        }

        $workflow->delete();

        session()->flash('success', 'Workflow wurde geloescht. Du kannst ihn jetzt per Seeder neu erzeugen.');

        $this->redirectRoute('network.workflows');
    }

    public function addStep(): void
    {
        $workflow = $this->selectedWorkflow();

        if (! $workflow) {
            return;
        }

        $validated = $this->validate([
            'newStepType' => ['required', 'string', 'in:preparation,data_processing,browser_control,interaction,decision,cleanup'],
            'newStepName' => ['nullable', 'string', 'max:160'],
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
            'wait_after_seconds' => 0,
        ]);

        $this->newStepName = '';
        $this->showAddStepModal = false;

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

        $this->showActionLibraryModal = false;

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

    public function openEditStep(int $stepId): void
    {
        $step = $this->stepForSelectedWorkflow($stepId);

        if (! $step) {
            return;
        }

        $config = is_array($step->config_json) ? $step->config_json : [];
        $routes = is_array($config['routes'] ?? null) ? $config['routes'] : [];

        $this->editingStepId = $step->id;
        $this->editingStepName = $step->name;
        $this->editingStepDescription = trim((string) ($config['description'] ?? $config['automation_summary'] ?? ''));
        $this->editingStepEnabled = (bool) $step->is_enabled;
        $this->editingStepWaitAfterSeconds = max(0, (int) $step->wait_after_seconds);
        $this->editingStepSuccessTarget = $this->routeValueFromTarget($routes['success'] ?? null);
        $this->editingStepPartialTarget = $this->routeValueFromTarget($routes['partial'] ?? null);
        $this->editingStepFailedTarget = $this->routeValueFromTarget($routes['failed'] ?? null);
        $this->showEditStepModal = true;
    }

    public function saveEditStep(): void
    {
        $step = $this->editingStepId ? $this->stepForSelectedWorkflow($this->editingStepId) : null;

        if (! $step) {
            return;
        }

        $validated = $this->validate([
            'editingStepName' => ['required', 'string', 'max:160'],
            'editingStepDescription' => ['nullable', 'string', 'max:1000'],
            'editingStepEnabled' => ['boolean'],
            'editingStepWaitAfterSeconds' => ['required', 'integer', 'min:0', 'max:3600'],
            'editingStepSuccessTarget' => ['nullable', 'string', 'max:180'],
            'editingStepPartialTarget' => ['nullable', 'string', 'max:180'],
            'editingStepFailedTarget' => ['nullable', 'string', 'max:180'],
        ]);

        $config = is_array($step->config_json) ? $step->config_json : [];
        $config['description'] = trim((string) ($validated['editingStepDescription'] ?? ''));
        $routes = is_array($config['routes'] ?? null) ? $config['routes'] : [];
        $routes = $this->setRoute($routes, 'success', (string) ($validated['editingStepSuccessTarget'] ?? ''));
        $routes = $this->setRoute($routes, 'partial', (string) ($validated['editingStepPartialTarget'] ?? ''));
        $routes = $this->setRoute($routes, 'failed', (string) ($validated['editingStepFailedTarget'] ?? ''));
        $config['routes'] = $routes;

        $step->forceFill([
            'name' => trim($validated['editingStepName']),
            'is_enabled' => (bool) $validated['editingStepEnabled'],
            'wait_after_seconds' => (int) $validated['editingStepWaitAfterSeconds'],
            'config_json' => $config,
        ])->save();

        $this->showEditStepModal = false;

        session()->flash('success', 'Liste wurde gespeichert.');
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
            'newTaskCatalogKey' => ['required', 'string', 'max:120'],
            'newTaskTitle' => ['required', 'string', 'max:160'],
            'newTaskKind' => ['required', 'string', 'in:browser,input,wait,data'],
            'newTaskDescription' => ['nullable', 'string', 'max:1000'],
            'newTaskElementSelector' => ['nullable', 'string', 'max:1000'],
            'newTaskInputSelector' => ['nullable', 'string', 'max:1000'],
            'newTaskInputValue' => ['nullable', 'string', 'max:2000'],
            'newTaskSuccessPayload' => ['nullable', 'string', 'max:4000'],
            'newTaskFailurePayload' => ['nullable', 'string', 'max:4000'],
            'newTaskSuccessTarget' => ['nullable', 'string', 'max:180'],
            'newTaskPartialTarget' => ['nullable', 'string', 'max:180'],
            'newTaskFailedTarget' => ['nullable', 'string', 'max:180'],
        ]);

        $step = $this->stepForSelectedWorkflow((int) $validated['newTaskListId']);

        if (! $step) {
            return;
        }

        $config = is_array($step->config_json) ? $step->config_json : [];
        $tasks = is_array($config['tasks'] ?? null) ? $config['tasks'] : [];
        $key = $this->uniqueTaskKey($tasks, $validated['newTaskTitle']);
        $task = app(WorkflowTaskCatalog::class)->cardFromDefinition($validated['newTaskCatalogKey'], [
            'key' => $key,
            'title' => trim($validated['newTaskTitle']),
            'description' => trim((string) ($validated['newTaskDescription'] ?? '')),
            'kind' => $validated['newTaskKind'],
            'selector' => trim((string) ($validated['newTaskElementSelector'] ?? '')),
            'element_selector' => trim((string) ($validated['newTaskElementSelector'] ?? '')),
            'input_selector' => trim((string) ($validated['newTaskInputSelector'] ?? '')),
            'input' => trim((string) ($validated['newTaskInputValue'] ?? '')),
            'value' => trim((string) ($validated['newTaskInputValue'] ?? '')),
            'status' => 'configured',
        ]);

        $successPayload = $this->payloadFromInput((string) ($validated['newTaskSuccessPayload'] ?? ''));
        $failurePayload = $this->payloadFromInput((string) ($validated['newTaskFailurePayload'] ?? ''));

        if ($successPayload !== null) {
            $task['success_payload'] = $successPayload;
        }

        if ($failurePayload !== null) {
            $task['failure_payload'] = $failurePayload;
        }

        $successRoute = $this->routeTargetFromValue((string) ($validated['newTaskSuccessTarget'] ?? ''));
        $partialRoute = $this->routeTargetFromValue((string) ($validated['newTaskPartialTarget'] ?? ''));
        $failedRoute = $this->routeTargetFromValue((string) ($validated['newTaskFailedTarget'] ?? ''));

        if ($successRoute) {
            $task['next'] = $successRoute;
        }

        if ($partialRoute) {
            $task['on_partial'] = $partialRoute;
        }

        if ($failedRoute) {
            $task['on_error'] = $failedRoute;
        }

        $tasks[] = $task;
        $config['tasks'] = array_values($tasks);
        $step->forceFill(['config_json' => $config])->save();

        $this->newTaskTitle = '';
        $this->newTaskDescription = '';
        $this->newTaskElementSelector = '';
        $this->newTaskInputSelector = '';
        $this->newTaskInputValue = '';
        $this->newTaskSuccessPayload = '';
        $this->newTaskFailurePayload = '';
        $this->newTaskSuccessTarget = '';
        $this->newTaskPartialTarget = '';
        $this->newTaskFailedTarget = 'fail';
        $this->showAddTaskModal = false;

        session()->flash('success', 'Step-Karte wurde hinzugefuegt.');
    }

    public function openEditTaskCard(int $stepId, string $taskKey): void
    {
        $step = $this->stepForSelectedWorkflow($stepId);

        if (! $step) {
            return;
        }

        $task = collect($step->task_cards)
            ->first(fn (array $task): bool => (string) ($task['key'] ?? '') === $taskKey);

        if (! $task) {
            return;
        }

        $this->editingTaskStepId = $step->id;
        $this->editingTaskKey = $taskKey;
        $this->editingTaskCatalogKey = trim((string) ($task['task_key'] ?? '')) ?: 'browser.open_url';
        $this->editingTaskTitle = (string) ($task['title'] ?? 'Task');
        $this->editingTaskKind = (string) ($task['kind'] ?? 'browser');
        $this->editingTaskDescription = (string) ($task['description'] ?? '');
        $this->editingTaskElementSelector = (string) ($task['element_selector'] ?? $task['selector'] ?? '');
        $this->editingTaskInputSelector = (string) ($task['input_selector'] ?? '');
        $this->editingTaskInputValue = (string) ($task['value'] ?? $task['input'] ?? '');
        $this->editingTaskSuccessPayload = $this->payloadToString($task['success_payload'] ?? null);
        $this->editingTaskFailurePayload = $this->payloadToString($task['failure_payload'] ?? null);
        $this->editingTaskTimeoutSeconds = max(0, (int) ($task['timeout_seconds'] ?? 0));
        $this->editingTaskSuccessTarget = $this->routeValueFromTarget($task['next'] ?? null);
        $this->editingTaskPartialTarget = $this->routeValueFromTarget($task['on_partial'] ?? null);
        $this->editingTaskFailedTarget = $this->routeValueFromTarget($task['on_error'] ?? null);
        $this->showEditTaskModal = true;
    }

    public function saveEditTaskCard(): void
    {
        $step = $this->editingTaskStepId ? $this->stepForSelectedWorkflow($this->editingTaskStepId) : null;

        if (! $step) {
            return;
        }

        $validated = $this->validate([
            'editingTaskCatalogKey' => ['required', 'string', 'max:120'],
            'editingTaskTitle' => ['required', 'string', 'max:160'],
            'editingTaskKind' => ['required', 'string', 'in:browser,input,wait,data'],
            'editingTaskDescription' => ['nullable', 'string', 'max:1000'],
            'editingTaskElementSelector' => ['nullable', 'string', 'max:1000'],
            'editingTaskInputSelector' => ['nullable', 'string', 'max:1000'],
            'editingTaskInputValue' => ['nullable', 'string', 'max:2000'],
            'editingTaskSuccessPayload' => ['nullable', 'string', 'max:4000'],
            'editingTaskFailurePayload' => ['nullable', 'string', 'max:4000'],
            'editingTaskTimeoutSeconds' => ['required', 'integer', 'min:0', 'max:3600'],
            'editingTaskSuccessTarget' => ['nullable', 'string', 'max:180'],
            'editingTaskPartialTarget' => ['nullable', 'string', 'max:180'],
            'editingTaskFailedTarget' => ['nullable', 'string', 'max:180'],
        ]);

        $config = is_array($step->config_json) ? $step->config_json : [];
        $tasks = collect(is_array($config['tasks'] ?? null) ? $config['tasks'] : []);

        $config['tasks'] = $tasks
            ->map(function (array $task) use ($validated): array {
                if ((string) ($task['key'] ?? '') !== $this->editingTaskKey) {
                    return $task;
                }

                $task = array_replace(
                    $task,
                    app(WorkflowTaskCatalog::class)->cardFromDefinition($validated['editingTaskCatalogKey'], [
                        'key' => $this->editingTaskKey,
                    ]),
                    [
                        'key' => $this->editingTaskKey,
                        'task_key' => $validated['editingTaskCatalogKey'],
                        'title' => trim($validated['editingTaskTitle']),
                        'description' => trim((string) ($validated['editingTaskDescription'] ?? '')),
                        'kind' => $validated['editingTaskKind'],
                        'selector' => trim((string) ($validated['editingTaskElementSelector'] ?? '')),
                        'element_selector' => trim((string) ($validated['editingTaskElementSelector'] ?? '')),
                        'input_selector' => trim((string) ($validated['editingTaskInputSelector'] ?? '')),
                        'input' => trim((string) ($validated['editingTaskInputValue'] ?? '')),
                        'value' => trim((string) ($validated['editingTaskInputValue'] ?? '')),
                        'timeout_seconds' => (int) $validated['editingTaskTimeoutSeconds'],
                    ],
                );

                foreach ([
                    'success_payload' => (string) ($validated['editingTaskSuccessPayload'] ?? ''),
                    'failure_payload' => (string) ($validated['editingTaskFailurePayload'] ?? ''),
                ] as $key => $value) {
                    $payload = $this->payloadFromInput($value);

                    if ($payload !== null) {
                        $task[$key] = $payload;
                    } else {
                        unset($task[$key]);
                    }
                }

                foreach ([
                    'next' => (string) ($validated['editingTaskSuccessTarget'] ?? ''),
                    'on_partial' => (string) ($validated['editingTaskPartialTarget'] ?? ''),
                    'on_error' => (string) ($validated['editingTaskFailedTarget'] ?? ''),
                ] as $key => $value) {
                    $route = $this->routeTargetFromValue($value);

                    if ($route) {
                        $task[$key] = $route;
                    } else {
                        unset($task[$key]);
                    }
                }

                return $task;
            })
            ->values()
            ->toArray();

        $step->forceFill(['config_json' => $config])->save();

        $this->showEditTaskModal = false;

        session()->flash('success', 'Step-Karte wurde gespeichert.');
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

            $this->showRunModal = false;
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

    protected function defaultStepName(string $type): string
    {
        return match ($type) {
            WorkflowStep::TYPE_MAIL_ACCOUNT_REGISTRATION => 'E-Mail-Postfach registrieren',
            WorkflowStep::TYPE_WEBMAIL_LOGIN => 'Webmailportal Login speichern',
            WorkflowStep::TYPE_DATA_PROCESSING => 'Daten verarbeiten',
            WorkflowStep::TYPE_BROWSER_CONTROL => 'Browsersteuerung',
            WorkflowStep::TYPE_INTERACTION => 'Interaktion',
            WorkflowStep::TYPE_DECISION => 'Status pruefen',
            WorkflowStep::TYPE_CLEANUP => 'Abschluss',
            default => 'Vorbereitung',
        };
    }

    protected function stepConfig(string $type, array $validated): array
    {
        return match ($type) {
            WorkflowStep::TYPE_PREPARATION => $this->genericStepConfig(
                'Vorbereitung',
                'Person- und Kontextdaten fuer die folgenden Aufgaben ermitteln.',
                'data.resolve_person',
            ),
            WorkflowStep::TYPE_DATA_PROCESSING => $this->genericStepConfig(
                'Daten verarbeiten',
                'Daten aus vorherigen Tasks lesen, normalisieren oder speichern.',
                'data.read_account_data',
            ),
            WorkflowStep::TYPE_BROWSER_CONTROL => $this->genericStepConfig(
                'Browsersteuerung',
                'Browserfenster oeffnen, URL aufrufen oder Browserfenster schliessen.',
                'browser.open',
            ),
            WorkflowStep::TYPE_INTERACTION => $this->genericStepConfig(
                'Interaktion',
                'Elemente ermitteln, Eingabefelder fuellen oder Buttons und Links klicken.',
                'browser.find_element',
            ),
            WorkflowStep::TYPE_DECISION => $this->genericStepConfig(
                'Status pruefen',
                'Statusregeln auswerten und je nach Ergebnis weiterleiten.',
                'wait.status',
            ),
            WorkflowStep::TYPE_CLEANUP => $this->genericStepConfig(
                'Abschluss',
                'Abschlussarbeiten ausfuehren und Browser/Runtime-Kontext sauber beenden.',
                'browser.close',
            ),
            WorkflowStep::TYPE_MAIL_ACCOUNT_REGISTRATION => [
                'provider_key' => 'standard',
                'allow_partial' => false,
                'timeout_seconds' => 1800,
                'tasks' => [
                    app(WorkflowTaskCatalog::class)->cardFromDefinition('browser.open', ['key' => 'open-browser']),
                    app(WorkflowTaskCatalog::class)->cardFromDefinition('browser.open_url', ['key' => 'open-registration-url', 'title' => 'Registrierungsseite aufrufen']),
                    app(WorkflowTaskCatalog::class)->cardFromDefinition('browser.find_inputs', ['key' => 'find-registration-inputs', 'title' => 'Input-Felder suchen']),
                    app(WorkflowTaskCatalog::class)->cardFromDefinition('input.fill_field', ['key' => 'fill-registration-inputs', 'title' => 'Formularfelder fuellen']),
                    app(WorkflowTaskCatalog::class)->cardFromDefinition('wait.status', ['key' => 'check-registration-status', 'title' => 'Registrierungsstatus auswerten']),
                    app(WorkflowTaskCatalog::class)->cardFromDefinition('data.persist_mail_account', ['key' => 'persist-account-data', 'title' => 'Accountdaten speichern']),
                    app(WorkflowTaskCatalog::class)->cardFromDefinition('browser.close', ['key' => 'close-browser']),
                ],
                'routes' => [
                    'success' => ['type' => 'step', 'step' => 'next', 'label' => 'Naechste Liste'],
                    'partial' => ['type' => 'end', 'label' => 'Manuelle Pruefung'],
                    'failed' => ['type' => 'fail', 'label' => 'Registrierung fehlgeschlagen'],
                    'timeout' => ['type' => 'fail', 'label' => 'Registrierung Timeout'],
                ],
            ],
            WorkflowStep::TYPE_WEBMAIL_LOGIN => [
                'provider' => 'standard',
                'use_person_email_account' => true,
                'allow_partial' => false,
                'timeout_seconds' => 900,
                'tasks' => [
                    app(WorkflowTaskCatalog::class)->cardFromDefinition('data.read_login_data', ['key' => 'read-login-data']),
                    app(WorkflowTaskCatalog::class)->cardFromDefinition('browser.open', ['key' => 'open-browser']),
                    app(WorkflowTaskCatalog::class)->cardFromDefinition('browser.open_url', ['key' => 'open-webmail-url', 'title' => 'Webmailportal aufrufen']),
                    app(WorkflowTaskCatalog::class)->cardFromDefinition('browser.find_inputs', ['key' => 'find-login-inputs', 'title' => 'Loginfelder suchen']),
                    app(WorkflowTaskCatalog::class)->cardFromDefinition('input.fill_field', ['key' => 'fill-username', 'title' => 'Benutzername fuellen']),
                    app(WorkflowTaskCatalog::class)->cardFromDefinition('input.fill_field', ['key' => 'fill-password', 'title' => 'Passwort fuellen']),
                    app(WorkflowTaskCatalog::class)->cardFromDefinition('wait.selector', ['key' => 'wait-mailbox', 'title' => 'Postfach erkennen']),
                    app(WorkflowTaskCatalog::class)->cardFromDefinition('data.persist_webmail_session', ['key' => 'save-session']),
                    app(WorkflowTaskCatalog::class)->cardFromDefinition('browser.close', ['key' => 'close-browser']),
                ],
                'routes' => [
                    'success' => ['type' => 'end', 'label' => 'Workflow abschliessen'],
                    'failed' => ['type' => 'fail', 'label' => 'Webmail Login fehlgeschlagen'],
                    'timeout' => ['type' => 'fail', 'label' => 'Webmail Login Timeout'],
                ],
            ],
            WorkflowStep::TYPE_WAIT => [
                'seconds' => 0,
                'timeout_seconds' => 60,
                'routes' => [
                    'success' => ['type' => 'step', 'step' => 'next', 'label' => 'Naechste Liste'],
                ],
            ],
            default => [
                'source' => 'manual',
                'label' => trim($validated['newStepName'] ?? '') ?: 'Geplante Aktion',
                'tasks' => [
                    app(WorkflowTaskCatalog::class)->cardFromDefinition('wait.status', [
                        'key' => 'aktion-ausfuehren',
                        'title' => 'Aktion ausfuehren',
                        'description' => 'Geplante Aktion als Workflow-Task verarbeiten.',
                        'kind' => 'data',
                        'status' => 'configured',
                        'next' => ['step' => 'next', 'label' => 'Naechste Liste'],
                        'on_partial' => ['step' => 'end', 'label' => 'Manuelle Pruefung'],
                        'on_error' => ['step' => 'fail', 'label' => 'Fehlerroute'],
                    ]),
                ],
                'routes' => [
                    'success' => ['type' => 'step', 'step' => 'next', 'label' => 'Naechste Liste'],
                    'partial' => ['type' => 'end', 'label' => 'Manuelle Pruefung'],
                    'failed' => ['type' => 'fail', 'label' => 'Fehlerroute'],
                ],
            ],
        };
    }

    protected function genericStepConfig(string $label, string $description, string $defaultTaskKey): array
    {
        return [
            'source' => 'workflow-board',
            'label' => $label,
            'description' => $description,
            'tasks' => [
                app(WorkflowTaskCatalog::class)->cardFromDefinition($defaultTaskKey, [
                    'key' => Str::slug($label) ?: 'task',
                    'title' => $label,
                    'description' => $description,
                    'next' => ['step' => 'next', 'label' => 'Naechste Liste'],
                    'on_error' => ['step' => 'fail', 'label' => 'Fehlerroute'],
                ]),
            ],
            'routes' => [
                'success' => ['type' => 'step', 'step' => 'next', 'label' => 'Naechste Liste'],
                'partial' => ['type' => 'end', 'label' => 'Manuelle Pruefung'],
                'failed' => ['type' => 'fail', 'label' => 'Fehlerroute'],
                'timeout' => ['type' => 'fail', 'label' => 'Timeout'],
            ],
        ];
    }

    protected function routeTargetFromValue(string $value): ?array
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
            $actionKey = trim(substr($value, 5));
            $workflow = $this->selectedWorkflow();
            $target = $workflow
                ? $workflow->steps()->where('action_key', $actionKey)->first()
                : null;

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
                'type' => 'card',
                'action_key' => $targetStep->action_key,
                'step' => $targetStep->action_key,
                'card_key' => $taskKey,
                'card' => $taskKey,
                'label' => $targetStep->name.' / '.(string) ($targetTask['title'] ?? $taskKey),
            ];
        }

        return null;
    }

    protected function setRoute(array $routes, string $outcome, string $value): array
    {
        $route = $this->routeTargetFromValue($value);

        if ($route) {
            $routes[$outcome] = $route;
        } else {
            unset($routes[$outcome]);
        }

        return $routes;
    }

    protected function routeValueFromTarget(mixed $route): string
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
            $workflow = $this->selectedWorkflow();
            $targetStep = $workflow
                ? $workflow->steps()->where('action_key', $step)->first()
                : null;

            return $targetStep ? 'card:'.$targetStep->id.':'.$card : '';
        }

        return $step !== '' && $step !== 'next' ? 'step:'.$step : '';
    }

    protected function payloadFromInput(string $value): mixed
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        return ['value' => $value];
    }

    protected function payloadToString(mixed $payload): string
    {
        if ($payload === null || $payload === '') {
            return '';
        }

        if (is_scalar($payload)) {
            return (string) $payload;
        }

        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
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
