<?php

namespace App\Services\Workflows;

use App\Models\ManagedProcess;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use App\Models\WorkflowStepRun;
use App\Services\Mail\MailAccountRegistrationRunner;
use App\Services\Processes\ManagedProcessInventory;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class WorkflowTaskRunner
{
    public function __construct(
        protected MailAccountRegistrationRunner $mailSettings,
    ) {}

    public function remoteRuntime(WorkflowRun $run, WorkflowStep $step, WorkflowStepRun $stepRun, array $runtimeContext = []): array
    {
        $settings = $this->mailSettings->settings();
        $timezone = $this->workflowTimezone($runtimeContext);
        $runId = (string) Str::uuid();
        $livePreviewEnabled = (bool) ($settings['live_preview_enabled'] ?? true);
        $livePreviewIntervalSeconds = max(1, min(60, (int) ($settings['live_preview_interval_seconds'] ?? 3)));

        return [
            'runId' => $runId,
            'processIdentity' => $this->processIdentity($runId, 'main', $run, $step, $stepRun),
            'workflow' => $runtimeContext,
            'workflowRunId' => $run->id,
            'workflowRunUuid' => $run->run_uuid,
            'workflowStepId' => $step->id,
            'workflowStepRunId' => $stepRun->id,
            'workflowStepName' => $step->name,
            'workflowStepType' => $step->type,
            'timezone' => $timezone,
            'timeZone' => $timezone,
            'tasks' => $this->runtimeTasks(
                $step,
                trim((string) ($runtimeContext['nextTaskKey'] ?? $runtimeContext['next_task_key'] ?? '')) ?: null,
                (bool) ($runtimeContext['copilotSupervised'] ?? $runtimeContext['copilot_supervised'] ?? false),
            ),
            'livePreviewEnabled' => $livePreviewEnabled,
            'livePreviewIntervalSeconds' => $livePreviewIntervalSeconds,
            'livePreviewIntervalMs' => $livePreviewIntervalSeconds * 1000,
            'livePreviewPollIntervalSeconds' => $livePreviewIntervalSeconds,
            'browserProfileKey' => $this->workflowBrowserProfileKey($run, $step, $runtimeContext),
            'browserEngine' => $settings['browser_engine'] ?? 'cloak-with-chrome-fallback',
            'cloakHumanizeEnabled' => (bool) ($settings['cloak_humanize_enabled'] ?? false),
            'cloakHumanPreset' => $settings['cloak_human_preset'] ?? '',
            'headlessEnabled' => (bool) ($settings['headless_enabled'] ?? false),
            'navigationTimeoutMs' => ((int) ($settings['navigation_timeout_seconds'] ?? 120)) * 1000,
            'observationTimeoutMs' => min(180000, max(30000, ((int) ($settings['observation_timeout_seconds'] ?? 60)) * 1000)),
            'scriptName' => 'run_step.cjs',
            'executionTarget' => 'client_controller',
            'devDebug' => $this->devDebugRuntimeConfig($run, $step, $stepRun, false),
        ];
    }

    public function start(WorkflowRun $run, WorkflowStep $step, WorkflowStepRun $stepRun, array $runtimeContext = []): array
    {
        $settings = $this->mailSettings->settings();
        $timezone = $this->workflowTimezone($runtimeContext);
        $runId = (string) Str::uuid();
        $livePreviewEnabled = (bool) ($settings['live_preview_enabled'] ?? true);
        $livePreviewIntervalSeconds = max(1, min(60, (int) ($settings['live_preview_interval_seconds'] ?? 3)));
        $runDirectory = $this->runDirectory($runId);
        $publicRunDirectory = storage_path('app/public/'.$this->publicRunRelativeDirectory($runId));
        $statusPath = $runDirectory.DIRECTORY_SEPARATOR.'status.json';
        $resultPath = $runDirectory.DIRECTORY_SEPARATOR.'result.json';
        $configPath = $runDirectory.DIRECTORY_SEPARATOR.'runtime.json';
        $stdoutPath = $runDirectory.DIRECTORY_SEPARATOR.'stdout.log';
        $stderrPath = $runDirectory.DIRECTORY_SEPARATOR.'stderr.log';

        File::ensureDirectoryExists($runDirectory);
        File::ensureDirectoryExists($publicRunDirectory);

        $tasks = $this->runtimeTasks(
            $step,
            trim((string) ($runtimeContext['nextTaskKey'] ?? $runtimeContext['next_task_key'] ?? '')) ?: null,
            (bool) ($runtimeContext['copilotSupervised'] ?? $runtimeContext['copilot_supervised'] ?? false),
        );

        $transientTask = $runtimeContext['copilotTransientTask'] ?? $runtimeContext['copilot_transient_task'] ?? null;

        if (is_array($transientTask) && $transientTask !== []) {
            $tasks = $this->expandRuntimeTasks(
                [$transientTask],
                [(int) $step->workflow_id],
            );
        }

        $runtime = [
            'runId' => $runId,
            'processIdentity' => $this->processIdentity($runId, 'main', $run, $step, $stepRun),
            'workflow' => $runtimeContext,
            'workflowRunId' => $run->id,
            'workflowRunUuid' => $run->run_uuid,
            'workflowStepId' => $step->id,
            'workflowStepRunId' => $stepRun->id,
            'workflowStepName' => $step->name,
            'workflowStepType' => $step->type,
            'timezone' => $timezone,
            'timeZone' => $timezone,
            'tasks' => $tasks,
            'statusPath' => $statusPath,
            'resultPath' => $resultPath,
            'runDirectory' => $runDirectory,
            'livePreviewEnabled' => $livePreviewEnabled,
            'livePreviewIntervalSeconds' => $livePreviewIntervalSeconds,
            'livePreviewIntervalMs' => $livePreviewIntervalSeconds * 1000,
            'livePreviewPollIntervalSeconds' => $livePreviewIntervalSeconds,
            'livePreviewPath' => $publicRunDirectory.DIRECTORY_SEPARATOR.'live.png',
            'livePreviewRelativePath' => $this->publicScreenshotRelativePath($runId),
            'browserProfileKey' => $this->workflowBrowserProfileKey($run, $step, $runtimeContext),
            'browserProfilePath' => $this->workflowBrowserProfilePath($run, $step, $runtimeContext),
            'browserEngine' => $settings['browser_engine'] ?? 'cloak-with-chrome-fallback',
            'cloakHumanizeEnabled' => (bool) ($settings['cloak_humanize_enabled'] ?? false),
            'cloakHumanPreset' => $settings['cloak_human_preset'] ?? '',
            'headlessEnabled' => (bool) ($settings['headless_enabled'] ?? false),
            'navigationTimeoutMs' => ((int) ($settings['navigation_timeout_seconds'] ?? 120)) * 1000,
            'observationTimeoutMs' => min(180000, max(30000, ((int) ($settings['observation_timeout_seconds'] ?? 60)) * 1000)),
            'scriptName' => 'run_step.cjs',
            'devDebug' => $this->devDebugRuntimeConfig($run, $step, $stepRun),
        ];

        $initialBrowserWindows = $this->browserWindowsFromRuntimeContext($runtimeContext);

        $this->writeJsonFile($statusPath, [
            'runId' => $runId,
            'workflow' => $this->publicRuntimeContext($runtimeContext),
            'processKey' => 'workflow-task:'.$runId.':main',
            'processIdentity' => $runtime['processIdentity'],
            'state' => 'queued',
            'stage' => 'queued',
            'message' => 'Workflow-Task-Lauf ist eingeplant.',
            'scriptName' => 'run_step.cjs',
            'livePreviewEnabled' => $livePreviewEnabled,
            'livePreviewIntervalSeconds' => $livePreviewIntervalSeconds,
            'livePreviewPollIntervalSeconds' => $livePreviewIntervalSeconds,
            'tasks' => $this->configuredTasks($tasks),
            'events' => [],
            'browserWindows' => $initialBrowserWindows,
            'at' => now()->toIso8601String(),
        ]);
        $this->writeJsonFile($configPath, $runtime);

        try {
            $pid = $this->spawnDetachedProcess([
                $this->resolveNodeBinary(),
                $this->resolveNodeScriptPath(),
                $configPath,
            ], base_path(), $stdoutPath, $stderrPath, $this->nodeProcessEnvironment($timezone));

            $status = $this->readJsonFile($statusPath) ?: [];
            $status['pid'] = $pid;
            $status['state'] = 'starting';
            $status['stage'] = 'process-started';
            $status['message'] = 'Workflow-Task-Prozess wurde gestartet.';
            $status['at'] = now()->toIso8601String();
            $this->writeJsonFile($statusPath, $status);
        } catch (\Throwable $exception) {
            $this->writeJsonFile($statusPath, [
                'runId' => $runId,
                'workflow' => $this->publicRuntimeContext($runtimeContext),
                'processKey' => 'workflow-task:'.$runId.':main',
                'processIdentity' => $runtime['processIdentity'],
                'state' => 'failed',
                'stage' => 'process-start-failed',
                'message' => $exception->getMessage(),
                'tasks' => $this->configuredTasks($tasks),
                'events' => [],
                'browserWindows' => $initialBrowserWindows,
                'at' => now()->toIso8601String(),
            ]);

            throw $exception;
        }

        return $this->readRun($runId) ?? [
            'runId' => $runId,
            'state' => 'starting',
            'stage' => 'process-started',
            'message' => 'Workflow-Task-Prozess wurde gestartet.',
        ];
    }

    public function readRun(?string $runId): ?array
    {
        $runId = trim((string) $runId);

        if ($runId === '') {
            return null;
        }

        $statusPath = $this->runDirectory($runId).DIRECTORY_SEPARATOR.'status.json';

        if (! File::exists($statusPath)) {
            return null;
        }

        $status = $this->readJsonFile($statusPath) ?: [];
        $result = $this->readResult($runId);

        if (is_array($result) && in_array((string) ($status['state'] ?? ''), ['queued', 'starting', 'running'], true)) {
            $status['state'] = ($result['ok'] ?? false) ? 'completed' : 'failed';
            $status['stage'] = $status['state'];
            $status['message'] = (string) ($result['statusMessage'] ?? $status['message'] ?? '');
            $this->writeJsonFile($statusPath, $status);
        }

        $status['runId'] = $runId;
        $status['isRunning'] = in_array((string) ($status['state'] ?? ''), ['queued', 'starting', 'running'], true);
        $status['livePreviewIntervalSeconds'] = (int) ($status['livePreviewIntervalSeconds'] ?? 3);
        $status['livePreviewPollIntervalSeconds'] = (int) ($status['livePreviewPollIntervalSeconds'] ?? $status['livePreviewIntervalSeconds']);
        $status['result'] = $result;

        return $this->publicRunStatus($status);
    }

    public function readResult(?string $runId): ?array
    {
        $runId = trim((string) $runId);

        if ($runId === '') {
            return null;
        }

        return $this->readJsonFile($this->runDirectory($runId).DIRECTORY_SEPARATOR.'result.json');
    }

    public function cancelRun(?string $runId, bool $force = true, string $message = 'Workflow-Task-Lauf wurde abgebrochen.'): array
    {
        $runId = trim((string) $runId);

        if ($runId === '') {
            return ['ok' => false, 'message' => 'Run-ID fehlt.'];
        }

        $statusPath = $this->runDirectory($runId).DIRECTORY_SEPARATOR.'status.json';
        $resultPath = $this->runDirectory($runId).DIRECTORY_SEPARATOR.'result.json';
        $status = $this->readJsonFile($statusPath) ?: [];
        $pid = (int) ($status['pid'] ?? 0);
        $browserWindows = $this->browserWindowsFromStatus($status);

        if ($pid > 1) {
            $this->stopProcess($pid, $force);
        }

        $result = [
            'ok' => false,
            'status' => 'cancelled',
            'statusMessage' => $message,
            'cancelledAt' => now()->toIso8601String(),
            'browserWindows' => $browserWindows,
        ];
        $events = is_array($status['events'] ?? null) ? $status['events'] : [];
        $events[] = [
            'at' => now()->toIso8601String(),
            'stage' => 'cancelled',
            'message' => $message,
            'pid' => $pid ?: null,
        ];

        $status = array_replace($status, [
            'runId' => $runId,
            'state' => 'cancelled',
            'stage' => 'cancelled',
            'message' => $message,
            'isRunning' => false,
            'cancelledAt' => now()->toIso8601String(),
            'at' => now()->toIso8601String(),
            'events' => array_slice($events, -80),
            'browserWindows' => $browserWindows,
        ]);

        $this->writeJsonFile($resultPath, $result);
        $this->writeJsonFile($statusPath, $status);

        return ['ok' => true, 'message' => $message, 'pid' => $pid ?: null];
    }

    public function closeRun(?string $runId, string $message = 'Workflow-Browser wurde nach Abschluss geschlossen.', bool $forceAfterGrace = true): array
    {
        $runId = trim((string) $runId);

        if ($runId === '') {
            return ['ok' => false, 'message' => 'Run-ID fehlt.'];
        }

        $statusPath = $this->runDirectory($runId).DIRECTORY_SEPARATOR.'status.json';
        $status = $this->readJsonFile($statusPath) ?: [];
        $pid = (int) ($status['pid'] ?? 0);
        $events = is_array($status['events'] ?? null) ? $status['events'] : [];
        $events[] = [
            'at' => now()->toIso8601String(),
            'stage' => 'workflow-browser-close-requested',
            'message' => $message,
            'pid' => $pid ?: null,
        ];

        $state = trim((string) ($status['state'] ?? $status['status'] ?? 'completed')) ?: 'completed';

        $status = array_replace($status, [
            'runId' => $runId,
            'state' => in_array($state, ['completed', 'failed', 'cancelled'], true) ? $state : 'completed',
            'stage' => 'workflow-browser-close-requested',
            'message' => $message,
            'isRunning' => false,
            'closedAt' => now()->toIso8601String(),
            'at' => now()->toIso8601String(),
            'events' => array_slice($events, -80),
        ]);

        $this->writeJsonFile($statusPath, $status);

        $terminated = false;

        if ($pid > 1) {
            $this->stopProcess($pid, false);
            usleep(3000000);
            $terminated = ! $this->workflowTaskRootIsRunning($runId, $pid);
        }

        if ($forceAfterGrace) {
            $familyTerminated = $this->terminateManagedProcessFamily($runId, true, $message);
            $terminated = $terminated || $familyTerminated;

            if (! $terminated && $pid > 1) {
                $this->stopProcess($pid, true);
                $terminated = true;
            }
        }

        return [
            'ok' => true,
            'message' => $message,
            'pid' => $pid ?: null,
            'terminated' => $terminated,
        ];
    }

    protected function browserWindowsFromStatus(array $status): array
    {
        foreach ([
            $status['browserWindows'] ?? null,
            data_get($status, 'result.browserWindows'),
            data_get($status, 'workflow.browserWindows'),
            data_get($status, 'workflow.browser_windows'),
        ] as $candidate) {
            if (! is_array($candidate) || $candidate === []) {
                continue;
            }

            return collect($candidate)
                ->map(function (mixed $window, int|string $key): ?array {
                    if (! is_array($window)) {
                        return null;
                    }

                    if (! array_key_exists('key', $window) && ! is_int($key)) {
                        $window['key'] = (string) $key;
                    }

                    return $window;
                })
                ->filter()
                ->values()
                ->all();
        }

        return [];
    }

    protected function browserWindowsFromRuntimeContext(array $runtimeContext): array
    {
        foreach ([
            $runtimeContext['browserWindows'] ?? null,
            $runtimeContext['browser_windows'] ?? null,
        ] as $candidate) {
            if (! is_array($candidate) || $candidate === []) {
                continue;
            }

            return collect($candidate)
                ->map(function (mixed $window, int|string $key): ?array {
                    if (! is_array($window)) {
                        return null;
                    }

                    if (! array_key_exists('key', $window) && ! is_int($key)) {
                        $window['key'] = (string) $key;
                    }

                    $windowKey = trim((string) ($window['key'] ?? $window['name'] ?? ''));

                    if ($windowKey === '') {
                        return null;
                    }

                    $window['key'] = $windowKey;
                    $window['label'] = trim((string) ($window['label'] ?? $windowKey)) ?: $windowKey;

                    return $window;
                })
                ->filter()
                ->values()
                ->all();
        }

        return [];
    }

    protected function configuredTasks(array $tasks): array
    {
        return collect($tasks)
            ->map(fn (array $task): array => array_replace($task, ['status' => 'configured']))
            ->values()
            ->toArray();
    }

    protected function runtimeTasks(WorkflowStep $step, ?string $startTaskKey = null, bool $singleTask = false): array
    {
        $tasks = $step->task_cards;

        if ($startTaskKey !== null) {
            $startIndex = collect($tasks)->search(
                fn (array $task): bool => (string) ($task['key'] ?? '') === $startTaskKey,
            );

            if ($startIndex === false) {
                throw new \RuntimeException('Die Ziel-Task fuer den Ruecksprung wurde nicht gefunden: '.$startTaskKey);
            }

            $tasks = array_slice($tasks, (int) $startIndex);
        }

        if ($singleTask) {
            $firstTask = $tasks[0] ?? null;

            if (is_array($firstTask) && (string) ($firstTask['task_key'] ?? '') === 'loop.for_each_element') {
                $endKey = trim((string) ($firstTask['loop_end_key'] ?? $firstTask['loopEndKey'] ?? ''));
                $pairId = trim((string) ($firstTask['loop_pair_id'] ?? $firstTask['loopPairId'] ?? ''));
                $endIndex = collect($tasks)->search(function (array $task) use ($endKey, $pairId): bool {
                    $taskKey = trim((string) ($task['key'] ?? ''));
                    $taskPairId = trim((string) ($task['loop_pair_id'] ?? $task['loopPairId'] ?? ''));

                    return ($endKey !== '' && $taskKey === $endKey)
                        || ($pairId !== ''
                            && $taskPairId === $pairId
                            && (string) ($task['task_key'] ?? '') === 'loop.end');
                });

                if ($endIndex === false) {
                    throw new \RuntimeException('Das gekoppelte Loop-Ende fuer den Copilot-Task wurde nicht gefunden.');
                }

                $tasks = array_slice($tasks, 0, (int) $endIndex + 1);
            } else {
                $tasks = array_slice($tasks, 0, 1);
            }
        }

        return $this->expandRuntimeTasks(
            $tasks,
            [(int) $step->workflow_id],
        );
    }

    protected function expandRuntimeTasks(
        array $tasks,
        array $workflowStack,
        ?string $parentTaskKey = null,
        string $keyPrefix = '',
        ?string $inheritedMailboxSource = null,
        ?string $embeddedWorkflowFrameKey = null,
        ?string $embeddedBrowserWindowName = null,
        array $embeddedWorkflowInputs = [],
        array $embeddedRouteMap = [],
        ?string $embeddedBoundaryTaskKey = null,
        ?string $sourceStepActionKey = null,
    ): array {
        $expanded = [];
        $embeddedBrowserWindowName = $this->normalizeBrowserWindowName($embeddedBrowserWindowName);

        foreach ($tasks as $task) {
            if (! is_array($task)) {
                continue;
            }

            if ((string) ($task['runner'] ?? '') !== 'workflow') {
                $runtimeTask = $this->normalizeRuntimeTask($task);
                $mailboxSource = $this->effectiveEmbeddedMailboxSource($runtimeTask, $inheritedMailboxSource);
                $browserWindow = $this->mappedEmbeddedBrowserWindowName($embeddedBrowserWindowName, $runtimeTask);

                if ($mailboxSource !== null) {
                    $runtimeTask['mailbox_source'] = $mailboxSource;
                    $runtimeTask['script_person_source'] = $mailboxSource;
                }

                if ($browserWindow !== null) {
                    $runtimeTask['browser_window'] = $browserWindow;
                    $runtimeTask['browser_window_name'] = $browserWindow;
                    $runtimeTask['embedded_workflow_browser_window'] = $embeddedBrowserWindowName;
                }

                if ($embeddedWorkflowInputs !== []) {
                    $runtimeTask['embedded_workflow_inputs'] = $embeddedWorkflowInputs;
                }

                if ($keyPrefix !== '') {
                    $originalKey = trim((string) ($runtimeTask['key'] ?? 'task')) ?: 'task';
                    $runtimeTask['key'] = Str::slug($keyPrefix.'-'.$originalKey);
                }

                $runtimeTask = $this->remapEmbeddedTaskRoutes(
                    $runtimeTask,
                    $embeddedRouteMap,
                    $embeddedBoundaryTaskKey,
                    $sourceStepActionKey,
                );

                if ($parentTaskKey !== null) {
                    $runtimeTask['parent_task_key'] = $parentTaskKey;
                }

                if ($embeddedWorkflowFrameKey !== null) {
                    $runtimeTask['embedded_workflow_frame_key'] = $embeddedWorkflowFrameKey;
                }

                if ($embeddedBoundaryTaskKey !== null) {
                    $runtimeTask['embedded_workflow_boundary_key'] = $embeddedBoundaryTaskKey;
                }

                $expanded[] = $runtimeTask;

                continue;
            }

            $workflowId = (int) ($task['workflow_id'] ?? 0);
            $taskKey = trim((string) ($task['key'] ?? 'workflow')) ?: 'workflow';
            $rootTaskKey = $parentTaskKey ?? $taskKey;
            $workflowMailboxSource = $this->effectiveEmbeddedMailboxSource($task, $inheritedMailboxSource);
            if ($workflowId <= 0) {
                throw new \RuntimeException('Die Workflow-Task "'.$taskKey.'" hat keine gueltige Workflow-Referenz.');
            }

            if (in_array($workflowId, $workflowStack, true)) {
                throw new \RuntimeException('Zyklische Workflow-Einbindung erkannt (Workflow #'.$workflowId.').');
            }

            $workflow = Workflow::query()
                ->with(['steps' => fn ($query) => $query->ordered()])
                ->find($workflowId);

            if (! $workflow) {
                throw new \RuntimeException('Der eingebettete Workflow #'.$workflowId.' wurde nicht gefunden.');
            }

            if (! $workflow->is_active) {
                throw new \RuntimeException('Der eingebettete Workflow "'.$workflow->name.'" ist deaktiviert.');
            }

            $workflowBrowserWindow = $this->embeddedWorkflowBrowserWindowName(
                $task,
                $embeddedBrowserWindowName,
                $workflow,
            );
            $workflowInputs = array_replace(
                $embeddedWorkflowInputs,
                $this->embeddedWorkflowInputs($task),
                ['browser_window' => ['literal' => $workflowBrowserWindow]],
            );

            $steps = $workflow->steps->where('is_enabled', true)->values();

            if ($steps->isEmpty()) {
                throw new \RuntimeException('Der eingebettete Workflow "'.$workflow->name.'" hat keine aktiven Listen.');
            }

            $workflowFrameKey = Str::slug(trim($keyPrefix.'-workflow-'.$workflow->id.'-'.$taskKey, '-'));
            $boundaryTaskKey = $workflowFrameKey.'-boundary';
            $nestedGroups = [];
            $workflowRouteMap = [
                'cards' => [],
                'first_tasks' => [],
                'next_steps' => [],
            ];

            foreach ($steps as $stepIndex => $nestedStep) {
                $nestedTasks = $nestedStep->task_cards;

                if ($nestedTasks === [] && $nestedStep->type === WorkflowStep::TYPE_WAIT) {
                    $nestedTasks = [app(WorkflowTaskCatalog::class)->cardFromDefinition('wait.seconds', [
                        'key' => 'warten',
                        'title' => $nestedStep->name,
                        'value' => max(0, (int) data_get($nestedStep->config_json, 'seconds', $nestedStep->wait_after_seconds)),
                    ])];
                }

                if ($nestedTasks === []) {
                    throw new \RuntimeException(
                        'Die Liste "'.$nestedStep->name.'" im eingebetteten Workflow "'.$workflow->name.'" enthaelt keine ausfuehrbare Task-Karte.'
                    );
                }

                if ($nestedStep->type !== WorkflowStep::TYPE_WAIT && $nestedStep->wait_after_seconds > 0) {
                    $nestedTasks[] = app(WorkflowTaskCatalog::class)->cardFromDefinition('wait.seconds', [
                        'key' => 'wartezeit-nach-liste',
                        'title' => 'Wartezeit nach '.$nestedStep->name,
                        'value' => $nestedStep->wait_after_seconds,
                    ]);
                }

                $nestedTasks = $this->applyEmbeddedStepRoutesToTasks($nestedStep, $nestedTasks);
                $nestedPrefix = trim($keyPrefix.'-workflow-'.$workflow->id.'-'.$taskKey.'-step-'.$nestedStep->id, '-');
                $nestedActionKey = trim((string) $nestedStep->action_key);
                $nextStep = $steps->get($stepIndex + 1);

                if ($nestedActionKey !== '' && $nextStep instanceof WorkflowStep) {
                    $workflowRouteMap['next_steps'][$nestedActionKey] = trim((string) $nextStep->action_key);
                }

                foreach ($nestedTasks as $nestedTaskIndex => $nestedTask) {
                    if (! is_array($nestedTask)) {
                        continue;
                    }

                    $originalKey = trim((string) ($nestedTask['key'] ?? 'task')) ?: 'task';
                    $runtimeKey = Str::slug($nestedPrefix.'-'.$originalKey);

                    if ($nestedTaskIndex === 0 && $nestedActionKey !== '') {
                        $workflowRouteMap['first_tasks'][$nestedActionKey] = $runtimeKey;
                    }

                    if ($nestedActionKey !== '') {
                        $workflowRouteMap['cards'][$nestedActionKey][$originalKey] = $runtimeKey;
                    }
                }

                $nestedGroups[] = [
                    'step' => $nestedStep,
                    'tasks' => $nestedTasks,
                    'prefix' => $nestedPrefix,
                    'action_key' => $nestedActionKey,
                ];
            }

            foreach ($nestedGroups as $nestedGroup) {
                $nestedExpanded = $this->expandRuntimeTasks(
                    $nestedGroup['tasks'],
                    [...$workflowStack, $workflowId],
                    $rootTaskKey,
                    $nestedGroup['prefix'],
                    $workflowMailboxSource,
                    $workflowFrameKey,
                    $workflowBrowserWindow,
                    $workflowInputs,
                    $workflowRouteMap,
                    $boundaryTaskKey,
                    $nestedGroup['action_key'],
                );

                foreach ($nestedExpanded as $nestedTask) {
                    $nestedTask['embedded_workflow_id'] ??= $workflow->id;
                    $nestedTask['embedded_workflow_name'] ??= $workflow->name;
                    $expanded[] = $nestedTask;
                }
            }

            $boundaryTask = [
                'key' => $boundaryTaskKey,
                'task_key' => 'workflow.boundary',
                'title' => $task['title'] ?? $workflow->name,
                'description' => 'Wartet auf den Abschluss des eingebetteten Workflows und wertet dessen Rueckgabewert aus.',
                'kind' => 'workflow',
                'runner' => 'workflow-boundary',
                'parent_task_key' => $rootTaskKey,
                'route_source_task_key' => $rootTaskKey,
                'embedded_workflow_id' => $workflow->id,
                'embedded_workflow_name' => $workflow->name,
                'embedded_workflow_frame_key' => $workflowFrameKey,
                'embedded_workflow_browser_window' => $workflowBrowserWindow,
                'embedded_workflow_inputs' => $workflowInputs,
            ];

            if ($embeddedWorkflowFrameKey !== null) {
                $boundaryTask['enclosing_embedded_workflow_frame_key'] = $embeddedWorkflowFrameKey;
            }

            if ($embeddedBoundaryTaskKey !== null) {
                $boundaryTask['enclosing_embedded_workflow_boundary_key'] = $embeddedBoundaryTaskKey;
            }

            foreach (['next', 'on_error'] as $routeKey) {
                if (is_array($task[$routeKey] ?? null)) {
                    $boundaryTask[$routeKey] = $this->remapEmbeddedRoute(
                        $task[$routeKey],
                        $embeddedRouteMap,
                        $embeddedBoundaryTaskKey,
                        $sourceStepActionKey,
                        $routeKey,
                    );
                }
            }

            $expanded[] = $boundaryTask;
        }

        return $expanded;
    }

    protected function applyEmbeddedStepRoutesToTasks(WorkflowStep $step, array $tasks): array
    {
        $tasks = collect($tasks)
            ->filter(fn (mixed $task): bool => is_array($task))
            ->values()
            ->toArray();

        if ($tasks === []) {
            return [];
        }

        $routes = is_array($step->config_json) && is_array($step->config_json['routes'] ?? null)
            ? $step->config_json['routes']
            : [];
        $lastIndex = array_key_last($tasks);

        if (is_array($routes['success'] ?? null) && ! is_array($tasks[$lastIndex]['next'] ?? null)) {
            $tasks[$lastIndex]['next'] = $routes['success'];
        }

        if (is_array($routes['failed'] ?? null)) {
            foreach ($tasks as &$task) {
                if (! is_array($task['on_error'] ?? null)) {
                    $task['on_error'] = $routes['failed'];
                }
            }
            unset($task);
        }

        return $tasks;
    }

    protected function remapEmbeddedTaskRoutes(
        array $task,
        array $routeMap,
        ?string $boundaryTaskKey,
        ?string $sourceStepActionKey,
    ): array {
        foreach (['next', 'on_error', 'on_partial'] as $routeKey) {
            if (is_array($task[$routeKey] ?? null)) {
                $task[$routeKey] = $this->remapEmbeddedRoute(
                    $task[$routeKey],
                    $routeMap,
                    $boundaryTaskKey,
                    $sourceStepActionKey,
                    $routeKey,
                );
            }
        }

        if (is_array($task['status_routes'] ?? null)) {
            foreach ($task['status_routes'] as $outcome => $route) {
                if (is_array($route)) {
                    $task['status_routes'][$outcome] = $this->remapEmbeddedRoute(
                        $route,
                        $routeMap,
                        $boundaryTaskKey,
                        $sourceStepActionKey,
                        (string) $outcome,
                    );
                }
            }
        }

        return $task;
    }

    protected function remapEmbeddedRoute(
        array $route,
        array $routeMap,
        ?string $boundaryTaskKey,
        ?string $sourceStepActionKey,
        string $routeKey,
    ): array {
        if ($routeMap === []) {
            return $route;
        }

        $type = strtolower(trim((string) ($route['type'] ?? '')));
        $step = trim((string) ($route['action_key'] ?? $route['step'] ?? ''));
        $reservedStep = strtolower($step);
        $card = trim((string) ($route['card_key'] ?? $route['card'] ?? ''));
        $targetTaskKey = null;
        $hasExplicitTarget = $card !== ''
            || ($step !== '' && ! in_array($reservedStep, ['next', 'end', 'fail'], true))
            || $type === 'card';

        if ($type === 'end' || $reservedStep === 'end') {
            $targetTaskKey = $boundaryTaskKey;
        } elseif ($reservedStep === 'next') {
            $nextStep = trim((string) ($routeMap['next_steps'][$sourceStepActionKey ?? ''] ?? ''));
            $targetTaskKey = $nextStep !== ''
                ? ($routeMap['first_tasks'][$nextStep] ?? null)
                : $boundaryTaskKey;
        } elseif ($card !== '') {
            $targetStep = ($step !== '' && ! in_array($reservedStep, ['next', 'end', 'fail'], true))
                ? $step
                : (string) $sourceStepActionKey;
            $targetTaskKey = $routeMap['cards'][$targetStep][$card] ?? null;
        } elseif ($step !== '' && ! in_array($reservedStep, ['end', 'fail'], true)) {
            $targetTaskKey = $routeMap['first_tasks'][$step] ?? null;
        }

        if ($targetTaskKey === null || trim((string) $targetTaskKey) === '') {
            if (
                $routeKey === 'next'
                && $boundaryTaskKey !== null
                && ! $hasExplicitTarget
                && ! in_array($reservedStep, ['fail'], true)
                && $type !== 'fail'
            ) {
                $targetTaskKey = $boundaryTaskKey;
            } else {
                return $route;
            }
        }

        $route['type'] = 'card';
        $route['card_key'] = (string) $targetTaskKey;
        $route['card'] = (string) $targetTaskKey;
        unset($route['action_key'], $route['step']);

        return $route;
    }

    protected function mappedEmbeddedBrowserWindowName(?string $embeddedBrowserWindowName, array $task): ?string
    {
        $embeddedBrowserWindowName = $this->normalizeBrowserWindowName($embeddedBrowserWindowName);
        $taskBrowserWindow = $this->normalizeBrowserWindowName(
            $task['browser_window_name']
            ?? $task['browser_window']
            ?? $task['browserWindowName']
            ?? $task['browserWindow']
            ?? null,
        );

        if ($embeddedBrowserWindowName === null) {
            return $taskBrowserWindow;
        }

        if ($taskBrowserWindow === null || $taskBrowserWindow === 'main') {
            return $embeddedBrowserWindowName;
        }

        if ($taskBrowserWindow === $embeddedBrowserWindowName || str_starts_with($taskBrowserWindow, $embeddedBrowserWindowName.'-')) {
            return $taskBrowserWindow;
        }

        return $this->normalizeBrowserWindowName($embeddedBrowserWindowName.'-'.$taskBrowserWindow);
    }

    protected function embeddedWorkflowInputs(array $task): array
    {
        $configured = $task['workflow_input_variables']
            ?? $task['workflowInputVariables']
            ?? $task['embedded_workflow_inputs']
            ?? $task['embeddedWorkflowInputs']
            ?? [];

        if (is_string($configured)) {
            $raw = trim($configured);

            if ($raw === '') {
                return [];
            }

            $configured = json_decode($raw, true);

            if (! is_array($configured)) {
                throw new \RuntimeException('Die Variablen-Zuordnung des eingebetteten Workflows ist kein gueltiges JSON-Objekt.');
            }
        }

        if (! is_array($configured)) {
            return [];
        }

        if ($configured !== [] && array_is_list($configured)) {
            $mapped = [];

            foreach ($configured as $definition) {
                if (! is_array($definition)) {
                    continue;
                }

                $name = trim((string) ($definition['name'] ?? $definition['key'] ?? ''));

                if ($name === '') {
                    continue;
                }

                $mapped[$name] = array_key_exists('source', $definition)
                    ? ['source' => $definition['source'], 'default' => $definition['default'] ?? null]
                    : ['literal' => $definition['value'] ?? $definition['literal'] ?? null];
            }

            return $mapped;
        }

        return $configured;
    }

    protected function embeddedWorkflowBrowserWindowName(array $task, ?string $parentBrowserWindowName, Workflow $workflow): string
    {
        $parentBrowserWindowName = $this->normalizeBrowserWindowName($parentBrowserWindowName);
        $configuredBrowserWindow = $this->normalizeBrowserWindowName(
            $task['embedded_workflow_browser_window']
            ?? $task['embeddedWorkflowBrowserWindow']
            ?? $task['browser_window_after_embedding']
            ?? $task['browserWindowAfterEmbedding']
            ?? $task['browser_window_name']
            ?? $task['browser_window']
            ?? $task['browserWindowName']
            ?? $task['browserWindow']
            ?? null,
        );

        if ($parentBrowserWindowName !== null) {
            return $this->mappedEmbeddedBrowserWindowName($parentBrowserWindowName, [
                'browser_window_name' => $configuredBrowserWindow,
                'browser_window' => $configuredBrowserWindow,
            ]) ?? $parentBrowserWindowName;
        }

        if ($configuredBrowserWindow !== null) {
            return $configuredBrowserWindow;
        }

        return $this->fallbackEmbeddedWorkflowBrowserWindowName($task, $workflow);
    }

    protected function fallbackEmbeddedWorkflowBrowserWindowName(array $task, Workflow $workflow): string
    {
        $base = trim((string) ($workflow->slug ?: $workflow->name ?: ($task['key'] ?? 'workflow')));
        $name = $this->normalizeBrowserWindowName('workflow-'.$base);

        if ($name !== null && $name !== 'main') {
            return $name;
        }

        $taskKey = $this->normalizeBrowserWindowName((string) ($task['key'] ?? $task['task_key'] ?? 'workflow'));

        return ($taskKey !== null && $taskKey !== 'main')
            ? $taskKey
            : 'embedded-workflow';
    }

    protected function normalizeBrowserWindowName(mixed $value): ?string
    {
        $name = trim((string) $value);

        if ($name === '') {
            return null;
        }

        $name = preg_replace('/\s+/', '-', $name) ?? '';
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '', $name) ?? '';
        $name = strtolower(substr($name, 0, 80));

        return $name !== '' ? $name : null;
    }

    protected function normalizeMailboxSource(mixed $value): ?string
    {
        $value = strtolower(trim((string) $value));

        if ($value === '') {
            return null;
        }

        return in_array($value, ['verification', 'verification_mailbox', 'veri-account', 'veri_account', 'main', 'master'], true)
            ? 'verification'
            : 'person';
    }

    protected function effectiveEmbeddedMailboxSource(array $task, ?string $inheritedMailboxSource = null): ?string
    {
        $taskMailboxSource = $this->normalizeMailboxSource(
            $task['script_person_source']
            ?? $task['scriptPersonSource']
            ?? $task['mailbox_source']
            ?? $task['mailboxSource']
            ?? null,
        );
        $inheritedMailboxSource = $this->normalizeMailboxSource($inheritedMailboxSource);

        if ($taskMailboxSource === 'verification') {
            return 'verification';
        }

        return $inheritedMailboxSource ?? $taskMailboxSource;
    }

    protected function workflowTimezone(array $runtimeContext = []): string
    {
        return $this->validTimezone(
            data_get($runtimeContext, 'timezone')
            ?: data_get($runtimeContext, 'timeZone')
            ?: data_get($runtimeContext, 'person.timezone')
            ?: data_get($runtimeContext, 'person.person_timezone')
            ?: (getenv('APP_TIMEZONE') ?: null)
            ?: (getenv('TZ') ?: null)
            ?: config('app.timezone', 'Europe/Berlin'),
        );
    }

    protected function validTimezone(mixed $timezone): string
    {
        $timezone = trim((string) $timezone);

        if ($timezone !== '') {
            try {
                new \DateTimeZone($timezone);

                return $timezone;
            } catch (\Throwable) {
                // Fall through to the stable default.
            }
        }

        return 'Europe/Berlin';
    }

    protected function nodeProcessEnvironment(string $timezone): array
    {
        return [
            'TZ' => $this->validTimezone($timezone),
            'APP_TIMEZONE' => $this->validTimezone($timezone),
        ];
    }

    protected function normalizeRuntimeTask(array $task): array
    {
        $script = match ((string) ($task['task_key'] ?? '')) {
            'browser.hover' => 'node/workflows/tasks/browser/hover.cjs',
            'browser.scroll' => 'node/workflows/tasks/browser/scroll.cjs',
            'browser.open_browser_session' => 'node/workflows/tasks/browser/open_browser_session.cjs',
            'loop.for_each_element' => 'node/workflows/tasks/loop/for_each_element.cjs',
            'loop.end' => 'node/workflows/tasks/loop/end.cjs',
            'browser.read_element_fields' => 'node/workflows/tasks/browser/read_element_fields.cjs',
            'browser.read_searchengine_result' => 'node/workflows/tasks/browser/read_searchengine_result.cjs',
            'data.append_to_array' => 'node/workflows/tasks/data/append_to_array.cjs',
            'decision.array_length' => 'node/workflows/tasks/decision/array_length.cjs',
            'data.validate_inputs' => 'node/workflows/tasks/data/validate_inputs.cjs',
            'data.read_account_data' => 'node/workflows/tasks/data/read_account_data.cjs',
            'data.resolve_person' => 'node/workflows/tasks/data/resolve_person.cjs',
            'data.read_login_data' => 'node/workflows/tasks/data/read_login_data.cjs',
            'data.save_workflow_data' => 'node/workflows/tasks/data/save_workflow_data.cjs',
            'data.persist_mail_account' => 'node/workflows/tasks/data/persist_mail_account.cjs',
            'data.persist_webmail_session' => 'node/workflows/tasks/data/persist_webmail_session.cjs',
            'data.persist_browser_session' => 'node/workflows/tasks/data/persist_browser_session.cjs',
            'data.delete_browser_session' => 'node/workflows/tasks/data/delete_browser_session.cjs',
            'data.workflow_return' => 'node/workflows/tasks/data/workflow_return.cjs',
            default => null,
        };

        if ($script === null) {
            return $task;
        }

        $task['runner'] = 'node';
        $task['node_script'] = $script;

        return $task;
    }

    protected function runDirectory(string $runId): string
    {
        return storage_path('app/workflow-task-runs/'.$runId);
    }

    protected function workflowBrowserProfilePath(WorkflowRun $run, WorkflowStep $step, array $runtimeContext = []): string
    {
        return storage_path('app/browser-profiles/workflows/'.$this->workflowBrowserProfileKey($run, $step, $runtimeContext));
    }

    protected function workflowBrowserProfileKey(WorkflowRun $run, WorkflowStep $step, array $runtimeContext = []): string
    {
        $settings = is_array($run->workflow?->settings_json) ? $run->workflow->settings_json : [];

        if (array_key_exists('persistent_browser_profile', $settings)
            && ! filter_var($settings['persistent_browser_profile'], FILTER_VALIDATE_BOOL)) {
            return 'run-'.Str::lower((string) $run->run_uuid);
        }

        $mailboxSource = collect(data_get($step->config_json, 'tasks', []))
            ->first(fn (mixed $task): bool => is_array($task) && in_array((string) ($task['task_key'] ?? ''), [
                'browser.open_webmail_session',
                'data.persist_webmail_session',
            ], true));
        $mailboxSource = is_array($mailboxSource)
            ? strtolower(trim((string) ($mailboxSource['script_person_source'] ?? $mailboxSource['mailbox_source'] ?? 'person')))
            : 'person';
        $account = in_array($mailboxSource, ['verification', 'verification_mailbox', 'veri-account', 'veri_account', 'main', 'master'], true)
            ? data_get($runtimeContext, 'verificationMailbox', data_get($runtimeContext, 'verification_mailbox', []))
            : data_get($runtimeContext, 'account', []);
        $identity = strtolower(trim((string) data_get($account, 'email', data_get($account, 'username', ''))));

        if ($identity !== '') {
            return 'mailbox-'.substr(hash('sha256', $identity), 0, 24);
        }

        $personId = (int) (data_get($runtimeContext, 'personId') ?: data_get($run->context_json, 'person_id'));

        return $personId > 0
            ? 'person-'.$personId
            : 'workflow-'.((int) $run->workflow_id ?: 'anonymous');
    }

    protected function devDebugRuntimeConfig(WorkflowRun $run, WorkflowStep $step, WorkflowStepRun $stepRun, bool $localArtifacts = true): array
    {
        $settings = is_array($run->workflow?->settings_json) ? $run->workflow->settings_json : [];
        $copilotObservation = (int) $run->workflow_copilot_session_id > 0
            || (int) data_get($run->context_json, 'workflow_copilot_session_id', 0) > 0;
        $enabled = $localArtifacts && (
            $copilotObservation
            || filter_var($settings['dev_mode'] ?? false, FILTER_VALIDATE_BOOL)
            || filter_var($settings['development'] ?? false, FILTER_VALIDATE_BOOL)
        );

        if (! $localArtifacts) {
            return [
                'enabled' => false,
                'dev_mode' => false,
                'status' => trim((string) ($settings['dev_status'] ?? '')),
            ];
        }

        $relativeDirectory = 'workflow-runs/'.$run->run_uuid.'/debug-artifacts/step-'.$stepRun->id;

        return [
            'enabled' => $enabled,
            'dev_mode' => $enabled,
            'copilotObservation' => $copilotObservation,
            'captureDomBeforeStep' => $copilotObservation || (bool) ($settings['dev_capture_dom_before_step'] ?? true),
            'captureDomAfterStep' => $copilotObservation || (bool) ($settings['dev_capture_dom_after_step'] ?? true),
            'captureScreenshotBeforeStep' => $copilotObservation || (bool) ($settings['dev_capture_screenshot_before_step'] ?? true),
            'captureScreenshotAfterStep' => $copilotObservation || (bool) ($settings['dev_capture_screenshot_after_step'] ?? true),
            'keepArtifacts' => $copilotObservation || (bool) ($settings['dev_keep_artifacts'] ?? true),
            'status' => trim((string) ($settings['dev_status'] ?? '')),
            'storageDisk' => 'local',
            'storagePath' => $relativeDirectory,
            'artifactDirectory' => storage_path('app/'.$relativeDirectory),
            'manifestPath' => storage_path('app/'.$relativeDirectory.'/manifest.json'),
            'workflowId' => $run->workflow_id,
            'workflowRunId' => $run->id,
            'workflowRunUuid' => $run->run_uuid,
            'workflowStepId' => $step->id,
            'workflowStepRunId' => $stepRun->id,
            'stepPosition' => (int) $step->position,
            'stepActionKey' => (string) $step->action_key,
        ];
    }

    protected function publicRuntimeContext(array $runtimeContext): array
    {
        $public = $runtimeContext;
        unset($public['browser'], $public['browser_runtime'], $public['browserWsEndpoint'], $public['browser_ws_endpoint'], $public['browserSessions'], $public['browser_sessions']);

        foreach (['account', 'email_account', 'verificationMailbox', 'verification_mailbox', 'veri_account', 'veri-account'] as $key) {
            if (isset($public[$key]) && is_array($public[$key])) {
                unset($public[$key]['password'], $public[$key]['passwordEncrypted'], $public[$key]['password_encrypted'], $public[$key]['webmailSession'], $public[$key]['webmail_session'], $public[$key]['browserSessions'], $public[$key]['browser_sessions']);
            }
        }

        if (isset($public['person']['emailAccount']) && is_array($public['person']['emailAccount'])) {
            unset($public['person']['emailAccount']['password'], $public['person']['emailAccount']['passwordEncrypted'], $public['person']['emailAccount']['password_encrypted'], $public['person']['emailAccount']['webmailSession'], $public['person']['emailAccount']['webmail_session'], $public['person']['emailAccount']['browserSessions'], $public['person']['emailAccount']['browser_sessions']);
        }

        if (isset($public['person']) && is_array($public['person'])) {
            unset($public['person']['password'], $public['person']['passwordEncrypted'], $public['person']['password_encrypted'], $public['person']['browserSessions'], $public['person']['browser_sessions']);

            if (isset($public['person']['metadata']) && is_array($public['person']['metadata'])) {
                unset($public['person']['metadata']['browser_sessions']);

                if (isset($public['person']['metadata']['email_account']) && is_array($public['person']['metadata']['email_account'])) {
                    unset($public['person']['metadata']['email_account']['webmail_session']);
                }
            }
        }

        return $public;
    }

    protected function processIdentity(string $runId, string $role, WorkflowRun $run, WorkflowStep $step, WorkflowStepRun $stepRun): array
    {
        return [
            'processKey' => 'workflow-task:'.$runId.':'.$role,
            'runId' => $runId,
            'runType' => 'workflow-task',
            'role' => $role,
            'workflowRunId' => $run->id,
            'workflowRunUuid' => $run->run_uuid,
            'workflowStepId' => $step->id,
            'workflowStepRunId' => $stepRun->id,
        ];
    }

    protected function publicRunStatus(array $status): array
    {
        unset($status['browserWsEndpoint'], $status['browser_ws_endpoint']);

        if (isset($status['result']) && is_array($status['result'])) {
            unset($status['result']['browserWsEndpoint'], $status['result']['browser_ws_endpoint']);
        }

        return $status;
    }

    protected function publicRunRelativeDirectory(string $runId): string
    {
        return 'workflow-task-runs/'.$runId;
    }

    protected function publicScreenshotRelativePath(string $runId): string
    {
        return $this->publicRunRelativeDirectory($runId).'/live.png';
    }

    protected function resolveNodeScriptPath(): string
    {
        $script = base_path('node/workflows/run_step.cjs');

        if (! File::exists($script)) {
            throw new \RuntimeException('Der Workflow-Task-Runner wurde nicht gefunden: '.$script);
        }

        return $script;
    }

    protected function writeJsonFile(string $path, array $payload): void
    {
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    protected function readJsonFile(string $path): ?array
    {
        try {
            if (! File::exists($path)) {
                return null;
            }

            $payload = json_decode(File::get($path), true);

            return is_array($payload) ? $payload : null;
        } catch (\Throwable) {
            return null;
        }
    }

    protected function resolveNodeBinary(): string
    {
        $candidates = PHP_OS_FAMILY === 'Windows'
            ? ['C:\\Program Files\\nodejs\\node.exe', 'C:\\Program Files (x86)\\nodejs\\node.exe']
            : ['/usr/bin/node', '/usr/local/bin/node', '/bin/node', '/snap/bin/node', '/usr/bin/nodejs', '/usr/local/bin/nodejs'];

        foreach ($candidates as $candidate) {
            if (File::exists($candidate)) {
                return $candidate;
            }
        }

        $resolved = PHP_OS_FAMILY === 'Windows'
            ? Process::timeout(5)->run(['where.exe', 'node'])
            : Process::timeout(5)->run(['sh', '-lc', 'command -v node 2>/dev/null || command -v nodejs 2>/dev/null']);
        $binary = trim(strtok($resolved->output(), "\r\n") ?: '');

        if ($resolved->successful() && $binary !== '') {
            return $binary;
        }

        throw new \RuntimeException('Node.js wurde fuer Workflow-Tasks nicht gefunden.');
    }

    protected function spawnDetachedProcess(array $command, string $workingDirectory, string $stdoutPath, string $stderrPath, array $environment = []): ?int
    {
        File::ensureDirectoryExists(dirname($stdoutPath));
        File::ensureDirectoryExists(dirname($stderrPath));

        if (PHP_OS_FAMILY === 'Windows') {
            $environmentScript = $this->powershellEnvironmentScript($environment);
            $script = '$p = Start-Process'
                .' -FilePath '.$this->powershellQuote($command[0])
                .' -ArgumentList @('.implode(',', array_map(fn (string $argument): string => $this->powershellQuote($argument), array_slice($command, 1))).')'
                .' -WorkingDirectory '.$this->powershellQuote($workingDirectory)
                .' -WindowStyle Hidden'
                .' -RedirectStandardOutput '.$this->powershellQuote($stdoutPath)
                .' -RedirectStandardError '.$this->powershellQuote($stderrPath)
                .' -PassThru; Write-Output $p.Id';

            $result = Process::timeout(15)->run(['powershell.exe', '-NoProfile', '-ExecutionPolicy', 'Bypass', '-Command', $environmentScript.$script]);
        } else {
            $environmentPrefix = $this->shellEnvironmentPrefix($environment);
            $commandLine = implode(' ', array_map('escapeshellarg', $command));
            $shellCommand = sprintf(
                'cd %s && if command -v setsid >/dev/null 2>&1; then %s setsid nohup %s > %s 2> %s < /dev/null & echo $!; else %s nohup %s > %s 2> %s < /dev/null & echo $!; fi',
                escapeshellarg($workingDirectory),
                $environmentPrefix,
                $commandLine,
                escapeshellarg($stdoutPath),
                escapeshellarg($stderrPath),
                $environmentPrefix,
                $commandLine,
                escapeshellarg($stdoutPath),
                escapeshellarg($stderrPath),
            );
            $result = Process::timeout(15)->run(['sh', '-lc', $shellCommand]);
        }

        if (! $result->successful()) {
            throw new \RuntimeException(trim($result->errorOutput()) ?: 'Der Workflow-Task-Prozess konnte nicht gestartet werden.');
        }

        $pid = (int) trim($result->output());

        return $pid > 0 ? $pid : null;
    }

    protected function shellEnvironmentPrefix(array $environment): string
    {
        return collect($environment)
            ->filter(fn (mixed $value, string $key): bool => trim((string) $key) !== '' && trim((string) $value) !== '')
            ->map(fn (mixed $value, string $key): string => $key.'='.escapeshellarg((string) $value))
            ->implode(' ');
    }

    protected function powershellEnvironmentScript(array $environment): string
    {
        return collect($environment)
            ->filter(fn (mixed $value, string $key): bool => trim((string) $key) !== '' && trim((string) $value) !== '')
            ->map(fn (mixed $value, string $key): string => '$env:'.$key.' = '.$this->powershellQuote((string) $value).';')
            ->implode(' ');
    }

    protected function stopProcess(int $pid, bool $force = true): void
    {
        if ($pid <= 1) {
            return;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            Process::timeout(10)->run(array_values(array_filter([
                'taskkill',
                '/PID',
                (string) $pid,
                '/T',
                $force ? '/F' : null,
            ])));

            return;
        }

        $signal = $force ? 'KILL' : 'TERM';

        Process::timeout(10)->run(['sh', '-lc', sprintf(
            'pkill -%1$s -P %2$d 2>/dev/null || true; kill -%1$s -%2$d 2>/dev/null || true; kill -%1$s %2$d 2>/dev/null || true',
            $signal,
            $pid,
        )]);
    }

    protected function terminateManagedProcessFamily(string $runId, bool $force, string $message): bool
    {
        if (! Schema::hasTable('managed_processes')) {
            return false;
        }

        try {
            app(ManagedProcessInventory::class)->sync();
        } catch (\Throwable) {
            // Best-effort cleanup: if syncing fails, fall back to the PID from status.json.
        }

        $process = ManagedProcess::query()
            ->where('run_id', $runId)
            ->where('run_type', 'workflow-task')
            ->whereIn('status', ['running', 'terminate_requested', 'kill_requested'])
            ->orderByDesc('is_root')
            ->latest('last_seen_at')
            ->first();

        if (! $process) {
            return false;
        }

        $result = app(ManagedProcessInventory::class)->terminate($process, $force);

        if (($result['ok'] ?? false) === true) {
            return true;
        }

        $process->forceFill([
            'last_action_at' => now(),
            'action_message' => $message,
        ])->save();

        return false;
    }

    protected function workflowTaskRootIsRunning(string $runId, int $pid): bool
    {
        if (Schema::hasTable('managed_processes')) {
            try {
                app(ManagedProcessInventory::class)->sync();

                return ManagedProcess::query()
                    ->where('run_id', $runId)
                    ->where('run_type', 'workflow-task')
                    ->where('is_root', true)
                    ->whereIn('status', ['running', 'terminate_requested', 'kill_requested'])
                    ->exists();
            } catch (\Throwable) {
                // Fall through to the lightweight PID check.
            }
        }

        if ($pid <= 1) {
            return false;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $result = Process::timeout(5)->run([
                'cmd.exe',
                '/C',
                'tasklist /FI "PID eq '.$pid.'" | findstr /R "\\<'.$pid.'\\>"',
            ]);

            return $result->successful();
        }

        return Process::timeout(5)->run(['kill', '-0', (string) $pid])->successful();
    }

    protected function powershellQuote(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }
}
