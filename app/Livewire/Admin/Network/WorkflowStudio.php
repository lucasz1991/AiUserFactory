<?php

namespace App\Livewire\Admin\Network;

use App\Enums\WorkflowCopilotPermissionMode;
use App\Jobs\RunWorkflowJob;
use App\Jobs\WorkflowCopilotSupervisorJob;
use App\Models\NetworkNode;
use App\Models\Person;
use App\Models\Workflow;
use App\Models\WorkflowCopilotSession;
use App\Models\WorkflowRun;
use App\Models\WorkflowStudioCheckpoint;
use App\Models\WorkflowStudioSession;
use App\Services\Workflows\WorkflowCopilotSessionService;
use App\Services\Workflows\WorkflowExecutionService;
use App\Services\Workflows\WorkflowStudioAuthorizationService;
use App\Services\Workflows\WorkflowStudioCheckpointService;
use App\Services\Workflows\WorkflowStudioRevisionService;
use App\Services\Workflows\WorkflowStudioSessionService;
use App\Services\Workflows\WorkflowTaskCatalog;
use App\Services\Workflows\WorkflowTaskOrderingService;
use DomainException;
use Illuminate\Support\Str;
use Livewire\Attributes\On;
use Livewire\Component;
use Throwable;

class WorkflowStudio extends Component
{
    public int $workflowId;

    public int $studioSessionId;

    public ?int $activeRunId = null;

    public string $mode = 'manual';

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

    public string $selectedCheckpointId = '';

    public array $lastActionResult = [];

    public array $pendingConfirmation = [];

    public bool $showCopilotSettingsModal = false;

    public bool $showCheckpointsModal = false;

    public function mount(Workflow $workflow): void
    {
        $this->workflowId = (int) $workflow->getKey();
        $this->mode = in_array((string) request()->query('mode'), ['manual', 'assisted', 'autonomous'], true)
            ? (string) request()->query('mode')
            : 'manual';
        $requestedSessionId = (int) request()->query('session', 0);
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
        app(WorkflowStudioRevisionService::class)->ensureBaseline($session);

        $this->studioSessionId = (int) $session->getKey();
        $this->activeRunId = $session->active_workflow_run_id ? (int) $session->active_workflow_run_id : null;
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

        $firstStep = $workflow->steps()->orderBy('position')->first();
        if ($firstStep) {
            $this->selectTask((int) $firstStep->getKey(), (string) data_get($firstStep->task_cards, '0.key', ''));
        }
    }

    public function setPermissionMode(): void
    {
        $this->perform('permission.changed', function (): array {
            $session = $this->session();
            app(WorkflowStudioAuthorizationService::class)->setPermissionMode(
                $session,
                $this->permissionMode,
                auth()->user(),
                $this->unrestrictedWarningAcknowledged,
            );

            return $this->result('ready', 'Copilot-Berechtigung wurde gespeichert.');
        });
    }

    public function saveSessionDefinition(): void
    {
        $this->perform('session.saved', function (): array {
            $inputs = $this->decodeObject($this->workflowInputs, 'Workflow-Eingaben');
            $criteria = collect(preg_split('/\r\n|\r|\n/', $this->successCriteria) ?: [])
                ->map(fn (string $item): string => trim($item))->filter()->values()->all();
            $session = $this->session();
            $state = is_array($session->state_json) ? $session->state_json : [];
            $state['execution_target'] = $this->executionTarget;
            $session->forceFill([
                'mode' => $this->mode,
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
            $session = $this->session();
            $active = $session->activeRun;
            if ($active && ! in_array($active->status, ['completed', 'failed', 'cancelled', 'timed_out', 'lost'], true)) {
                throw new DomainException('Die Studio-Sitzung besitzt bereits einen aktiven Lauf.');
            }

            $inputs = $this->decodeObject($this->workflowInputs, 'Workflow-Eingaben');
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
            $run = app(WorkflowExecutionService::class)->start($this->workflow(), $context, 'workflow-studio');
            app(WorkflowStudioSessionService::class)->attachRun($session, $run);
            $this->activeRunId = (int) $run->getKey();

            return $this->result((string) $run->status, 'Workflow-Test wurde gestartet.', $run);
        });
    }

    public function pauseRun(): void
    {
        $this->perform('run.pause_requested', function (): array {
            $run = $this->activeRunOrFail();
            $response = app(WorkflowExecutionService::class)->requestManualPause($run);

            return $this->result((string) ($run->fresh()?->status ?? $run->status), $response['message'], $run->fresh());
        });
    }

    public function resumeRun(): void
    {
        $this->perform('run.resumed', function (): array {
            $run = $this->activeRunOrFail();
            $this->rebasePausedRunRevision($run);
            $response = app(WorkflowExecutionService::class)->resumeManualPause($run);

            return $this->result('running', $response['message'], $run->fresh());
        });
    }

    public function runSingleTask(): void
    {
        $this->perform('run.single_task', function (): array {
            $run = $this->activeRunOrFail();
            if ($run->status !== 'paused') {
                throw new DomainException('Einzel-Task ist nur bei pausiertem Lauf moeglich.');
            }
            $this->rebasePausedRunRevision($run);
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
            $run = $this->activeRunOrFail();
            $response = app(WorkflowExecutionService::class)->cancel($run);
            $this->session()->forceFill(['status' => 'stopped', 'finished_at' => now()])->save();

            return $this->result('cancelled', $response['message'], $run->fresh());
        });
    }

    public function restartRun(): void
    {
        $run = $this->activeRun();
        if ($run && ! in_array($run->status, ['completed', 'failed', 'cancelled', 'timed_out', 'lost'], true)) {
            app(WorkflowExecutionService::class)->cancel($run, 'Workflow-Lauf wurde fuer den Neustart beendet.');
        }
        $this->activeRunId = null;
        $this->session()->forceFill(['active_workflow_run_id' => null, 'status' => 'draft'])->save();
        $this->startRun();
    }

    public function runProbe(?string $confirmationId = null): void
    {
        $this->perform('probe.started', function () use ($confirmationId): array {
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
        $this->selectedStepId = (string) $stepId;
        $this->selectedTaskKey = $taskKey;
        $this->editingTaskJson = $task ? (json_encode($task, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '') : '';
    }

    public function editTask(int $stepId, string $taskKey): void
    {
        $this->selectTask($stepId, $taskKey);
        $this->dispatch('open-workflow-studio-task-editor', stepId: $stepId, taskKey: $taskKey);
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
    }

    public function saveSelectedTask(): void
    {
        $this->perform('task.saved', function (): array {
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

    public function createCheckpoint(): void
    {
        $this->perform('checkpoint.created', function (): array {
            $checkpoint = app(WorkflowStudioCheckpointService::class)->create(
                $this->session(),
                $this->activeRunOrFail(),
                $this->checkpointName,
            );
            $this->selectedCheckpointId = (string) $checkpoint->getKey();
            $this->checkpointName = '';

            return $this->result('checkpoint_created', 'Checkpoint wurde gespeichert.', $this->activeRun(), false, null, $checkpoint);
        });
    }

    public function restoreCheckpoint(): void
    {
        $this->perform('checkpoint.restored', function (): array {
            $checkpoint = $this->selectedCheckpointOrFail();
            $run = app(WorkflowStudioCheckpointService::class)->restore($this->session(), $checkpoint, $this->activeRunOrFail());

            return $this->result('paused', 'Checkpoint wurde in den aktuellen Lauf geladen.', $run, false, null, $checkpoint);
        });
    }

    public function branchFromCheckpoint(): void
    {
        $this->perform('checkpoint.branched', function (): array {
            $checkpoint = $this->selectedCheckpointOrFail();
            $run = app(WorkflowStudioCheckpointService::class)->branch($this->session(), $checkpoint);
            $this->activeRunId = (int) $run->getKey();

            return $this->result('paused', 'Neuer Lauf wurde vom Checkpoint abgezweigt.', $run, false, null, $checkpoint);
        });
    }

    public function startCopilot(): void
    {
        $this->perform('copilot.started', function (): array {
            $session = $this->session();
            $active = $this->activeRun();
            if ($active && ! in_array($active->status, ['completed', 'failed', 'cancelled', 'timed_out', 'lost'], true)) {
                throw new DomainException('Beenden Sie den manuellen Lauf, bevor die autonome Optimierung gestartet wird.');
            }
            $copilot = app(WorkflowCopilotSessionService::class)->start($this->workflow(), [
                'person_id' => $this->personId !== '' ? (int) $this->personId : null,
                'goal' => trim($this->goal),
                'success_criteria' => collect(preg_split('/\r\n|\r|\n/', $this->successCriteria) ?: [])->filter()->values()->all(),
                'workflow_inputs' => $this->decodeObject($this->workflowInputs, 'Workflow-Eingaben'),
                'budget' => [
                    ...WorkflowCopilotSessionService::DEFAULT_BUDGET,
                    'auto_execute_workflow_actions' => $session->permission_mode !== WorkflowCopilotPermissionMode::ASK_ALL->value,
                    'permission_mode' => $session->permission_mode,
                ],
            ]);
            $session->forceFill([
                'workflow_copilot_session_id' => $copilot->getKey(),
                'mode' => 'autonomous',
                'status' => 'running',
                'started_at' => now(),
            ])->save();
            WorkflowCopilotSupervisorJob::dispatch($copilot->getKey());

            return $this->result('running', 'Copilot-Optimierung wurde gestartet.');
        });
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
        if ($session && $run && $session->status !== $run->status && $session->status !== 'confirmation_required') {
            $session->forceFill([
                'status' => $run->status,
                'paused_at' => $run->status === 'paused' ? ($session->paused_at ?: now()) : null,
                'finished_at' => in_array($run->status, ['completed', 'failed', 'cancelled', 'timed_out', 'lost'], true) ? ($run->finished_at ?: now()) : null,
                'last_activity_at' => now(),
            ])->save();
        }
    }

    public function render()
    {
        $workflow = $this->workflow()->load(['steps', 'studioRevisions']);
        $session = $this->session()->load(['checkpoints.revision', 'events']);
        $run = $this->activeRun()?->load(['stepRuns.workflowStep', 'artifacts']);

        return view('livewire.admin.network.workflow-studio', [
            'workflow' => $workflow,
            'session' => $session,
            'run' => $run,
            'steps' => $workflow->steps,
            'checkpoints' => $session->checkpoints()->with('revision')->latest('sequence')->get(),
            'events' => $session->events()->latest('sequence')->limit(40)->get()->reverse()->values(),
            'persons' => Person::query()->orderBy('sort_order')->orderBy('id')->limit(500)->get(),
            'networkNodes' => NetworkNode::query()->available()->orderBy('name')->get(),
            'permissionModes' => WorkflowCopilotPermissionMode::cases(),
        ])->layout('layouts.master');
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
        } catch (Throwable $exception) {
            report($exception);
            $this->addError('studio', $exception->getMessage());
            $this->lastActionResult = $this->result('failed', $exception->getMessage());
        }
    }

    private function result(
        string $status,
        string $message,
        ?WorkflowRun $run = null,
        bool $confirmationRequired = false,
        ?string $confirmationId = null,
        ?WorkflowStudioCheckpoint $checkpoint = null,
    ): array {
        $run ??= $this->activeRun();

        return [
            'status' => $status,
            'cursor' => [
                'workflow_step_id' => $run?->current_workflow_step_id,
                'task_key' => data_get($run?->context_json, 'next_task_key'),
            ],
            'revision' => (int) $this->workflow()->copilot_revision,
            'checkpoint' => $checkpoint ? ['id' => (int) $checkpoint->getKey(), 'sequence' => (int) $checkpoint->sequence] : null,
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

    private function selectedCheckpointOrFail(): WorkflowStudioCheckpoint
    {
        if ($this->selectedCheckpointId === '') {
            throw new DomainException('Waehlen Sie zuerst einen Checkpoint aus.');
        }

        return $this->session()->checkpoints()->findOrFail((int) $this->selectedCheckpointId);
    }

    private function rebasePausedRunRevision(WorkflowRun $run): void
    {
        $currentRevision = (int) $this->workflow()->copilot_revision;

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
}
