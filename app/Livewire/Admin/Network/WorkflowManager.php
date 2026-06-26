<?php

namespace App\Livewire\Admin\Network;

use App\Models\Person;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use App\Services\Workflows\PersonaActionWorkflowCatalog;
use App\Services\Workflows\WorkflowExecutionService;
use App\Services\Workflows\WorkflowTaskCatalog;
use App\Services\Workflows\WorkflowTaskOrderingService;
use Illuminate\Support\Str;
use Livewire\Attributes\On;
use Livewire\Component;

class WorkflowManager extends Component
{
    public ?int $selectedWorkflowId = null;

    public string $workflowName = '';

    public string $workflowDescription = '';

    public string $workflowGroup = 'custom';

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

    public string $newTaskFailedTarget = 'fail';

    public ?int $newTaskInsertPosition = null;

    public string $runPersonId = '';

    public string $actionPersonFilter = '';

    public string $actionTypeFilter = 'all';

    public bool $showWorkflowModal = false;

    public bool $showRunModal = false;

    public bool $showRunPreviewModal = false;

    public ?int $previewWorkflowRunId = null;

    public bool $showAddStepModal = false;

    public bool $showAddTaskModal = false;

    public bool $showTaskPanel = false;

    public bool $showActionLibraryModal = false;

    public bool $showEditStepModal = false;

    public bool $showEditTaskModal = false;

    public string $activeTaskGroup = 'browser';

    public ?int $editingStepId = null;

    public string $editingStepName = '';

    public string $editingStepDescription = '';

    public bool $editingStepEnabled = true;

    public int $editingStepWaitAfterSeconds = 0;

    public string $editingStepSuccessTarget = '';

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
        $quickPreviewRun = $selectedWorkflow ? $this->quickPreviewRun($selectedWorkflow) : null;
        $persons = Person::query()
            ->where('platform', 'instagram')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
        $catalogPersons = $catalog->persons();
        $actions = array_slice($catalog->actions($catalogPersons, $this->actionPersonFilter, $this->actionTypeFilter), 0, 30);
        $taskDefinitions = collect($taskCatalog->options());
        $taskGroups = $taskDefinitions
            ->pluck('kind')
            ->unique()
            ->sortBy(function (string $kind): int {
                $index = array_search($kind, ['browser', 'input', 'wait', 'data'], true);

                return $index === false ? 99 : $index;
            })
            ->values();

        if (! $taskGroups->contains($this->activeTaskGroup)) {
            $this->activeTaskGroup = (string) ($taskGroups->first() ?? 'browser');
        }

        return view('livewire.admin.network.workflow-manager', [
            'selectedWorkflow' => $selectedWorkflow,
            'steps' => $steps,
            'quickPreviewRun' => $quickPreviewRun,
            'previewWorkflowRun' => $this->previewWorkflowRun(),
            'persons' => $persons,
            'personOptions' => $catalog->personOptions($catalogPersons),
            'actions' => $actions,
            'taskDefinitions' => $taskDefinitions->values()->toArray(),
            'taskGroups' => $taskGroups->values()->toArray(),
            'taskGroupLabels' => $this->taskGroupLabels(),
            'visibleTaskDefinitions' => $taskDefinitions
                ->where('kind', $this->activeTaskGroup)
                ->values()
                ->toArray(),
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
            'workflowGroup' => ['required', 'string', 'max:80'],
            'workflowActive' => ['boolean'],
        ]);

        $workflow->forceFill([
            'name' => trim($validated['workflowName']),
            'description' => trim((string) ($validated['workflowDescription'] ?? '')),
            'category' => $this->normalizeGroup($validated['workflowGroup']),
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
            'editingStepFailedTarget' => ['nullable', 'string', 'max:180'],
        ]);

        $config = is_array($step->config_json) ? $step->config_json : [];
        $config['description'] = trim((string) ($validated['editingStepDescription'] ?? ''));
        $routes = is_array($config['routes'] ?? null) ? $config['routes'] : [];
        $routes = $this->setRoute($routes, 'success', (string) ($validated['editingStepSuccessTarget'] ?? ''));
        $routes = $this->setRoute($routes, 'failed', (string) ($validated['editingStepFailedTarget'] ?? ''));
        unset($routes['partial']);
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

        app(WorkflowTaskOrderingService::class)->sortSteps($workflow, (int) $item, (int) $position);
    }

    #[On('reorderWorkflowSteps')]
    public function handleReorderWorkflowSteps(mixed $item = null, mixed $position = null): void
    {
        $payload = $this->sortPayload($item, $position);

        $this->reorderStep($payload['item'], $payload['position']);
    }

    public function prepareTaskFromCatalog(int $stepId, string $taskKey, ?int $position = null): void
    {
        if (! $this->stepForSelectedWorkflow($stepId) || ! app(WorkflowTaskCatalog::class)->task($taskKey)) {
            return;
        }

        $this->newTaskListId = (string) $stepId;
        $this->newTaskInsertPosition = $position;
        $this->newTaskCatalogKey = $taskKey;
        $this->newTaskElementSelector = '';
        $this->newTaskInputSelector = '';
        $this->newTaskInputValue = '';
        $this->newTaskSuccessPayload = '';
        $this->newTaskFailurePayload = '';
        $this->newTaskSuccessTarget = '';
        $this->newTaskFailedTarget = 'fail';
        $this->applyTaskDefinitionToForm('newTask', $taskKey, true);
        $this->showAddTaskModal = true;
        $this->showTaskPanel = false;
    }

    public function updatedNewTaskCatalogKey(string $taskKey): void
    {
        $this->applyTaskDefinitionToForm('newTask', $taskKey, false);
    }

    public function updatedEditingTaskCatalogKey(string $taskKey): void
    {
        $this->applyTaskDefinitionToForm('editingTask', $taskKey, false);
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
            'newTaskFailedTarget' => ['nullable', 'string', 'max:180'],
        ]);

        $formConfig = $this->taskFormConfig($validated['newTaskCatalogKey']);

        if (! $this->validateTaskFieldRequirements('newTask', $formConfig)) {
            return;
        }

        $step = $this->stepForSelectedWorkflow((int) $validated['newTaskListId']);

        if (! $step) {
            return;
        }

        $config = is_array($step->config_json) ? $step->config_json : [];
        $tasks = is_array($config['tasks'] ?? null) ? $config['tasks'] : [];
        $key = $this->uniqueTaskKey($tasks, $validated['newTaskTitle']);
        $selector = trim((string) ($validated['newTaskElementSelector'] ?? ''));
        $value = trim((string) ($validated['newTaskInputValue'] ?? ''));
        $task = app(WorkflowTaskCatalog::class)->cardFromDefinition($validated['newTaskCatalogKey'], [
            'key' => $key,
            'title' => trim($validated['newTaskTitle']),
            'description' => trim((string) ($validated['newTaskDescription'] ?? '')),
            'kind' => $validated['newTaskKind'],
            'selector' => $selector,
            'element_selector' => $selector,
            'input' => $value,
            'value' => $value,
            'url' => ($formConfig['url'] ?? false) ? $value : null,
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
        $failedRoute = $this->routeTargetFromValue((string) ($validated['newTaskFailedTarget'] ?? ''));

        if ($successRoute) {
            $task['next'] = $successRoute;
        }

        if ($failedRoute) {
            $task['on_error'] = $failedRoute;
        }

        if ($this->newTaskInsertPosition !== null) {
            app(WorkflowTaskOrderingService::class)->insertTask($step, $task, $this->newTaskInsertPosition);
        } else {
            app(WorkflowTaskOrderingService::class)->appendTask($step, $task);
        }

        $this->newTaskTitle = '';
        $this->newTaskDescription = '';
        $this->newTaskElementSelector = '';
        $this->newTaskInputSelector = '';
        $this->newTaskInputValue = '';
        $this->newTaskSuccessPayload = '';
        $this->newTaskFailurePayload = '';
        $this->newTaskSuccessTarget = '';
        $this->newTaskFailedTarget = 'fail';
        $this->newTaskInsertPosition = null;
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
        $this->editingTaskInputValue = (string) ($task['url'] ?? $task['value'] ?? $task['input'] ?? '');
        $this->editingTaskSuccessPayload = $this->payloadToString($task['success_payload'] ?? null);
        $this->editingTaskFailurePayload = $this->payloadToString($task['failure_payload'] ?? null);
        $this->editingTaskTimeoutSeconds = max(0, (int) ($task['timeout_seconds'] ?? 0));
        $this->editingTaskSuccessTarget = $this->routeValueFromTarget($task['next'] ?? null);
        $this->editingTaskFailedTarget = $this->routeValueFromTarget($task['on_error'] ?? null);
        $this->applyTaskDefinitionToForm('editingTask', $this->editingTaskCatalogKey, false);
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
            'editingTaskFailedTarget' => ['nullable', 'string', 'max:180'],
        ]);

        $formConfig = $this->taskFormConfig($validated['editingTaskCatalogKey']);

        if (! $this->validateTaskFieldRequirements('editingTask', $formConfig)) {
            return;
        }

        $config = is_array($step->config_json) ? $step->config_json : [];
        $tasks = collect(is_array($config['tasks'] ?? null) ? $config['tasks'] : []);

        $config['tasks'] = $tasks
            ->map(function (array $task) use ($validated): array {
                if ((string) ($task['key'] ?? '') !== $this->editingTaskKey) {
                    return $task;
                }

                $formConfig = $this->taskFormConfig($validated['editingTaskCatalogKey']);
                $selector = trim((string) ($validated['editingTaskElementSelector'] ?? ''));
                $value = trim((string) ($validated['editingTaskInputValue'] ?? ''));
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
                        'selector' => $selector,
                        'element_selector' => $selector,
                        'input_selector' => '',
                        'input' => $value,
                        'value' => $value,
                        'url' => ($formConfig['url'] ?? false) ? $value : null,
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
                    'on_error' => (string) ($validated['editingTaskFailedTarget'] ?? ''),
                ] as $key => $value) {
                    $route = $this->routeTargetFromValue($value);

                    if ($route) {
                        $task[$key] = $route;
                    } else {
                        unset($task[$key]);
                    }
                }

                unset($task['on_partial']);

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

        app(WorkflowTaskOrderingService::class)->removeTask($step, $taskKey);

        session()->flash('success', 'Step-Karte wurde entfernt.');
    }

    public function reorderTaskCard(int $stepId, mixed $item, mixed $position): void
    {
        $workflow = $this->selectedWorkflow();
        $step = $this->stepForSelectedWorkflow($stepId);

        if (! $workflow || ! $step) {
            return;
        }

        $itemValue = (string) $item;
        $sourceStepId = null;
        $taskKey = $itemValue;

        if (str_contains($itemValue, '::')) {
            [$sourceStepId, $taskKey] = explode('::', $itemValue, 2);
            $sourceStepId = (int) $sourceStepId;
        }

        app(WorkflowTaskOrderingService::class)->moveTask(
            $workflow,
            $step,
            $taskKey,
            (int) $position,
            $sourceStepId ?: null,
        );
    }

    #[On('reorderWorkflowTaskCards')]
    public function handleReorderWorkflowTaskCards(mixed $item = null, mixed $position = null, mixed $targetStepId = null): void
    {
        $payload = $this->sortPayload($item, $position, [
            'targetStepId' => $targetStepId,
        ]);

        $targetStepId = (int) ($payload['targetStepId'] ?? 0);

        if ($targetStepId <= 0) {
            return;
        }

        $this->reorderTaskCard($targetStepId, $payload['item'], $payload['position']);
    }

    public function moveTaskCard(int $targetStepId, mixed $sourceStepId, string $taskKey, mixed $position): void
    {
        $workflow = $this->selectedWorkflow();
        $targetStep = $this->stepForSelectedWorkflow($targetStepId);

        if (! $workflow || ! $targetStep) {
            return;
        }

        app(WorkflowTaskOrderingService::class)->moveTask(
            $workflow,
            $targetStep,
            $taskKey,
            (int) $position,
            ((int) $sourceStepId) ?: null,
        );
    }

    #[On('moveWorkflowTaskCard')]
    public function handleMoveWorkflowTaskCard(mixed $targetStepId = null, mixed $sourceStepId = null, mixed $taskKey = null, mixed $position = null): void
    {
        $payload = is_array($targetStepId)
            ? $targetStepId
            : [
                'targetStepId' => $targetStepId,
                'sourceStepId' => $sourceStepId,
                'taskKey' => $taskKey,
                'position' => $position,
            ];

        $this->moveTaskCard(
            (int) ($payload['targetStepId'] ?? 0),
            $payload['sourceStepId'] ?? null,
            (string) ($payload['taskKey'] ?? ''),
            $payload['position'] ?? 0,
        );
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
            $this->previewWorkflowRunId = $run->id;
            $this->showRunPreviewModal = true;
            session()->flash('success', 'Workflow-Lauf wurde eingeplant: '.$run->run_uuid);
        } catch (\Throwable $exception) {
            session()->flash('success', 'Workflow konnte nicht gestartet werden: '.$exception->getMessage());
        }
    }

    public function closeRunPreview(): void
    {
        $this->showRunPreviewModal = false;
    }

    public function openLatestRunPreview(): void
    {
        $workflow = $this->selectedWorkflow();
        $run = $workflow ? $this->quickPreviewRun($workflow) : null;

        if (! $run) {
            session()->flash('success', 'Es gibt noch keinen Testlauf fuer diesen Workflow.');

            return;
        }

        $this->previewWorkflowRunId = $run->id;
        $this->showRunPreviewModal = true;
    }

    protected function selectedWorkflow(): ?Workflow
    {
        if (! $this->selectedWorkflowId) {
            return null;
        }

        return Workflow::query()->find($this->selectedWorkflowId);
    }

    protected function previewWorkflowRun(): ?WorkflowRun
    {
        if (! $this->previewWorkflowRunId) {
            return null;
        }

        return WorkflowRun::query()
            ->with([
                'currentStep',
                'workflow.steps' => fn ($query) => $query->ordered(),
                'stepRuns.workflowStep',
            ])
            ->find($this->previewWorkflowRunId);
    }

    protected function quickPreviewRun(Workflow $workflow): ?WorkflowRun
    {
        $activeRun = $workflow->runs()
            ->whereIn('status', ['queued', 'running', 'waiting'])
            ->latest('updated_at')
            ->latest('id')
            ->first();

        return $activeRun ?: $workflow->runs()
            ->latest('created_at')
            ->latest('id')
            ->first();
    }

    protected function loadWorkflowForm(): void
    {
        $workflow = $this->selectedWorkflow();

        $this->workflowName = (string) ($workflow?->name ?? '');
        $this->workflowDescription = (string) ($workflow?->description ?? '');
        $this->workflowGroup = trim((string) ($workflow?->category ?? 'custom')) ?: 'custom';
        $this->workflowActive = (bool) ($workflow?->is_active ?? true);
    }

    protected function normalizeGroup(string $group): string
    {
        $group = Str::slug($group, '_');

        return $group !== '' ? $group : 'custom';
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
                    app(WorkflowTaskCatalog::class)->cardFromDefinition('mail.generate_address', ['key' => 'generate-mail-address']),
                    app(WorkflowTaskCatalog::class)->cardFromDefinition('mail.fill_address', ['key' => 'fill-mail-address']),
                    app(WorkflowTaskCatalog::class)->cardFromDefinition('mail.check_address_availability', ['key' => 'check-mail-address']),
                    app(WorkflowTaskCatalog::class)->cardFromDefinition('input.fill_field', ['key' => 'fill-registration-inputs', 'title' => 'Weitere Formularfelder fuellen']),
                    app(WorkflowTaskCatalog::class)->cardFromDefinition('input.submit', ['key' => 'submit-registration-form', 'title' => 'Registrierung absenden']),
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
                        'on_error' => ['step' => 'fail', 'label' => 'Fehlerroute'],
                    ]),
                ],
                'routes' => [
                    'success' => ['type' => 'step', 'step' => 'next', 'label' => 'Naechste Liste'],
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
                'failed' => ['type' => 'fail', 'label' => 'Fehlerroute'],
                'timeout' => ['type' => 'fail', 'label' => 'Timeout'],
            ],
        ];
    }

    protected function applyTaskDefinitionToForm(string $prefix, string $taskKey, bool $replaceTitle): void
    {
        $definition = app(WorkflowTaskCatalog::class)->task($taskKey);

        if (! $definition) {
            return;
        }

        $titleProperty = $prefix.'Title';
        $kindProperty = $prefix.'Kind';
        $descriptionProperty = $prefix.'Description';
        $selectorProperty = $prefix.'ElementSelector';
        $inputSelectorProperty = $prefix.'InputSelector';
        $valueProperty = $prefix.'InputValue';
        $successPayloadProperty = $prefix.'SuccessPayload';
        $failurePayloadProperty = $prefix.'FailurePayload';
        $formConfig = $this->taskFormConfig($taskKey);

        if ($replaceTitle || trim((string) $this->{$titleProperty}) === '') {
            $this->{$titleProperty} = (string) ($definition['label'] ?? 'Task');
        }

        $this->{$kindProperty} = (string) ($definition['kind'] ?? 'data');

        if ($replaceTitle || trim((string) $this->{$descriptionProperty}) === '') {
            $this->{$descriptionProperty} = (string) ($definition['description'] ?? '');
        }

        if (! ($formConfig['selector'] ?? false)) {
            $this->{$selectorProperty} = '';
            $this->{$inputSelectorProperty} = '';
        }

        if (! ($formConfig['value'] ?? false) && ! ($formConfig['url'] ?? false)) {
            $this->{$valueProperty} = '';
        }

        if (! ($formConfig['success_payload'] ?? false)) {
            $this->{$successPayloadProperty} = '';
        }

        if (! ($formConfig['failure_payload'] ?? false)) {
            $this->{$failurePayloadProperty} = '';
        }
    }

    protected function taskFormConfig(string $taskKey): array
    {
        $definition = app(WorkflowTaskCatalog::class)->task($taskKey) ?? [];
        $form = is_array($definition['form'] ?? null) ? $definition['form'] : [];

        return array_replace([
            'selector' => false,
            'selector_label' => 'Selector',
            'selector_placeholder' => 'button[type=submit], input[name=email], text=Weiter',
            'value' => false,
            'value_label' => 'Wert',
            'value_placeholder' => 'person.email oder fester Wert',
            'url' => false,
            'url_label' => 'URL',
            'url_placeholder' => 'https://example.test',
            'success_payload' => false,
            'failure_payload' => false,
        ], $form);
    }

    protected function taskGroupLabels(): array
    {
        return [
            'browser' => 'Browser',
            'input' => 'Eingaben',
            'wait' => 'Warten & Status',
            'data' => 'Daten',
        ];
    }

    protected function validateTaskFieldRequirements(string $prefix, array $formConfig): bool
    {
        $valid = true;
        $selectorProperty = $prefix.'ElementSelector';
        $valueProperty = $prefix.'InputValue';

        if (($formConfig['selector'] ?? false) && trim((string) $this->{$selectorProperty}) === '') {
            $this->addError($selectorProperty, 'Bitte einen Selector angeben.');
            $valid = false;
        }

        if ((($formConfig['value'] ?? false) || ($formConfig['url'] ?? false)) && trim((string) $this->{$valueProperty}) === '') {
            $this->addError($valueProperty, ($formConfig['url'] ?? false) ? 'Bitte eine URL angeben.' : 'Bitte einen Wert oder eine Datenquelle angeben.');
            $valid = false;
        }

        return $valid;
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

    protected function sortPayload(mixed $item = null, mixed $position = null, array $extra = []): array
    {
        $payload = is_array($item)
            ? $item
            : [
                'item' => $item,
                'position' => $position,
                ...$extra,
            ];

        if (! array_key_exists('item', $payload) && array_key_exists('id', $payload)) {
            $payload['item'] = $payload['id'];
        }

        if (! array_key_exists('position', $payload)) {
            $payload['position'] = 0;
        }

        return $payload;
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
