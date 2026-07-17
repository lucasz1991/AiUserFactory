<?php

namespace App\Livewire\Admin\Network;

use App\Models\Workflow;
use App\Models\WorkflowStudioSession;
use App\Services\Workflows\WorkflowStudioRevisionService;
use App\Services\Workflows\WorkflowTaskCatalog;
use Illuminate\Support\Collection;
use Livewire\Attributes\On;

class WorkflowStudioTaskEditor extends WorkflowManager
{
    public int $studioSessionId;

    public function mount(Workflow $workflow, ?int $studioSessionId = null): void
    {
        $this->selectedWorkflowId = (int) $workflow->getKey();
        $this->studioSessionId = (int) $studioSessionId;
    }

    #[On('open-workflow-studio-task-editor')]
    public function openFromStudio(int $stepId, string $taskKey): void
    {
        $this->resetValidation();
        $this->openEditTaskCard($stepId, $taskKey);
    }

    public function saveEditTaskCard(?string $mailboxSourceOverride = null): void
    {
        $step = $this->editingTaskStepId ? $this->stepForSelectedWorkflow($this->editingTaskStepId) : null;

        if (! $step) {
            return;
        }

        $session = WorkflowStudioSession::query()
            ->where('workflow_id', $this->selectedWorkflowId)
            ->findOrFail($this->studioSessionId);
        $activeRun = $session->activeRun;

        if ($activeRun && ! in_array($activeRun->status, ['paused', 'completed', 'failed', 'cancelled', 'timed_out', 'lost'], true)) {
            $this->addError('editingTaskCatalogKey', 'Pausiere den Lauf zuerst am nächsten sicheren Task-Checkpoint. Danach kann die Task bearbeitet werden.');

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
            'editingTaskExtra.*' => ['nullable', 'string', 'max:4000'],
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
        $this->dispatch('workflow-studio-task-saved', stepId: $editingTaskStepId, taskKey: $editingTaskKey);
    }

    public function render()
    {
        $workflow = $this->selectedWorkflow();
        $steps = $workflow?->steps()->ordered()->get() ?? new Collection;
        $taskDefinitions = collect(app(WorkflowTaskCatalog::class)->options())
            ->concat($this->workflowTaskOptions($workflow))
            ->values();

        return view('livewire.admin.network.workflow-studio-task-editor', [
            'steps' => $steps,
            'taskDefinitions' => $taskDefinitions,
        ]);
    }
}
