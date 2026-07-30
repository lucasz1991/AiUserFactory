<?php

namespace App\Livewire\Admin\Network;

use App\Models\Workflow;
use App\Models\WorkflowStudioSession;
use App\Services\Workflows\WorkflowRouteMapPresenter;
use App\Services\Workflows\WorkflowStudioRevisionService;
use App\Services\Workflows\WorkflowTaskCatalog;
use Closure;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\On;
use Throwable;

class WorkflowStudioTaskEditor extends WorkflowManager
{
    public int $studioSessionId;

    public bool $modalOnly = false;

    public bool $taskEditReadOnly = false;

    public bool $taskEditCanRequestPause = false;

    public bool $taskEditPauseRequested = false;

    public string $taskEditRunStatus = 'idle';

    public string $taskEditLockMessage = '';

    public function mount(Workflow $workflow, ?int $studioSessionId = null, bool $modalOnly = false): void
    {
        $this->selectedWorkflowId = (int) $workflow->getKey();
        $this->studioSessionId = (int) $studioSessionId;
        $this->modalOnly = $modalOnly;
        $firstStep = $workflow->steps()->ordered()->first();
        $this->catalogTargetStepId = (string) ($firstStep?->getKey() ?: '');
        $this->overviewSelectedStepId = (int) ($firstStep?->getKey() ?: 0);
        $this->overviewSelectedTaskKey = trim((string) data_get($firstStep?->task_cards, '0.key', ''));
        $this->synchronizeTaskEditAccess();
    }

    #[On('open-workflow-studio-task-editor')]
    public function openFromStudio(int $stepId, string $taskKey, ?int $studioSessionId = null): void
    {
        if ($studioSessionId !== null && $studioSessionId !== $this->studioSessionId) {
            return;
        }

        $this->resetValidation();
        $this->taskEditPauseRequested = false;
        $this->selectOverviewTask($stepId, $taskKey);
        $this->openEditTaskCard($stepId, $taskKey);
        $this->synchronizeTaskEditAccess();
    }

    public function requestPauseForTaskEdit(): void
    {
        if (! $this->showEditTaskModal) {
            return;
        }

        $session = $this->studioSession();
        $activeRun = $session->activeRun;
        $this->synchronizeTaskEditAccess($session, $activeRun);

        if (! $this->taskEditReadOnly) {
            return;
        }

        if ($session->mode === 'autonomous') {
            $this->addError('studioBuilder', 'Im autonomen Modus kann eine Task nicht manuell bearbeitet werden.');

            return;
        }

        if (! $activeRun) {
            return;
        }

        $this->taskEditPauseRequested = true;
        $this->taskEditLockMessage = 'Pause ist angefordert. Die Bearbeitung wird nach dem sicheren Haltepunkt freigeschaltet.';
        $this->dispatch(
            'workflow-studio-pause-for-edit-requested',
            studioSessionId: $this->studioSessionId,
            runId: (int) $activeRun->getKey(),
            stepId: $this->editingTaskStepId,
            taskKey: $this->editingTaskKey,
        );
    }

    #[On('workflow-studio-run-status-changed')]
    public function handleRunStatusChanged(
        int $studioSessionId,
        ?int $runId = null,
        string $status = 'idle',
    ): void {
        if ($studioSessionId !== $this->studioSessionId) {
            return;
        }

        $session = $this->studioSession();
        $activeRun = $session->activeRun;

        if (($runId === null && $activeRun)
            || ($runId !== null && (! $activeRun || (int) $activeRun->getKey() !== $runId))) {
            return;
        }

        $this->synchronizeTaskEditAccess($session, $activeRun);

        if (! $this->taskEditReadOnly) {
            $this->resetErrorBag('studioBuilder');
        }
    }

    public function prepareCatalogTask(string $taskKey): void
    {
        if (! $this->ensureDefinitionEditable()) {
            return;
        }

        $stepId = (int) $this->catalogTargetStepId;
        if ($stepId <= 0) {
            $this->addError('studioBuilder', 'Wähle zuerst eine Zielliste aus.');

            return;
        }

        parent::prepareTaskFromCatalog($stepId, $taskKey);
    }

    public function prepareTaskFromCatalog(int $stepId, string $taskKey, ?int $position = null): void
    {
        if (! $this->ensureDefinitionEditable()) {
            return;
        }

        $this->catalogTargetStepId = (string) $stepId;
        parent::prepareTaskFromCatalog($stepId, $taskKey, $position);
    }

    public function closeEditTaskModal(): void
    {
        $this->showEditTaskModal = false;
        $this->editingTaskLoopPairSegment = '';
        $this->editingTaskLoopPairEndKey = '';
        $this->taskEditPauseRequested = false;
        $this->taskEditCanRequestPause = false;
        $this->taskEditLockMessage = '';
        $this->resetValidation();
    }

    public function addStep(): void
    {
        if ($this->mutateDefinition('Neue Liste im Workflow Studio angelegt.', fn () => parent::addStep())) {
            $newStep = $this->selectedWorkflow()?->steps()->ordered()->get()->last();
            if ($newStep) {
                $this->catalogTargetStepId = (string) $newStep->getKey();
            }
            $this->notifyDefinitionUpdated(message: 'Liste wurde angelegt.');
        }
    }

    public function toggleStep(int $stepId): void
    {
        if ($this->mutateDefinition('Liste im Workflow Studio aktiviert oder pausiert.', fn () => parent::toggleStep($stepId))) {
            $this->notifyDefinitionUpdated($stepId, message: 'Listenstatus wurde geändert.');
        }
    }

    public function removeStep(int $stepId): void
    {
        if ($this->mutateDefinition('Liste samt Tasks im Workflow Studio entfernt.', fn () => parent::removeStep($stepId))) {
            $firstStepId = (int) ($this->selectedWorkflow()?->steps()->ordered()->value('id') ?: 0);
            $this->catalogTargetStepId = $firstStepId > 0 ? (string) $firstStepId : '';
            $this->notifyDefinitionUpdated(message: 'Liste wurde entfernt.');
        }
    }

    public function saveEditStep(): void
    {
        $stepId = (int) $this->editingStepId;
        if ($this->mutateDefinition('Liste im Workflow Studio bearbeitet.', fn () => parent::saveEditStep())) {
            $this->notifyDefinitionUpdated($stepId, message: 'Liste wurde gespeichert.');
        }
    }

    public function reorderStep(mixed $item, mixed $position): void
    {
        if ($this->mutateDefinition('Listen im Workflow Studio neu sortiert.', fn () => parent::reorderStep($item, $position))) {
            $this->notifyDefinitionUpdated(message: 'Listenreihenfolge wurde gespeichert.');
        }
    }

    public function addTaskCard(?string $mailboxSourceOverride = null): void
    {
        $stepId = (int) $this->newTaskListId;
        $beforeKeys = collect($this->stepForSelectedWorkflow($stepId)?->task_cards ?? [])->pluck('key');
        $createdTaskKey = null;

        $saved = $this->mutateDefinition(
            'Task aus dem Katalog im Workflow Studio eingefügt.',
            function () use ($mailboxSourceOverride, $stepId, $beforeKeys, &$createdTaskKey): void {
                parent::addTaskCard($mailboxSourceOverride);
                $createdTaskKey = collect($this->stepForSelectedWorkflow($stepId)?->fresh()->task_cards ?? [])
                    ->pluck('key')
                    ->first(fn (mixed $key): bool => ! $beforeKeys->contains($key));
            },
        );

        if ($saved) {
            $this->notifyDefinitionUpdated($stepId, is_string($createdTaskKey) ? $createdTaskKey : null, 'Task wurde eingefügt.');
        }
    }

    public function saveEditTaskCard(?string $mailboxSourceOverride = null): void
    {
        if (! $this->ensureDefinitionEditable()) {
            return;
        }

        $step = $this->editingTaskStepId ? $this->stepForSelectedWorkflow($this->editingTaskStepId) : null;

        if (! $step) {
            return;
        }

        $session = WorkflowStudioSession::query()
            ->where('workflow_id', $this->selectedWorkflowId)
            ->findOrFail($this->studioSessionId);
        $activeRun = $session->activeRun;

        if ($activeRun && ! in_array($activeRun->status, ['paused', 'completed', 'failed', 'cancelled', 'timed_out', 'lost'], true)) {
            $this->addError('editingTaskCatalogKey', 'Pausiere den Lauf zuerst. Danach kann die Task bearbeitet werden.');

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
            $this->addError('editingTaskCatalogKey', 'Dieser Workflow oder Task ist nicht verfügbar.');

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

        $expectedRevision = (int) $this->selectedWorkflow()?->copilot_revision;
        $editingTaskKey = $this->editingTaskKey;
        $editingTaskStepId = (int) $step->getKey();

        app(WorkflowStudioRevisionService::class)->apply(
            $session,
            $expectedRevision,
            'Task '.$editingTaskKey.' im Workflow Studio bearbeitet.',
            function () use ($editingTaskStepId, $editingTaskKey, $validated, $mailboxSourceOverride): void {
                $step = $this->stepForSelectedWorkflow($editingTaskStepId)?->fresh();

                if (! $step) {
                    return;
                }

                $config = is_array($step->config_json) ? $step->config_json : [];
                $tasks = collect(is_array($config['tasks'] ?? null) ? $config['tasks'] : []);

                $config['tasks'] = $tasks
                    ->map(function (array $task) use ($validated, $step, $editingTaskKey, $mailboxSourceOverride): array {
                        if ((string) ($task['key'] ?? '') !== $editingTaskKey) {
                            return $task;
                        }

                        $formConfig = $this->taskFormConfig($validated['editingTaskCatalogKey']);
                        $selector = trim((string) ($validated['editingTaskElementSelector'] ?? ''));
                        $value = trim((string) ($validated['editingTaskInputValue'] ?? ''));
                        $mailboxSource = $this->normalizeMailboxSource((string) ($mailboxSourceOverride ?: ($validated['editingTaskMailboxSource'] ?? 'person')));
                        $browserWindow = $this->normalizeBrowserWindowName((string) ($validated['editingTaskBrowserWindow'] ?? ''));
                        $task = array_replace(
                            $task,
                            $this->taskCardFromDefinition($validated['editingTaskCatalogKey'], ['key' => $editingTaskKey]),
                            [
                                'key' => $editingTaskKey,
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
                        ] as $key => $payloadInput) {
                            $payload = $this->payloadFromInput($payloadInput);

                            if ($payload !== null) {
                                $task[$key] = $payload;
                            } else {
                                unset($task[$key]);
                            }
                        }

                        foreach ([
                            'next' => (string) ($validated['editingTaskSuccessTarget'] ?? ''),
                            'on_error' => (string) ($validated['editingTaskFailedTarget'] ?? ''),
                        ] as $key => $targetValue) {
                            $route = $this->taskRouteTargetFromValue($targetValue, $step, $editingTaskKey);

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
                    ->all();

                if ($this->editingTaskLoopPairSegment !== '') {
                    $config['tasks'] = $this->syncLoopPairTasks($config['tasks'], $editingTaskKey);
                }

                $step->forceFill(['config_json' => $config])->save();
            },
            'user:'.auth()->id(),
        );

        $this->showEditTaskModal = false;
        $this->editingTaskLoopPairSegment = '';
        $this->editingTaskLoopPairEndKey = '';
        $this->taskEditPauseRequested = false;
        $this->taskEditCanRequestPause = false;
        $this->taskEditLockMessage = '';
        $this->dispatch('workflow-studio-task-saved', stepId: $editingTaskStepId, taskKey: $editingTaskKey);
    }

    public function removeTaskCard(int $stepId, string $taskKey): void
    {
        if ($this->mutateDefinition('Task im Workflow Studio entfernt.', fn () => parent::removeTaskCard($stepId, $taskKey))) {
            // Feature R2: Der Studio-Editor rendert keine Session-Flash-Meldung,
            // deshalb wird der Hinweis der Elternklasse in die Notice gehoben.
            $this->notifyDefinitionUpdated($stepId, message: trim(
                'Task wurde entfernt. '.$this->lastRemovalRouteWarning,
            ));
        }
    }

    public function reorderTaskCard(int $stepId, mixed $item, mixed $position): void
    {
        if ($this->mutateDefinition('Tasks im Workflow Studio neu sortiert.', fn () => parent::reorderTaskCard($stepId, $item, $position))) {
            $this->notifyDefinitionUpdated(message: 'Task-Reihenfolge wurde gespeichert.');
        }
    }

    public function moveTaskCard(int $targetStepId, mixed $sourceStepId, string $taskKey, mixed $position): void
    {
        if ($this->mutateDefinition(
            'Task im Workflow Studio verschoben.',
            fn () => parent::moveTaskCard($targetStepId, $sourceStepId, $taskKey, $position),
        )) {
            $this->catalogTargetStepId = (string) $targetStepId;
            $this->notifyDefinitionUpdated($targetStepId, $taskKey, 'Task wurde verschoben.');
        }
    }

    public function render()
    {
        $workflow = $this->selectedWorkflow();
        $steps = $workflow?->steps()->ordered()->get() ?? new Collection;
        $workflow?->setRelation('steps', $steps);
        $taskCatalog = app(WorkflowTaskCatalog::class);
        $taskDefinitions = collect($taskCatalog->arrangeLibraryOptions(
            collect($taskCatalog->options())
                ->concat($this->workflowTaskOptions($workflow))
                ->values()
                ->all(),
        ));
        $taskGroups = $taskDefinitions
            ->pluck('library_group')
            ->unique()
            ->values();
        if (! $taskGroups->contains($this->activeTaskGroup)) {
            $this->activeTaskGroup = (string) ($taskGroups->first() ?? 'navigation');
        }
        $search = mb_strtolower(trim($this->taskSearch));
        $visibleTaskDefinitions = ($search === ''
            ? $taskDefinitions->where('library_group', $this->activeTaskGroup)
            : $taskDefinitions)
            ->filter(function (array $definition) use ($search): bool {
                if ($search === '') {
                    return true;
                }

                return str_contains(mb_strtolower(implode(' ', [
                    (string) ($definition['label'] ?? ''),
                    (string) ($definition['description'] ?? ''),
                    (string) ($definition['key'] ?? ''),
                    (string) ($definition['library_group_label'] ?? ''),
                    (string) ($definition['library_group_short_label'] ?? ''),
                    (string) ($definition['library_group_description'] ?? ''),
                ])), $search);
            })
            ->values();
        $studioSession = $this->studioSession();
        $activeRun = $studioSession->activeRun;
        $this->synchronizeTaskEditAccess($studioSession, $activeRun);
        $routeMap = app(WorkflowRouteMapPresenter::class)->present(
            $workflow,
            $activeRun,
            $activeRun
                ? WorkflowRouteMapPresenter::MODE_COMBINED
                : WorkflowRouteMapPresenter::MODE_DEFINITION,
        );
        $selectedOverviewStep = $steps->firstWhere('id', $this->overviewSelectedStepId);

        if (! $selectedOverviewStep) {
            $selectedOverviewStep = $steps->first();
            $this->overviewSelectedStepId = (int) ($selectedOverviewStep?->getKey() ?: 0);
            $this->overviewSelectedTaskKey = '';
        } elseif ($this->overviewSelectedTaskKey !== '' && ! collect($selectedOverviewStep->task_cards)->contains(
            fn (array $task): bool => trim((string) ($task['key'] ?? '')) === $this->overviewSelectedTaskKey,
        )) {
            $this->overviewSelectedTaskKey = '';
        }

        return view('livewire.admin.network.workflow-studio-task-editor', [
            'workflow' => $workflow,
            'steps' => $steps,
            'taskDefinitions' => $taskDefinitions,
            'taskGroups' => $taskGroups,
            'taskGroupLabels' => $this->taskGroupLabels(),
            'taskGroupMeta' => $taskCatalog->libraryGroups(),
            'taskGroupCounts' => $taskDefinitions->countBy('library_group'),
            'visibleTaskDefinitions' => $visibleTaskDefinitions,
            'searchActive' => $search !== '',
            'canEdit' => $this->definitionIsEditable($activeRun, $studioSession),
            'runStatus' => $activeRun?->status,
            'activeRun' => $activeRun,
            'routeMap' => $routeMap,
            'modalOnly' => $this->modalOnly,
            'taskEditReadOnly' => $this->taskEditReadOnly,
            'taskEditCanRequestPause' => $this->taskEditCanRequestPause,
            'taskEditPauseRequested' => $this->taskEditPauseRequested,
            'taskEditRunStatus' => $this->taskEditRunStatus,
            'taskEditLockMessage' => $this->taskEditLockMessage,
        ]);
    }

    private function mutateDefinition(string $reason, Closure $mutation): bool
    {
        if (! $this->ensureDefinitionEditable()) {
            return false;
        }

        try {
            app(WorkflowStudioRevisionService::class)->apply(
                WorkflowStudioSession::query()->findOrFail($this->studioSessionId),
                (int) $this->selectedWorkflow()?->copilot_revision,
                $reason,
                fn () => $mutation(),
                'user:'.auth()->id(),
            );

            return true;
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (DomainException $exception) {
            if ($exception->getMessage() !== 'Die angeforderte Workflow-Aenderung hat keine Definition veraendert.') {
                $this->addError('studioBuilder', $exception->getMessage());
            }

            return false;
        } catch (Throwable $exception) {
            report($exception);
            $this->addError('studioBuilder', $exception->getMessage());

            return false;
        }
    }

    private function ensureDefinitionEditable(): bool
    {
        if ($this->definitionIsEditable()) {
            return true;
        }

        $this->addError('studioBuilder', 'Pausiere den Lauf zuerst. Danach kannst du Katalog, Listen und Tasks weiter bearbeiten.');

        return false;
    }

    private function definitionIsEditable(
        mixed $activeRun = null,
        ?WorkflowStudioSession $session = null,
    ): bool
    {
        $session ??= $this->studioSession();

        if ($session->mode === 'autonomous') {
            return false;
        }

        $activeRun ??= $session->activeRun;

        return ! $activeRun || in_array((string) $activeRun->status, [
            'paused', 'completed', 'failed', 'cancelled', 'timed_out', 'lost',
        ], true);
    }

    private function studioSession(): WorkflowStudioSession
    {
        return WorkflowStudioSession::query()
            ->where('workflow_id', $this->selectedWorkflowId)
            ->findOrFail($this->studioSessionId);
    }

    private function synchronizeTaskEditAccess(
        ?WorkflowStudioSession $session = null,
        mixed $activeRun = null,
    ): void {
        $session ??= $this->studioSession();
        $activeRun ??= $session->activeRun;
        $this->taskEditRunStatus = (string) ($activeRun?->status ?? 'idle');
        $this->taskEditReadOnly = ! $this->definitionIsEditable($activeRun, $session);

        if (! $this->taskEditReadOnly) {
            $this->taskEditCanRequestPause = false;
            $this->taskEditPauseRequested = false;
            $this->taskEditLockMessage = '';

            return;
        }

        if ($session->mode === 'autonomous') {
            $this->taskEditCanRequestPause = false;
            $this->taskEditPauseRequested = false;
            $this->taskEditLockMessage = 'Autonome Läufe sind schreibgeschützt und können hier nicht bearbeitet werden.';

            return;
        }

        $this->taskEditCanRequestPause = (bool) $activeRun;
        $this->taskEditLockMessage = $this->taskEditPauseRequested
            ? 'Pause ist angefordert. Die Bearbeitung wird nach dem sicheren Haltepunkt freigeschaltet.'
            : 'Der Lauf ist aktiv. Pausiere ihn am sicheren Haltepunkt, um diese Task zu bearbeiten.';
    }

    private function notifyDefinitionUpdated(?int $stepId = null, ?string $taskKey = null, string $message = 'Workflow wurde aktualisiert.'): void
    {
        $this->dispatch(
            'workflow-studio-definition-updated',
            stepId: $stepId,
            taskKey: $taskKey,
            message: $message,
        );
    }
}
