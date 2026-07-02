<?php

namespace App\Livewire\Admin\Network;

use App\Models\Person;
use App\Models\Device;
use App\Models\NetworkNode;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use App\Services\Workflows\PersonaActionWorkflowCatalog;
use App\Services\Workflows\WorkflowExecutionService;
use App\Services\Workflows\WorkflowRunDebugPackageService;
use App\Services\Workflows\WorkflowTaskCatalog;
use App\Services\Workflows\WorkflowTaskOrderingService;
use App\Services\Workflows\WorkflowTransferService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\On;
use Livewire\Component;

class WorkflowManager extends Component
{
    public ?int $selectedWorkflowId = null;

    public string $workflowName = '';

    public string $workflowDescription = '';

    public string $workflowGroup = 'custom';

    public string $workflowSubcategory = '';

    public bool $workflowActive = true;

    public bool $workflowLocked = false;

    public string $newStepType = WorkflowStep::TYPE_PREPARATION;

    public string $newStepName = '';

    public string $newStepCreationMode = 'new';

    public string $importWorkflowId = '';

    public string $newTaskListId = '';

    public string $newTaskCatalogKey = 'data.resolve_person';

    public string $newTaskTitle = '';

    public string $newTaskKind = 'data';

    public string $newTaskDescription = '';

    public string $newTaskElementSelector = '';

    public string $newTaskInputSelector = '';

    public string $newTaskInputValue = '';

    public string $newTaskMailboxSource = 'person';

    public string $newTaskBrowserWindow = 'main';

    public string $newTaskSuccessPayload = '';

    public string $newTaskFailurePayload = '';

    public array $newTaskExtra = [];

    public int $newTaskTimeoutSeconds = 0;

    public string $newTaskSuccessTarget = '';

    public string $newTaskFailedTarget = 'fail';

    public int $newTaskFailedRetryLimit = 3;

    public ?int $newTaskInsertPosition = null;

    public string $runPersonId = '';

    public string $runExecutionTarget = 'system';

    public string $runNetworkNodeId = '';

    public string $runDeviceId = '';

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

    public string $editingStepSuccessReason = '';

    public string $editingStepFailedReason = '';

    public int $editingStepFailedRetryLimit = 0;

    public ?int $editingTaskStepId = null;

    public string $editingTaskKey = '';

    public string $editingTaskCatalogKey = '';

    public string $editingTaskTitle = '';

    public string $editingTaskKind = 'browser';

    public string $editingTaskDescription = '';

    public string $editingTaskElementSelector = '';

    public string $editingTaskInputSelector = '';

    public string $editingTaskInputValue = '';

    public string $editingTaskMailboxSource = 'person';

    public string $editingTaskBrowserWindow = 'main';

    public string $editingTaskSuccessPayload = '';

    public string $editingTaskFailurePayload = '';

    public array $editingTaskExtra = [];

    public int $editingTaskTimeoutSeconds = 0;

    public string $editingTaskSuccessTarget = '';

    public string $editingTaskFailedTarget = '';

    public int $editingTaskFailedRetryLimit = 0;

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
        $taskDefinitions = collect($taskCatalog->options())
            ->concat($this->workflowTaskOptions($selectedWorkflow));
        $taskGroups = $taskDefinitions
            ->pluck('kind')
            ->unique()
            ->sortBy(function (string $kind): int {
                $index = array_search($kind, ['browser', 'input', 'wait', 'data', 'workflow'], true);

                return $index === false ? 99 : $index;
            })
            ->values();

        if (! $taskGroups->contains($this->activeTaskGroup)) {
            $this->activeTaskGroup = (string) ($taskGroups->first() ?? 'browser');
        }

        $runStats = $selectedWorkflow
            ? $this->workflowRunStats($selectedWorkflow)
            : ['runs' => 0, 'successful_runs' => 0, 'failed_runs' => 0];

        return view('livewire.admin.network.workflow-manager', [
            'selectedWorkflow' => $selectedWorkflow,
            'steps' => $steps,
            'quickPreviewRun' => $quickPreviewRun,
            'previewWorkflowRun' => $this->previewWorkflowRun(),
            'persons' => $persons,
            'runNetworkNodes' => NetworkNode::query()->where('status', 'active')->orderByDesc('is_online')->orderBy('name')->get(),
            'runDevices' => Device::query()->with('networkNode')->orderBy('name')->get(),
            'personOptions' => $catalog->personOptions($catalogPersons),
            'actions' => $actions,
            'taskDefinitions' => $taskDefinitions->values()->toArray(),
            'taskGroups' => $taskGroups->values()->toArray(),
            'taskGroupLabels' => $this->taskGroupLabels(),
            'importableWorkflows' => $this->importableWorkflows($selectedWorkflow),
            'visibleTaskDefinitions' => $taskDefinitions
                ->where('kind', $this->activeTaskGroup)
                ->values()
                ->toArray(),
            'summary' => [
                'actions' => $steps->filter(fn (WorkflowStep $step): bool => $step->type !== WorkflowStep::TYPE_WAIT)->count(),
                'lists' => $steps->count(),
                'task_cards' => $steps->sum(fn (WorkflowStep $step): int => count($step->task_cards)),
                ...$runStats,
            ],
        ])->layout('layouts.master');
    }

    public function saveWorkflow(): void
    {
        $workflow = $this->editableWorkflow();

        if (! $workflow) {
            return;
        }

        $validated = $this->validate([
            'workflowName' => ['required', 'string', 'max:160'],
            'workflowDescription' => ['nullable', 'string', 'max:1000'],
            'workflowGroup' => ['required', 'string', 'max:80'],
            'workflowSubcategory' => ['nullable', 'string', 'max:80'],
            'workflowActive' => ['boolean'],
            'workflowLocked' => ['boolean'],
        ]);

        $workflow->forceFill([
            'name' => trim($validated['workflowName']),
            'description' => trim((string) ($validated['workflowDescription'] ?? '')),
            'category' => $this->normalizeGroup($validated['workflowGroup']),
            'subcategory' => $this->normalizeSubcategory($validated['workflowSubcategory'] ?? ''),
            'is_active' => (bool) $validated['workflowActive'],
            'is_locked' => (bool) $validated['workflowLocked'],
        ])->save();

        $this->showWorkflowModal = false;

        session()->flash('success', 'Workflow wurde gespeichert.');
    }

    public function exportWorkflow(WorkflowTransferService $transferService): mixed
    {
        $workflow = $this->selectedWorkflow();

        if (! $workflow) {
            session()->flash('error', 'Workflow wurde nicht gefunden.');

            return null;
        }

        $workflow->load(['steps' => fn ($query) => $query->ordered()]);
        $export = $transferService->zip(
            [$workflow],
            'workflow-'.$workflow->slug.'-'.now()->format('Y-m-d-His'),
        );

        return response()->download($export['path'], $export['filename'])->deleteFileAfterSend(true);
    }

    public function deleteWorkflow(): void
    {
        $workflow = $this->selectedWorkflow();

        if (! $workflow) {
            return;
        }

        if ($workflow->is_edit_locked) {
            session()->flash('error', 'Ein gesperrter Workflow kann nicht geloescht werden. '.$workflow->lock_reason);

            return;
        }

        $workflow->delete();

        session()->flash('success', 'Workflow wurde geloescht. Du kannst ihn jetzt per Seeder neu erzeugen.');

        $this->redirectRoute('network.workflows');
    }

    public function addStep(): void
    {
        $workflow = $this->editableWorkflow();

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

    public function importWorkflowSteps(): void
    {
        $workflow = $this->editableWorkflow();

        if (! $workflow) {
            return;
        }

        $validated = $this->validate([
            'importWorkflowId' => ['required', 'integer', 'exists:workflows,id'],
        ]);

        $sourceWorkflow = Workflow::query()
            ->with(['steps' => fn ($query) => $query->ordered()])
            ->find((int) $validated['importWorkflowId']);

        if (! $sourceWorkflow || (int) $sourceWorkflow->id === (int) $workflow->id) {
            $this->addError('importWorkflowId', 'Bitte einen anderen Workflow auswaehlen.');

            return;
        }

        if ($sourceWorkflow->includesWorkflow($workflow->id)) {
            $this->addError('importWorkflowId', 'Dieser Workflow enthaelt den aktuellen Workflow und kann deshalb nicht importiert werden.');

            return;
        }

        if ($sourceWorkflow->steps->isEmpty()) {
            $this->addError('importWorkflowId', 'Dieser Workflow hat keine Listen zum Importieren.');

            return;
        }

        $importedCount = DB::transaction(function () use ($workflow, $sourceWorkflow): int {
            $sourceSteps = $sourceWorkflow->steps->values();
            $actionKeyMap = $this->importedStepActionKeyMap($workflow, $sourceSteps);
            $basePosition = (int) $workflow->steps()->max('position');

            foreach ($sourceSteps as $index => $sourceStep) {
                $sourceActionKey = trim((string) $sourceStep->action_key);
                $config = is_array($sourceStep->config_json) ? $sourceStep->config_json : [];

                $workflow->steps()->create([
                    'name' => $sourceStep->name,
                    'type' => $sourceStep->type,
                    'action_key' => $actionKeyMap[$sourceActionKey] ?? $actionKeyMap['__step:'.$sourceStep->id],
                    'position' => $basePosition + (($index + 1) * 10),
                    'is_enabled' => (bool) $sourceStep->is_enabled,
                    'config_json' => $this->remapImportedWorkflowReferences($config, $actionKeyMap),
                    'retry_attempts' => max(0, (int) $sourceStep->retry_attempts),
                    'wait_after_seconds' => max(0, (int) $sourceStep->wait_after_seconds),
                ]);
            }

            return $sourceSteps->count();
        });

        $this->importWorkflowId = '';
        $this->newStepCreationMode = 'new';
        $this->showAddStepModal = false;

        session()->flash('success', $importedCount.' Listen aus "'.$sourceWorkflow->name.'" wurden importiert.');
    }

    public function addActionStep(string $actionId): void
    {
        $catalog = app(PersonaActionWorkflowCatalog::class);
        $workflow = $this->editableWorkflow();
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
        $this->editingStepSuccessReason = trim((string) data_get($routes, 'success.reason', ''));
        $this->editingStepFailedReason = trim((string) data_get($routes, 'failed.reason', ''));
        $this->editingStepFailedRetryLimit = max(0, (int) data_get($routes, 'failed.max_attempts', 0));
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
            'editingStepSuccessReason' => ['nullable', 'string', 'max:180'],
            'editingStepFailedReason' => ['nullable', 'string', 'max:180'],
            'editingStepFailedRetryLimit' => ['required', 'integer', 'min:0', 'max:20'],
        ]);

        $config = is_array($step->config_json) ? $step->config_json : [];
        $config['description'] = trim((string) ($validated['editingStepDescription'] ?? ''));
        $routes = is_array($config['routes'] ?? null) ? $config['routes'] : [];
        $routes = $this->setRoute(
            $routes,
            'success',
            (string) ($validated['editingStepSuccessTarget'] ?? ''),
            (string) ($validated['editingStepSuccessReason'] ?? ''),
        );
        $routes = $this->setRoute(
            $routes,
            'failed',
            (string) ($validated['editingStepFailedTarget'] ?? ''),
            (string) ($validated['editingStepFailedReason'] ?? ''),
            (int) ($validated['editingStepFailedRetryLimit'] ?? 0),
        );
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
        $workflow = $this->editableWorkflow();

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
        if (! $this->stepForSelectedWorkflow($stepId) || ! $this->taskDefinition($taskKey)) {
            return;
        }

        $this->newTaskListId = (string) $stepId;
        $this->newTaskInsertPosition = $position;
        $this->newTaskCatalogKey = $taskKey;
        $this->newTaskElementSelector = '';
        $this->newTaskInputSelector = '';
        $this->newTaskInputValue = '';
        $this->newTaskMailboxSource = 'person';
        $this->newTaskBrowserWindow = 'main';
        $this->newTaskSuccessPayload = '';
        $this->newTaskFailurePayload = '';
        $this->newTaskExtra = [];
        $this->newTaskSuccessTarget = '';
        $this->newTaskFailedTarget = 'fail';
        $this->newTaskFailedRetryLimit = 3;
        $this->newTaskTimeoutSeconds = max(0, (int) data_get($this->taskDefinition($taskKey), 'timeout_seconds', 0));
        $this->applyTaskDefinitionToForm('newTask', $taskKey, true);
        $this->newTaskBrowserWindow = $this->defaultBrowserWindowNameForTask($taskKey);
        $this->showAddTaskModal = true;
        $this->showTaskPanel = false;
    }

    public function updatedNewTaskCatalogKey(string $taskKey): void
    {
        $this->applyTaskDefinitionToForm('newTask', $taskKey, false);
        $this->newTaskTimeoutSeconds = max(0, (int) data_get($this->taskDefinition($taskKey), 'timeout_seconds', 0));
        $this->newTaskBrowserWindow = $this->defaultBrowserWindowNameForTask($taskKey);
    }

    public function updatedEditingTaskCatalogKey(string $taskKey): void
    {
        $this->applyTaskDefinitionToForm('editingTask', $taskKey, false);
        $this->editingTaskTimeoutSeconds = max(0, (int) data_get($this->taskDefinition($taskKey), 'timeout_seconds', 0));
    }

    public function updatedNewTaskMailboxSource(mixed $value = null): void
    {
        $this->newTaskMailboxSource = $this->normalizeMailboxSource((string) $this->newTaskMailboxSource);
    }

    public function updatedEditingTaskMailboxSource(mixed $value = null): void
    {
        $this->editingTaskMailboxSource = $this->normalizeMailboxSource((string) $this->editingTaskMailboxSource);
    }

    public function addTaskCard(?string $mailboxSourceOverride = null): void
    {
        $workflow = $this->editableWorkflow();

        if (! $workflow) {
            return;
        }

        $validated = $this->validate([
            'newTaskListId' => ['required', 'integer'],
            'newTaskCatalogKey' => ['required', 'string', 'max:120'],
            'newTaskTitle' => ['required', 'string', 'max:160'],
            'newTaskKind' => ['required', 'string', 'in:browser,input,wait,data,workflow'],
            'newTaskDescription' => ['nullable', 'string', 'max:1000'],
            'newTaskElementSelector' => ['nullable', 'string', 'max:1000'],
            'newTaskInputSelector' => ['nullable', 'string', 'max:1000'],
            'newTaskInputValue' => ['nullable', 'string', 'max:2000'],
            'newTaskMailboxSource' => ['nullable', 'string', 'in:person,verification'],
            'newTaskBrowserWindow' => ['nullable', 'string', 'max:80'],
            'newTaskSuccessPayload' => ['nullable', 'string', 'max:4000'],
            'newTaskFailurePayload' => ['nullable', 'string', 'max:4000'],
            'newTaskExtra' => ['array'],
            'newTaskExtra.*' => ['nullable', 'string', 'max:4000'],
            'newTaskTimeoutSeconds' => ['required', 'integer', 'min:0', 'max:3600'],
            'newTaskSuccessTarget' => ['nullable', 'string', 'max:180'],
            'newTaskFailedTarget' => ['nullable', 'string', 'max:180'],
            'newTaskFailedRetryLimit' => ['required', 'integer', 'min:0', 'max:20'],
        ]);

        $definition = $this->taskDefinition($validated['newTaskCatalogKey']);

        if (! $definition) {
            $this->addError('newTaskCatalogKey', 'Dieser Workflow oder Task ist nicht verfuegbar.');

            return;
        }

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
        $mailboxSource = $this->normalizeMailboxSource((string) ($mailboxSourceOverride ?: ($validated['newTaskMailboxSource'] ?? 'person')));
        $browserWindow = $this->normalizeBrowserWindowName((string) ($validated['newTaskBrowserWindow'] ?? ''));

        if (! $this->validateBrowserWindowState($validated['newTaskCatalogKey'], $browserWindow, 'newTaskBrowserWindow')) {
            return;
        }

        $task = $this->taskCardFromDefinition($validated['newTaskCatalogKey'], [
            'key' => $key,
            'title' => trim($validated['newTaskTitle']),
            'description' => trim((string) ($validated['newTaskDescription'] ?? '')),
            'kind' => $validated['newTaskKind'],
            'browser_window' => ($formConfig['browser_window'] ?? false) ? $browserWindow : null,
            'browser_window_name' => ($formConfig['browser_window'] ?? false) ? $browserWindow : null,
            'selector' => $selector,
            'element_selector' => $selector,
            'input' => $value,
            'value' => $value,
            'url' => ($formConfig['url'] ?? false) ? $value : null,
            'mailbox_source' => $mailboxSource,
            'script_person_source' => $mailboxSource,
            'timeout_seconds' => (int) $validated['newTaskTimeoutSeconds'],
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

        $task = $this->applyTaskExtraFields($task, $formConfig, $validated['newTaskExtra'] ?? []);

        $successRoute = $this->taskRouteTargetFromValue(
            (string) ($validated['newTaskSuccessTarget'] ?? ''),
            $step,
            null,
            $this->newTaskInsertPosition,
        );
        $failedRoute = $this->taskRouteTargetFromValue(
            (string) ($validated['newTaskFailedTarget'] ?? ''),
            $step,
            null,
            $this->newTaskInsertPosition,
        );

        if ($successRoute) {
            $task['next'] = $successRoute;
        }

        if ($failedRoute) {
            if ((int) $validated['newTaskFailedRetryLimit'] > 0) {
                $failedRoute['max_attempts'] = (int) $validated['newTaskFailedRetryLimit'];
            }

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
        $this->newTaskMailboxSource = 'person';
        $this->newTaskBrowserWindow = 'main';
        $this->newTaskSuccessPayload = '';
        $this->newTaskFailurePayload = '';
        $this->newTaskExtra = [];
        $this->newTaskTimeoutSeconds = 0;
        $this->newTaskSuccessTarget = '';
        $this->newTaskFailedTarget = 'fail';
        $this->newTaskFailedRetryLimit = 3;
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

        $rawTasks = is_array(data_get($step->config_json, 'tasks')) ? data_get($step->config_json, 'tasks') : [];
        $task = collect($rawTasks)
            ->filter(fn (mixed $task): bool => is_array($task))
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
        $this->editingTaskInputValue = (string) (collect([
            $task['url'] ?? null,
            $task['value'] ?? null,
            $task['input'] ?? null,
        ])->first(fn (mixed $value): bool => $value !== null && trim((string) $value) !== '') ?? '');
        $this->editingTaskMailboxSource = $this->normalizeMailboxSource((string) ($task['script_person_source'] ?? $task['mailbox_source'] ?? 'person'));
        $existingBrowserWindow = trim((string) ($task['browser_window_name'] ?? $task['browser_window'] ?? ''));
        $this->editingTaskBrowserWindow = $this->normalizeBrowserWindowName($existingBrowserWindow ?: $this->defaultBrowserWindowNameForTask($this->editingTaskCatalogKey));
        $this->editingTaskSuccessPayload = $this->payloadToString($task['success_payload'] ?? null);
        $this->editingTaskFailurePayload = $this->payloadToString($task['failure_payload'] ?? null);
        $this->editingTaskTimeoutSeconds = max(0, (int) ($task['timeout_seconds'] ?? 0));
        $this->editingTaskSuccessTarget = $this->routeValueFromTarget($task['next'] ?? null);
        $this->editingTaskFailedTarget = $this->routeValueFromTarget($task['on_error'] ?? null);
        $this->editingTaskFailedRetryLimit = max(0, (int) data_get($task, 'on_error.max_attempts', 0));
        $this->applyTaskDefinitionToForm('editingTask', $this->editingTaskCatalogKey, false);
        $this->editingTaskExtra = $this->taskExtraFieldsFromTask($this->taskFormConfig($this->editingTaskCatalogKey), $task);
        $this->showEditTaskModal = true;
    }

    public function saveEditTaskCard(?string $mailboxSourceOverride = null): void
    {
        $step = $this->editingTaskStepId ? $this->stepForSelectedWorkflow($this->editingTaskStepId) : null;

        if (! $step) {
            return;
        }

        $validated = $this->validate([
            'editingTaskCatalogKey' => ['required', 'string', 'max:120'],
            'editingTaskTitle' => ['required', 'string', 'max:160'],
            'editingTaskKind' => ['required', 'string', 'in:browser,input,wait,data,workflow'],
            'editingTaskDescription' => ['nullable', 'string', 'max:1000'],
            'editingTaskElementSelector' => ['nullable', 'string', 'max:1000'],
            'editingTaskInputSelector' => ['nullable', 'string', 'max:1000'],
            'editingTaskInputValue' => ['nullable', 'string', 'max:2000'],
            'editingTaskMailboxSource' => ['nullable', 'string', 'in:person,verification'],
            'editingTaskBrowserWindow' => ['nullable', 'string', 'max:80'],
            'editingTaskSuccessPayload' => ['nullable', 'string', 'max:4000'],
            'editingTaskFailurePayload' => ['nullable', 'string', 'max:4000'],
            'editingTaskExtra' => ['array'],
            'editingTaskExtra.*' => ['nullable', 'string', 'max:4000'],
            'editingTaskTimeoutSeconds' => ['required', 'integer', 'min:0', 'max:3600'],
            'editingTaskSuccessTarget' => ['nullable', 'string', 'max:180'],
            'editingTaskFailedTarget' => ['nullable', 'string', 'max:180'],
            'editingTaskFailedRetryLimit' => ['required', 'integer', 'min:0', 'max:20'],
        ]);

        $definition = $this->taskDefinition($validated['editingTaskCatalogKey']);

        if (! $definition) {
            $this->addError('editingTaskCatalogKey', 'Dieser Workflow oder Task ist nicht verfuegbar.');

            return;
        }

        $formConfig = $this->taskFormConfig($validated['editingTaskCatalogKey']);

        if (! $this->validateTaskFieldRequirements('editingTask', $formConfig)) {
            return;
        }

        $editingBrowserWindow = $this->normalizeBrowserWindowName((string) ($validated['editingTaskBrowserWindow'] ?? ''));

        if (! $this->validateBrowserWindowState($validated['editingTaskCatalogKey'], $editingBrowserWindow, 'editingTaskBrowserWindow', $this->editingTaskKey)) {
            return;
        }

        $config = is_array($step->config_json) ? $step->config_json : [];
        $tasks = collect(is_array($config['tasks'] ?? null) ? $config['tasks'] : []);

        $config['tasks'] = $tasks
            ->map(function (array $task) use ($validated, $step, $mailboxSourceOverride): array {
                if ((string) ($task['key'] ?? '') !== $this->editingTaskKey) {
                    return $task;
                }

                $formConfig = $this->taskFormConfig($validated['editingTaskCatalogKey']);
                $selector = trim((string) ($validated['editingTaskElementSelector'] ?? ''));
                $value = trim((string) ($validated['editingTaskInputValue'] ?? ''));
                $mailboxSource = $this->normalizeMailboxSource((string) ($mailboxSourceOverride ?: ($validated['editingTaskMailboxSource'] ?? 'person')));
                $browserWindow = $this->normalizeBrowserWindowName((string) ($validated['editingTaskBrowserWindow'] ?? ''));
                $task = array_replace(
                    $task,
                    $this->taskCardFromDefinition($validated['editingTaskCatalogKey'], [
                        'key' => $this->editingTaskKey,
                    ]),
                    [
                        'key' => $this->editingTaskKey,
                        'task_key' => $validated['editingTaskCatalogKey'],
                        'title' => trim($validated['editingTaskTitle']),
                        'description' => trim((string) ($validated['editingTaskDescription'] ?? '')),
                        'kind' => $validated['editingTaskKind'],
                        'browser_window' => ($formConfig['browser_window'] ?? false) ? $browserWindow : null,
                        'browser_window_name' => ($formConfig['browser_window'] ?? false) ? $browserWindow : null,
                        'selector' => $selector,
                        'element_selector' => $selector,
                        'input_selector' => '',
                        'input' => $value,
                        'value' => $value,
                        'url' => ($formConfig['url'] ?? false) ? $value : null,
                        'mailbox_source' => $mailboxSource,
                        'script_person_source' => $mailboxSource,
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
                    $route = $this->taskRouteTargetFromValue($value, $step, $this->editingTaskKey);

                    if ($route) {
                        if ($key === 'on_error' && (int) $validated['editingTaskFailedRetryLimit'] > 0) {
                            $route['max_attempts'] = (int) $validated['editingTaskFailedRetryLimit'];
                        }

                        $task[$key] = $route;
                    } else {
                        unset($task[$key]);
                    }
                }

                if (! ($formConfig['browser_window'] ?? false)) {
                    unset($task['browser_window'], $task['browser_window_name']);
                }

                if (($task['runner'] ?? null) === 'workflow') {
                    unset($task['node_script'], $task['php_handler']);
                } else {
                    unset($task['workflow_id'], $task['workflow_slug']);
                }

                unset($task['on_partial']);

                return $this->applyTaskExtraFields($task, $formConfig, $validated['editingTaskExtra'] ?? []);
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
            'runExecutionTarget' => ['required', 'string', 'in:system,client_controller'],
            'runNetworkNodeId' => ['nullable', 'required_if:runExecutionTarget,client_controller', 'integer', 'exists:network_nodes,id'],
            'runDeviceId' => ['nullable', 'integer', 'exists:devices,id'],
        ]);

        $nodeId = $validated['runExecutionTarget'] === 'client_controller'
            ? (int) ($validated['runNetworkNodeId'] ?? 0)
            : null;
        $deviceId = $validated['runExecutionTarget'] === 'client_controller'
            ? (int) ($validated['runDeviceId'] ?? 0) ?: null
            : null;

        if ($deviceId) {
            $device = Device::query()->find($deviceId);

            if (! $device || (int) $device->network_node_id !== $nodeId) {
                $this->addError('runDeviceId', 'Das Geraet gehoert nicht zum ausgewaehlten Node.');

                return;
            }
        }

        try {
            $run = $execution->start($workflow, [
                'person_id' => $validated['runPersonId'] ?: null,
                'started_from' => 'workflow-manager',
                'execution_target' => $validated['runExecutionTarget'],
                'network_node_id' => $nodeId,
                'device_id' => $deviceId,
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

    public function refreshRunPreview(): void
    {
        if (! $this->previewWorkflowRunId) {
            return;
        }

        $run = WorkflowRun::query()->find($this->previewWorkflowRunId);

        if (! $run || in_array($run->status, ['completed', 'failed', 'cancelled'], true)) {
            return;
        }

        app(WorkflowExecutionService::class)->refresh($run);
    }

    public function cancelPreviewWorkflowRun(): void
    {
        if (! $this->previewWorkflowRunId) {
            return;
        }

        $run = WorkflowRun::query()->find($this->previewWorkflowRunId);

        if (! $run) {
            return;
        }

        app(WorkflowExecutionService::class)->cancel($run, 'Workflow-Test wurde im Vorschau-Fenster gestoppt.');
        session()->flash('success', 'Workflow-Test wurde gestoppt.');
    }

    public function deleteQueuedPreviewWorkflowRun(): void
    {
        if (! $this->previewWorkflowRunId) {
            return;
        }

        $run = WorkflowRun::query()->find($this->previewWorkflowRunId);

        if (! $run) {
            return;
        }

        $result = app(WorkflowExecutionService::class)->deleteQueued($run);

        if ($result['ok'] ?? false) {
            $this->previewWorkflowRunId = null;
            $this->showRunPreviewModal = false;
        }

        session()->flash('success', (string) ($result['message'] ?? 'Workflow-Test wurde geloescht.'));
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

    public function downloadLatestRunDebugPackage(WorkflowRunDebugPackageService $debugPackages): mixed
    {
        $workflow = $this->selectedWorkflow();
        $run = $workflow ? $this->quickPreviewRun($workflow) : null;

        if (! $run) {
            session()->flash('error', 'Es gibt noch keinen Testlauf fuer diesen Workflow.');

            return null;
        }

        try {
            $export = $debugPackages->make($run);
        } catch (\Throwable $exception) {
            session()->flash('error', 'Debug-Paket konnte nicht erzeugt werden: '.$exception->getMessage());

            return null;
        }

        return response()->download($export['path'], $export['filename'])->deleteFileAfterSend(true);
    }

    protected function selectedWorkflow(): ?Workflow
    {
        if (! $this->selectedWorkflowId) {
            return null;
        }

        return Workflow::query()
            ->with(['includedByWorkflows', 'includedWorkflows'])
            ->find($this->selectedWorkflowId);
    }

    protected function editableWorkflow(): ?Workflow
    {
        return $this->selectedWorkflow();
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

    protected function workflowRunStats(Workflow $workflow): array
    {
        return [
            'runs' => $workflow->runs()->count(),
            'successful_runs' => $workflow->runs()->where('status', 'completed')->count(),
            'failed_runs' => $workflow->runs()->where('status', 'failed')->count(),
        ];
    }

    protected function loadWorkflowForm(): void
    {
        $workflow = $this->selectedWorkflow();

        $this->workflowName = (string) ($workflow?->name ?? '');
        $this->workflowDescription = (string) ($workflow?->description ?? '');
        $this->workflowGroup = trim((string) ($workflow?->category ?? 'custom')) ?: 'custom';
        $this->workflowSubcategory = trim((string) ($workflow?->subcategory ?? ''));
        $this->workflowActive = (bool) ($workflow?->is_active ?? true);
        $this->workflowLocked = (bool) ($workflow?->is_locked ?? false);
    }

    protected function normalizeGroup(string $group): string
    {
        $group = Str::slug($group, '_');

        return $group !== '' ? $group : 'custom';
    }

    protected function normalizeSubcategory(string $subcategory): ?string
    {
        $subcategory = Str::slug($subcategory, '_');

        return $subcategory !== '' ? $subcategory : null;
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
                    app(WorkflowTaskCatalog::class)->cardFromDefinition('mail.check_address_availability', ['key' => 'check-mail-address']),
                    app(WorkflowTaskCatalog::class)->cardFromDefinition('mail.generate_password', ['key' => 'generate-password']),
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
        $definition = $this->taskDefinition($taskKey);

        if (! $definition) {
            return;
        }

        $titleProperty = $prefix.'Title';
        $kindProperty = $prefix.'Kind';
        $descriptionProperty = $prefix.'Description';
        $selectorProperty = $prefix.'ElementSelector';
        $inputSelectorProperty = $prefix.'InputSelector';
        $valueProperty = $prefix.'InputValue';
        $mailboxSourceProperty = $prefix.'MailboxSource';
        $browserWindowProperty = $prefix.'BrowserWindow';
        $successPayloadProperty = $prefix.'SuccessPayload';
        $failurePayloadProperty = $prefix.'FailurePayload';
        $extraProperty = $prefix.'Extra';
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

        if ($formConfig['mailbox_source'] ?? false) {
            $this->{$mailboxSourceProperty} = $this->normalizeMailboxSource((string) $this->{$mailboxSourceProperty});
        } else {
            $this->{$mailboxSourceProperty} = 'person';
        }

        if ($formConfig['browser_window'] ?? false) {
            $this->{$browserWindowProperty} = $this->normalizeBrowserWindowName((string) $this->{$browserWindowProperty});
        } else {
            $this->{$browserWindowProperty} = '';
        }

        if (! ($formConfig['success_payload'] ?? false)) {
            $this->{$successPayloadProperty} = '';
        }

        if (! ($formConfig['failure_payload'] ?? false)) {
            $this->{$failurePayloadProperty} = '';
        }

        $this->{$extraProperty} = $this->taskExtraFieldDefaults($formConfig);
    }

    protected function taskFormConfig(string $taskKey): array
    {
        $definition = $this->taskDefinition($taskKey) ?? [];
        $form = is_array($definition['form'] ?? null) ? $definition['form'] : [];
        $usesBrowserWindow = in_array((string) ($definition['kind'] ?? ''), ['browser', 'input', 'wait'], true)
            && $taskKey !== 'wait.seconds';

        return array_replace([
            'browser_window' => $usesBrowserWindow,
            'browser_window_label' => $taskKey === 'browser.open' ? 'Fenstername' : 'Browserfenster',
            'browser_window_placeholder' => $taskKey === 'browser.open' ? 'main, registrierung, webmail' : 'Fenster auswaehlen',
            'selector' => false,
            'selector_label' => 'Selector',
            'selector_placeholder' => 'button[type=submit], button:has(span:has-text("Login"))',
            'value' => false,
            'value_required' => true,
            'value_label' => 'Wert',
            'value_placeholder' => 'person.email oder fester Wert',
            'url' => false,
            'url_label' => 'URL',
            'url_placeholder' => 'https://example.test',
            'mailbox_source' => true,
            'mailbox_source_label' => 'Script-Bezugsperson',
            'mailbox_source_options' => [
                'person' => 'Bezugs-Person',
                'verification' => 'Haupt-Verifikationskonto',
            ],
            'success_payload' => false,
            'success_payload_label' => 'Daten bei Erfolg',
            'success_payload_placeholder' => '{"email":"person.email"} oder Textwert',
            'failure_payload' => false,
            'failure_payload_label' => 'Daten bei Fehler',
            'failure_payload_placeholder' => '{"reason":"element_not_found"} oder Textwert',
            'extra_fields' => [],
        ], $form);
    }

    protected function taskExtraFields(array $formConfig): array
    {
        return collect(is_array($formConfig['extra_fields'] ?? null) ? $formConfig['extra_fields'] : [])
            ->filter(fn (mixed $field): bool => is_array($field) && trim((string) ($field['name'] ?? '')) !== '')
            ->map(function (array $field): array {
                $field['name'] = preg_replace('/[^A-Za-z0-9_.-]+/', '', (string) ($field['name'] ?? '')) ?: '';

                return $field;
            })
            ->filter(fn (array $field): bool => $field['name'] !== '')
            ->values()
            ->all();
    }

    protected function taskExtraFieldDefaults(array $formConfig): array
    {
        return collect($this->taskExtraFields($formConfig))
            ->mapWithKeys(fn (array $field): array => [
                $field['name'] => (string) ($field['default'] ?? ''),
            ])
            ->all();
    }

    protected function taskExtraFieldsFromTask(array $formConfig, array $task): array
    {
        $legacyPayload = $this->arrayPayloadFromTaskValue($task['success_payload'] ?? null);

        return collect($this->taskExtraFields($formConfig))
            ->mapWithKeys(function (array $field) use ($task, $legacyPayload): array {
                $name = $field['name'];
                $value = data_get($task, $name, data_get($legacyPayload, $name, $field['default'] ?? ''));

                if (is_array($value) || is_object($value)) {
                    $value = json_encode($value, JSON_UNESCAPED_SLASHES);
                }

                return [$name => (string) $value];
            })
            ->all();
    }

    protected function arrayPayloadFromTaskValue(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    protected function applyTaskExtraFields(array $task, array $formConfig, array $values): array
    {
        foreach ($this->knownTaskExtraFieldNames() as $knownName) {
            unset($task[$knownName]);
        }

        foreach ($this->taskExtraFields($formConfig) as $field) {
            $name = $field['name'];
            $value = trim((string) ($values[$name] ?? ''));

            if ($value === '') {
                unset($task[$name]);

                continue;
            }

            $task[$name] = $value;
        }

        return $task;
    }

    protected function knownTaskExtraFieldNames(): array
    {
        return collect(app(WorkflowTaskCatalog::class)->all())
            ->flatMap(fn (array $definition): array => is_array(data_get($definition, 'form.extra_fields'))
                ? data_get($definition, 'form.extra_fields')
                : [])
            ->map(fn (array $field): string => preg_replace('/[^A-Za-z0-9_.-]+/', '', (string) ($field['name'] ?? '')) ?: '')
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    protected function taskGroupLabels(): array
    {
        return [
            'browser' => 'Browser',
            'input' => 'Eingaben',
            'wait' => 'Warten & Status',
            'data' => 'Daten',
            'workflow' => 'Workflows',
        ];
    }

    protected function workflowTaskOptions(?Workflow $selectedWorkflow): array
    {
        if (! $selectedWorkflow) {
            return [];
        }

        return Workflow::query()
            ->whereKeyNot($selectedWorkflow->id)
            ->where('is_active', true)
            ->orderBy('category')
            ->orderBy('subcategory')
            ->orderBy('name')
            ->get()
            ->reject(fn (Workflow $workflow): bool => $workflow->includesWorkflow($selectedWorkflow->id))
            ->map(fn (Workflow $workflow): array => $this->workflowTaskDefinition($workflow))
            ->values()
            ->all();
    }

    protected function importableWorkflows(?Workflow $selectedWorkflow): array
    {
        if (! $selectedWorkflow) {
            return [];
        }

        return Workflow::query()
            ->with(['steps' => fn ($query) => $query->ordered()])
            ->whereKeyNot($selectedWorkflow->id)
            ->orderBy('category')
            ->orderBy('subcategory')
            ->orderBy('name')
            ->get()
            ->reject(fn (Workflow $workflow): bool => $workflow->includesWorkflow($selectedWorkflow->id))
            ->map(fn (Workflow $workflow): array => [
                'id' => $workflow->id,
                'name' => $workflow->name,
                'category' => trim((string) $workflow->category) ?: 'custom',
                'subcategory' => trim((string) $workflow->subcategory),
                'steps_count' => $workflow->steps->count(),
                'task_cards' => $workflow->steps->sum(fn (WorkflowStep $step): int => count($step->task_cards)),
                'is_active' => (bool) $workflow->is_active,
            ])
            ->values()
            ->all();
    }

    protected function taskDefinition(string $taskKey): ?array
    {
        $definition = app(WorkflowTaskCatalog::class)->task($taskKey);

        if ($definition) {
            return $definition;
        }

        if (! preg_match('/^workflow\.include\.(\d+)$/', $taskKey, $matches)) {
            return null;
        }

        $workflow = Workflow::query()->find((int) $matches[1]);
        $selectedWorkflow = $this->selectedWorkflow();

        if (
            ! $workflow
            || ! $workflow->is_active
            || ! $selectedWorkflow
            || (int) $workflow->id === (int) $selectedWorkflow->id
            || $workflow->includesWorkflow($selectedWorkflow->id)
        ) {
            return null;
        }

        return ['task_key' => $taskKey, ...$this->workflowTaskDefinition($workflow)];
    }

    protected function workflowTaskDefinition(Workflow $workflow): array
    {
        return [
            'key' => 'workflow.include.'.$workflow->id,
            'label' => 'Workflow: '.$workflow->name,
            'kind' => 'workflow',
            'runner' => 'workflow',
            'workflow_id' => $workflow->id,
            'workflow_slug' => $workflow->slug,
            'timeout_seconds' => 3600,
            'description' => $workflow->description ?: 'Fuehrt den gesamten referenzierten Workflow aus, wartet auf dessen Ergebnis und nutzt danach die Erfolgs- oder Fehlerweiterleitung.',
            'form' => [
                'browser_window' => true,
                'browser_window_create' => true,
                'browser_window_label' => 'Browserfenster nach Einbettung',
                'browser_window_placeholder' => 'registrierung, webmail, child-flow',
                'mailbox_source' => true,
                'mailbox_source_label' => 'Script-Bezugsperson',
                'mailbox_source_options' => [
                    'person' => 'Bezugs-Person',
                    'verification' => 'Haupt-Verifikationskonto',
                ],
            ],
        ];
    }

    protected function taskCardFromDefinition(string $taskKey, array $overrides = []): array
    {
        $definition = $this->taskDefinition($taskKey);

        if (! $definition || ($definition['runner'] ?? null) !== 'workflow') {
            return app(WorkflowTaskCatalog::class)->cardFromDefinition($taskKey, $overrides);
        }

        $card = app(WorkflowTaskCatalog::class)->cardFromDefinition($taskKey, $overrides);
        $card = array_replace($card, [
            'task_key' => $taskKey,
            'title' => (string) ($overrides['title'] ?? $definition['label']),
            'description' => (string) ($overrides['description'] ?? $definition['description']),
            'kind' => 'workflow',
            'runner' => 'workflow',
            'workflow_id' => (int) $definition['workflow_id'],
            'workflow_slug' => (string) $definition['workflow_slug'],
            'mailbox_source' => $this->normalizeMailboxSource((string) ($overrides['mailbox_source'] ?? 'person')),
            'script_person_source' => $this->normalizeMailboxSource((string) ($overrides['script_person_source'] ?? $overrides['mailbox_source'] ?? 'person')),
            'timeout_seconds' => max(0, (int) ($overrides['timeout_seconds'] ?? $definition['timeout_seconds'])),
        ]);

        unset($card['node_script'], $card['php_handler']);

        return $card;
    }

    protected function validateTaskFieldRequirements(string $prefix, array $formConfig): bool
    {
        $valid = true;
        $selectorProperty = $prefix.'ElementSelector';
        $valueProperty = $prefix.'InputValue';
        $mailboxSourceProperty = $prefix.'MailboxSource';
        $browserWindowProperty = $prefix.'BrowserWindow';
        $extraProperty = $prefix.'Extra';

        if (($formConfig['browser_window'] ?? false) && trim((string) $this->{$browserWindowProperty}) === '') {
            $this->addError($browserWindowProperty, 'Bitte ein Browserfenster angeben.');
            $valid = false;
        }

        if (($formConfig['selector'] ?? false) && ($formConfig['selector_required'] ?? true) && trim((string) $this->{$selectorProperty}) === '') {
            $this->addError($selectorProperty, 'Bitte einen Selector angeben.');
            $valid = false;
        }

        if (
            (($formConfig['value'] ?? false) || ($formConfig['url'] ?? false))
            && ($formConfig['value_required'] ?? true)
            && trim((string) $this->{$valueProperty}) === ''
        ) {
            $this->addError($valueProperty, ($formConfig['url'] ?? false) ? 'Bitte eine URL angeben.' : 'Bitte einen Wert oder eine Datenquelle angeben.');
            $valid = false;
        }

        if (($formConfig['mailbox_source'] ?? false) && ! in_array($this->normalizeMailboxSource((string) $this->{$mailboxSourceProperty}), ['person', 'verification'], true)) {
            $this->addError($mailboxSourceProperty, 'Bitte eine Script-Bezugsperson auswaehlen.');
            $valid = false;
        }

        foreach ($this->taskExtraFields($formConfig) as $field) {
            $name = $field['name'];
            $fieldValue = trim((string) (($this->{$extraProperty} ?? [])[$name] ?? ''));
            $fieldLabel = (string) ($field['label'] ?? $name);

            if (($field['required'] ?? false) && $fieldValue === '') {
                $this->addError($extraProperty.'.'.$name, 'Bitte '.$fieldLabel.' angeben.');
                $valid = false;
            }

            if (($field['type'] ?? 'text') === 'number' && $fieldValue !== '' && ! is_numeric($fieldValue)) {
                $this->addError($extraProperty.'.'.$name, $fieldLabel.' muss eine Zahl sein.');
                $valid = false;
            }
        }

        return $valid;
    }

    protected function normalizeMailboxSource(string $value): string
    {
        $value = strtolower(trim($value));

        return in_array($value, ['verification', 'verification_mailbox', 'veri-account', 'veri_account', 'main', 'master'], true)
            ? 'verification'
            : 'person';
    }

    protected function normalizeBrowserWindowName(string $value): string
    {
        $name = trim($value);
        $name = preg_replace('/\s+/', '-', $name) ?? '';
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '', $name) ?? '';
        $name = strtolower(substr($name, 0, 80));

        return $name !== '' ? $name : 'main';
    }

    protected function defaultBrowserWindowNameForTask(string $taskKey): string
    {
        if ($taskKey === 'browser.open') {
            return $this->nextBrowserWindowName();
        }

        if (preg_match('/^workflow\.include\.(\d+)$/', $taskKey, $matches)) {
            $workflow = Workflow::query()->find((int) $matches[1]);
            $base = trim((string) ($workflow?->slug ?: $workflow?->name ?: 'workflow'));

            return $this->normalizeBrowserWindowName('workflow-'.$base);
        }

        return $this->lastActiveBrowserWindowName() ?: 'main';
    }

    protected function nextBrowserWindowName(): string
    {
        $names = $this->activeBrowserWindowNames();

        if (! in_array('main', $names, true)) {
            return 'main';
        }

        for ($index = 2; $index < 100; $index++) {
            $candidate = 'window-'.$index;

            if (! in_array($candidate, $names, true)) {
                return $candidate;
            }
        }

        return 'window-'.count($names);
    }

    protected function lastActiveBrowserWindowName(): string
    {
        $names = $this->activeBrowserWindowNames();

        return end($names) ?: 'main';
    }

    protected function validateBrowserWindowState(string $taskKey, string $browserWindow, string $property, ?string $excludingTaskKey = null): bool
    {
        $browserWindow = $this->normalizeBrowserWindowName($browserWindow);
        $activeNames = $this->activeBrowserWindowNames($excludingTaskKey);

        if ($taskKey === 'browser.open' && in_array($browserWindow, $activeNames, true)) {
            $this->addError($property, 'Dieses Browserfenster ist im Workflow bereits offen. Schliesse es zuerst mit einer Browserfenster-schliessen-Task oder nutze einen anderen Namen.');

            return false;
        }

        return true;
    }

    protected function configuredBrowserWindowNames(): array
    {
        $workflow = $this->selectedWorkflow();

        if (! $workflow) {
            return ['main'];
        }

        return collect(['main'])
            ->merge($workflow->steps()->ordered()->get()->flatMap(fn (WorkflowStep $step) => collect($step->task_cards)
                ->map(fn (array $task): string => $this->normalizeBrowserWindowName((string) ($task['browser_window_name'] ?? $task['browser_window'] ?? '')))))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    protected function activeBrowserWindowNames(?string $excludingTaskKey = null): array
    {
        $workflow = $this->selectedWorkflow();
        $active = [];

        if (! $workflow) {
            return [];
        }

        foreach ($workflow->steps()->ordered()->get() as $step) {
            foreach ($step->task_cards as $task) {
                if ($excludingTaskKey !== null && (string) ($task['key'] ?? '') === $excludingTaskKey) {
                    continue;
                }

                $taskKey = (string) ($task['task_key'] ?? '');

                if (! in_array($taskKey, ['browser.open', 'browser.close'], true)) {
                    continue;
                }

                $name = $this->normalizeBrowserWindowName((string) ($task['browser_window_name'] ?? $task['browser_window'] ?? 'main'));

                if ($taskKey === 'browser.open') {
                    if (! in_array($name, $active, true)) {
                        $active[] = $name;
                    }

                    continue;
                }

                $active = array_values(array_filter($active, fn (string $activeName): bool => $activeName !== $name));
            }
        }

        return $active;
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

    protected function taskRouteTargetFromValue(
        string $value,
        WorkflowStep $sourceStep,
        ?string $sourceTaskKey = null,
        ?int $insertPosition = null,
    ): ?array {
        if (trim($value) !== 'next') {
            return $this->routeTargetFromValue($value);
        }

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
                return [
                    'type' => 'card',
                    'action_key' => $sourceStep->action_key,
                    'step' => $sourceStep->action_key,
                    'card_key' => $taskKey,
                    'card' => $taskKey,
                    'label' => 'Naechste Karte',
                ];
            }
        }

        $workflow = $this->selectedWorkflow();
        $steps = $workflow ? $workflow->steps()->ordered()->get()->values() : collect();
        $sourceStepIndex = $steps->search(fn (WorkflowStep $step): bool => (int) $step->id === (int) $sourceStep->id);
        $nextStep = $sourceStepIndex !== false ? $steps->get($sourceStepIndex + 1) : null;

        if (! $nextStep) {
            return ['type' => 'end', 'step' => 'end', 'label' => 'Workflow abschliessen'];
        }

        $firstTask = collect($nextStep->task_cards)->first();
        $firstTaskKey = is_array($firstTask) ? trim((string) ($firstTask['key'] ?? '')) : '';

        if ($firstTaskKey !== '') {
            return [
                'type' => 'card',
                'action_key' => $nextStep->action_key,
                'step' => $nextStep->action_key,
                'card_key' => $firstTaskKey,
                'card' => $firstTaskKey,
                'label' => 'Naechste Karte',
            ];
        }

        return [
            'type' => 'step',
            'action_key' => $nextStep->action_key,
            'step' => $nextStep->action_key,
            'label' => $nextStep->name,
        ];
    }

    protected function setRoute(array $routes, string $outcome, string $value, string $reason = '', int $maxAttempts = 0): array
    {
        $route = $this->routeTargetFromValue($value);

        if ($route) {
            $reason = trim($reason);

            if ($reason !== '') {
                $route['reason'] = $reason;
            } else {
                unset($route['reason']);
            }

            if ($outcome === 'failed' && $maxAttempts > 0) {
                $route['max_attempts'] = $maxAttempts;
            } else {
                unset($route['max_attempts']);
            }

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

    protected function importedStepActionKeyMap(Workflow $targetWorkflow, Collection $sourceSteps): array
    {
        $usedActionKeys = $targetWorkflow->steps()
            ->pluck('action_key')
            ->map(fn (mixed $actionKey): string => trim((string) $actionKey))
            ->filter()
            ->values()
            ->all();
        $map = [];

        foreach ($sourceSteps as $sourceStep) {
            if (! $sourceStep instanceof WorkflowStep) {
                continue;
            }

            $sourceActionKey = trim((string) $sourceStep->action_key);
            $newActionKey = $this->uniqueImportedActionKey(
                $usedActionKeys,
                $sourceActionKey ?: $sourceStep->name,
            );

            $usedActionKeys[] = $newActionKey;
            $map['__step:'.$sourceStep->id] = $newActionKey;

            if ($sourceActionKey !== '') {
                $map[$sourceActionKey] = $newActionKey;
            }
        }

        return $map;
    }

    protected function uniqueImportedActionKey(array $usedActionKeys, string $base): string
    {
        $base = Str::slug($base) ?: 'workflow-liste';
        $base = substr($base, 0, 170);
        $candidate = $base;
        $counter = 2;

        while (in_array($candidate, $usedActionKeys, true)) {
            $suffix = '-'.$counter++;
            $candidate = substr($base, 0, 191 - strlen($suffix)).$suffix;
        }

        return $candidate;
    }

    protected function remapImportedWorkflowReferences(mixed $value, array $actionKeyMap): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $remapped = [];

        foreach ($value as $key => $item) {
            if (in_array($key, ['action_key', 'step'], true)) {
                $actionKey = trim((string) $item);
                $remapped[$key] = $actionKeyMap[$actionKey] ?? $item;

                continue;
            }

            $remapped[$key] = $this->remapImportedWorkflowReferences($item, $actionKeyMap);
        }

        return $remapped;
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
