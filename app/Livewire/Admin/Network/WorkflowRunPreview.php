<?php

namespace App\Livewire\Admin\Network;

use App\Models\NetworkJob;
use App\Models\WorkflowRun;
use App\Models\WorkflowRunArtifact;
use App\Models\WorkflowStep;
use App\Models\WorkflowStepRun;
use App\Services\Mail\MailAccountRegistrationRunner;
use App\Services\Mail\WebmailSessionRunner;
use App\Services\Workflows\WorkflowDebugArtifactService;
use App\Services\Workflows\WorkflowTaskRunner;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Component;

class WorkflowRunPreview extends Component
{
    public ?int $workflowRunId = null;

    public ?int $activeStepId = null;

    public ?string $activeTaskKey = null;

    public ?string $processPid = null;

    public ?string $processType = null;

    public ?string $processStatus = null;

    protected array $liveStatusCache = [];

    public function mount(
        int|string|null $workflowRunId = null,
        ?int $activeStepId = null,
        ?string $activeTaskKey = null,
        int|string|null $processPid = null,
        ?string $processType = null,
        ?string $processStatus = null,
    ): void {
        $this->workflowRunId = is_numeric($workflowRunId) ? (int) $workflowRunId : null;
        $this->activeStepId = $activeStepId;
        $this->activeTaskKey = $activeTaskKey !== null ? trim($activeTaskKey) : null;
        $this->processPid = is_scalar($processPid) ? (string) $processPid : null;
        $this->processType = $processType !== null ? trim($processType) : null;
        $this->processStatus = $processStatus !== null ? trim($processStatus) : null;
    }

    public function refresh(): void
    {
        //
    }

    public function render(): View
    {
        $workflowRun = $this->workflowRun();
        $data = $workflowRun ? $this->previewData($workflowRun) : $this->emptyPreviewData();

        return view('livewire.admin.network.workflow-run-preview', [
            'workflowRun' => $workflowRun,
            'processSummary' => $this->processSummary(),
            'jsonDownload' => fn (array $payload): string => $this->jsonDownload($payload),
            'downloadName' => fn (string $name): string => $this->downloadName($name),
            'formatWorkflowTimestamp' => fn (mixed $value, string $format = 'd.m.Y H:i:s'): string => $this->formatWorkflowTimestamp($value, $format),
            'formatWorkflowValue' => fn (mixed $value, int $limit = 240): string => $this->formatWorkflowValue($value, $limit),
            ...$data,
        ]);
    }

    public function formatWorkflowTimestamp(mixed $value, string $format = 'd.m.Y H:i:s'): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $timezone = trim((string) config('app.timezone', 'Europe/Berlin')) ?: 'Europe/Berlin';

        try {
            return Carbon::parse((string) $value)
                ->setTimezone($timezone)
                ->format($format).' '.$timezone;
        } catch (\Throwable) {
            return (string) $value;
        }
    }

    public function formatDuration(?int $milliseconds): string
    {
        if ($milliseconds === null) {
            return '-';
        }

        $milliseconds = max(0, $milliseconds);

        if ($milliseconds > 0 && $milliseconds < 1000) {
            return '< 1s';
        }

        $seconds = intdiv($milliseconds, 1000);
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $remainingSeconds = $seconds % 60;

        return collect([
            $hours > 0 ? $hours.'h' : null,
            $minutes > 0 ? $minutes.'m' : null,
            ($hours === 0 && $remainingSeconds > 0) || ($hours === 0 && $minutes === 0) ? $remainingSeconds.'s' : null,
        ])->filter()->implode(' ');
    }

    public function formatWorkflowValue(mixed $value, int $limit = 240): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return 'null';
        }

        if (is_array($value) || is_object($value)) {
            return Str::limit(json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]', $limit);
        }

        return Str::limit((string) $value, $limit);
    }

    public function jsonDownload(array $payload): string
    {
        return 'data:application/json;base64,'.base64_encode(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    public function downloadName(string $name): string
    {
        return Str::slug($name) ?: 'workflow-debug';
    }

    protected function workflowRun(): ?WorkflowRun
    {
        if (! $this->workflowRunId) {
            return null;
        }

        return WorkflowRun::query()
            ->with([
                'currentStep',
                'workflow.steps' => fn ($query) => $query->ordered(),
                'stepRuns.workflowStep',
            ])
            ->find($this->workflowRunId);
    }

    protected function processSummary(): ?array
    {
        if ($this->processPid === null && ! $this->processType && ! $this->processStatus) {
            return null;
        }

        return [
            'pid' => $this->processPid,
            'process_type' => $this->processType,
            'status' => $this->processStatus,
        ];
    }

    protected function previewData(WorkflowRun $workflowRun): array
    {
        $stepRuns = collect($workflowRun->stepRuns ?? []);
        $workflowDurationMs = $this->runDurationMs($workflowRun);
        $screenshotPanels = $this->screenshotPanels($stepRuns);
        $latestStatusResult = $this->latestStatusResult($stepRuns);
        $stepDebugPanels = $this->stepDebugPanels($stepRuns);
        $timelineEvents = collect(data_get($latestStatusResult, 'events', []))
            ->filter(fn (mixed $event): bool => is_array($event))
            ->values();

        if ($timelineEvents->isEmpty()) {
            $timelineEvents = $stepDebugPanels
                ->flatMap(fn (array $panel): array => $panel['events'] ?? [])
                ->filter(fn (mixed $event): bool => is_array($event))
                ->values();
        }

        $workflowReturn = $this->workflowReturnSummary(
            $latestStatusResult,
            is_array($workflowRun->result_json) ? $workflowRun->result_json : [],
            is_array($workflowRun->context_json) ? $workflowRun->context_json : [],
        );
        $workflowVariables = $this->collectWorkflowVariables(
            is_array($workflowRun->context_json) ? $workflowRun->context_json : [],
            is_array($workflowRun->result_json) ? $workflowRun->result_json : [],
            $latestStatusResult,
            ...$stepDebugPanels->pluck('debug.result')->all(),
        );
        $debugArtifactGroups = $this->debugArtifactGroups($workflowRun);
        $embeddedCards = $this->embeddedWorkflowCards($workflowRun, $stepDebugPanels);
        $compactWorkflowMap = $this->compactWorkflowMap($workflowRun, $stepDebugPanels);
        $browserCount = $screenshotPanels->count();

        return [
            'polling' => in_array((string) $workflowRun->status, ['queued', 'running', 'waiting'], true),
            'workflowDurationMs' => $workflowDurationMs,
            'workflowDurationLabel' => $this->formatDuration($workflowDurationMs),
            'compactWorkflowMap' => $compactWorkflowMap,
            'screenshotPanels' => $screenshotPanels,
            'browserPanelBasis' => $this->browserPanelBasis($browserCount),
            'browserPanelMinWidth' => $browserCount <= 1 ? '100%' : ($browserCount === 2 ? '24rem' : '20rem'),
            'latestStatusResult' => $latestStatusResult,
            'stepDebugPanels' => $stepDebugPanels,
            'timelineEvents' => $timelineEvents,
            'workflowReturn' => $workflowReturn,
            'workflowVariables' => $workflowVariables,
            'debugArtifactGroups' => $debugArtifactGroups,
            'embeddedCards' => $embeddedCards,
            'runJsonPayload' => [
                'workflowRunId' => $workflowRun->id,
                'workflowRunUuid' => $workflowRun->run_uuid,
                'status' => $workflowRun->status,
                'durationMs' => $workflowDurationMs,
                'workflowReturn' => $workflowReturn['has'] ? $workflowReturn : null,
                'workflowVariables' => $workflowVariables,
                'steps' => $stepDebugPanels->pluck('debug')->all(),
            ],
        ];
    }

    protected function emptyPreviewData(): array
    {
        return [
            'polling' => false,
            'workflowDurationMs' => null,
            'workflowDurationLabel' => '-',
            'screenshotPanels' => collect(),
            'compactWorkflowMap' => collect(),
            'browserPanelBasis' => 100,
            'browserPanelMinWidth' => '100%',
            'latestStatusResult' => [],
            'stepDebugPanels' => collect(),
            'timelineEvents' => collect(),
            'workflowReturn' => $this->emptyWorkflowReturn(),
            'workflowVariables' => [],
            'debugArtifactGroups' => collect(),
            'embeddedCards' => collect(),
            'runJsonPayload' => [],
        ];
    }

    protected function runDurationMs(?WorkflowRun $run): ?int
    {
        if (! $run) {
            return null;
        }

        $stored = $run->duration_ms
            ?? data_get($run->result_json, 'durationMs')
            ?? data_get($run->result_json, 'duration_ms');

        if (is_numeric($stored) && (int) $stored >= 0) {
            return (int) $stored;
        }

        $startedAt = $run->started_at ?? $run->queued_at;

        if (! $startedAt) {
            return null;
        }

        $finishedAt = $run->finished_at ?? now();

        return max(0, $startedAt->diffInMilliseconds($finishedAt));
    }

    protected function screenshotPanels(Collection $stepRuns): Collection
    {
        return $stepRuns
            ->flatMap(function (WorkflowStepRun $stepRun): array {
                $storedResult = is_array($stepRun->result_json) ? $stepRun->result_json : [];
                $liveStatus = $this->liveStatusForStepRun($stepRun);
                $result = $this->mergeLiveStatus($storedResult, $liveStatus);
                $hasNamedWindows = is_array(data_get($result, 'registrationWindowStatus')) || is_array(data_get($result, 'webmailWindowStatus'));
                $hasBrowserWindows = false;
                $panels = [];

                foreach ((array) data_get($result, 'browserWindows', []) as $window) {
                    if (! is_array($window)) {
                        continue;
                    }

                    $image = data_get($window, 'screenshotUrl') ?: $this->publicUrl(data_get($window, 'livePreviewRelativePath'));

                    if (! $image && ! data_get($window, 'error')) {
                        continue;
                    }

                    $panels[] = [
                        'title' => data_get($window, 'label', 'Browserfenster'),
                        'windowKey' => data_get($window, 'key', data_get($window, 'label', 'Browserfenster')),
                        'image' => $image,
                        'window' => $this->windowStatus($window, $result),
                        'dom' => data_get($window, 'debugDomUrl') ?: $this->publicUrl(data_get($window, 'debugDomRelativePath')),
                        'step' => $stepRun->workflowStep?->name ?? 'Schritt',
                        'capturedAt' => data_get($window, 'capturedAt', data_get($window, 'liveScreenshotAt')),
                        'targetId' => data_get($window, 'targetId'),
                    ];
                    $hasBrowserWindows = true;
                }

                if ($hasBrowserWindows) {
                    return $panels;
                }

                foreach ([
                    ['title' => 'Browser', 'image' => $hasNamedWindows ? null : data_get($result, 'screenshotUrl'), 'window' => data_get($result, 'windowStatus'), 'dom' => data_get($result, 'debugDomUrl')],
                    ['title' => 'Registrierung', 'image' => data_get($result, 'registrationScreenshotUrl', is_array(data_get($result, 'registrationWindowStatus')) ? data_get($result, 'screenshotUrl') : null), 'window' => data_get($result, 'registrationWindowStatus'), 'dom' => data_get($result, 'registrationDebugDomUrl')],
                    ['title' => 'Webmail', 'image' => data_get($result, 'webmailScreenshotUrl'), 'window' => data_get($result, 'webmailWindowStatus'), 'dom' => data_get($result, 'webmailDebugDomUrl')],
                ] as $panel) {
                    if ($panel['image'] || is_array($panel['window']) || $panel['dom']) {
                        $panel['step'] = $stepRun->workflowStep?->name ?? 'Schritt';
                        $panel['windowKey'] = $panel['title'];
                        $panel['capturedAt'] = data_get($panel['window'], 'capturedAt', data_get($panel['window'], 'heartbeatAt'));
                        $panel['targetId'] = data_get($panel['window'], 'targetId');
                        $panels[] = $panel;
                    }
                }

                return $panels;
            })
            ->filter(fn (array $panel): bool => (bool) ($panel['image'] || is_array($panel['window']) || $panel['dom']))
            ->groupBy(function (array $panel): string {
                $targetId = trim((string) ($panel['targetId'] ?? ''));

                return $targetId !== ''
                    ? 'target:'.$targetId
                    : (string) ($panel['windowKey'] ?? $panel['title'] ?? 'Browser');
            })
            ->map(fn (Collection $panels): array => $panels->sortBy(fn (array $panel): string => (string) ($panel['capturedAt'] ?? ''))->last())
            ->values();
    }

    protected function latestStatusResult(Collection $stepRuns): array
    {
        return $stepRuns
            ->reverse()
            ->map(function (WorkflowStepRun $stepRun): array {
                $storedResult = is_array($stepRun->result_json) ? $stepRun->result_json : [];

                return $this->mergeLiveStatus($storedResult, $this->liveStatusForStepRun($stepRun));
            })
            ->first(fn (array $result): bool => $result !== []) ?? [];
    }

    protected function stepDebugPanels(Collection $stepRuns): Collection
    {
        return $stepRuns
            ->map(function (WorkflowStepRun $stepRun): array {
                $step = $stepRun->workflowStep;
                $storedResult = is_array($stepRun->result_json) ? $stepRun->result_json : [];
                $storedLogs = is_array($stepRun->logs_json) ? $stepRun->logs_json : [];
                $liveStatus = $this->liveStatusForStepRun($stepRun);
                $result = $this->mergeLiveStatus($storedResult, $liveStatus);
                $resultTasks = collect(is_array(data_get($result, 'tasks')) ? data_get($result, 'tasks') : [])
                    ->filter(fn (mixed $task): bool => is_array($task))
                    ->keyBy(fn (array $task): string => (string) data_get($task, 'key'));
                $templateTasks = collect($step?->task_cards ?? []);
                $tasks = $templateTasks
                    ->map(function (array $task) use ($resultTasks, $stepRun, $result): array {
                        $taskKey = (string) ($task['key'] ?? '');
                        $resultTask = $resultTasks->get($taskKey);
                        $status = 'configured';

                        if (
                            ($stepRun->workflowStep?->type ?? null) === WorkflowStep::TYPE_PLANNED_ACTION
                            && trim((string) $stepRun->external_run_id) === ''
                        ) {
                            $status = 'not_executed';
                        } elseif (is_array($resultTask) && trim((string) data_get($resultTask, 'status')) !== '') {
                            $status = (string) data_get($resultTask, 'status');
                        } elseif ($stepRun->status === 'completed' && ! is_array(data_get($result, 'tasks'))) {
                            $status = 'not_executed';
                        }

                        $return = $this->workflowReturnSummary(is_array($resultTask) ? $resultTask : [], $task);
                        $debug = [
                            'workflowRunId' => $stepRun->workflow_run_id,
                            'workflowStepRunId' => $stepRun->id,
                            'workflowStepId' => $stepRun->workflow_step_id,
                            'externalRunType' => $stepRun->external_run_type,
                            'externalRunId' => $stepRun->external_run_id,
                            'status' => $status,
                            'task' => $task,
                            'resultTask' => $resultTask,
                            'workflowReturn' => $return['has'] ? $return : null,
                            'note' => $status === 'not_executed'
                                ? 'Fuer diese Karte liegt kein Runner-Resultat vor. Sie war in diesem Lauf nur Planungskonfiguration.'
                                : null,
                        ];

                        return [
                            'key' => $taskKey,
                            'title' => (string) ($task['title'] ?? 'Task'),
                            'status' => $status,
                            'runner' => (string) ($task['runner'] ?? ''),
                            'node_script' => (string) ($task['node_script'] ?? ''),
                            'php_handler' => (string) ($task['php_handler'] ?? ''),
                            'return' => $return,
                            'mailScan' => $this->mailScanSummary($debug),
                            'debug' => $debug,
                        ];
                    })
                    ->values();

                if ($tasks->isEmpty() && $resultTasks->isNotEmpty()) {
                    $tasks = $resultTasks
                        ->values()
                        ->map(function (array $task) use ($stepRun): array {
                            $return = $this->workflowReturnSummary($task);
                            $debug = [
                                'workflowRunId' => $stepRun->workflow_run_id,
                                'workflowStepRunId' => $stepRun->id,
                                'workflowStepId' => $stepRun->workflow_step_id,
                                'externalRunType' => $stepRun->external_run_type,
                                'externalRunId' => $stepRun->external_run_id,
                                'status' => (string) data_get($task, 'status', $stepRun->status),
                                'resultTask' => $task,
                            ];

                            return [
                                'key' => (string) data_get($task, 'key', ''),
                                'title' => (string) data_get($task, 'title', 'Task'),
                                'status' => (string) data_get($task, 'status', $stepRun->status),
                                'runner' => (string) data_get($task, 'runner', ''),
                                'node_script' => (string) data_get($task, 'node_script', ''),
                                'php_handler' => (string) data_get($task, 'php_handler', ''),
                                'return' => $return,
                                'mailScan' => $this->mailScanSummary($debug),
                                'debug' => $debug,
                            ];
                        });
                }

                $debug = [
                    'workflowRunId' => $stepRun->workflow_run_id,
                    'workflowStepRunId' => $stepRun->id,
                    'workflowStepId' => $stepRun->workflow_step_id,
                    'stepName' => $step?->name,
                    'stepType' => $step?->type,
                    'stepStatus' => $stepRun->status,
                    'externalRunType' => $stepRun->external_run_type,
                    'externalRunId' => $stepRun->external_run_id,
                    'errorMessage' => $stepRun->error_message,
                    'config' => $step?->config_json,
                    'result' => $result,
                    'logs' => $storedLogs,
                ];
                $events = collect([
                    ...((array) data_get($result, 'events', [])),
                    ...((array) data_get($storedLogs, 'events', [])),
                    ...((array) data_get($result, 'browserDebugEvents', [])),
                    ...((array) data_get($storedLogs, 'browserDebugEvents', [])),
                ])->filter(fn (mixed $event): bool => is_array($event))->values()->take(-20)->all();

                return [
                    'title' => $step?->name ?? 'Schritt',
                    'status' => (string) $stepRun->status,
                    'external' => trim((string) $stepRun->external_run_type) !== '' ? $stepRun->external_run_type.' · '.$stepRun->external_run_id : '',
                    'message' => (string) data_get($result, 'statusMessage', data_get($result, 'message', $stepRun->error_message)),
                    'events' => $events,
                    'tasks' => $tasks,
                    'return' => $this->workflowReturnSummary($result, $storedResult),
                    'normalized' => $this->normalizedSummary($result),
                    'debug' => $debug,
                ];
            })
            ->values();
    }

    protected function debugArtifactGroups(WorkflowRun $workflowRun): Collection
    {
        if (! Schema::hasTable('workflow_run_artifacts')) {
            return collect();
        }

        $artifactService = app(WorkflowDebugArtifactService::class);

        return WorkflowRunArtifact::query()
            ->where('workflow_run_id', $workflowRun->id)
            ->with('workflowStep')
            ->orderBy('step_position')
            ->orderBy('workflow_step_run_id')
            ->orderBy('phase')
            ->orderBy('artifact_type')
            ->get()
            ->groupBy(fn (WorkflowRunArtifact $artifact): string => (string) ($artifact->workflow_step_run_id ?: $artifact->workflow_step_id ?: 'run'))
            ->map(function (Collection $artifacts) use ($artifactService): array {
                $first = $artifacts->first();

                return [
                    'step' => $first?->workflowStep?->name ?: ($first?->step_action_key ?: 'Schritt'),
                    'position' => $first?->step_position,
                    'artifacts' => $artifacts->map(fn (WorkflowRunArtifact $artifact): array => [
                        'id' => $artifact->id,
                        'phase' => $artifact->phase,
                        'type' => $artifact->artifact_type,
                        'browser_window' => $artifact->browser_window ?: 'main',
                        'status' => $artifact->status,
                        'url' => $artifact->status === 'success' ? $artifactService->artifactUrl($artifact) : null,
                        'download_url' => $artifact->status === 'success' ? $artifactService->artifactUrl($artifact, true) : null,
                        'title' => $artifact->title,
                        'current_url' => $artifact->current_url,
                        'error' => $artifact->error_message,
                        'created_at' => $artifact->created_at,
                        'metadata' => is_array($artifact->metadata_json) ? $artifact->metadata_json : [],
                    ])->values(),
                ];
            })
            ->values();
    }

    protected function compactWorkflowMap(WorkflowRun $workflowRun, Collection $stepDebugPanels): Collection
    {
        $steps = collect($workflowRun->workflow?->steps ?? [])->values();
        $stepRuns = collect($workflowRun->stepRuns ?? [])->values();
        $runningStepRun = $stepRuns->first(fn (WorkflowStepRun $stepRun): bool => in_array($stepRun->status, ['running', 'waiting'], true));
        $activeStepId = $this->activeStepId ?: ($workflowRun->current_workflow_step_id ?: $runningStepRun?->workflow_step_id);
        $activeTaskKey = trim((string) ($this->activeTaskKey ?: data_get($workflowRun->context_json, 'next_task_key', '')));
        $panelsByStepId = $stepDebugPanels
            ->filter(fn (array $panel): bool => (int) data_get($panel, 'debug.workflowStepId') > 0)
            ->keyBy(fn (array $panel): int => (int) data_get($panel, 'debug.workflowStepId'));

        return $steps
            ->map(function (WorkflowStep $step, int $index) use ($panelsByStepId, $activeStepId, $activeTaskKey): array {
                $panel = $panelsByStepId->get((int) $step->id, []);
                $isActiveStep = (int) $activeStepId === (int) $step->id;
                $stepStatus = trim((string) data_get($panel, 'status', $isActiveStep ? 'running' : 'pending')) ?: 'pending';
                $panelTasks = collect(data_get($panel, 'tasks', []))
                    ->filter(fn (mixed $task): bool => is_array($task))
                    ->values();
                $panelTasksByKey = $panelTasks->keyBy(fn (array $task): string => (string) ($task['key'] ?? ''));
                $templateTasks = collect($step->task_cards ?? [])->filter(fn (mixed $task): bool => is_array($task))->values();
                $usedKeys = [];
                $tasks = $templateTasks
                    ->map(function (array $task, int $taskIndex) use ($panelTasksByKey, $activeTaskKey, &$usedKeys): array {
                        $taskKey = (string) ($task['key'] ?? 'task-'.$taskIndex);
                        $resultTask = $panelTasksByKey->get($taskKey, []);
                        $status = trim((string) data_get($resultTask, 'status', data_get($task, 'status', 'pending'))) ?: 'pending';
                        $isActive = $activeTaskKey !== '' && $activeTaskKey === $taskKey;
                        $usedKeys[$taskKey] = true;

                        return [
                            'key' => $taskKey,
                            'title' => (string) ($task['title'] ?? data_get($resultTask, 'title', $taskKey)),
                            'status' => $isActive ? 'running' : $status,
                            'active' => $isActive,
                        ];
                    });

                $extraTasks = $panelTasks
                    ->reject(fn (array $task): bool => isset($usedKeys[(string) ($task['key'] ?? '')]))
                    ->map(function (array $task) use ($activeTaskKey): array {
                        $taskKey = (string) ($task['key'] ?? '');
                        $isActive = $activeTaskKey !== '' && $activeTaskKey === $taskKey;

                        return [
                            'key' => $taskKey,
                            'title' => (string) ($task['title'] ?? $taskKey ?: 'Task'),
                            'status' => $isActive ? 'running' : (trim((string) ($task['status'] ?? 'pending')) ?: 'pending'),
                            'active' => $isActive,
                        ];
                    });

                $tasks = $tasks->concat($extraTasks)->values();

                if ($tasks->isEmpty()) {
                    $tasks = collect([[
                        'key' => 'step-'.$step->id,
                        'title' => $step->name,
                        'status' => $stepStatus,
                        'active' => $isActiveStep,
                    ]]);
                }

                if ($isActiveStep && ! $tasks->contains(fn (array $task): bool => (bool) ($task['active'] ?? false))) {
                    $tasks = $tasks
                        ->values()
                        ->map(function (array $task, int $taskIndex): array {
                            if ($taskIndex === 0) {
                                $task['active'] = true;
                                $task['status'] = in_array((string) ($task['status'] ?? ''), ['completed', 'success', 'failed', 'timeout'], true)
                                    ? (string) $task['status']
                                    : 'running';
                            }

                            return $task;
                        });
                }

                $visibleTasks = $tasks->take(16)->values();
                $activeTask = $visibleTasks->first(fn (array $task): bool => (bool) ($task['active'] ?? false))
                    ?: $visibleTasks->first(fn (array $task): bool => in_array((string) ($task['status'] ?? ''), ['running', 'waiting'], true));

                return [
                    'id' => (int) $step->id,
                    'position' => $index + 1,
                    'title' => $step->name,
                    'status' => $isActiveStep ? 'running' : $stepStatus,
                    'active' => $isActiveStep || $visibleTasks->contains(fn (array $task): bool => (bool) ($task['active'] ?? false)),
                    'activeTaskTitle' => is_array($activeTask) ? (string) ($activeTask['title'] ?? '') : '',
                    'tasks' => $visibleTasks,
                    'overflow' => max(0, $tasks->count() - $visibleTasks->count()),
                ];
            })
            ->values();
    }

    protected function embeddedWorkflowCards(WorkflowRun $workflowRun, Collection $stepDebugPanels): Collection
    {
        if (! in_array((string) $workflowRun->status, ['queued', 'running', 'waiting'], true)) {
            return collect();
        }

        $items = collect();

        foreach ($stepDebugPanels as $panel) {
            foreach ((array) data_get($panel, 'debug.result.tasks', []) as $task) {
                if (! is_array($task)) {
                    continue;
                }

                foreach ($this->embeddedTaskItems($task, $panel) as $item) {
                    $items->push($item);
                }
            }
        }

        return $items
            ->groupBy(fn (array $item): string => $item['groupKey'])
            ->map(function (Collection $tasks, string $groupKey): array {
                $first = $tasks->first();
                $status = $this->embeddedStatus($tasks);

                return [
                    'id' => 'embedded-'.md5($groupKey),
                    'title' => $first['workflowName'] ?: $first['parentTaskKey'] ?: 'Eingebetteter Workflow',
                    'status' => $status,
                    'statusLabel' => $this->statusLabel($status),
                    'frameKey' => $first['frameKey'],
                    'parentTaskKey' => $first['parentTaskKey'],
                    'browserWindow' => $first['browserWindow'],
                    'stepTitle' => $first['stepTitle'],
                    'taskCount' => $tasks->count(),
                    'tasks' => $tasks->values(),
                    'return' => $this->workflowReturnSummary(...$tasks->pluck('raw')->all()),
                ];
            })
            ->filter(fn (array $card): bool => in_array($card['status'], ['queued', 'starting', 'running', 'waiting'], true))
            ->values();
    }

    protected function embeddedTaskItems(array $task, array $panel): array
    {
        $items = [];
        $includedTasks = collect((array) data_get($task, 'included_tasks', []))
            ->filter(fn (mixed $item): bool => is_array($item))
            ->values();
        $parentTaskKey = trim((string) data_get($task, 'parent_task_key', data_get($task, 'key', '')));
        $taskHasEmbeddedIdentity = trim((string) data_get($task, 'parent_task_key', '')) !== ''
            || trim((string) data_get($task, 'embedded_workflow_id', '')) !== ''
            || trim((string) data_get($task, 'embedded_workflow_name', '')) !== ''
            || trim((string) data_get($task, 'embedded_workflow_frame_key', '')) !== '';

        if ($includedTasks->isNotEmpty()) {
            foreach ($includedTasks as $includedTask) {
                $items[] = $this->embeddedTaskItem($includedTask, $panel, $task);
            }

            return $items;
        }

        if ($taskHasEmbeddedIdentity) {
            $items[] = $this->embeddedTaskItem($task, $panel, ['key' => $parentTaskKey]);
        }

        return $items;
    }

    protected function embeddedTaskItem(array $task, array $panel, array $parentTask = []): array
    {
        $parentTaskKey = trim((string) data_get($task, 'parent_task_key', data_get($parentTask, 'key', '')));
        $frameKey = trim((string) data_get($task, 'embedded_workflow_frame_key', data_get($parentTask, 'embedded_workflow_frame_key', '')));
        $workflowId = trim((string) data_get($task, 'embedded_workflow_id', data_get($parentTask, 'embedded_workflow_id', '')));
        $groupKey = $frameKey ?: ($parentTaskKey ?: ($workflowId ?: trim((string) data_get($task, 'key', 'embedded'))));
        $return = $this->workflowReturnSummary($task);

        return [
            'groupKey' => $groupKey,
            'key' => (string) data_get($task, 'key', ''),
            'title' => (string) data_get($task, 'title', data_get($task, 'key', 'Task')),
            'status' => (string) data_get($task, 'status', data_get($panel, 'status', 'running')),
            'runner' => (string) data_get($task, 'runner', ''),
            'node_script' => (string) data_get($task, 'node_script', ''),
            'php_handler' => (string) data_get($task, 'php_handler', ''),
            'workflowName' => trim((string) data_get($task, 'embedded_workflow_name', data_get($parentTask, 'embedded_workflow_name', ''))),
            'frameKey' => $frameKey,
            'parentTaskKey' => $parentTaskKey,
            'browserWindow' => trim((string) data_get($task, 'embedded_workflow_browser_window', data_get($parentTask, 'embedded_workflow_browser_window', ''))),
            'stepTitle' => (string) data_get($panel, 'title', 'Schritt'),
            'return' => $return,
            'raw' => $task,
        ];
    }

    protected function embeddedStatus(Collection $tasks): string
    {
        $statuses = $tasks->pluck('status')->map(fn (mixed $status): string => (string) $status);

        if ($statuses->contains(fn (string $status): bool => in_array($status, ['running', 'waiting', 'queued', 'starting'], true))) {
            return $statuses->first(fn (string $status): bool => in_array($status, ['running', 'waiting', 'queued', 'starting'], true)) ?: 'running';
        }

        if ($statuses->contains(fn (string $status): bool => in_array($status, ['failed', 'timeout'], true))) {
            return 'failed';
        }

        if ($statuses->contains(fn (string $status): bool => in_array($status, ['configured', 'not_executed', ''], true))) {
            return 'waiting';
        }

        if ($statuses->every(fn (string $status): bool => in_array($status, ['success', 'completed'], true))) {
            return 'completed';
        }

        return 'partial';
    }

    protected function mailScanSummary(array $debug): array
    {
        $mailScanDebug = data_get($debug, 'resultTask.mailListScanDebug')
            ?: data_get($debug, 'resultTask.mail_list_scan_debug');
        $mailScanSearch = data_get($mailScanDebug, 'webmailSearch')
            ?: data_get($mailScanDebug, 'webmail_search');

        return [
            'has' => is_array($mailScanDebug),
            'debug' => is_array($mailScanDebug) ? $mailScanDebug : [],
            'search' => is_array($mailScanSearch) ? $mailScanSearch : [],
            'candidates' => is_array(data_get($mailScanDebug, 'candidates'))
                ? array_slice(data_get($mailScanDebug, 'candidates'), 0, 8)
                : [],
        ];
    }

    protected function normalizedSummary(array $result): array
    {
        $normalized = data_get($result, 'normalized_result');

        if (! is_array($normalized)) {
            return [
                'has' => false,
                'data' => [],
                'mail' => [],
                'counts' => [],
                'embedded' => collect(),
            ];
        }

        return [
            'has' => true,
            'data' => $normalized,
            'mail' => (array) data_get($normalized, 'mail_scan', []),
            'counts' => (array) data_get($normalized, 'counts', []),
            'embedded' => collect((array) data_get($normalized, 'embedded_workflows', [])),
        ];
    }

    protected function publicUrl(?string $relativePath): ?string
    {
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

    protected function windowStatus(array $window, array $result): array
    {
        $capturedAt = data_get($window, 'capturedAt', data_get($window, 'liveScreenshotAt'));
        $intervalSeconds = max(1, (int) data_get($result, 'livePreviewIntervalSeconds', data_get($result, 'livePreviewPollIntervalSeconds', 3)));

        return [
            'label' => data_get($window, 'label', 'Browser'),
            'alive' => ! data_get($window, 'error'),
            'stale' => false,
            'hasScreenshot' => (bool) (data_get($window, 'screenshotUrl') || data_get($window, 'livePreviewRelativePath')),
            'heartbeatAt' => $capturedAt,
            'ageSeconds' => null,
            'statusText' => $capturedAt ? 'Lebenszeichen aktiv' : 'Screenshot bereit',
            'state' => (string) data_get($result, 'status', 'running'),
            'stage' => (string) data_get($result, 'statusMessage', ''),
            'message' => (string) data_get($window, 'error', ''),
            'livePreviewEnabled' => true,
            'livePreviewIntervalSeconds' => $intervalSeconds,
        ];
    }

    protected function liveStatusForStepRun(WorkflowStepRun $stepRun): array
    {
        $externalRunId = trim((string) $stepRun->external_run_id);

        if ($externalRunId === '') {
            return [];
        }

        $cacheKey = (string) $stepRun->external_run_type.':'.$externalRunId;

        if (array_key_exists($cacheKey, $this->liveStatusCache)) {
            return $this->liveStatusCache[$cacheKey];
        }

        try {
            return $this->liveStatusCache[$cacheKey] = match ((string) $stepRun->external_run_type) {
                'mail-registration' => app(MailAccountRegistrationRunner::class)->readRun($externalRunId) ?: [],
                'webmail-session' => app(WebmailSessionRunner::class)->readRun($externalRunId) ?: [],
                'workflow-task' => app(WorkflowTaskRunner::class)->readRun($externalRunId) ?: [],
                'client-controller-workflow-task', 'client-controller-workflow-run' => $this->clientControllerLiveStatus($externalRunId),
                default => [],
            };
        } catch (\Throwable) {
            return $this->liveStatusCache[$cacheKey] = [];
        }
    }

    protected function clientControllerLiveStatus(string $jobUuid): array
    {
        $job = NetworkJob::query()->where('job_uuid', $jobUuid)->first();

        if (! $job) {
            return [];
        }

        $result = is_array($job->result_json) ? $job->result_json : [];

        if ($job->status === 'dispatched') {
            return array_replace($result, [
                'runId' => $job->job_uuid,
                'state' => 'running',
                'isRunning' => true,
            ]);
        }

        return [
            'runId' => $job->job_uuid,
            'state' => match ($job->status) {
                'success' => 'completed',
                'pending' => 'queued',
                default => $job->status,
            },
            'isRunning' => false,
            'message' => $job->error_message ?: data_get($result, 'statusMessage', 'ClientController-Job: '.$job->status),
            'result' => $result,
            ...$result,
        ];
    }

    protected function mergeLiveStatus(array $storedResult, array $liveStatus): array
    {
        $liveResult = is_array(data_get($liveStatus, 'result')) ? data_get($liveStatus, 'result') : [];
        $result = array_replace_recursive($storedResult, $liveStatus, $liveResult);
        $liveBrowserWindows = data_get($liveStatus, 'browserWindows');

        if (! is_array($liveBrowserWindows) || $liveBrowserWindows === []) {
            $liveBrowserWindows = data_get($liveResult, 'browserWindows');
        }

        if (
            is_array(data_get($storedResult, 'browserWindows'))
            && data_get($storedResult, 'browserWindows') !== []
            && (
                ! is_array($liveBrowserWindows)
                || $liveBrowserWindows === []
            )
        ) {
            $result['browserWindows'] = data_get($storedResult, 'browserWindows');
        }

        return $result;
    }

    protected function collectWorkflowVariables(mixed ...$sources): array
    {
        $variables = [];
        $extract = function (mixed $source) use (&$extract, &$variables): void {
            if (! is_array($source)) {
                return;
            }

            foreach (['workflow_variables', 'workflowVariables'] as $variablesKey) {
                $candidateVariables = data_get($source, $variablesKey);

                if (is_array($candidateVariables)) {
                    $variables = array_replace($variables, $candidateVariables);
                }
            }

            foreach ([
                'verification_code',
                'verificationCode',
                'verification_mail_id',
                'verificationMailId',
                'verification_mail',
                'verificationMail',
                'mail_id',
                'mailId',
                'message_id',
                'messageId',
                'matched_mail',
                'matchedMail',
                'workflow_return',
                'workflowReturn',
                'workflow_return_ok',
                'workflowReturnOk',
            ] as $directKey) {
                if (Arr::has($source, $directKey)) {
                    $directValue = data_get($source, $directKey);

                    if ($directValue !== null && $directValue !== '') {
                        $variables[$directKey] = $directValue;
                    }
                }
            }

            foreach (['result', 'resultTask'] as $nestedKey) {
                $extract(data_get($source, $nestedKey));
            }

            foreach (['included_tasks', 'tasks'] as $listKey) {
                $items = data_get($source, $listKey);

                if (! is_array($items)) {
                    continue;
                }

                foreach ($items as $item) {
                    $extract($item);
                }
            }
        };

        foreach ($sources as $source) {
            $extract($source);
        }

        $variables = array_filter(
            $variables,
            fn (mixed $value): bool => $value !== null && $value !== '',
        );

        ksort($variables);

        return $variables;
    }

    protected function workflowReturnSummary(mixed ...$sources): array
    {
        $empty = $this->emptyWorkflowReturn();
        $variables = [];
        $extract = function (mixed $source) use (&$extract, &$variables, $empty): ?array {
            if (! is_array($source)) {
                return null;
            }

            foreach (['workflow_variables', 'workflowVariables'] as $variablesKey) {
                $candidateVariables = data_get($source, $variablesKey);

                if (is_array($candidateVariables)) {
                    $variables = array_replace($variables, $candidateVariables);
                }
            }

            $hasValue = false;
            $value = null;

            if (Arr::has($source, 'workflow_return')) {
                $hasValue = true;
                $value = data_get($source, 'workflow_return');
            } elseif (Arr::has($source, 'workflowReturn')) {
                $hasValue = true;
                $value = data_get($source, 'workflowReturn');
            } elseif (Arr::has($source, 'workflow_variables.workflow_return')) {
                $hasValue = true;
                $value = data_get($source, 'workflow_variables.workflow_return');
            } elseif (Arr::has($source, 'workflowVariables.workflow_return')) {
                $hasValue = true;
                $value = data_get($source, 'workflowVariables.workflow_return');
            }

            if ($hasValue) {
                $ok = Arr::has($source, 'workflow_return_ok')
                    ? (bool) data_get($source, 'workflow_return_ok')
                    : (Arr::has($source, 'workflow_variables.workflow_return_ok')
                        ? (bool) data_get($source, 'workflow_variables.workflow_return_ok')
                        : (Arr::has($source, 'workflowVariables.workflow_return_ok')
                            ? (bool) data_get($source, 'workflowVariables.workflow_return_ok')
                            : $value !== false));
                $key = trim((string) (
                    data_get($source, 'workflow_return_key')
                    ?: data_get($source, 'workflowReturnKey')
                    ?: ''
                ));

                if ($key === '') {
                    foreach ($variables as $variableKey => $variableValue) {
                        if (! in_array($variableKey, ['workflow_return', 'workflow_return_ok'], true) && $variableValue === $value) {
                            $key = (string) $variableKey;
                            break;
                        }
                    }
                }

                $key = $key !== '' ? $key : 'workflow_return';

                return [
                    ...$empty,
                    'has' => true,
                    'key' => $key,
                    'value' => $value,
                    'valueLabel' => $this->formatWorkflowValue($value),
                    'ok' => $ok,
                    'okLabel' => $ok ? 'true' : 'false',
                    'variables' => $variables,
                ];
            }

            foreach (['result', 'resultTask'] as $nestedKey) {
                $nested = data_get($source, $nestedKey);
                $summary = is_array($nested) ? $extract($nested) : null;

                if ($summary && $summary['has']) {
                    return $summary;
                }
            }

            foreach (['included_tasks', 'tasks'] as $listKey) {
                $items = data_get($source, $listKey);

                if (! is_array($items)) {
                    continue;
                }

                foreach (array_reverse($items) as $item) {
                    $summary = is_array($item) ? $extract($item) : null;

                    if ($summary && $summary['has']) {
                        return $summary;
                    }
                }
            }

            return null;
        };

        foreach ($sources as $source) {
            $summary = $extract($source);

            if ($summary && $summary['has']) {
                return [
                    ...$summary,
                    'variables' => array_replace($variables, $summary['variables'] ?? []),
                ];
            }
        }

        return [
            ...$empty,
            'variables' => $variables,
        ];
    }

    protected function emptyWorkflowReturn(): array
    {
        return [
            'has' => false,
            'key' => 'workflow_return',
            'value' => null,
            'valueLabel' => '-',
            'ok' => null,
            'okLabel' => '-',
            'variables' => [],
        ];
    }

    protected function browserPanelBasis(int $browserCount): int
    {
        if ($browserCount <= 1) {
            return 100;
        }

        if ($browserCount === 2) {
            return 50;
        }

        return 30;
    }

    protected function statusLabel(string $status): string
    {
        return [
            'queued' => 'Wartet',
            'starting' => 'Startet',
            'running' => 'Laeuft',
            'waiting' => 'Wartet',
            'completed' => 'Fertig',
            'success' => 'Fertig',
            'failed' => 'Fehler',
            'timeout' => 'Timeout',
            'partial' => 'Teilstatus',
            'cancelled' => 'Abgebrochen',
            'skipped' => 'Uebersprungen',
            'configured' => 'Konfiguriert',
            'not_executed' => 'Nicht ausgefuehrt',
        ][$status] ?? $status;
    }
}
