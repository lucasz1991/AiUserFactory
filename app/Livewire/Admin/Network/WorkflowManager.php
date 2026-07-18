<?php

namespace App\Livewire\Admin\Network;

use App\Jobs\WorkflowCopilotSupervisorJob;
use App\Models\Device;
use App\Models\NetworkNode;
use App\Models\Person;
use App\Models\Setting;
use App\Models\Workflow;
use App\Models\WorkflowCopilotSession;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use App\Models\WorkflowStudioSession;
use App\Services\Ai\WorkflowCopilotAiUsageTracker;
use App\Services\Workflows\PersonaActionWorkflowCatalog;
use App\Services\Workflows\WorkflowCopilotLogExportService;
use App\Services\Workflows\WorkflowCopilotPlanningService;
use App\Services\Workflows\WorkflowCopilotSessionService;
use App\Services\Workflows\WorkflowExecutionService;
use App\Services\Workflows\WorkflowRunDebugPackageService;
use App\Services\Workflows\WorkflowStudioRevisionService;
use App\Services\Workflows\WorkflowStudioSessionService;
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

    public bool $workflowDevelopment = false;

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

    public string $newTaskValueSource = 'fixed';

    public string $newTaskWorkflowVariable = '';

    public string $newTaskValueFallback = '';

    public int $newTaskTimeoutSeconds = 0;

    public string $newTaskSuccessTarget = '';

    public string $newTaskFailedTarget = 'fail';

    public int $newTaskFailedRetryLimit = 3;

    public ?int $newTaskInsertPosition = null;

    public string $runPersonId = '';

    public string $runExecutionTarget = 'system';

    public string $runNetworkNodeId = '';

    public string $runDeviceId = '';

    public string $runWorkflowInputs = '';

    public string $actionPersonFilter = '';

    public string $actionTypeFilter = 'all';

    public bool $showWorkflowModal = false;

    public bool $showRunModal = false;

    public bool $showRunPreviewModal = false;

    public bool $showCopilotModal = false;

    public bool $showCopilotPreviewModal = false;

    public bool $showCopilotRunsModal = false;

    public ?int $activeCopilotSessionId = null;

    public ?int $dismissedCopilotTerminalSessionId = null;

    public string $copilotGoal = '';

    public string $copilotSuccessCriteria = '';

    public string $copilotPersonId = '';

    public string $copilotWorkflowInputs = '';

    public int $copilotMaxMinutes = 90;

    public int $copilotMaxRepairIterations = 15;

    public int $copilotMaxProbeActions = 60;

    public int $copilotMaxSameStateRepeats = 2;

    public float $copilotMaxCostUsd = 0.0;

    public bool $copilotAutoExecute = true;

    public string $copilotRewindCheckpoint = '';

    public array $copilotStatus = [];

    public array $copilotEvents = [];

    public ?int $previewWorkflowRunId = null;

    public string $manualResumeCursor = '';

    public bool $showAddStepModal = false;

    public bool $showAddTaskModal = false;

    public bool $showTaskPanel = false;

    public bool $showActionLibraryModal = false;

    public bool $showEditStepModal = false;

    public bool $showEditTaskModal = false;

    public bool $showRevisionHistoryModal = false;

    public ?int $revisionStudioSessionId = null;

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

    public string $editingTaskValueSource = 'fixed';

    public string $editingTaskWorkflowVariable = '';

    public string $editingTaskValueFallback = '';

    public int $editingTaskTimeoutSeconds = 0;

    public string $editingTaskSuccessTarget = '';

    public string $editingTaskFailedTarget = '';

    public int $editingTaskFailedRetryLimit = 0;

    public string $editingTaskLoopPairSegment = '';

    public string $editingTaskLoopPairEndKey = '';

    public function mount(Workflow $workflow): void
    {
        $this->selectedWorkflowId = $workflow->id;
        $this->loadWorkflowForm();
        $this->loadCopilotDefaults();
        $this->restoreWorkflowCopilotSession();

        $requestedRunId = max(0, (int) request()->query('runPreview', 0));
        $requestedSessionId = max(0, (int) request()->query('copilotSession', 0));

        if ($requestedRunId > 0 || $requestedSessionId > 0 || request()->boolean('openPreview')) {
            $this->openRunPreviewFromAssistant($requestedRunId, $requestedSessionId);
        }
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
            'manualResumeOptions' => $this->manualResumeOptions($selectedWorkflow),
            'activeCopilotSession' => $this->activeCopilotSession(),
            'persons' => $persons,
            'runNetworkNodes' => NetworkNode::query()->available()->orderBy('name')->get(),
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
            'workflowDevelopment' => ['boolean'],
        ]);
        $settings = is_array($workflow->settings_json) ? $workflow->settings_json : [];
        $settings['dev_mode'] = (bool) $validated['workflowDevelopment'];
        $settings['dev_capture_dom_before_step'] = true;
        $settings['dev_keep_artifacts'] = true;

        $workflow->forceFill([
            'name' => trim($validated['workflowName']),
            'description' => trim((string) ($validated['workflowDescription'] ?? '')),
            'category' => $this->normalizeGroup($validated['workflowGroup']),
            'subcategory' => $this->normalizeSubcategory($validated['workflowSubcategory'] ?? ''),
            'is_active' => (bool) $validated['workflowActive'],
            'is_locked' => (bool) $validated['workflowLocked'],
            'settings_json' => $settings,
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
            'newTaskValueSource' => ['required', 'string', 'in:fixed,workflow_variable'],
            'newTaskWorkflowVariable' => ['nullable', 'string', 'max:4000'],
            'newTaskValueFallback' => ['nullable', 'string', 'max:4000'],
            'newTaskExtra' => ['array'],
            'newTaskExtra.*' => ['nullable', 'string', 'max:20000'],
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

        $task = $this->applyTaskExtraFields(
            $task,
            $formConfig,
            $this->taskExtraFieldValues('newTask', $formConfig, $validated['newTaskExtra'] ?? []),
        );

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

        $tasksToInsert = [$task];

        if ($this->isLoopStartTaskKey($validated['newTaskCatalogKey'])) {
            $tasksToInsert = $this->buildLoopPairTasks($task, $tasks);
        }

        if ($this->newTaskInsertPosition !== null) {
            app(WorkflowTaskOrderingService::class)->insertTasks($step, $tasksToInsert, $this->newTaskInsertPosition);
        } else {
            app(WorkflowTaskOrderingService::class)->appendTasks($step, $tasksToInsert);
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
        $this->newTaskValueSource = 'fixed';
        $this->newTaskWorkflowVariable = '';
        $this->newTaskValueFallback = '';
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

        $loopPair = $this->editableLoopPairTask($rawTasks, $task);
        $task = $loopPair['task'];

        $this->editingTaskStepId = $step->id;
        $this->editingTaskKey = (string) ($task['key'] ?? $taskKey);
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
        $this->editingTaskLoopPairSegment = $loopPair['segment'];
        $this->editingTaskLoopPairEndKey = $loopPair['end_key'];
        $this->applyTaskDefinitionToForm('editingTask', $this->editingTaskCatalogKey, false);
        $this->editingTaskExtra = $this->taskExtraFieldsFromTask($this->taskFormConfig($this->editingTaskCatalogKey), $task);
        $this->syncTaskValueSourceProperties(
            'editingTask',
            $this->taskFormConfig($this->editingTaskCatalogKey),
            $this->editingTaskExtra,
        );
        $this->showEditTaskModal = true;
    }

    public function openRevisionHistory(): void
    {
        $workflow = $this->selectedWorkflow();

        if (! $workflow) {
            return;
        }

        $session = WorkflowStudioSession::query()
            ->where('workflow_id', $workflow->getKey())
            ->latest('last_activity_at')
            ->latest('id')
            ->first();
        $session ??= app(WorkflowStudioSessionService::class)->latestOrOpen(
            $workflow,
            auth()->user(),
            'manual',
        );
        app(WorkflowStudioRevisionService::class)->ensureBaseline($session);

        $this->revisionStudioSessionId = (int) $session->getKey();
        $this->showRevisionHistoryModal = true;
    }

    #[On('workflow-studio-revision-restored')]
    public function handleRevisionRestored(): void
    {
        $this->loadWorkflowForm();
    }

    public function openAssistantImprovement(int $workflowId, int $stepId, ?string $taskKey = null): void
    {
        if ((int) $this->selectedWorkflowId !== $workflowId) {
            return;
        }

        $step = $this->stepForSelectedWorkflow($stepId);

        if (! $step) {
            return;
        }

        $this->showRunPreviewModal = false;
        $this->showEditStepModal = false;
        $this->showEditTaskModal = false;
        $taskKey = trim((string) $taskKey);

        if ($taskKey !== '' && collect($step->task_cards)->contains(fn (array $task): bool => (string) ($task['key'] ?? '') === $taskKey)) {
            $this->openEditTaskCard($step->id, $taskKey);
        } else {
            $this->openEditStep($step->id);
        }

        $this->dispatch('assistant-reapply-workflow-improvements');
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
            'editingTaskValueSource' => ['required', 'string', 'in:fixed,workflow_variable'],
            'editingTaskWorkflowVariable' => ['nullable', 'string', 'max:4000'],
            'editingTaskValueFallback' => ['nullable', 'string', 'max:4000'],
            'editingTaskExtra' => ['array'],
            'editingTaskExtra.*' => ['nullable', 'string', 'max:20000'],
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

        if ($this->editingTaskLoopPairSegment !== '' && ! $this->isLoopStartTaskKey($validated['editingTaskCatalogKey'])) {
            $this->addError('editingTaskCatalogKey', 'Loop-Start und Loop-Ende werden gemeinsam bearbeitet; die Funktion bleibt eine Schleife.');

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

                return $this->applyTaskExtraFields(
                    $task,
                    $formConfig,
                    $this->taskExtraFieldValues('editingTask', $formConfig, $validated['editingTaskExtra'] ?? []),
                );
            })
            ->values()
            ->toArray();

        if ($this->editingTaskLoopPairSegment !== '') {
            $config['tasks'] = $this->syncLoopPairTasks($config['tasks'], $this->editingTaskKey);
        }

        $step->forceFill(['config_json' => $config])->save();

        $this->showEditTaskModal = false;
        $this->editingTaskLoopPairSegment = '';
        $this->editingTaskLoopPairEndKey = '';

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
            'runNetworkNodeId' => ['nullable', 'integer', 'exists:network_nodes,id'],
            'runDeviceId' => ['nullable', 'integer', 'exists:devices,id'],
            'runWorkflowInputs' => ['nullable', 'json'],
        ]);

        $workflowInputs = trim((string) ($validated['runWorkflowInputs'] ?? '')) === ''
            ? []
            : json_decode((string) $validated['runWorkflowInputs'], true);

        if (! is_array($workflowInputs) || ($workflowInputs !== [] && array_is_list($workflowInputs))) {
            $this->addError('runWorkflowInputs', 'Workflow-Eingaben muessen ein JSON-Objekt mit benannten Werten sein.');

            return;
        }

        $nodeId = $validated['runExecutionTarget'] === 'client_controller'
            ? (int) ($validated['runNetworkNodeId'] ?? 0)
            : null;
        $deviceId = $validated['runExecutionTarget'] === 'client_controller'
            ? (int) ($validated['runDeviceId'] ?? 0) ?: null
            : null;

        if ($deviceId) {
            $device = Device::query()->find($deviceId);

            if (! $device || ($nodeId && (int) $device->network_node_id !== $nodeId)) {
                $this->addError('runDeviceId', 'Das Geraet gehoert nicht zum ausgewaehlten Node.');

                return;
            }

            $nodeId = $nodeId ?: (int) $device->network_node_id;
        }

        try {
            $run = $execution->start($workflow, [
                'person_id' => $validated['runPersonId'] ?: null,
                'started_from' => 'workflow-manager',
                'execution_target' => $validated['runExecutionTarget'],
                'network_node_id' => $nodeId,
                'device_id' => $deviceId,
                'workflow_variables' => $workflowInputs,
                'workflowVariables' => $workflowInputs,
                'interactive_debug' => true,
            ]);

            $this->showRunModal = false;
            $this->previewWorkflowRunId = $run->id;
            $this->showRunPreviewModal = true;
            session()->flash('success', 'Workflow-Lauf wurde eingeplant: '.$run->run_uuid);
        } catch (\Throwable $exception) {
            session()->flash('success', 'Workflow konnte nicht gestartet werden: '.$exception->getMessage());
        }
    }

    public function openCopilotOptimization(): void
    {
        if ($session = $this->activeCopilotSession()) {
            $this->activeCopilotSessionId = (int) $session->getKey();
            $this->previewWorkflowRunId = $session->active_workflow_run_id
                ? (int) $session->active_workflow_run_id
                : null;
            $this->showCopilotPreviewModal = false;
            $this->showRunPreviewModal = true;
            $this->refreshCopilotSession();
            $this->dispatch('workflow-copilot-session-activated', sessionId: (int) $session->getKey());

            return;
        }

        $this->resetErrorBag();
        $this->showCopilotModal = true;
    }

    public function startCopilotOptimization(): void
    {
        $workflow = $this->selectedWorkflow();

        if (! $workflow) {
            return;
        }

        $this->copilotAutoExecute = $this->copilotAutoExecutionAllowed();

        if (! $this->copilotAutoExecute) {
            $this->addError(
                'copilotAutoExecute',
                'Autonome Workflow-Aktionen sind in den Copilot-Einstellungen deaktiviert. Der Start wurde blockiert.',
            );

            return;
        }

        $validated = $this->validate([
            'copilotGoal' => ['required', 'string', 'max:4000'],
            'copilotSuccessCriteria' => ['required', 'string', 'max:8000'],
            'copilotPersonId' => ['nullable', 'integer', 'exists:persons,id'],
            'copilotWorkflowInputs' => ['nullable', 'json'],
            'copilotMaxMinutes' => ['required', 'integer', 'min:5', 'max:1440'],
            'copilotMaxRepairIterations' => ['required', 'integer', 'min:1', 'max:100'],
            'copilotMaxProbeActions' => ['required', 'integer', 'min:1', 'max:500'],
            'copilotMaxSameStateRepeats' => ['required', 'integer', 'min:1', 'max:10'],
            'copilotMaxCostUsd' => ['required', 'numeric', 'min:0', 'max:10000'],
        ]);

        $workflowInputs = trim((string) ($validated['copilotWorkflowInputs'] ?? '')) === ''
            ? []
            : json_decode((string) $validated['copilotWorkflowInputs'], true);

        if (! is_array($workflowInputs) || ($workflowInputs !== [] && array_is_list($workflowInputs))) {
            $this->addError('copilotWorkflowInputs', 'Workflow-Eingaben muessen ein JSON-Objekt mit benannten Werten sein.');

            return;
        }

        $successCriteria = $this->parseCopilotSuccessCriteria($validated['copilotSuccessCriteria']);

        try {
            $initialPlan = null;
            $initialAiUsage = [];
            $planner = app(WorkflowCopilotPlanningService::class);

            if ($planner->needsInitialPlan($workflow)) {
                $usageTracker = app(WorkflowCopilotAiUsageTracker::class);
                $usageTracker->beginCapture();

                try {
                    $initialPlan = $planner->planAndApply(
                        $workflow,
                        trim($validated['copilotGoal']),
                        $successCriteria,
                        $workflowInputs,
                    );
                } finally {
                    $initialAiUsage = $usageTracker->finishCapture();
                }
                $workflow = $workflow->fresh(['steps']) ?? $workflow;
            }

            $sessionService = app(WorkflowCopilotSessionService::class);
            $session = $sessionService->start($workflow, [
                'person_id' => $validated['copilotPersonId'] ?: null,
                'execution_target' => 'system',
                'goal' => trim($validated['copilotGoal']),
                'success_criteria' => $successCriteria,
                'workflow_inputs' => $workflowInputs,
                'state' => $initialPlan ? ['initial_plan' => $initialPlan] : [],
                'budget' => [
                    'max_minutes' => (int) $validated['copilotMaxMinutes'],
                    'max_repair_iterations' => (int) $validated['copilotMaxRepairIterations'],
                    'max_probe_actions' => (int) $validated['copilotMaxProbeActions'],
                    'max_same_state_repeats' => (int) $validated['copilotMaxSameStateRepeats'],
                    'max_cost_usd' => round((float) $validated['copilotMaxCostUsd'], 4),
                    'auto_execute_workflow_actions' => true,
                ],
            ]);

            if ($initialAiUsage !== []) {
                $session = $sessionService->recordAiUsage($session, $initialAiUsage, 'initial_planning');
            }

            if ($initialPlan) {
                $sessionService->appendEvent(
                    $session,
                    'plan.applied',
                    'Der leere Workflow wurde aus Zielbeschreibung und Katalogdaten geplant und aufgebaut.',
                    ['plan' => $initialPlan],
                    'planning',
                    'success',
                    true,
                );
            }

            WorkflowCopilotSupervisorJob::dispatch((int) $session->getKey());
            $this->activeCopilotSessionId = (int) $session->getKey();
            $this->showCopilotModal = false;
            $this->showCopilotPreviewModal = false;
            $this->previewWorkflowRunId = null;
            $this->showRunPreviewModal = true;
            $this->refreshCopilotSession();
            $this->dispatch('workflow-copilot-session-activated', sessionId: (int) $session->getKey());
            session()->flash('success', 'Autonome Workflow-Optimierung wurde in der System-Ausfuehrung gestartet.');
        } catch (\Throwable $exception) {
            $this->addError('copilotGoal', $exception->getMessage());
        }
    }

    public function refreshCopilotSession(): void
    {
        $session = $this->activeCopilotSession();

        if (! $session) {
            $this->copilotStatus = [];
            $this->copilotEvents = [];

            return;
        }

        $session->loadMissing('workflow');
        $this->copilotStatus = $this->copilotStatusPayload($session);

        if ($session->active_workflow_run_id) {
            $this->previewWorkflowRunId = (int) $session->active_workflow_run_id;
        }

        $afterSequence = max(0, (int) ($session->last_event_sequence ?? 0) - 50);
        $this->copilotEvents = app(WorkflowCopilotSessionService::class)
            ->eventsAfter($session, $afterSequence, 50)
            ->filter(fn ($event): bool => $this->isVisibleCopilotEvent($event))
            ->map(fn ($event): array => [
                'id' => (int) $event->getKey(),
                'sequence' => (int) ($event->sequence ?? 0),
                'phase' => (string) ($event->phase ?? ''),
                'level' => (string) ($event->level ?? 'info'),
                'message' => Str::limit(trim((string) ($event->message ?? '')), 2000, ''),
                'is_milestone' => (bool) ($event->is_milestone ?? false),
                'time' => optional($event->occurred_at ?? $event->created_at)->format('H:i:s'),
            ])
            ->values()
            ->all();
    }

    public function pauseCopilotOptimization(): void
    {
        if ($session = $this->activeCopilotSession()) {
            app(WorkflowCopilotSessionService::class)->pause($session, 'Im Workflow Manager pausiert.');
            $this->refreshCopilotSession();
        }
    }

    public function resumeCopilotOptimization(): void
    {
        if ($session = $this->activeCopilotSession()) {
            $session = app(WorkflowCopilotSessionService::class)->resume($session);
            WorkflowCopilotSupervisorJob::dispatch((int) $session->getKey());
            $this->refreshCopilotSession();
        }
    }

    public function rewindCopilotOptimization(): void
    {
        $session = $this->activeCopilotSession();

        if (! $session) {
            return;
        }

        $checkpoint = (int) $this->copilotRewindCheckpoint;

        if ($checkpoint < 1) {
            $this->addError('copilotRewindCheckpoint', 'Bitte einen Checkpoint auswaehlen oder eingeben.');

            return;
        }

        $session = app(WorkflowCopilotSessionService::class)->rewind($session, $checkpoint, 'Im Workflow Manager angefordert.');
        WorkflowCopilotSupervisorJob::dispatch((int) $session->getKey());
        $this->refreshCopilotSession();
    }

    public function stopCopilotOptimization(): void
    {
        if ($session = $this->activeCopilotSession()) {
            $session->loadMissing('activeRun');

            if ($session->activeRun) {
                app(WorkflowExecutionService::class)->cancel(
                    $session->activeRun,
                    'Workflow-Test wurde mit der Copilot-Sitzung gestoppt.',
                );
            }

            app(WorkflowCopilotSessionService::class)->stop($session, 'Im Workflow Manager gestoppt.');
            $this->refreshCopilotSession();
        }
    }

    public function restartCopilotOptimization(): void
    {
        $session = $this->activeCopilotSession();

        if (! $session) {
            return;
        }

        try {
            $restarted = app(WorkflowCopilotSessionService::class)->restart(
                $session,
                'Vollstaendiger Neustart wurde im Workflow Manager angefordert.',
            );

            WorkflowCopilotSupervisorJob::dispatch((int) $restarted->getKey());
            $this->activeCopilotSessionId = (int) $restarted->getKey();
            $this->dismissedCopilotTerminalSessionId = null;
            $this->previewWorkflowRunId = null;
            $this->showCopilotPreviewModal = false;
            $this->showRunPreviewModal = true;
            $this->copilotRewindCheckpoint = '';
            $this->refreshCopilotSession();
            $this->dispatch('workflow-copilot-session-activated', sessionId: (int) $restarted->getKey());
            session()->flash('success', 'Copilot-Optimierung wurde mit denselben Vorgaben neu gestartet.');
        } catch (\Throwable $exception) {
            session()->flash('error', 'Copilot-Neustart fehlgeschlagen: '.$exception->getMessage());
        }
    }

    public function openCopilotChat(): void
    {
        if ($session = $this->activeCopilotSession()) {
            $this->dispatch('workflow-copilot-session-activated', sessionId: (int) $session->getKey());
        }
    }

    public function closeCopilotPreview(): void
    {
        $this->showCopilotPreviewModal = false;
        $this->closeRunPreview();
    }

    public function closeRunPreview(): void
    {
        $this->showRunPreviewModal = false;

        $session = $this->activeCopilotSession();

        if ($session
            && in_array((string) $session->status, WorkflowCopilotSession::TERMINAL_STATUSES, true)
            && ! $session->retainsWorkflowLock()) {
            $this->dismissedCopilotTerminalSessionId = (int) $session->getKey();
            $this->activeCopilotSessionId = null;
            $this->copilotStatus = [];
            $this->copilotEvents = [];
            $this->copilotRewindCheckpoint = '';
        }
    }

    public function openRunPreviewFromAssistant(int $runId = 0, int $sessionId = 0): void
    {
        if ($sessionId > 0) {
            $session = WorkflowCopilotSession::query()
                ->where('workflow_id', $this->selectedWorkflowId)
                ->find($sessionId);

            if ($session) {
                $this->activeCopilotSessionId = (int) $session->id;
                $this->refreshCopilotSession();
                $runId = $runId ?: (int) ($session->active_workflow_run_id ?? 0);
            }
        }

        if ($runId > 0) {
            $run = WorkflowRun::query()
                ->where('workflow_id', $this->selectedWorkflowId)
                ->find($runId);

            if ($run) {
                $this->previewWorkflowRunId = (int) $run->id;
            }
        }

        $this->showCopilotPreviewModal = false;
        $this->showRunPreviewModal = true;
    }

    public function refreshRunPreview(): void
    {
        if ($this->activeCopilotSession()) {
            $this->refreshCopilotSession();
        }

        if (! $this->previewWorkflowRunId) {
            return;
        }

        $run = WorkflowRun::query()->find($this->previewWorkflowRunId);

        if (! $run || in_array($run->status, ['completed', 'failed', 'cancelled', 'timed_out', 'lost'], true)) {
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

    public function pausePreviewWorkflowRun(): void
    {
        $run = $this->previewRunForControl();

        if (! $run) {
            return;
        }

        $result = app(WorkflowExecutionService::class)->requestManualPause($run);
        session()->flash(($result['ok'] ?? false) ? 'success' : 'error', (string) ($result['message'] ?? 'Pause konnte nicht angefordert werden.'));
    }

    public function resumePreviewWorkflowRun(): void
    {
        $run = $this->previewRunForControl();

        if (! $run) {
            return;
        }

        [$stepId, $taskKey] = array_pad(explode(':', $this->manualResumeCursor, 2), 2, null);

        try {
            $result = app(WorkflowExecutionService::class)->resumeManualPause(
                $run,
                is_numeric($stepId) ? (int) $stepId : null,
                $taskKey,
            );
            session()->flash(($result['ok'] ?? false) ? 'success' : 'error', (string) ($result['message'] ?? 'Fortsetzen ist fehlgeschlagen.'));
        } catch (\Throwable $exception) {
            session()->flash('error', 'Fortsetzen ist fehlgeschlagen: '.$exception->getMessage());
        }
    }

    protected function previewRunForControl(): ?WorkflowRun
    {
        if (! $this->previewWorkflowRunId) {
            return null;
        }

        return WorkflowRun::query()
            ->where('workflow_id', $this->selectedWorkflowId)
            ->find($this->previewWorkflowRunId);
    }

    public function downloadCopilotOptimizationLog(WorkflowCopilotLogExportService $exports): mixed
    {
        $session = $this->activeCopilotSession();

        if (! $session) {
            session()->flash('error', 'Es wurde keine Copilot-Sitzung fuer den Export gefunden.');

            return null;
        }

        try {
            $export = $exports->make($session);
        } catch (\Throwable $exception) {
            session()->flash('error', 'Copilot-Protokoll konnte nicht erzeugt werden: '.$exception->getMessage());

            return null;
        }

        return response()->download($export['path'], $export['filename'])->deleteFileAfterSend(true);
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

    protected function loadCopilotDefaults(): void
    {
        $settings = Setting::getValue('ai_assistant', 'workflow_copilot');
        $settings = is_array($settings) ? $settings : [];
        $defaults = is_array($settings['optimization_defaults'] ?? null)
            ? $settings['optimization_defaults']
            : [];

        $this->copilotMaxMinutes = max(5, min(1440, (int) ($defaults['max_minutes'] ?? 90)));
        $this->copilotMaxRepairIterations = max(1, min(100, (int) ($defaults['max_repair_iterations'] ?? 15)));
        $this->copilotMaxProbeActions = max(1, min(500, (int) ($defaults['max_probe_actions'] ?? 60)));
        $this->copilotMaxSameStateRepeats = max(1, min(10, (int) ($defaults['max_same_state_repeats'] ?? 2)));
        $this->copilotMaxCostUsd = max(0, min(10000, (float) ($defaults['max_cost_usd'] ?? 0)));
        $this->copilotAutoExecute = filter_var(
            $defaults['auto_execute_workflow_actions'] ?? true,
            FILTER_VALIDATE_BOOL,
        );
    }

    protected function copilotAutoExecutionAllowed(): bool
    {
        $settings = Setting::getValue('ai_assistant', 'workflow_copilot');
        $defaults = is_array(data_get($settings, 'optimization_defaults'))
            ? data_get($settings, 'optimization_defaults')
            : [];

        return filter_var(
            $defaults['auto_execute_workflow_actions'] ?? true,
            FILTER_VALIDATE_BOOL,
        );
    }

    protected function restoreWorkflowCopilotSession(): void
    {
        if (! $this->selectedWorkflowId) {
            return;
        }

        $session = $this->preferredCopilotSessionForWorkflow();

        if (! $session) {
            return;
        }

        $this->activeCopilotSessionId = (int) $session->getKey();
        $this->refreshCopilotSession();
    }

    protected function activeCopilotSession(): ?WorkflowCopilotSession
    {
        if ($this->activeCopilotSessionId) {
            $session = WorkflowCopilotSession::query()
                ->where('workflow_id', $this->selectedWorkflowId)
                ->find($this->activeCopilotSessionId);

            if ($session) {
                return $session;
            }
        }

        if (! $this->selectedWorkflowId) {
            return null;
        }

        return $this->preferredCopilotSessionForWorkflow();
    }

    protected function preferredCopilotSessionForWorkflow(): ?WorkflowCopilotSession
    {
        if (! $this->selectedWorkflowId) {
            return null;
        }

        $query = WorkflowCopilotSession::query()
            ->where('workflow_id', $this->selectedWorkflowId);
        $active = (clone $query)
            ->whereIn('status', WorkflowCopilotSession::ACTIVE_STATUSES)
            ->latest('id')
            ->first();

        if ($active) {
            return $active;
        }

        $terminal = (clone $query)
            ->whereIn('status', WorkflowCopilotSession::TERMINAL_STATUSES)
            ->latest('id')
            ->first();

        return $terminal && (int) $terminal->getKey() !== (int) $this->dismissedCopilotTerminalSessionId
            ? $terminal
            : null;
    }

    protected function parseCopilotSuccessCriteria(string $criteria): array
    {
        $criteria = trim($criteria);
        $decoded = json_decode($criteria, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }

        return [
            'assertions' => collect(preg_split('/\r\n|\r|\n/', $criteria) ?: [])
                ->map(fn (string $assertion): string => trim(ltrim($assertion, "- *\t")))
                ->filter()
                ->values()
                ->all(),
        ];
    }

    protected function copilotStatusPayload(WorkflowCopilotSession $session): array
    {
        $state = is_array($session->state_json) ? $session->state_json : [];
        $budget = is_array($session->budget_json) ? $session->budget_json : [];
        $usage = is_array($session->usage_json) ? $session->usage_json : [];
        $maxMinutes = max(1, (int) ($budget['max_minutes'] ?? 90));
        $elapsedMinutes = $session->started_at
            ? max(0, (int) $session->started_at->diffInMinutes(now()))
            : 0;
        $screenshotUrl = $this->copilotScreenshotUrl($state);
        $checkpoints = $session->checkpoints()
            ->with(['workflowStep', 'screenshotArtifact'])
            ->latest('sequence')
            ->limit(20)
            ->get()
            ->map(function ($checkpoint): array {
                $sideEffects = is_array($checkpoint->side_effect_ledger_json)
                    ? $checkpoint->side_effect_ledger_json
                    : [];

                return [
                    'id' => (int) $checkpoint->getKey(),
                    'sequence' => (int) $checkpoint->sequence,
                    'phase' => (string) ($checkpoint->phase ?? ''),
                    'step_name' => (string) ($checkpoint->workflowStep?->name ?? data_get($checkpoint->cursor_json, 'step_name', '')),
                    'task_key' => (string) ($checkpoint->task_key ?? data_get($checkpoint->cursor_json, 'task_key', '')),
                    'is_reproducible' => (bool) $checkpoint->is_reproducible,
                    'has_side_effects' => $sideEffects !== [],
                    'screenshot_url' => $checkpoint->screenshotArtifact
                        ? route('workflow-run-artifacts.show', [
                            'run' => $checkpoint->screenshotArtifact->workflow_run_id,
                            'artifact' => $checkpoint->screenshotArtifact->getKey(),
                        ], false)
                        : null,
                ];
            })
            ->values()
            ->all();
        $revisions = $session->revisions()
            ->latest('revision_number')
            ->limit(10)
            ->get()
            ->map(fn ($revision): array => [
                'id' => (int) $revision->getKey(),
                'revision_number' => (int) $revision->revision_number,
                'reason' => Str::limit(trim((string) ($revision->reason ?? '')), 500, ''),
                'is_verified' => (bool) $revision->is_verified,
                'diff' => is_array($revision->diff_json) ? $revision->diff_json : [],
                'created_at' => optional($revision->created_at)->format('H:i:s'),
            ])
            ->values()
            ->all();
        $domElements = data_get($state, 'observation.interaction_map', data_get($state, 'dom_elements', []));
        $domElements = collect(is_array($domElements) ? $domElements : [])
            ->take(30)
            ->map(fn (mixed $element): array => [
                'ref' => (string) data_get($element, 'element_ref', data_get($element, 'ref', data_get($element, 'id', ''))),
                'role' => (string) data_get($element, 'role', data_get($element, 'tag', 'Element')),
                'text' => Str::limit(trim((string) data_get($element, 'text', data_get($element, 'label', ''))), 160, ''),
                'selector' => Str::limit(trim((string) data_get($element, 'selector_candidates.0', data_get($element, 'selector', ''))), 220, ''),
            ])
            ->values()
            ->all();

        return [
            'id' => (int) $session->getKey(),
            'workflow_name' => (string) ($session->workflow?->name ?? 'Workflow'),
            'status' => (string) $session->status,
            'active' => $session->isActive(),
            'paused' => (string) $session->status === WorkflowCopilotSession::STATUS_PAUSED,
            'phase' => (string) ($session->phase ?: data_get($state, 'phase', 'executing')),
            'goal' => (string) $session->goal,
            'execution_target' => 'system',
            'current_step_name' => (string) data_get($state, 'current_step_name', data_get($state, 'cursor.step_name', '')),
            'current_task_key' => (string) data_get($state, 'current_task_key', data_get($state, 'cursor.task_key', '')),
            'current_task_title' => (string) data_get($state, 'current_task_title', ''),
            'latest_screenshot_url' => $screenshotUrl,
            'page_state' => $this->copilotDisplayValue(data_get($state, 'page_state', data_get($state, 'observation.page_state'))),
            'last_action' => $this->copilotDisplayValue(data_get($state, 'last_action')),
            'current_result' => $this->copilotDisplayValue(data_get($state, 'last_result', data_get($state, 'current_result'))),
            'next_action' => $this->copilotDisplayValue(data_get($state, 'next_action')),
            'repair_iteration' => (int) ($session->repair_round ?? 0),
            'max_repair_iterations' => max(1, (int) ($budget['max_repair_iterations'] ?? 15)),
            'probe_actions' => (int) ($usage['probe_actions'] ?? 0),
            'max_probe_actions' => max(1, (int) ($budget['max_probe_actions'] ?? 60)),
            'ai_requests' => max(0, (int) ($usage['ai_requests'] ?? 0)),
            'total_tokens' => max(0, (int) ($usage['total_tokens'] ?? 0)),
            'cost_usd' => max(0, (float) ($usage['cost_usd'] ?? 0)),
            'max_cost_usd' => max(0, (float) ($budget['max_cost_usd'] ?? 0)),
            'elapsed_minutes' => $elapsedMinutes,
            'remaining_minutes' => max(0, $maxMinutes - $elapsedMinutes),
            'started_at' => optional($session->started_at)->format('d.m.Y H:i:s'),
            'finished_at' => optional($session->finished_at)->format('d.m.Y H:i:s'),
            'vision_analysis' => $this->copilotVisionAnalysis($state),
            'verification_report' => $this->copilotVerificationReport($session, $state),
            'checkpoints' => $checkpoints,
            'revisions' => $revisions,
            'dom_elements' => $domElements,
        ];
    }

    protected function copilotVisionAnalysis(array $state): ?array
    {
        $vision = is_array($state['vision'] ?? null) ? $state['vision'] : [];

        if ($vision === []) {
            return null;
        }

        $confidence = is_numeric($vision['confidence'] ?? null)
            ? round(max(0, min(1, (float) $vision['confidence'])), 3)
            : null;
        $verdict = Str::lower(trim((string) ($vision['verdict'] ?? 'pause')));
        $progress = $vision['goal_progress'] ?? null;

        return [
            'page_type' => Str::limit(trim((string) ($vision['page_type'] ?? '')), 120, ''),
            'ui_state' => Str::limit(trim((string) ($vision['ui_state'] ?? '')), 160, ''),
            'goal_progress' => is_numeric($progress)
                ? number_format(max(0, min(1, (float) $progress)) * 100, 0, ',', '.').' %'
                : $this->copilotDisplayValue($progress),
            'confidence' => $confidence,
            'verdict' => $verdict,
            'verdict_label' => match ($verdict) {
                'pass' => 'Ziel erreicht',
                'continue' => 'Fortsetzen',
                default => 'Pruefen',
            },
            'blockers' => collect(is_array($vision['blockers'] ?? null) ? $vision['blockers'] : [])
                ->map(fn (mixed $item): string => Str::limit(trim((string) $item), 300, ''))
                ->filter()
                ->take(5)
                ->values()
                ->all(),
            'relevant_elements' => collect(is_array($vision['relevant_elements'] ?? null) ? $vision['relevant_elements'] : [])
                ->map(fn (mixed $element): array => [
                    'element_ref' => Str::limit(trim((string) data_get($element, 'element_ref', '')), 191, ''),
                    'reason' => Str::limit(trim((string) data_get($element, 'reason', '')), 300, ''),
                    'confidence' => is_numeric(data_get($element, 'confidence'))
                        ? round(max(0, min(1, (float) data_get($element, 'confidence'))), 3)
                        : null,
                ])
                ->filter(fn (array $element): bool => $element['element_ref'] !== '')
                ->take(8)
                ->values()
                ->all(),
            'suggested_task_actions' => collect(is_array($vision['suggested_task_actions'] ?? null) ? $vision['suggested_task_actions'] : [])
                ->map(fn (mixed $action): array => [
                    'task_key' => Str::limit(trim((string) data_get($action, 'task_key', '')), 191, ''),
                    'element_ref' => Str::limit(trim((string) data_get($action, 'element_ref', '')), 191, ''),
                    'reason' => Str::limit(trim((string) data_get($action, 'reason', '')), 300, ''),
                    'confidence' => is_numeric(data_get($action, 'confidence'))
                        ? round(max(0, min(1, (float) data_get($action, 'confidence'))), 3)
                        : null,
                ])
                ->filter(fn (array $action): bool => $action['task_key'] !== '')
                ->take(8)
                ->values()
                ->all(),
            'model' => Str::limit(trim((string) ($vision['model'] ?? '')), 200, ''),
            'analysis_source' => Str::limit(trim((string) ($vision['analysis_source'] ?? '')), 80, ''),
            'duration_ms' => max(0, (int) ($vision['duration_ms'] ?? 0)),
        ];
    }

    protected function copilotVerificationReport(WorkflowCopilotSession $session, array $state): ?array
    {
        $event = $session->events()
            ->whereIn('event_type', ['verification.passed', 'verification.failed'])
            ->latest('sequence')
            ->first();
        $stateVerification = data_get($state, 'verification');

        if (! $event && ! is_array($stateVerification)) {
            return null;
        }

        $payload = is_array($event?->payload_json) ? $event->payload_json : [];
        $criteria = data_get($payload, 'criteria_evaluation', data_get($state, 'verification.criteria', []));
        $criteria = is_array($criteria) ? $criteria : [];
        $vision = data_get($state, 'verification.vision', data_get($payload, 'vision', []));
        $vision = is_array($vision) ? $vision : [];
        $pass = $event
            ? (string) $event->event_type === 'verification.passed'
            : (bool) data_get($state, 'verification.pass', false);

        return [
            'final' => in_array((string) $session->status, WorkflowCopilotSession::TERMINAL_STATUSES, true),
            'pass' => $pass,
            'message' => Str::limit(trim((string) ($event?->message ?? ($pass
                ? 'Workflow vollstaendig erfolgreich und automatisch verifiziert.'
                : 'Die letzte Endpruefung wurde nicht bestanden.'))), 2000, ''),
            'workflow_run_id' => (int) ($payload['workflow_run_id'] ?? data_get($state, 'verification_run_id', 0)),
            'revision' => (int) ($payload['revision'] ?? $session->current_revision ?? 0),
            'technical_status' => (string) ($payload['technical_status'] ?? ''),
            'business_status' => (string) ($payload['business_status'] ?? ''),
            'criteria_pass' => (bool) ($criteria['pass'] ?? false),
            'criteria_passed' => (int) ($criteria['passed'] ?? 0),
            'criteria_total' => (int) ($criteria['total'] ?? 0),
            'vision_verdict' => (string) ($payload['vision_verdict'] ?? $vision['verdict'] ?? ''),
            'vision_confidence' => is_numeric($payload['vision_confidence'] ?? $vision['confidence'] ?? null)
                ? (float) ($payload['vision_confidence'] ?? $vision['confidence'])
                : null,
            'time' => optional($event?->occurred_at ?? $event?->created_at)->format('d.m.Y H:i:s'),
        ];
    }

    protected function copilotScreenshotUrl(array $state): ?string
    {
        $directUrl = trim((string) data_get($state, 'latest_screenshot_url', data_get($state, 'observation.screenshot_url', '')));

        if ($directUrl !== '') {
            return $directUrl;
        }

        $artifactId = (int) data_get($state, 'last_screenshot_artifact_id', 0);

        if ($artifactId < 1) {
            return null;
        }

        $artifact = \App\Models\WorkflowRunArtifact::query()->find($artifactId);

        if (! $artifact || ! $artifact->workflow_run_id) {
            return null;
        }

        return route('workflow-run-artifacts.show', [
            'run' => $artifact->workflow_run_id,
            'artifact' => $artifact->getKey(),
        ], false);
    }

    protected function copilotDisplayValue(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if (is_scalar($value)) {
            return Str::limit((string) $value, 1000, '');
        }

        return Str::limit(json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '', 1000, '');
    }

    protected function isVisibleCopilotEvent(mixed $event): bool
    {
        $eventType = is_object($event)
            ? (string) ($event->event_type ?? '')
            : (string) $event;
        $eventType = Str::lower(trim($eventType));

        if (Str::contains($eventType, [
            'reasoning',
            'internal_analysis',
            'chain_of_thought',
            'chain-of-thought',
            'thought',
        ])) {
            return false;
        }

        return ! in_array($eventType, [
            'ai.usage_recorded',
            'checkpoint.created',
            'checkpoint.review_pause',
            'observation.captured',
            'observation.started',
            'queue.recovery_dispatched',
            'session.status_changed',
            'task.completed',
            'task.scheduled',
            'vision.analysis_started',
        ], true);
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
        $workflow = $this->selectedWorkflow();

        if ($workflow?->has_active_copilot_lock) {
            session()->flash('error', 'Dieser Workflow wird gerade exklusiv durch den Copilot optimiert und kann nicht manuell veraendert werden.');

            return null;
        }

        return $workflow;
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

    protected function manualResumeOptions(?Workflow $workflow): array
    {
        if (! $workflow) {
            return [];
        }

        return $workflow->steps()
            ->ordered()
            ->get()
            ->flatMap(fn (WorkflowStep $step) => collect($step->task_cards)
                ->filter(fn (mixed $task): bool => is_array($task) && filled($task['key'] ?? null))
                ->map(fn (array $task): array => [
                    'value' => $step->id.':'.trim((string) $task['key']),
                    'label' => $step->name.' — '.(($task['title'] ?? null) ?: $task['key']),
                ]))
            ->values()
            ->all();
    }

    protected function quickPreviewRun(Workflow $workflow): ?WorkflowRun
    {
        $activeRun = $workflow->runs()
            ->whereIn('status', ['queued', 'running', 'waiting', 'paused', 'stop_requested', 'unreachable'])
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
        $this->workflowDevelopment = filter_var(data_get($workflow?->settings_json, 'dev_mode', false), FILTER_VALIDATE_BOOL);
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
        $this->syncTaskValueSourceProperties($prefix, $formConfig, $this->{$extraProperty});
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
            'value_source_control' => false,
            'value_label' => 'Wert',
            'value_placeholder' => 'person.email oder fester Wert',
            'value_help' => '',
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
                $field['name'] => $this->taskExtraFieldDefaultValue($field),
            ])
            ->all();
    }

    protected function taskExtraFieldsFromTask(array $formConfig, array $task): array
    {
        $legacyPayload = $this->arrayPayloadFromTaskValue($task['success_payload'] ?? null);

        return collect($this->taskExtraFields($formConfig))
            ->mapWithKeys(function (array $field) use ($task, $legacyPayload): array {
                $name = $field['name'];
                $value = data_get(
                    $task,
                    $name,
                    data_get($legacyPayload, $name, $this->taskExtraFieldDefaultValue($field)),
                );

                if (is_array($value) || is_object($value)) {
                    $value = json_encode($value, JSON_UNESCAPED_SLASHES);
                }

                return [$name => (string) $value];
            })
            ->all();
    }

    protected function taskExtraFieldDefaultValue(array $field): string
    {
        if (($field['name'] ?? null) === 'value_source') {
            return 'fixed';
        }

        return (string) ($field['default'] ?? '');
    }

    protected function syncTaskValueSourceProperties(string $prefix, array $formConfig, array $values): void
    {
        $valueSourceProperty = $prefix.'ValueSource';
        $workflowVariableProperty = $prefix.'WorkflowVariable';
        $valueFallbackProperty = $prefix.'ValueFallback';

        if (! ($formConfig['value_source_control'] ?? false)) {
            $this->{$valueSourceProperty} = 'fixed';
            $this->{$workflowVariableProperty} = '';
            $this->{$valueFallbackProperty} = '';

            return;
        }

        $this->{$valueSourceProperty} = ($values['value_source'] ?? 'fixed') === 'workflow_variable'
            ? 'workflow_variable'
            : 'fixed';
        $this->{$workflowVariableProperty} = trim((string) ($values['workflow_variable'] ?? ''));
        $this->{$valueFallbackProperty} = trim((string) ($values['value_fallback'] ?? ''));
    }

    protected function taskExtraFieldValues(string $prefix, array $formConfig, array $values): array
    {
        if (! ($formConfig['value_source_control'] ?? false)) {
            return $values;
        }

        $valueSourceProperty = $prefix.'ValueSource';
        $workflowVariableProperty = $prefix.'WorkflowVariable';
        $valueFallbackProperty = $prefix.'ValueFallback';
        $values['value_source'] = trim((string) $this->{$valueSourceProperty});
        $values['workflow_variable'] = trim((string) $this->{$workflowVariableProperty});
        $values['value_fallback'] = trim((string) $this->{$valueFallbackProperty});

        return $values;
    }

    protected function taskExtraFieldErrorProperty(string $prefix, array $formConfig, string $fieldName): string
    {
        if ($formConfig['value_source_control'] ?? false) {
            $dedicatedProperties = [
                'value_source' => $prefix.'ValueSource',
                'workflow_variable' => $prefix.'WorkflowVariable',
                'value_fallback' => $prefix.'ValueFallback',
            ];

            if (isset($dedicatedProperties[$fieldName])) {
                return $dedicatedProperties[$fieldName];
            }
        }

        return $prefix.'Extra.'.$fieldName;
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

        if (($task['task_key'] ?? null) === 'input.fill_field') {
            $valueSource = ($task['value_source'] ?? 'fixed') === 'workflow_variable'
                ? 'workflow_variable'
                : 'fixed';
            $task['value_source'] = $valueSource;

            if ($valueSource === 'workflow_variable') {
                unset($task['value'], $task['input']);
            } else {
                unset($task['workflow_variable'], $task['value_fallback']);
            }
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
            ->push('workflow_input_variables')
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
                'browser_window_create' => false,
                'browser_window_label' => 'Offenes Browserfenster uebergeben',
                'browser_window_placeholder' => 'main, registrierung oder webmail',
                'mailbox_source' => true,
                'mailbox_source_label' => 'Script-Bezugsperson',
                'mailbox_source_options' => [
                    'person' => 'Bezugs-Person',
                    'verification' => 'Haupt-Verifikationskonto',
                ],
                'extra_fields' => [
                    [
                        'name' => 'workflow_input_variables',
                        'label' => 'Zu uebergebende Variablen (JSON)',
                        'type' => 'textarea',
                        'rows' => 9,
                        'span' => 'full',
                        'format' => 'json_object',
                        'placeholder' => "{\n  \"Mail-Inbox-Liste-Scan.subject_filter\": \"workflow_variables.subject_filter\",\n  \"Mail-Inbox-Liste-Scan.max_age_minutes\": \"workflow_variables.mail_max_age\",\n  \"custom_value\": \"literal:mein-wert\"\n}",
                        'help' => 'Schluessel = Eingabename im eingebetteten Workflow. Wert = Variablenpfad des Eltern-Workflows; feste Werte mit literal:. browser_window wird automatisch aus dem oben gewaehlten offenen Fenster uebergeben.',
                        'tab' => 'Eingaben',
                    ],
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

    protected function isLoopStartTaskKey(string $taskKey): bool
    {
        return trim($taskKey) === 'loop.for_each_element';
    }

    protected function isLoopEndTaskKey(string $taskKey): bool
    {
        return trim($taskKey) === 'loop.end';
    }

    protected function buildLoopPairTasks(array $startTask, array $existingTasks): array
    {
        $pairId = 'loop-'.(string) Str::uuid();
        $startKey = trim((string) ($startTask['key'] ?? 'loop-start')) ?: 'loop-start';
        $endKey = $this->uniqueTaskKey([...$existingTasks, $startTask], ($startTask['title'] ?? 'Loop').' Ende');

        $startTask['loop_pair_id'] = $pairId;
        $startTask['loop_pair_segment'] = 'start';
        $startTask['loop_start_key'] = $startKey;
        $startTask['loop_end_key'] = $endKey;

        return [
            $startTask,
            $this->loopEndTaskForStart($startTask, $endKey, $pairId),
        ];
    }

    protected function loopEndTaskForStart(array $startTask, string $endKey, string $pairId): array
    {
        $startKey = trim((string) ($startTask['key'] ?? '')) ?: 'loop-start';
        $browserWindow = $this->normalizeBrowserWindowName((string) ($startTask['browser_window_name'] ?? $startTask['browser_window'] ?? 'main'));
        $title = trim((string) ($startTask['title'] ?? 'Loop'));

        return $this->taskCardFromDefinition('loop.end', [
            'key' => $endKey,
            'title' => 'Loop-Ende: '.($title !== '' ? $title : $startKey),
            'description' => 'Automatisches Endsegment fuer '.($title !== '' ? $title : $startKey).'.',
            'browser_window' => $browserWindow,
            'browser_window_name' => $browserWindow,
            'loop_pair_id' => $pairId,
            'loop_pair_segment' => 'end',
            'loop_start_key' => $startKey,
            'loop_end_key' => $endKey,
            'status' => 'configured',
        ]);
    }

    protected function editableLoopPairTask(array $tasks, array $selectedTask): array
    {
        $pairId = trim((string) ($selectedTask['loop_pair_id'] ?? $selectedTask['loopPairId'] ?? ''));
        $segment = trim((string) ($selectedTask['loop_pair_segment'] ?? $selectedTask['loopPairSegment'] ?? ''));

        if ($pairId === '') {
            return [
                'task' => $selectedTask,
                'segment' => '',
                'end_key' => '',
            ];
        }

        $pairedTasks = collect($tasks)
            ->filter(fn (mixed $task): bool => is_array($task) && trim((string) ($task['loop_pair_id'] ?? $task['loopPairId'] ?? '')) === $pairId);
        $startTask = $pairedTasks->first(fn (array $task): bool => $this->isLoopStartTaskKey((string) ($task['task_key'] ?? '')))
            ?: $pairedTasks->first(fn (array $task): bool => trim((string) ($task['loop_pair_segment'] ?? $task['loopPairSegment'] ?? '')) === 'start')
            ?: $selectedTask;
        $endTask = $pairedTasks->first(fn (array $task): bool => $this->isLoopEndTaskKey((string) ($task['task_key'] ?? '')))
            ?: $pairedTasks->first(fn (array $task): bool => trim((string) ($task['loop_pair_segment'] ?? $task['loopPairSegment'] ?? '')) === 'end');

        return [
            'task' => $startTask,
            'segment' => $segment !== '' ? $segment : 'start',
            'end_key' => is_array($endTask) ? (string) ($endTask['key'] ?? '') : (string) ($startTask['loop_end_key'] ?? ''),
        ];
    }

    protected function syncLoopPairTasks(array $tasks, string $startTaskKey): array
    {
        $tasks = collect($tasks)
            ->filter(fn (mixed $task): bool => is_array($task))
            ->values();
        $startIndex = $tasks->search(fn (array $task): bool => (string) ($task['key'] ?? '') === $startTaskKey);

        if ($startIndex === false) {
            return $tasks->all();
        }

        $startTask = $tasks->get($startIndex);
        $pairId = trim((string) ($startTask['loop_pair_id'] ?? $startTask['loopPairId'] ?? ''));

        if ($pairId === '') {
            return $tasks->all();
        }

        $endIndex = $tasks->search(fn (array $task): bool => trim((string) ($task['loop_pair_id'] ?? $task['loopPairId'] ?? '')) === $pairId
            && ($this->isLoopEndTaskKey((string) ($task['task_key'] ?? '')) || trim((string) ($task['loop_pair_segment'] ?? $task['loopPairSegment'] ?? '')) === 'end'));
        $endKey = $endIndex !== false
            ? (string) ($tasks->get($endIndex)['key'] ?? '')
            : (trim((string) ($startTask['loop_end_key'] ?? '')) ?: $this->uniqueTaskKey($tasks->all(), ($startTask['title'] ?? 'Loop').' Ende'));
        $startTask['loop_pair_segment'] = 'start';
        $startTask['loop_start_key'] = (string) ($startTask['key'] ?? $startTaskKey);
        $startTask['loop_end_key'] = $endKey;
        $endTask = $this->loopEndTaskForStart($startTask, $endKey, $pairId);

        if ($endIndex !== false) {
            $existingEndTask = $tasks->get($endIndex);
            $endTask = array_replace($existingEndTask, $endTask, [
                'order_id' => $existingEndTask['order_id'] ?? null,
                'position' => $existingEndTask['position'] ?? null,
            ]);
        }

        $synced = $tasks
            ->map(function (array $task, int $index) use ($startIndex, $endIndex, $startTask, $endTask): array {
                if ($index === $startIndex) {
                    return $startTask;
                }

                if ($endIndex !== false && $index === $endIndex) {
                    return $endTask;
                }

                return $task;
            })
            ->values();

        if ($endIndex === false) {
            $synced->splice($startIndex + 1, 0, [$endTask]);
        }

        return $synced->values()->all();
    }

    protected function validateTaskFieldRequirements(string $prefix, array $formConfig): bool
    {
        $valid = true;
        $selectorProperty = $prefix.'ElementSelector';
        $valueProperty = $prefix.'InputValue';
        $mailboxSourceProperty = $prefix.'MailboxSource';
        $browserWindowProperty = $prefix.'BrowserWindow';
        $extraProperty = $prefix.'Extra';
        $extraValues = $this->taskExtraFieldValues(
            $prefix,
            $formConfig,
            is_array($this->{$extraProperty} ?? null) ? $this->{$extraProperty} : [],
        );

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

        if ($formConfig['value_source_control'] ?? false) {
            $valueSource = trim((string) ($extraValues['value_source'] ?? 'fixed'));
            $workflowVariable = trim((string) ($extraValues['workflow_variable'] ?? ''));

            if (! in_array($valueSource, ['fixed', 'workflow_variable'], true)) {
                $this->addError(
                    $this->taskExtraFieldErrorProperty($prefix, $formConfig, 'value_source'),
                    'Bitte eine gueltige Wertquelle auswaehlen.',
                );
                $valid = false;
            } elseif ($valueSource === 'fixed' && trim((string) $this->{$valueProperty}) === '') {
                $this->addError($valueProperty, 'Bitte einen festen Wert angeben.');
                $valid = false;
            } elseif ($valueSource === 'workflow_variable' && $workflowVariable === '') {
                $this->addError(
                    $this->taskExtraFieldErrorProperty($prefix, $formConfig, 'workflow_variable'),
                    'Bitte den Namen der Workflow-Variable angeben.',
                );
                $valid = false;
            }
        }

        if (($formConfig['mailbox_source'] ?? false) && ! in_array($this->normalizeMailboxSource((string) $this->{$mailboxSourceProperty}), ['person', 'verification'], true)) {
            $this->addError($mailboxSourceProperty, 'Bitte eine Script-Bezugsperson auswaehlen.');
            $valid = false;
        }

        foreach ($this->taskExtraFields($formConfig) as $field) {
            $name = $field['name'];
            $fieldValue = trim((string) ($extraValues[$name] ?? ''));
            $fieldLabel = (string) ($field['label'] ?? $name);
            $errorProperty = $this->taskExtraFieldErrorProperty($prefix, $formConfig, $name);

            if (($field['required'] ?? false) && $fieldValue === '') {
                $this->addError($errorProperty, 'Bitte '.$fieldLabel.' angeben.');
                $valid = false;
            }

            $requiredWhen = is_array($field['required_when'] ?? null) ? $field['required_when'] : [];
            $requiredWhenField = trim((string) ($requiredWhen['field'] ?? ''));
            $requiredWhenValue = (string) ($requiredWhen['equals'] ?? '');

            if ($requiredWhenField !== ''
                && (string) ($extraValues[$requiredWhenField] ?? '') === $requiredWhenValue
                && $fieldValue === '') {
                $this->addError($errorProperty, 'Bitte '.$fieldLabel.' angeben.');
                $valid = false;
            }

            if (($field['type'] ?? 'text') === 'select') {
                $options = is_array($field['options'] ?? null) ? array_keys($field['options']) : [];

                if ($fieldValue !== '' && ! in_array($fieldValue, $options, true)) {
                    $this->addError($errorProperty, 'Bitte eine gueltige Option fuer '.$fieldLabel.' auswaehlen.');
                    $valid = false;
                }
            }

            if (($field['type'] ?? 'text') === 'number' && $fieldValue !== '' && ! is_numeric($fieldValue)) {
                $this->addError($errorProperty, $fieldLabel.' muss eine Zahl sein.');
                $valid = false;
            }

            if (($field['format'] ?? null) === 'variable_path'
                && $fieldValue !== ''
                && preg_match('/^[A-Za-z0-9_.-]+$/', $fieldValue) !== 1) {
                $this->addError($errorProperty, $fieldLabel.' darf nur Buchstaben, Zahlen, Punkt, Unterstrich und Bindestrich enthalten.');
                $valid = false;
            }

            if (($field['format'] ?? null) === 'json_object' && $fieldValue !== '') {
                $decoded = json_decode($fieldValue, true);

                if (! is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
                    $this->addError($extraProperty.'.'.$name, $fieldLabel.' muss ein JSON-Objekt sein.');
                    $valid = false;
                }
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
        $workflow = $this->editableWorkflow();

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
