<?php

namespace App\Livewire\Admin\Network;

use App\Enums\WorkflowCopilotPermissionMode;
use App\Jobs\RunWorkflowJob;
use App\Jobs\WorkflowCopilotSupervisorJob;
use App\Models\NetworkNode;
use App\Models\Person;
use App\Models\Workflow;
use App\Models\WorkflowCopilotSession;
use App\Models\WorkflowOptimizationPlan;
use App\Models\WorkflowRun;
use App\Models\WorkflowStudioSession;
use App\Services\Workflows\WorkflowCopilotLaunchRequest;
use App\Services\Workflows\WorkflowCopilotLaunchService;
use App\Services\Workflows\WorkflowCopilotSessionService;
use App\Services\Workflows\WorkflowDefinitionValidator;
use App\Services\Workflows\WorkflowExecutionService;
use App\Services\Workflows\WorkflowRetryRouteAutoRepairService;
use App\Services\Workflows\WorkflowRouteTargetAutoRepairService;
use App\Services\Workflows\WorkflowStudioAuthorizationService;
use App\Services\Workflows\WorkflowStudioCheckpointService;
use App\Services\Workflows\WorkflowStudioControlService;
use App\Services\Workflows\WorkflowStudioRevisionService;
use App\Services\Workflows\WorkflowStudioSessionService;
use App\Services\Workflows\WorkflowTaskCatalog;
use App\Services\Workflows\WorkflowTaskOrderingService;
use DomainException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\On;
use Livewire\Component;
use Throwable;

class WorkflowStudio extends Component
{
    private const STUDIO_PANELS = [
        'builder',
    ];

    private const TOOL_MODALS = [
        'browser',
        'data',
        'checkpoints',
        'logs',
        'debug',
        'steps',
        'tasks',
        'variables',
        'artifacts',
    ];

    public int $workflowId;

    public int $studioSessionId;

    public ?int $activeRunId = null;

    public string $mode = 'interactive';

    public bool $embedded = false;

    public string $activeWorkspaceTab = 'test';

    public string $permissionMode = 'ask_critical';

    public bool $unrestrictedWarningAcknowledged = false;

    public string $goal = '';

    public string $successCriteria = '';

    public string $workflowInputs = '{}';

    public string $executionTarget = 'system';

    public string $personId = '';

    public string $networkNodeId = '';

    public string $selectedStepId = '';

    public string $selectedTaskKey = '';

    public string $editingTaskJson = '';

    public string $probeAction = 'selector.search';

    public string $probeSelector = '';

    public string $probeValue = '';

    public string $probeBrowserWindow = 'main';

    public string $checkpointName = '';

    public array $lastActionResult = [];

    public array $pendingConfirmation = [];

    public bool $showCopilotSettingsModal = false;

    public bool $showSelectorProbeModal = false;

    /** Feature R1: Dialog fuer Verzweigungen, deren Ziel geloescht wurde. */
    public bool $showRouteRepairModal = false;

    /** @var array<int,array<string,mixed>> Befunde aus WorkflowRouteTargetAutoRepairService::analyze() */
    public array $routeRepairFindings = [];

    /** @var array<int,string> Fehler, die auch nach der Reparatur bestehen bleiben */
    public array $routeRepairBlockingMessages = [];

    /** Welche Aktion nach der Reparatur fortgesetzt wird. */
    public string $routeRepairIntent = 'start_run';

    public string $activeToolModal = '';

    public string $activeStudioPanel = '';

    public string $observedCursorSignature = '';

    public function mount(
        Workflow $workflow,
        bool $embedded = false,
        string $initialMode = 'interactive',
        ?int $runId = null,
    ): void {
        $this->workflowId = (int) $workflow->getKey();
        $this->embedded = $embedded;
        $requestedMode = $embedded ? $initialMode : (string) request()->query('mode', $initialMode);
        $this->mode = $requestedMode === 'autonomous' ? 'autonomous' : 'interactive';
        $requestedRun = WorkflowRun::query()
            ->where('workflow_id', $workflow->getKey())
            ->find((int) ($runId ?: request()->query('run', 0)));
        $requestedSessionId = (int) request()->query('session', $requestedRun?->workflow_studio_session_id ?? 0);
        $session = $requestedSessionId > 0
            ? WorkflowStudioSession::query()
                ->where('workflow_id', $workflow->getKey())
                ->find($requestedSessionId)
            : null;
        $session ??= app(WorkflowStudioSessionService::class)->latestOrOpen(
            $workflow,
            auth()->user(),
            $this->mode,
        );
        if (! $session->mode_locked_at) {
            $session = app(WorkflowStudioControlService::class)->choose($session, $this->mode, auth()->user());
        }
        $activeCopilotId = (int) ($workflow->active_workflow_copilot_session_id ?? 0);
        if ($this->mode === 'autonomous' && $activeCopilotId > 0 && ! $session->workflow_copilot_session_id) {
            $session->forceFill(['workflow_copilot_session_id' => $activeCopilotId])->save();
            WorkflowOptimizationPlan::query()
                ->where('workflow_copilot_session_id', $activeCopilotId)
                ->update(['workflow_studio_session_id' => $session->id]);
        }
        if ($this->mode === 'autonomous' && $activeCopilotId > 0 && ! $session->mode_locked_at) {
            $session = app(WorkflowStudioControlService::class)->lock($session, 'autonomous', auth()->user());
        }
        app(WorkflowStudioRevisionService::class)->ensureBaseline($session);

        if ($requestedRun) {
            app(WorkflowStudioSessionService::class)->attachRun($session, $requestedRun);

            if ($requestedRun->workflow_copilot_session_id && ! $session->workflow_copilot_session_id) {
                $session->forceFill([
                    'workflow_copilot_session_id' => $requestedRun->workflow_copilot_session_id,
                ])->save();
            }
        }

        $this->studioSessionId = (int) $session->getKey();
        $this->mode = $session->mode === 'autonomous' ? 'autonomous' : 'interactive';
        $this->activeRunId = $requestedRun
            ? (int) $requestedRun->id
            : ($session->active_workflow_run_id ? (int) $session->active_workflow_run_id : null);
        $this->permissionMode = $session->permission_mode;
        $this->goal = (string) $session->goal;
        $this->successCriteria = collect($session->success_criteria_json ?: [])
            ->map(fn (mixed $criterion): string => is_scalar($criterion)
                ? (string) $criterion
                : (json_encode($criterion, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: ''))
            ->implode("\n");
        $this->workflowInputs = json_encode($session->workflow_inputs_json ?: new \stdClass, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '{}';
        $this->personId = (string) ($session->person_id ?: '');
        $this->executionTarget = (string) data_get($session->state_json, 'execution_target', 'system');

        $activeRun = $this->activeRun();
        if (! $this->synchronizeSelectionWithRunCursor($activeRun, true)) {
            $firstTask = $this->taskNavigation($workflow)->first();
            if ($firstTask) {
                $this->selectTask((int) $firstTask['step_id'], (string) $firstTask['task_key']);
            }
        }
    }

    public function setPermissionMode(): void
    {
        $this->perform('permission.changed', function (): array {
            $session = $this->session();
            app(WorkflowStudioControlService::class)->assertUserControl($session);
            app(WorkflowStudioAuthorizationService::class)->setPermissionMode(
                $session,
                $this->permissionMode,
                auth()->user(),
                $this->unrestrictedWarningAcknowledged,
            );

            return $this->result('ready', 'Copilot-Berechtigung wurde gespeichert.');
        });
    }

    public function unlockControlMode(): void
    {
        $this->perform('control.mode_unlocked', function (): array {
            app(WorkflowStudioControlService::class)->release($this->session());

            return $this->result('ready', 'Testmodus wurde entsperrt. Der Modus kann neu gewaehlt werden.');
        });
    }

    public function saveSessionDefinition(): void
    {
        $this->perform('session.saved', function (): array {
            $inputs = $this->decodeObject($this->workflowInputs, 'Workflow-Eingaben');
            $criteria = collect(preg_split('/\r\n|\r|\n/', $this->successCriteria) ?: [])
                ->map(fn (string $item): string => trim($item))->filter()->values()->all();
            $session = $this->session();
            app(WorkflowStudioControlService::class)->assertUserControl($session);
            $state = is_array($session->state_json) ? $session->state_json : [];
            $state['execution_target'] = $this->executionTarget;
            $session->forceFill([
                'goal' => trim($this->goal),
                'success_criteria_json' => $criteria,
                'workflow_inputs_json' => $inputs,
                'person_id' => $this->personId !== '' ? (int) $this->personId : null,
                'state_json' => $state,
                'last_activity_at' => now(),
            ])->save();

            return $this->result('ready', 'Ziel, Kriterien und Eingaben wurden gespeichert.');
        });
    }

    public function startRun(): void
    {
        $this->perform('run.started', function (): array {
            app(WorkflowStudioControlService::class)->assertUserControl($this->session());
            $run = $this->startInteractiveRun();
            app(WorkflowStudioControlService::class)->lock($this->session(), 'interactive', auth()->user());

            return $this->result((string) $run->status, 'Workflow-Test wurde gestartet.', $run);
        });
    }

    public function pauseRun(): void
    {
        $this->perform('run.pause_requested', function (): array {
            app(WorkflowStudioControlService::class)->assertUserControl($this->session());
            $run = $this->activeRunOrFail();
            $response = app(WorkflowExecutionService::class)->requestManualPause($run);

            return $this->result((string) ($run->fresh()?->status ?? $run->status), $response['message'], $run->fresh());
        });
    }

    public function resumeRun(): void
    {
        $this->perform('run.resumed', function (): array {
            app(WorkflowStudioControlService::class)->assertUserControl($this->session());
            $run = $this->activeRunOrFail();
            $this->rebasePausedRunRevision($run, 'resume_run');
            $response = app(WorkflowExecutionService::class)->resumeManualPause($run);

            return $this->result('running', $response['message'], $run->fresh());
        });
    }

    public function runSingleTask(): void
    {
        $this->perform('run.single_task', function (): array {
            app(WorkflowStudioControlService::class)->assertUserControl($this->session());
            if ($this->selectedStepId === '' || $this->selectedTaskKey === '') {
                throw new DomainException('Wählen Sie zuerst eine Task aus.');
            }

            $run = $this->activeRun();
            if (! $run || $this->isFinalRunStatus((string) $run->status)) {
                $run = $this->startInteractiveRun(
                    true,
                    (int) $this->selectedStepId,
                    $this->selectedTaskKey,
                );
                app(WorkflowStudioControlService::class)->lock($this->session(), 'interactive', auth()->user());

                return $this->result('running', 'Die ausgewählte Task wird einmal ausgeführt; danach pausiert der Lauf.', $run);
            }

            if ($run->status !== 'paused') {
                throw new DomainException('Die laufende Task muss zuerst beendet oder der Lauf pausiert werden.');
            }
            $this->rebasePausedRunRevision($run, 'single_task');
            $response = app(WorkflowExecutionService::class)->resumeManualPause(
                $run,
                $this->selectedStepId !== '' ? (int) $this->selectedStepId : null,
                $this->selectedTaskKey ?: null,
                true,
            );

            return $this->result('running', $response['message'], $run->fresh());
        });
    }

    public function stopRun(): void
    {
        $this->perform('run.stopped', function (): array {
            app(WorkflowStudioControlService::class)->assertUserControl($this->session());
            $run = $this->activeRunOrFail();
            $response = app(WorkflowExecutionService::class)->cancel($run);
            $this->session()->forceFill(['status' => 'stopped', 'finished_at' => now()])->save();

            return $this->result('cancelled', $response['message'], $run->fresh());
        });
    }

    public function terminateRun(): void
    {
        $this->perform('run.terminated', function (): array {
            app(WorkflowStudioControlService::class)->assertUserControl($this->session());
            $run = $this->activeRunOrFail();
            $response = app(WorkflowExecutionService::class)->terminate(
                $run,
                'Workflow-Test und alle zugeordneten Node-Prozesse wurden im Workflow Studio beendet.',
            );
            $this->session()->forceFill(['status' => 'stopped', 'finished_at' => now()])->save();

            return $this->result(
                (string) ($run->fresh()?->status ?? 'cancelled'),
                (string) $response['message'],
                $run->fresh(),
            );
        });
    }

    public function restartRun(): void
    {
        $this->perform('run.restarted', function (): array {
            $session = $this->session();
            app(WorkflowStudioControlService::class)->assertUserControl($session);

            if ($run = $this->activeRun()) {
                app(WorkflowExecutionService::class)->terminate(
                    $run,
                    'Workflow-Lauf und zugehoerige Node-Prozesse wurden fuer den Neustart beendet.',
                );
            }

            $this->activeRunId = null;
            $session->forceFill([
                'active_workflow_run_id' => null,
                'status' => 'draft',
                'finished_at' => null,
            ])->save();

            $newRun = $this->startInteractiveRun();
            app(WorkflowStudioControlService::class)->lock($this->session(), 'interactive', auth()->user());

            return $this->result((string) $newRun->status, 'Workflow-Test wurde neu gestartet.', $newRun);
        });
    }

    public function createCheckpoint(): void
    {
        $this->perform('checkpoint.created', function (): array {
            $session = $this->session();
            app(WorkflowStudioControlService::class)->assertUserControl($session);
            $run = $this->activeRunOrFail()->fresh();
            $this->assertCheckpointRunIsSafe($run, requirePaused: true);

            $checkpoint = app(WorkflowStudioCheckpointService::class)->create(
                $session,
                $run,
                $this->checkpointName,
            );
            $this->checkpointName = '';

            return $this->result(
                'paused',
                'Checkpoint „'.$checkpoint->name.'“ wurde dauerhaft gespeichert.',
                $run,
                checkpointId: (int) $checkpoint->getKey(),
            );
        });
    }

    public function restoreCheckpoint(int $checkpointId, ?string $confirmationId = null, ?int $targetRunId = null): void
    {
        $this->perform('checkpoint.restore_requested', function () use ($checkpointId, $confirmationId, $targetRunId): array {
            $session = $this->session();
            app(WorkflowStudioControlService::class)->assertUserControl($session);
            $checkpoint = $session->checkpoints()->with(['revision', 'run'])->findOrFail($checkpointId);
            $run = $targetRunId
                ? $session->runs()->findOrFail($targetRunId)
                : $this->activeRunOrFail();

            if ((int) $session->active_workflow_run_id !== (int) $run->getKey()) {
                throw new DomainException('Der zu bestätigende Ziel-Lauf ist nicht mehr der aktive Studio-Lauf.');
            }
            $this->assertCheckpointRunIsSafe($run);

            $parameters = $this->checkpointActionParameters($checkpoint, $run);
            $decision = app(WorkflowStudioAuthorizationService::class)->decide(
                $session,
                'checkpoint.restore',
                $parameters,
                $confirmationId,
            );
            if ($decision['requires_confirmation']) {
                $this->rememberPendingConfirmation([
                    'type' => 'checkpoint_restore',
                    'action' => 'checkpoint.restore',
                    'checkpoint_id' => (int) $checkpoint->getKey(),
                    'workflow_run_id' => (int) $run->getKey(),
                    'confirmation_id' => $decision['confirmation_id'],
                    'message' => 'Aktuellen Lauf auf Checkpoint „'.$checkpoint->name.'“ zurücksetzen?',
                ]);

                return $this->result('confirmation_required', (string) $decision['message'], $run, true, $decision['confirmation_id'], (int) $checkpoint->getKey());
            }

            $restored = app(WorkflowStudioCheckpointService::class)->restore($session, $checkpoint, $run);
            if ($confirmationId) {
                app(WorkflowStudioAuthorizationService::class)->consume($session->fresh(), $confirmationId);
            }
            $this->activeRunId = (int) $restored->getKey();
            $this->synchronizeSelectionWithRunCursor($restored, true);

            return $this->result('paused', 'Der aktuelle Lauf wurde auf „'.$checkpoint->name.'“ zurückgesetzt und bleibt pausiert.', $restored, checkpointId: (int) $checkpoint->getKey());
        });
    }

    public function branchFromCheckpoint(int $checkpointId, ?string $confirmationId = null): void
    {
        $this->perform('checkpoint.branch_requested', function () use ($checkpointId, $confirmationId): array {
            $session = $this->session();
            app(WorkflowStudioControlService::class)->assertUserControl($session);
            $checkpoint = $session->checkpoints()->with(['revision', 'run'])->findOrFail($checkpointId);
            if ($run = $this->activeRun()) {
                $this->assertCheckpointRunIsSafe($run);
            }

            $parameters = $this->checkpointActionParameters($checkpoint);
            $decision = app(WorkflowStudioAuthorizationService::class)->decide(
                $session,
                'checkpoint.branch',
                $parameters,
                $confirmationId,
            );
            if ($decision['requires_confirmation']) {
                $this->rememberPendingConfirmation([
                    'type' => 'checkpoint_branch',
                    'action' => 'checkpoint.branch',
                    'checkpoint_id' => (int) $checkpoint->getKey(),
                    'confirmation_id' => $decision['confirmation_id'],
                    'message' => 'Neuen pausierten Lauf ab Checkpoint „'.$checkpoint->name.'“ erstellen?',
                ]);

                return $this->result('confirmation_required', (string) $decision['message'], $this->activeRun(), true, $decision['confirmation_id'], (int) $checkpoint->getKey());
            }

            $branched = app(WorkflowStudioCheckpointService::class)->branch($session, $checkpoint);
            if ($confirmationId) {
                app(WorkflowStudioAuthorizationService::class)->consume($session->fresh(), $confirmationId);
            }
            $this->activeRunId = (int) $branched->getKey();
            $this->synchronizeSelectionWithRunCursor($branched, true);

            return $this->result((string) $branched->status, 'Neuer Lauf wurde ab „'.$checkpoint->name.'“ verzweigt.', $branched, checkpointId: (int) $checkpoint->getKey());
        });
    }

    public function runProbe(?string $confirmationId = null): void
    {
        $this->perform('probe.started', function () use ($confirmationId): array {
            app(WorkflowStudioControlService::class)->assertUserControl($this->session());
            $run = $this->activeRunOrFail();
            if ($run->status !== 'paused') {
                throw new DomainException('Probeaktionen sind ausschliesslich im pausierten Zustand zulaessig.');
            }
            $task = $this->probeTask();
            $parameters = ['action' => $this->probeAction, 'task' => $task];
            $decision = app(WorkflowStudioAuthorizationService::class)->decide(
                $this->session(),
                $this->probeAction,
                $parameters,
                $confirmationId,
            );
            if ($decision['requires_confirmation']) {
                $this->pendingConfirmation = [
                    'type' => 'probe',
                    'confirmation_id' => $decision['confirmation_id'],
                    'message' => $decision['message'],
                ];

                return $this->result('confirmation_required', $decision['message'], $run, true, $decision['confirmation_id']);
            }
            if ($confirmationId) {
                app(WorkflowStudioAuthorizationService::class)->consume($this->session(), $confirmationId);
            }
            $response = app(WorkflowExecutionService::class)->runManualProbe(
                $run,
                $task,
                $this->selectedStepId !== '' ? (int) $this->selectedStepId : null,
            );

            return $this->result('running', $response['message'], $run->fresh());
        });
    }

    public function confirmPendingAction(): void
    {
        $pending = $this->pendingConfirmation;
        $actionId = (string) ($pending['confirmation_id'] ?? '');
        if ($actionId === '') {
            return;
        }
        app(WorkflowStudioAuthorizationService::class)->confirm($this->session(), $actionId);
        $this->pendingConfirmation = [];
        if (($pending['type'] ?? null) === 'probe') {
            $this->runProbe($actionId);
        } elseif (in_array(($pending['type'] ?? null), ['copilot_task', 'copilot_plan'], true)) {
            $session = $this->session()->fresh();
            $state = is_array($session->state_json) ? $session->state_json : [];
            $state['pending_copilot_confirmation'] = null;
            $session->forceFill(['state_json' => $state, 'status' => 'running', 'last_activity_at' => now()])->save();
            $run = WorkflowRun::query()->find((int) ($pending['workflow_run_id'] ?? 0));
            if ($run && ($pending['type'] ?? null) === 'copilot_task') {
                $context = is_array($run->context_json) ? $run->context_json : [];
                $context['studio_authorization_confirmation_id'] = $actionId;
                unset($context['studio_authorization_hold']);
                $run->forceFill(['status' => 'running', 'context_json' => $context])->save();
            }
            $copilot = WorkflowCopilotSession::query()->find((int) ($pending['workflow_copilot_session_id'] ?? 0));
            if ($copilot && $copilot->status === WorkflowCopilotSession::STATUS_PAUSED) {
                app(WorkflowCopilotSessionService::class)->resume($copilot);
            }
            if ($run && ($pending['type'] ?? null) === 'copilot_task') {
                RunWorkflowJob::dispatch($run->getKey());
            }
            if ($copilot) {
                WorkflowCopilotSupervisorJob::dispatch($copilot->getKey());
            }
        }
    }

    public function discardPendingAction(): void
    {
        $this->pendingConfirmation = [];
        $this->lastActionResult = $this->result('discarded', 'Vorgeschlagene Aktion wurde verworfen.');
    }

    public function commitProbeAsTask(): void
    {
        $this->perform('probe.committed', function (): array {
            app(WorkflowStudioControlService::class)->assertUserControl($this->session());
            $run = $this->activeRunOrFail();
            $probeResult = data_get($run->context_json, 'studio_probe_result');
            $task = is_array(data_get($probeResult, 'task')) ? data_get($probeResult, 'task') : null;
            if (! $task) {
                throw new DomainException('Es liegt keine abgeschlossene Probe zur Uebernahme vor.');
            }
            $step = $this->workflow()->steps()->findOrFail((int) ($this->selectedStepId ?: $run->current_workflow_step_id));
            $task['key'] = $this->uniqueTaskKey($step->task_cards, (string) ($task['key'] ?? 'probe-task'));
            $session = $this->session();
            app(WorkflowStudioRevisionService::class)->apply(
                $session,
                (int) $this->workflow()->copilot_revision,
                'Erfolgreiche Browser-Probe als Task uebernommen.',
                fn () => app(WorkflowTaskOrderingService::class)->appendTask($step->fresh(), $task),
                'user:'.auth()->id(),
            );
            $this->selectTask((int) $step->getKey(), (string) $task['key']);

            return $this->result('draft', 'Probe wurde als neue Workflow-Revision uebernommen.', $run->fresh());
        });
    }

    public function selectTask(int $stepId, string $taskKey): void
    {
        $step = $this->workflow()->steps()->find($stepId);
        if (! $step) {
            return;
        }
        $task = collect($step->task_cards)->firstWhere('key', $taskKey);
        if ($taskKey !== '' && ! $task) {
            return;
        }
        $this->selectedStepId = (string) $stepId;
        $this->selectedTaskKey = $taskKey;
        $this->editingTaskJson = $task ? (json_encode($task, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '') : '';
    }

    public function selectPreviousTask(): void
    {
        $this->moveTaskSelection(-1);
    }

    public function selectNextTask(): void
    {
        $this->moveTaskSelection(1);
    }

    public function openBuilderForStep(int $stepId): void
    {
        app(WorkflowStudioControlService::class)->assertUserControl($this->session());
        $step = $this->workflow()->steps()->find($stepId);
        if (! $step) {
            return;
        }

        $this->selectedStepId = (string) $stepId;
        $this->openStudioPanel('builder');
        $this->dispatch('workflow-studio-builder-target', stepId: $stepId);
    }

    public function editTask(int $stepId, string $taskKey): void
    {
        app(WorkflowStudioControlService::class)->assertUserControl($this->session());
        $this->selectTask($stepId, $taskKey);
        $this->openStudioPanel('builder');
        $this->dispatch('open-workflow-studio-task-editor', stepId: $stepId, taskKey: $taskKey);
    }

    public function openStudioPanel(string $panel): void
    {
        $this->activeStudioPanel = in_array($panel, self::STUDIO_PANELS, true)
            ? $panel
            : '';
    }

    public function closeStudioPanel(): void
    {
        $this->activeStudioPanel = '';
    }

    public function openToolModal(string $tool): void
    {
        $this->activeToolModal = in_array($tool, self::TOOL_MODALS, true) ? $tool : '';
    }

    public function closeToolModal(): void
    {
        $this->activeToolModal = '';
    }

    public function selectTaskFromTool(int $stepId, string $taskKey): void
    {
        $this->selectTask($stepId, $taskKey);
        $this->closeToolModal();
    }

    public function editTaskFromTool(int $stepId, string $taskKey): void
    {
        $this->closeToolModal();
        $this->editTask($stepId, $taskKey);
    }

    public function openBuilderForStepFromTool(int $stepId): void
    {
        $this->closeToolModal();
        $this->openBuilderForStep($stepId);
    }

    public function editSelectedTask(): void
    {
        if ($this->selectedStepId === '' || $this->selectedTaskKey === '') {
            $this->addError('studio', 'Wählen Sie zuerst eine Task im Diagramm aus.');

            return;
        }

        $this->editTask((int) $this->selectedStepId, $this->selectedTaskKey);
    }

    #[On('workflow-studio-task-saved')]
    public function handleTaskSaved(int $stepId, string $taskKey): void
    {
        $this->selectTask($stepId, $taskKey);
        $this->lastActionResult = $this->result('draft', 'Task wurde gespeichert und eine neue Revision erstellt.');
        $this->dispatchStudioNotice($this->lastActionResult);
    }

    #[On('workflow-studio-definition-updated')]
    public function handleDefinitionUpdated(?int $stepId = null, ?string $taskKey = null, string $message = 'Workflow wurde aktualisiert.'): void
    {
        if ($stepId && filled($taskKey)) {
            $this->selectTask($stepId, (string) $taskKey);
        } else {
            $this->ensureSelectedTaskExists();
        }

        $this->lastActionResult = $this->result('draft', $message);
        $this->dispatchStudioNotice($this->lastActionResult);
    }

    public function saveSelectedTask(): void
    {
        $this->perform('task.saved', function (): array {
            app(WorkflowStudioControlService::class)->assertUserControl($this->session());
            $replacement = json_decode($this->editingTaskJson, true);
            if (! is_array($replacement) || trim((string) ($replacement['task_key'] ?? '')) === '') {
                throw new DomainException('Die Task-Definition muss gueltiges JSON mit task_key sein.');
            }
            $step = $this->workflow()->steps()->findOrFail((int) $this->selectedStepId);
            $taskKey = $this->selectedTaskKey;
            $replacement['key'] = $taskKey;
            app(WorkflowStudioRevisionService::class)->apply(
                $this->session(),
                (int) $this->workflow()->copilot_revision,
                'Task '.$taskKey.' im Workflow Studio bearbeitet.',
                function () use ($step, $taskKey, $replacement): void {
                    $config = is_array($step->config_json) ? $step->config_json : [];
                    $config['tasks'] = collect($step->task_cards)
                        ->map(fn (array $task): array => (string) ($task['key'] ?? '') === $taskKey ? $replacement : $task)
                        ->values()->all();
                    $step->forceFill(['config_json' => $config])->save();
                },
                'user:'.auth()->id(),
            );

            return $this->result('draft', 'Task wurde als neue Revision gespeichert.');
        });
    }

    public function startCopilot(): void
    {
        $this->perform('copilot.started', function (): array {
            $session = $this->session();
            app(WorkflowStudioControlService::class)->choose($session, 'autonomous', auth()->user());
            $criteria = collect(preg_split('/\r\n|\r|\n/', $this->successCriteria) ?: [])->map(fn (string $item): string => trim($item))->filter()->values()->all();
            $inputs = $this->decodeObject($this->workflowInputs, 'Workflow-Eingaben');
            $state = is_array($session->state_json) ? $session->state_json : [];
            $state['execution_target'] = 'system';
            $session->forceFill([
                'goal' => trim($this->goal),
                'success_criteria_json' => $criteria,
                'workflow_inputs_json' => $inputs,
                'person_id' => $this->personId !== '' ? (int) $this->personId : null,
                'state_json' => $state,
                'last_activity_at' => now(),
            ])->save();
            $active = $this->activeRun();
            if ($active && ! in_array($active->status, ['completed', 'failed', 'cancelled', 'timed_out', 'lost'], true)) {
                throw new DomainException('Beenden Sie den manuellen Lauf, bevor die autonome Optimierung gestartet wird.');
            }
            $launch = app(WorkflowCopilotLaunchService::class)->start(
                $this->workflow(),
                WorkflowCopilotLaunchRequest::fromArray([
                    'person_id' => $this->personId !== '' ? (int) $this->personId : null,
                    'goal' => trim($this->goal),
                    'success_criteria' => $criteria,
                    'workflow_inputs' => $inputs,
                    'permission_mode' => $session->permission_mode,
                    'source' => 'workflow-studio',
                    'budget' => [
                        ...WorkflowCopilotSessionService::DEFAULT_BUDGET,
                        'auto_execute_workflow_actions' => $session->permission_mode !== WorkflowCopilotPermissionMode::ASK_ALL->value,
                    ],
                ]),
            );
            $copilot = $launch['session'];
            $session = app(WorkflowStudioControlService::class)->lock($session, 'autonomous', auth()->user());
            $session->forceFill([
                'workflow_copilot_session_id' => $copilot->getKey(),
                'mode' => 'autonomous',
                'status' => 'running',
                'started_at' => now(),
            ])->save();

            return $this->result('running', $launch['initial_plan']
                ? 'Leerer Workflow wurde geplant, validiert und die Copilot-Optimierung gestartet.'
                : 'Copilot-Optimierung wurde nach erfolgreicher Workflow-Validierung gestartet.');
        });
    }

    public function pauseCopilot(): void
    {
        $this->perform('copilot.paused', function (): array {
            app(WorkflowStudioControlService::class)->assertUserControl($this->session());
            $copilot = $this->copilotSessionOrFail();
            app(WorkflowCopilotSessionService::class)->pause($copilot, 'Im Workflow Studio pausiert.');

            return $this->result('paused', 'Copilot-Optimierung wurde pausiert.');
        });
    }

    public function resumeCopilot(): void
    {
        $this->perform('copilot.resumed', function (): array {
            app(WorkflowStudioControlService::class)->assertUserControl($this->session());
            $copilot = app(WorkflowCopilotSessionService::class)->resume($this->copilotSessionOrFail());
            WorkflowCopilotSupervisorJob::dispatch((int) $copilot->getKey());

            return $this->result('running', 'Copilot-Optimierung wird fortgesetzt.');
        });
    }

    public function restartCopilot(): void
    {
        $this->perform('copilot.restarted', function (): array {
            app(WorkflowStudioControlService::class)->assertUserControl($this->session());
            $copilot = app(WorkflowCopilotSessionService::class)->restart(
                $this->copilotSessionOrFail(),
                'Vollstaendiger Neustart wurde im Workflow Studio angefordert.',
            );
            $this->session()->forceFill([
                'workflow_copilot_session_id' => $copilot->getKey(),
                'mode' => 'autonomous',
                'status' => 'running',
                'started_at' => now(),
                'finished_at' => null,
            ])->save();
            WorkflowCopilotSupervisorJob::dispatch((int) $copilot->getKey());

            return $this->result('running', 'Copilot-Optimierung und Testlauf wurden vollstaendig neu gestartet.');
        });
    }

    public function stopCopilot(): void
    {
        $this->perform('copilot.stopped', function (): array {
            app(WorkflowStudioControlService::class)->assertUserControl($this->session());
            $copilot = $this->copilotSessionOrFail();
            $copilot->loadMissing('activeRun');

            if ($copilot->activeRun) {
                app(WorkflowExecutionService::class)->cancel(
                    $copilot->activeRun,
                    'Workflow-Test wurde mit der Copilot-Sitzung gestoppt.',
                );
            }

            app(WorkflowCopilotSessionService::class)->stop($copilot, 'Im Workflow Studio gestoppt.');

            return $this->result('stopped', 'Copilot-Optimierung wurde gestoppt.');
        });
    }

    public function terminateCopilot(): void
    {
        $this->perform('copilot.terminated', function (): array {
            app(WorkflowStudioControlService::class)->assertUserControl($this->session());
            $copilot = $this->copilotSessionOrFail();
            $response = app(WorkflowExecutionService::class)->terminateCopilotRuns(
                $copilot,
                'Copilot-Optimierung und alle zugeordneten Node-Prozesse wurden im Workflow Studio beendet.',
            );

            app(WorkflowCopilotSessionService::class)->stop($copilot, 'Mit zugeordneten Node-Prozessen im Workflow Studio beendet.');
            $this->session()->forceFill(['status' => 'stopped', 'finished_at' => now()])->save();

            return $this->result('stopped', (string) $response['message']);
        });
    }

    public function openSelectorProbe(string $browserWindow): void
    {
        app(WorkflowStudioControlService::class)->assertUserControl($this->session());
        $this->probeBrowserWindow = trim($browserWindow) ?: 'main';
        $this->probeAction = 'selector.search';
        $this->showSelectorProbeModal = true;
    }

    public function updatedProbeAction(string $action): void
    {
        if ($action === 'probe.keypress' && ! in_array($this->probeValue, ['Enter', 'Tab'], true)) {
            $this->probeValue = 'Enter';
        }
    }

    public function refreshStudio(): void
    {
        $session = $this->session()->fresh();
        $this->activeRunId = $session?->active_workflow_run_id ? (int) $session->active_workflow_run_id : $this->activeRunId;
        $pending = data_get($session?->state_json, 'pending_copilot_confirmation');
        if ($this->pendingConfirmation === [] && is_array($pending) && filled($pending['confirmation_id'] ?? null)) {
            $this->pendingConfirmation = $pending;
        }
        $run = $this->activeRun();
        $this->synchronizeSelectionWithRunCursor($run);
        $this->ensureSelectedTaskExists();
        if ($session && $run && $session->status !== $run->status && $session->status !== 'confirmation_required') {
            $previousStatus = (string) $session->status;
            $session->forceFill([
                'status' => $run->status,
                'paused_at' => $run->status === 'paused' ? ($session->paused_at ?: now()) : null,
                'finished_at' => in_array($run->status, ['completed', 'failed', 'cancelled', 'timed_out', 'lost'], true) ? ($run->finished_at ?: now()) : null,
                'last_activity_at' => now(),
            ])->save();

            if (in_array((string) $run->status, ['paused', 'completed', 'failed', 'cancelled', 'timed_out', 'lost'], true)) {
                $message = match ((string) $run->status) {
                    'paused' => 'Der Workflow wurde am nächsten sicheren Punkt pausiert.',
                    'completed' => 'Der Workflow-Test wurde vollständig abgeschlossen.',
                    'failed' => 'Der Workflow-Test wurde wegen eines Fehlers angehalten.',
                    'cancelled' => 'Der Workflow-Test wurde gestoppt.',
                    'timed_out' => 'Der Workflow-Test wurde wegen einer Zeitüberschreitung angehalten.',
                    'lost' => 'Die Verbindung zum Workflow-Lauf wurde verloren.',
                    default => 'Der Workflow-Status wurde aktualisiert.',
                };
                $this->dispatchStudioNotice($this->result((string) $run->status, $message, $run), $previousStatus);
            }
        }
    }

    public function chooseControlMode(string $mode): void
    {
        $this->perform('control.mode_selected', function () use ($mode): array {
            $session = app(WorkflowStudioControlService::class)->choose($this->session(), $mode, auth()->user());
            $this->mode = $session->mode === 'autonomous' ? 'autonomous' : 'interactive';
            if ($this->mode === 'autonomous' && $this->activeWorkspaceTab === 'tools') {
                $this->activeWorkspaceTab = 'test';
            }

            return $this->result('ready', $this->mode === 'autonomous'
                ? 'Autonomer Modus ist vorbereitet und wird beim Start fest gesperrt.'
                : 'Interaktiver Testmodus ist vorbereitet und wird beim ersten Test fest gesperrt.');
        });
    }

    public function closeStudio(): void
    {
        if ($this->embedded) {
            $this->dispatch('workflow-studio-unpin-copilot');
            $this->dispatch('workflow-test-workbench-close');
        }
    }

    public function render()
    {
        $workflow = $this->workflow()->load('steps');
        $session = $this->session()->load('copilotSession');
        $run = $this->activeRun()?->load(['stepRuns.workflowStep', 'artifacts']);
        $copilotSession = $session->copilotSession;
        $taskNavigation = $this->taskNavigation($workflow);
        $selectedTaskIndex = $taskNavigation->search(fn (array $task): bool => (int) $task['step_id'] === (int) $this->selectedStepId
            && (string) $task['task_key'] === $this->selectedTaskKey
        );
        $selectedTaskIndex = $selectedTaskIndex === false ? null : (int) $selectedTaskIndex;

        // Poll-Takt an die tatsaechliche Aktivitaet koppeln: nur bei laufendem/
        // wartendem Lauf oder aktivem Copilot eng (2s) pollen, sonst traege (15s).
        // Ein offener Studio-Tab erzeugte bisher bedingungslos ~30 Requests/min
        // und leerte damit den PHP-FPM-Pool der Domain.
        $liveRunActive = $run && in_array((string) $run->status, ['queued', 'running', 'waiting', 'stop_requested', 'unreachable'], true);
        $copilotActive = $copilotSession && in_array((string) $copilotSession->status, ['running', 'repairing', 'verifying'], true);
        $studioPollSeconds = ($liveRunActive || $copilotActive) ? 2 : 15;

        $view = view('livewire.admin.network.workflow-studio', [
            'workflow' => $workflow,
            'session' => $session,
            'copilotSession' => $copilotSession,
            'copilotLatestEvent' => $copilotSession?->events()->latest('sequence')->first(),
            'run' => $run,
            'browserWindows' => $this->browserWindowCards($workflow, $run),
            'steps' => $workflow->steps,
            'events' => $session->events()->latest('sequence')->limit(40)->get()->reverse()->values(),
            'checkpoints' => $run?->checkpoints()->latest('sequence')->limit(30)->get() ?? collect(),
            'taskAttempts' => $run?->taskAttempts()->latest('id')->limit(30)->get() ?? collect(),
            'persons' => Person::query()
                ->select(['id', 'sort_order', 'person_first_name', 'person_last_name', 'person_alias', 'profile_label'])
                ->orderBy('sort_order')
                ->orderBy('id')
                ->limit(500)
                ->get(),
            'networkNodes' => NetworkNode::query()->available()->orderBy('name')->get(),
            'permissionModes' => WorkflowCopilotPermissionMode::cases(),
            'taskCount' => $taskNavigation->count(),
            'studioPollSeconds' => $studioPollSeconds,
            'selectedTaskNumber' => $selectedTaskIndex === null ? null : $selectedTaskIndex + 1,
            'hasPreviousTask' => $selectedTaskIndex !== null && $selectedTaskIndex > 0,
            'hasNextTask' => $selectedTaskIndex !== null && $selectedTaskIndex < $taskNavigation->count() - 1,
            'modeLocked' => (bool) $session->mode_locked_at,
            'autonomousMode' => $session->mode === 'autonomous',
        ]);

        return $this->embedded ? $view : $view->layout('layouts.master');
    }

    private function probeTask(): array
    {
        [$catalogKey, $overrides] = match ($this->probeAction) {
            'selector.search', 'selector.read' => ['browser.find_element', ['selector' => trim($this->probeSelector)]],
            'selector.highlight' => ['browser.highlight', ['selector' => trim($this->probeSelector)]],
            'probe.click' => ['browser.click', ['selector' => trim($this->probeSelector)]],
            'probe.fill' => ['input.fill_field', ['selector' => trim($this->probeSelector), 'value' => $this->probeValue, 'value_source' => 'fixed']],
            'probe.keypress' => ['browser.press_key', ['value' => trim($this->probeValue)]],
            'probe.submit' => ['input.submit', ['selector' => trim($this->probeSelector)]],
            'probe.wait' => ['wait.seconds', ['value' => max(1, (int) $this->probeValue)]],
            'probe.navigate' => ['browser.open_url', ['url' => trim($this->probeValue)]],
            'probe.screenshot', 'probe.dom_refresh' => ['wait.seconds', ['value' => 0]],
            default => throw new DomainException('Diese Probeaktion wird noch nicht unterstuetzt.'),
        };
        $overrides['key'] = 'studio-probe-'.Str::lower(Str::random(8));
        $overrides['title'] = 'Studio-Probe: '.$this->probeAction;
        $overrides['browser_window'] = trim($this->probeBrowserWindow) ?: 'main';

        return app(WorkflowTaskCatalog::class)->cardFromDefinition($catalogKey, $overrides);
    }

    private function perform(string $event, callable $action): void
    {
        $this->resetErrorBag();
        try {
            $this->lastActionResult = $action();
            app(WorkflowStudioSessionService::class)->appendEvent($this->session(), $event, $this->lastActionResult['message'] ?? $event, $this->lastActionResult);
            $this->dispatchStudioNotice($this->lastActionResult);
        } catch (Throwable $exception) {
            report($exception);
            $this->addError('studio', $exception->getMessage());
            $this->lastActionResult = $this->result('failed', $exception->getMessage());
            $this->dispatchStudioNotice($this->lastActionResult);
        }
    }

    private function dispatchStudioNotice(array $result, ?string $previousStatus = null): void
    {
        $status = (string) ($result['status'] ?? 'ready');
        $type = in_array($status, ['failed', 'timed_out', 'lost', 'unreachable'], true)
            ? 'error'
            : (in_array($status, ['completed', 'success', 'succeeded'], true) ? 'success' : 'info');

        $this->dispatch(
            'workflow-studio-notice',
            type: $type,
            message: (string) ($result['message'] ?? 'Workflow Studio wurde aktualisiert.'),
            status: $status,
            previousStatus: $previousStatus,
        );
    }

    private function result(
        string $status,
        string $message,
        ?WorkflowRun $run = null,
        bool $confirmationRequired = false,
        ?string $confirmationId = null,
    ): array {
        $run ??= $this->activeRun();

        return [
            'status' => $status,
            'cursor' => [
                'workflow_step_id' => $run?->current_workflow_step_id,
                'task_key' => data_get($run?->context_json, 'next_task_key'),
            ],
            'revision' => (int) $this->workflow()->copilot_revision,
            'confirmation_required' => $confirmationRequired,
            'confirmation_id' => $confirmationId,
            'message' => $message,
        ];
    }

    private function decodeObject(string $json, string $label): array
    {
        $decoded = json_decode(trim($json) ?: '{}', true);
        if (! is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw new DomainException($label.' muessen ein JSON-Objekt sein.');
        }

        return $decoded;
    }

    private function uniqueTaskKey(array $tasks, string $candidate): string
    {
        $candidate = Str::slug($candidate) ?: 'studio-task';
        $keys = collect($tasks)->pluck('key')->all();
        $suffix = 1;
        $key = $candidate;
        while (in_array($key, $keys, true)) {
            $key = $candidate.'-'.$suffix++;
        }

        return $key;
    }

    private function workflow(): Workflow
    {
        return Workflow::query()->with('steps')->findOrFail($this->workflowId);
    }

    private function session(): WorkflowStudioSession
    {
        return WorkflowStudioSession::query()->findOrFail($this->studioSessionId);
    }

    private function activeRun(): ?WorkflowRun
    {
        return $this->activeRunId ? WorkflowRun::query()->find($this->activeRunId) : $this->session()->activeRun;
    }

    private function activeRunOrFail(): WorkflowRun
    {
        return $this->activeRun() ?? throw new DomainException('Es ist kein Studio-Lauf aktiv.');
    }

    private function copilotSessionOrFail(): WorkflowCopilotSession
    {
        $session = $this->session();

        return $session->workflow_copilot_session_id
            ? WorkflowCopilotSession::query()->findOrFail($session->workflow_copilot_session_id)
            : throw new DomainException('Es ist keine Copilot-Optimierung mit diesem Studio verbunden.');
    }

    private function browserWindowCards(Workflow $workflow, ?WorkflowRun $run): array
    {
        $context = $run && is_array($run->context_json) ? $run->context_json : [];
        $runtimeWindows = data_get($context, 'browser_windows', []);
        $snapshots = $this->browserWindowSnapshots($run);

        if (! is_array($runtimeWindows) || $runtimeWindows === []) {
            $runtimeWindows = data_get($context, 'manual_pause_checkpoint.browser_windows', []);
        }
        $activeWindow = trim((string) ($context['activeBrowserWindow'] ?? $context['active_browser_window'] ?? 'main')) ?: 'main';

        $cards = collect(is_array($runtimeWindows) ? $runtimeWindows : [])
            ->filter(fn (mixed $window): bool => is_array($window))
            ->mapWithKeys(function (array $window, string|int $key) use ($activeWindow): array {
                $name = trim((string) ($window['key'] ?? $window['name'] ?? $key)) ?: 'main';

                return [$name => [
                    'name' => $name,
                    'title' => trim((string) ($window['title'] ?? '')),
                    'url' => trim((string) ($window['url'] ?? $window['currentUrl'] ?? '')),
                    'target_id' => trim((string) ($window['targetId'] ?? $window['target_id'] ?? '')),
                    'connected' => filled($window['targetId'] ?? $window['target_id'] ?? null),
                    'active' => $name === $activeWindow,
                    'runtime' => true,
                    'task_count' => 0,
                    'screenshot_url' => null,
                    'dom_url' => null,
                ]];
            });

        foreach ($workflow->steps as $step) {
            foreach ($step->task_cards as $task) {
                $name = trim((string) ($task['browser_window_name'] ?? $task['browser_window'] ?? ''));

                if ($name === '') {
                    continue;
                }

                $card = $cards->get($name, [
                    'name' => $name,
                    'title' => '',
                    'url' => '',
                    'target_id' => '',
                    'connected' => false,
                    'active' => $name === $activeWindow,
                    'runtime' => false,
                    'task_count' => 0,
                    'screenshot_url' => null,
                    'dom_url' => null,
                ]);
                $card['task_count']++;
                $cards->put($name, $card);
            }
        }

        foreach ($snapshots as $name => $snapshot) {
            $card = $cards->get($name, [
                'name' => $name,
                'title' => '',
                'url' => '',
                'target_id' => '',
                'connected' => false,
                'active' => $name === $activeWindow,
                'runtime' => true,
                'task_count' => 0,
                'screenshot_url' => null,
                'dom_url' => null,
            ]);
            $cards->put($name, array_replace($card, array_filter($snapshot, fn (mixed $value): bool => filled($value))));
        }

        if ($cards->isEmpty()) {
            $cards->put('main', [
                'name' => 'main',
                'title' => '',
                'url' => '',
                'target_id' => '',
                'connected' => false,
                'active' => true,
                'runtime' => false,
                'task_count' => 0,
                'screenshot_url' => null,
                'dom_url' => null,
            ]);
        }

        return $cards->values()->all();
    }

    private function browserWindowSnapshots(?WorkflowRun $run): array
    {
        if (! $run) {
            return [];
        }

        $snapshots = [];
        foreach ($run->stepRuns->sortByDesc('id') as $stepRun) {
            $result = is_array($stepRun->result_json) ? $stepRun->result_json : [];
            foreach ((array) data_get($result, 'browserWindows', []) as $key => $window) {
                if (! is_array($window)) {
                    continue;
                }

                $name = trim((string) ($window['key'] ?? $window['name'] ?? $key)) ?: 'main';
                $snapshots[$name] ??= [
                    'title' => trim((string) ($window['title'] ?? $window['label'] ?? '')),
                    'url' => trim((string) ($window['url'] ?? $window['currentUrl'] ?? '')),
                    'target_id' => trim((string) ($window['targetId'] ?? $window['target_id'] ?? '')),
                    'connected' => filled($window['targetId'] ?? $window['target_id'] ?? null),
                    'runtime' => true,
                    'screenshot_url' => $this->runtimePreviewUrl($window['screenshotUrl'] ?? null, $window['livePreviewRelativePath'] ?? null),
                    'dom_url' => $this->runtimePreviewUrl($window['debugDomUrl'] ?? null, $window['debugDomRelativePath'] ?? null),
                ];
            }

            if (! isset($snapshots['main']) && filled(data_get($result, 'screenshotUrl'))) {
                $snapshots['main'] = [
                    'title' => 'Browser',
                    'url' => trim((string) data_get($result, 'windowStatus.url', '')),
                    'target_id' => trim((string) data_get($result, 'windowStatus.targetId', '')),
                    'connected' => filled(data_get($result, 'windowStatus.targetId')),
                    'runtime' => true,
                    'screenshot_url' => trim((string) data_get($result, 'screenshotUrl')),
                    'dom_url' => trim((string) data_get($result, 'debugDomUrl')),
                ];
            }
        }

        foreach ($run->artifacts->sortByDesc('id') as $artifact) {
            $name = trim((string) $artifact->browser_window) ?: 'main';
            $snapshots[$name] ??= [
                'title' => trim((string) $artifact->title),
                'url' => trim((string) $artifact->current_url),
                'target_id' => '',
                'connected' => false,
                'runtime' => true,
                'screenshot_url' => null,
                'dom_url' => null,
            ];
            if ($artifact->status === 'success' && $artifact->artifact_type === 'screenshot' && blank($snapshots[$name]['screenshot_url'] ?? null)) {
                $snapshots[$name]['screenshot_url'] = route('workflow-run-artifacts.show', ['run' => $run, 'artifact' => $artifact]);
            }
            if ($artifact->status === 'success' && $artifact->artifact_type === 'dom' && blank($snapshots[$name]['dom_url'] ?? null)) {
                $snapshots[$name]['dom_url'] = route('workflow-run-artifacts.show', ['run' => $run, 'artifact' => $artifact]);
            }
        }

        return $snapshots;
    }

    private function runtimePreviewUrl(mixed $url, mixed $relativePath): ?string
    {
        $url = trim((string) $url);
        if ($url !== '') {
            return $url;
        }

        $relativePath = trim((string) $relativePath);
        if ($relativePath === '') {
            return null;
        }

        $absolutePath = storage_path('app/public/'.$relativePath);
        if (! File::exists($absolutePath)) {
            return null;
        }

        return Storage::disk('public')->url($relativePath).'?v='.File::lastModified($absolutePath);
    }

    private function startInteractiveRun(
        bool $singleTask = false,
        ?int $workflowStepId = null,
        ?string $taskKey = null,
    ): WorkflowRun {
        $session = $this->session()->load('activeRun');
        $active = $session->activeRun;
        if ($active && ! $this->isFinalRunStatus((string) $active->status)) {
            throw new DomainException('Die Studio-Sitzung besitzt bereits einen aktiven Lauf.');
        }

        $workflow = $this->workflow()->load('steps');
        $this->validateWorkflowDefinition($workflow, $singleTask ? 'single_task' : 'start_run');
        $inputs = $this->decodeObject($this->workflowInputs, 'Workflow-Eingaben');
        // Personenwahl auf der Sitzung persistieren, damit mount() sie nach
        // einem Reload wieder vorfindet (der Run-Context allein ueberlebt das nicht).
        $session->forceFill(['person_id' => $this->personId !== '' ? (int) $this->personId : null])->save();
        $context = [
            'workflow_studio_session_id' => $session->getKey(),
            'interactive_debug' => true,
            'workflow_inputs' => $inputs,
            'workflow_variables' => $inputs,
            'person_id' => $this->personId !== '' ? (int) $this->personId : null,
            'execution_target' => $this->executionTarget,
            'network_node_id' => $this->executionTarget === 'client_controller' && $this->networkNodeId !== ''
                ? (int) $this->networkNodeId
                : null,
        ];

        if ($singleTask) {
            $step = $workflow->steps->firstWhere('id', $workflowStepId);
            $taskKey = trim((string) $taskKey);
            $taskExists = $step && collect($step->task_cards)->contains(
                fn (array $task): bool => trim((string) ($task['key'] ?? '')) === $taskKey,
            );

            if (! $step || ! $step->is_enabled || ! $taskExists) {
                throw new DomainException('Die ausgewählte Task ist nicht ausführbar oder nicht mehr vorhanden.');
            }

            $context['studio_single_task'] = true;
            $context['next_step_action_key'] = (string) $step->action_key;
            $context['next_task_key'] = $taskKey;
        }

        $run = app(WorkflowExecutionService::class)->start($workflow, $context, 'workflow-studio');
        app(WorkflowStudioSessionService::class)->attachRun($session, $run);
        $this->activeRunId = (int) $run->getKey();

        if ($singleTask && $workflowStepId && filled($taskKey)) {
            $this->observedCursorSignature = $workflowStepId.'::'.$taskKey;
        }

        return $run;
    }

    private function isFinalRunStatus(string $status): bool
    {
        return in_array($status, ['completed', 'failed', 'cancelled', 'timed_out', 'lost'], true);
    }

    private function taskNavigation(?Workflow $workflow = null): \Illuminate\Support\Collection
    {
        $workflow ??= $this->workflow()->load('steps');
        $steps = $workflow->relationLoaded('steps')
            ? $workflow->steps->sortBy([['position', 'asc'], ['id', 'asc']])->values()
            : $workflow->steps()->ordered()->get();

        return $steps->flatMap(function ($step): array {
            return collect($step->task_cards)
                ->filter(fn (mixed $task): bool => is_array($task) && filled($task['key'] ?? null))
                ->values()
                ->map(fn (array $task, int $taskIndex): array => [
                    'step_id' => (int) $step->getKey(),
                    'step_name' => (string) $step->name,
                    'step_action_key' => (string) $step->action_key,
                    'step_enabled' => (bool) $step->is_enabled,
                    'task_index' => $taskIndex,
                    'task_key' => (string) $task['key'],
                    'task_title' => (string) ($task['title'] ?? $task['key']),
                ])->all();
        })->values();
    }

    private function moveTaskSelection(int $direction): void
    {
        $tasks = $this->taskNavigation();
        if ($tasks->isEmpty()) {
            return;
        }

        $currentIndex = $tasks->search(fn (array $task): bool => (int) $task['step_id'] === (int) $this->selectedStepId
            && (string) $task['task_key'] === $this->selectedTaskKey
        );
        $currentIndex = $currentIndex === false ? ($direction > 0 ? -1 : $tasks->count()) : (int) $currentIndex;
        $target = $tasks->get(max(0, min($tasks->count() - 1, $currentIndex + $direction)));

        if ($target) {
            $this->selectTask((int) $target['step_id'], (string) $target['task_key']);
        }
    }

    private function ensureSelectedTaskExists(): void
    {
        $tasks = $this->taskNavigation();
        $selectedExists = $tasks->contains(fn (array $task): bool => (int) $task['step_id'] === (int) $this->selectedStepId
            && (string) $task['task_key'] === $this->selectedTaskKey
        );

        if ($selectedExists) {
            return;
        }

        $first = $tasks->first();
        if ($first) {
            $this->selectTask((int) $first['step_id'], (string) $first['task_key']);
        } else {
            $this->selectedStepId = '';
            $this->selectedTaskKey = '';
            $this->editingTaskJson = '';
        }
    }

    private function synchronizeSelectionWithRunCursor(?WorkflowRun $run, bool $force = false): bool
    {
        if (! $run) {
            return false;
        }

        $taskKey = trim((string) data_get($run->context_json, 'next_task_key', ''));
        if ($taskKey === '') {
            return false;
        }

        $workflow = $this->workflow()->load('steps');
        $step = $workflow->steps->firstWhere('id', (int) $run->current_workflow_step_id);
        if (! $step || ! collect($step->task_cards)->contains(fn (array $task): bool => (string) ($task['key'] ?? '') === $taskKey)) {
            $step = $workflow->steps->first(fn ($candidate): bool => collect($candidate->task_cards)->contains(fn (array $task): bool => (string) ($task['key'] ?? '') === $taskKey)
            );
        }

        if (! $step) {
            return false;
        }

        $signature = $step->getKey().'::'.$taskKey;
        if ($force || $signature !== $this->observedCursorSignature) {
            $this->selectTask((int) $step->getKey(), $taskKey);
        }
        $this->observedCursorSignature = $signature;

        return true;
    }

    private function rebasePausedRunRevision(WorkflowRun $run, string $intent = 'resume_run'): void
    {
        $workflow = $this->workflow()->load('steps');
        $this->validateWorkflowDefinition($workflow, $intent);
        $currentRevision = (int) $workflow->copilot_revision;

        if ((int) $run->workflow_revision === $currentRevision) {
            return;
        }

        if ($run->status !== 'paused' || $run->stepRuns()->whereIn('status', ['running'])->exists()) {
            throw new DomainException('Die Workflow-Struktur wurde geändert. Der Lauf muss für die Übernahme der neuen Revision sicher pausiert sein.');
        }

        $context = is_array($run->context_json) ? $run->context_json : [];
        $history = collect(is_array($context['studio_revision_rebases'] ?? null) ? $context['studio_revision_rebases'] : [])
            ->push([
                'from_revision' => (int) $run->workflow_revision,
                'to_revision' => $currentRevision,
                'task_key' => $context['next_task_key'] ?? null,
                'rebased_at' => now()->toIso8601String(),
            ])
            ->take(-20)
            ->values()
            ->all();
        $context['studio_revision_rebases'] = $history;
        $run->forceFill([
            'workflow_revision' => $currentRevision,
            'context_json' => $context,
        ])->save();

        app(WorkflowStudioSessionService::class)->appendEvent(
            $this->session(),
            'run.rebased',
            'Pausierter Lauf wurde auf Revision '.$currentRevision.' aktualisiert und behält seinen Runtime-Zustand.',
            ['workflow_run_id' => (int) $run->getKey(), 'revision' => $currentRevision],
            'warning',
        );
    }

    private function validateWorkflowDefinition(Workflow $workflow, string $intent = 'start_run'): void
    {
        $criteria = collect(preg_split('/\r\n|\r|\n/', $this->successCriteria) ?: [])
            ->map(fn (string $criterion): string => trim($criterion))
            ->filter()
            ->values()
            ->all();
        $inputs = $this->decodeObject($this->workflowInputs, 'Workflow-Eingaben');

        app(WorkflowRetryRouteAutoRepairService::class)->repair($workflow);

        $this->guardMissingRouteTargets($workflow, $criteria, $inputs, $intent);

        app(WorkflowDefinitionValidator::class)->assertValid($workflow, $criteria, $inputs);
    }

    /**
     * Feature R1: Zeigen Verzweigungen auf geloeschte Karten oder Listen, bricht
     * der Start nicht mehr wortlos ab. Stattdessen wird der Bestaetigungsdialog
     * geoeffnet, der jede betroffene Route mit ihrer Standardroute zeigt.
     *
     * @param  array<int,string>  $criteria
     * @param  array<string,mixed>  $inputs
     */
    private function guardMissingRouteTargets(Workflow $workflow, array $criteria, array $inputs, string $intent): void
    {
        $findings = app(WorkflowRouteTargetAutoRepairService::class)->analyze($workflow);

        if ($findings === []) {
            $this->resetRouteRepairPrompt();

            return;
        }

        // Fehler, die die Reparatur nicht beseitigt, muessen sichtbar bleiben —
        // sonst verspricht der Dialog einen Start, der danach doch scheitert.
        $blocking = collect(app(WorkflowDefinitionValidator::class)->validate($workflow, $criteria, $inputs)['diagnostics'])
            ->where('severity', 'error')
            ->reject(fn (array $diagnostic): bool => in_array(
                (string) ($diagnostic['code'] ?? ''),
                ['route_task_missing', 'route_step_missing'],
                true,
            ))
            ->pluck('message')
            ->map(fn (mixed $message): string => (string) $message)
            ->unique()
            ->take(5)
            ->values()
            ->all();

        $this->routeRepairFindings = $findings;
        $this->routeRepairBlockingMessages = $blocking;
        $this->routeRepairIntent = $intent;
        $this->showRouteRepairModal = true;

        throw new DomainException($blocking === []
            ? count($findings).' Verzweigung(en) zeigen auf geloeschte Ziele. Bitte im Dialog entscheiden.'
            : count($findings).' Verzweigung(en) zeigen auf geloeschte Ziele; zusaetzlich blockieren weitere Fehler den Start.');
    }

    /**
     * Setzt die fehlenden Verzweigungen auf ihre Standardroute und fuehrt danach
     * genau die Aktion aus, die den Dialog ausgeloest hat.
     */
    public function applyRouteRepairAndStart(): void
    {
        $intent = $this->routeRepairIntent;
        $findings = $this->routeRepairFindings;
        $this->resetRouteRepairPrompt();

        if ($findings === []) {
            return;
        }

        try {
            app(WorkflowStudioControlService::class)->assertUserControl($this->session());
            $repaired = app(WorkflowRouteTargetAutoRepairService::class)->repair($this->workflow()->load('steps'));

            app(WorkflowStudioSessionService::class)->appendEvent(
                $this->session(),
                'workflow.route_targets_defaulted',
                count($repaired).' Verzweigung(en) ohne gueltiges Ziel wurden auf die Standardroute gesetzt.',
                ['repaired' => $repaired, 'intent' => $intent],
                'warning',
            );
        } catch (Throwable $exception) {
            report($exception);
            $this->addError('studio', $exception->getMessage());
            $this->lastActionResult = $this->result('failed', $exception->getMessage());
            $this->dispatchStudioNotice($this->lastActionResult);

            return;
        }

        match ($intent) {
            'single_task' => $this->runSingleTask(),
            'resume_run' => $this->resumeRun(),
            default => $this->startRun(),
        };
    }

    public function closeRouteRepairModal(): void
    {
        $this->resetRouteRepairPrompt();
    }

    private function resetRouteRepairPrompt(): void
    {
        $this->showRouteRepairModal = false;
        $this->routeRepairFindings = [];
        $this->routeRepairBlockingMessages = [];
        $this->routeRepairIntent = 'start_run';
    }
}
