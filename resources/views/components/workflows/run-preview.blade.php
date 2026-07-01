@props([
    'workflowRun',
    'process' => null,
    'activeStepId' => null,
    'activeTaskKey' => null,
])

@php
    $publicUrl = static function (?string $relativePath): ?string {
        $relativePath = trim((string) $relativePath);

        if ($relativePath === '') {
            return null;
        }

        $absolutePath = storage_path('app/public/'.$relativePath);

        if (! \Illuminate\Support\Facades\File::exists($absolutePath)) {
            return null;
        }

        return \Illuminate\Support\Facades\Storage::disk('public')->url($relativePath).'?v='.\Illuminate\Support\Facades\File::lastModified($absolutePath);
    };

    $windowStatus = static function (array $window, array $result): array {
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
    };

    $liveStatusForStepRun = static function ($stepRun): array {
        $externalRunId = trim((string) $stepRun?->external_run_id);

        if ($externalRunId === '') {
            return [];
        }

        return match ((string) $stepRun?->external_run_type) {
            'mail-registration' => app(\App\Services\Mail\MailAccountRegistrationRunner::class)->readRun($externalRunId) ?: [],
            'webmail-session' => app(\App\Services\Mail\WebmailSessionRunner::class)->readRun($externalRunId) ?: [],
            'workflow-task' => app(\App\Services\Workflows\WorkflowTaskRunner::class)->readRun($externalRunId) ?: [],
            default => [],
        };
    };
    $mergeLiveStatus = static function (array $storedResult, array $liveStatus): array {
        $result = array_replace_recursive($storedResult, $liveStatus);

        if (
            is_array(data_get($storedResult, 'browserWindows'))
            && data_get($storedResult, 'browserWindows') !== []
            && (
                ! is_array(data_get($liveStatus, 'browserWindows'))
                || data_get($liveStatus, 'browserWindows') === []
            )
        ) {
            $result['browserWindows'] = data_get($storedResult, 'browserWindows');
        }

        return $result;
    };
    $jsonDownload = static function (array $payload): string {
        return 'data:application/json;base64,'.base64_encode(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    };
    $downloadName = static function (string $name): string {
        return \Illuminate\Support\Str::slug($name) ?: 'workflow-debug';
    };
    $formatDuration = static function (?int $milliseconds): string {
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
    };
    $runDurationMs = static function ($run): ?int {
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
    };
    $formatWorkflowValue = static function ($value): string {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return 'null';
        }

        if (is_array($value) || is_object($value)) {
            return \Illuminate\Support\Str::limit(json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]', 240);
        }

        return \Illuminate\Support\Str::limit((string) $value, 240);
    };
    $workflowReturnSummary = static function (...$sources) use ($formatWorkflowValue): array {
        $empty = [
            'has' => false,
            'key' => 'workflow_return',
            'value' => null,
            'valueLabel' => '-',
            'ok' => null,
            'okLabel' => '-',
            'variables' => [],
        ];
        $variables = [];
        $extract = function ($source) use (&$extract, &$variables, $formatWorkflowValue, $empty): ?array {
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

            if (\Illuminate\Support\Arr::has($source, 'workflow_return')) {
                $hasValue = true;
                $value = data_get($source, 'workflow_return');
            } elseif (\Illuminate\Support\Arr::has($source, 'workflowReturn')) {
                $hasValue = true;
                $value = data_get($source, 'workflowReturn');
            } elseif (\Illuminate\Support\Arr::has($source, 'workflow_variables.workflow_return')) {
                $hasValue = true;
                $value = data_get($source, 'workflow_variables.workflow_return');
            } elseif (\Illuminate\Support\Arr::has($source, 'workflowVariables.workflow_return')) {
                $hasValue = true;
                $value = data_get($source, 'workflowVariables.workflow_return');
            }

            if ($hasValue) {
                $ok = \Illuminate\Support\Arr::has($source, 'workflow_return_ok')
                    ? (bool) data_get($source, 'workflow_return_ok')
                    : (\Illuminate\Support\Arr::has($source, 'workflow_variables.workflow_return_ok')
                        ? (bool) data_get($source, 'workflow_variables.workflow_return_ok')
                        : (\Illuminate\Support\Arr::has($source, 'workflowVariables.workflow_return_ok')
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
                    'valueLabel' => $formatWorkflowValue($value),
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
    };
    $stepRuns = $workflowRun?->stepRuns ?? collect();
    $workflowDurationMs = $runDurationMs($workflowRun);
    $workflowDurationLabel = $formatDuration($workflowDurationMs);
    $screenshotPanels = collect($stepRuns)
        ->flatMap(function ($stepRun) use ($publicUrl, $windowStatus, $liveStatusForStepRun, $mergeLiveStatus) {
            $storedResult = is_array($stepRun->result_json) ? $stepRun->result_json : [];
            $liveStatus = $liveStatusForStepRun($stepRun);
            $result = $mergeLiveStatus($storedResult, $liveStatus);
            $hasNamedWindows = is_array(data_get($result, 'registrationWindowStatus')) || is_array(data_get($result, 'webmailWindowStatus'));
            $hasBrowserWindows = false;
            $panels = [];

            foreach (data_get($result, 'browserWindows', []) as $window) {
                if (! is_array($window)) {
                    continue;
                }

                $image = data_get($window, 'screenshotUrl') ?: $publicUrl(data_get($window, 'livePreviewRelativePath'));

                if (! $image && ! data_get($window, 'error')) {
                    continue;
                }

                $panels[] = [
                    'title' => data_get($window, 'label', 'Browserfenster'),
                    'windowKey' => data_get($window, 'key', data_get($window, 'label', 'Browserfenster')),
                    'image' => $image,
                    'window' => $windowStatus($window, $result),
                    'dom' => data_get($window, 'debugDomUrl') ?: $publicUrl(data_get($window, 'debugDomRelativePath')),
                    'step' => $stepRun->workflowStep?->name ?? 'Schritt',
                    'capturedAt' => data_get($window, 'capturedAt', data_get($window, 'liveScreenshotAt')),
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
                    $panels[] = $panel;
                }
            }

            return $panels;
        })
        ->filter(fn ($panel) => $panel['image'] || is_array($panel['window']) || $panel['dom'])
        ->groupBy(fn ($panel) => (string) ($panel['windowKey'] ?? $panel['title'] ?? 'Browser'))
        ->map(fn ($panels) => $panels->sortBy(fn ($panel) => (string) ($panel['capturedAt'] ?? ''))->last())
        ->values();
    $latestStatusResult = collect($stepRuns)
        ->reverse()
        ->map(function ($stepRun) use ($liveStatusForStepRun, $mergeLiveStatus) {
            $storedResult = is_array($stepRun->result_json) ? $stepRun->result_json : [];

            return $mergeLiveStatus($storedResult, $liveStatusForStepRun($stepRun));
        })
        ->first(fn (array $result): bool => $result !== []) ?? [];
    $stepDebugPanels = collect($stepRuns)
        ->map(function ($stepRun) use ($liveStatusForStepRun, $mergeLiveStatus, $workflowReturnSummary) {
            $step = $stepRun->workflowStep;
            $storedResult = is_array($stepRun->result_json) ? $stepRun->result_json : [];
            $storedLogs = is_array($stepRun->logs_json) ? $stepRun->logs_json : [];
            $liveStatus = $liveStatusForStepRun($stepRun);
            $result = $mergeLiveStatus($storedResult, $liveStatus);
            $resultTasks = collect(is_array(data_get($result, 'tasks')) ? data_get($result, 'tasks') : [])
                ->filter(fn ($task) => is_array($task))
                ->keyBy(fn ($task) => (string) data_get($task, 'key'));
            $templateTasks = collect($step?->task_cards ?? []);
            $tasks = $templateTasks
                ->map(function (array $task) use ($resultTasks, $stepRun, $result, $workflowReturnSummary) {
                    $taskKey = (string) ($task['key'] ?? '');
                    $resultTask = $resultTasks->get($taskKey);
                    $status = 'configured';

                    if (
                        ($stepRun?->workflowStep?->type ?? null) === \App\Models\WorkflowStep::TYPE_PLANNED_ACTION
                        && trim((string) ($stepRun?->external_run_id ?? '')) === ''
                    ) {
                        $status = 'not_executed';
                    } elseif (is_array($resultTask) && trim((string) data_get($resultTask, 'status')) !== '') {
                        $status = (string) data_get($resultTask, 'status');
                    } elseif (($stepRun?->status ?? null) === 'completed' && ! is_array(data_get($result, 'tasks'))) {
                        $status = 'not_executed';
                    }
                    $return = $workflowReturnSummary(is_array($resultTask) ? $resultTask : [], $task);
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
                        'debug' => $debug,
                    ];
                })
                ->values();

            if ($tasks->isEmpty() && $resultTasks->isNotEmpty()) {
                $tasks = $resultTasks
                    ->values()
                    ->map(fn (array $task) => [
                        'key' => (string) data_get($task, 'key', ''),
                        'title' => (string) data_get($task, 'title', 'Task'),
                        'status' => (string) data_get($task, 'status', $stepRun->status),
                        'runner' => (string) data_get($task, 'runner', ''),
                        'node_script' => (string) data_get($task, 'node_script', ''),
                        'php_handler' => (string) data_get($task, 'php_handler', ''),
                        'return' => $workflowReturnSummary($task),
                        'debug' => [
                            'workflowRunId' => $stepRun->workflow_run_id,
                            'workflowStepRunId' => $stepRun->id,
                            'workflowStepId' => $stepRun->workflow_step_id,
                            'externalRunType' => $stepRun->external_run_type,
                            'externalRunId' => $stepRun->external_run_id,
                            'status' => (string) data_get($task, 'status', $stepRun->status),
                            'resultTask' => $task,
                        ],
                    ]);
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

            $return = $workflowReturnSummary($result, $storedResult);

            return [
                'title' => $step?->name ?? 'Schritt',
                'status' => (string) $stepRun->status,
                'external' => trim((string) $stepRun->external_run_type) !== '' ? $stepRun->external_run_type.' · '.$stepRun->external_run_id : '',
                'message' => (string) data_get($result, 'statusMessage', data_get($result, 'message', $stepRun->error_message)),
                'events' => collect([
                    ...((array) data_get($result, 'events', [])),
                    ...((array) data_get($storedLogs, 'events', [])),
                    ...((array) data_get($result, 'browserDebugEvents', [])),
                    ...((array) data_get($storedLogs, 'browserDebugEvents', [])),
                ])->filter(fn ($event) => is_array($event))->values()->take(-20)->all(),
                'tasks' => $tasks,
                'return' => $return,
                'debug' => $debug,
            ];
        })
        ->values();
    $timelineEvents = collect(data_get($latestStatusResult, 'events', []))
        ->filter(fn ($event) => is_array($event))
        ->values();

    if ($timelineEvents->isEmpty()) {
        $timelineEvents = $stepDebugPanels
            ->flatMap(fn ($panel) => $panel['events'] ?? [])
            ->filter(fn ($event) => is_array($event))
            ->values();
    }
    $workflowReturn = $workflowReturnSummary(
        $latestStatusResult,
        is_array($workflowRun?->result_json) ? $workflowRun->result_json : [],
        is_array($workflowRun?->context_json) ? $workflowRun->context_json : [],
    );
@endphp

<div {{ $attributes->merge(['class' => 'space-y-5']) }}>
    @if($process)
        <div class="rounded-md border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600">
            <span class="font-semibold text-slate-900">Prozess:</span>
            PID {{ $process->pid }} · {{ $process->process_type }} · {{ $process->status }}
        </div>
    @endif

    <details class="group rounded-lg border border-slate-200 bg-white shadow-sm" open>
        <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-4 py-3 marker:hidden">
            <div class="min-w-0">
                <div class="truncate text-sm font-semibold text-slate-900">{{ $workflowRun?->workflow?->name ?? 'Workflow' }}</div>
                <div class="mt-1 flex flex-wrap items-center gap-2 text-xs text-slate-500">
                    <span>Run #{{ $workflowRun?->id ?? '-' }}</span>
                    <span>{{ $workflowRun?->status ?? '-' }}</span>
                    <span>Dauer: {{ $workflowDurationLabel }}</span>
                    @if($activeTaskKey)
                        <span class="max-w-[18rem] truncate">Aktiv: {{ $activeTaskKey }}</span>
                    @endif
                </div>
            </div>
            <div class="flex shrink-0 items-center gap-2">
                <x-workflows.status-badge :status="$workflowRun?->status" />
                <span class="rounded border border-slate-200 px-2 py-1 text-[10px] font-semibold uppercase tracking-wide text-slate-500 group-open:hidden">Maximieren</span>
                <span class="hidden rounded border border-slate-200 px-2 py-1 text-[10px] font-semibold uppercase tracking-wide text-slate-500 group-open:inline">Minimieren</span>
            </div>
        </summary>
        <div class="border-t border-slate-100 px-4 py-3">
            <x-workflows.minimap
                :workflow-run="$workflowRun"
                :active-step-id="$activeStepId"
                :active-task-key="$activeTaskKey"
            />
        </div>
    </details>

    <div class="space-y-5">
        <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
            @forelse($screenshotPanels as $panel)
                <div class="overflow-hidden rounded-lg border border-slate-200 bg-slate-950">
                    <div class="flex items-center justify-between gap-3 border-b border-slate-800 px-3 py-2 text-xs font-semibold uppercase tracking-wide text-slate-300">
                        <div class="min-w-0">
                            <div class="truncate">{{ $panel['title'] }} · {{ $panel['step'] }}</div>
                            @include('livewire.admin.config.partials.browser-window-status', [
                                'windowStatus' => is_array($panel['window'] ?? null) ? $panel['window'] : [],
                            ])
                        </div>
                        @if($panel['dom'])
                            <a href="{{ $panel['dom'] }}" download="{{ $downloadName(($panel['step'] ?? 'workflow').' '.($panel['title'] ?? 'browser')).'-dom.json' }}" class="rounded border border-slate-700 px-2 py-1 text-[10px] text-slate-200 hover:bg-slate-800">
                                DOM
                            </a>
                        @endif
                    </div>
                    @if($panel['image'])
                        <img src="{{ $panel['image'] }}" alt="Workflow Live Screenshot" class="aspect-video w-full object-contain">
                    @else
                        <div class="flex aspect-video items-center justify-center text-sm font-semibold text-slate-300">
                            Noch kein Screenshot verfuegbar.
                        </div>
                    @endif
                </div>
            @empty
                <div class="rounded-md border border-dashed border-slate-300 bg-slate-50 p-4 text-sm text-slate-500 md:col-span-2 xl:col-span-3">
                    Fuer diesen Workflow-Lauf wurden noch keine Browser-Screenshots gespeichert.
                </div>
            @endforelse
        </div>

        <div class="grid gap-4 xl:grid-cols-[minmax(260px,360px)_minmax(0,1fr)]">
            <div class="rounded-lg border border-slate-200 bg-white p-4">
                <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Status</div>
                <div class="mt-2 text-sm font-semibold text-slate-900">
                    {{ data_get($latestStatusResult, 'statusMessage', data_get($latestStatusResult, 'message', $workflowRun?->status)) }}
                </div>
                <div class="mt-2 break-all text-xs text-slate-500">
                    Run: {{ $workflowRun?->run_uuid ?: '-' }}
                </div>
                <div class="mt-1 text-xs text-slate-500">
                    Dauer: {{ $workflowDurationLabel }}
                </div>
                @if($workflowReturn['has'])
                    <div class="mt-3 rounded-md border border-indigo-100 bg-indigo-50 px-3 py-2 text-xs text-indigo-900">
                        <div class="font-semibold uppercase tracking-wide text-indigo-600">Rueckgabe</div>
                        <div class="mt-1 break-words font-semibold">{{ $workflowReturn['key'] }} = {{ $workflowReturn['valueLabel'] }}</div>
                        <div class="mt-1 text-indigo-700">OK: {{ $workflowReturn['okLabel'] }}</div>
                    </div>
                @endif
                <div class="mt-1 text-xs text-slate-500">
                    Script: {{ data_get($latestStatusResult, 'scriptVersionLabel', data_get($latestStatusResult, 'scriptName', '-')) }}
                </div>
                @if(data_get($latestStatusResult, 'processHeartbeatStatus.statusText'))
                    <div class="mt-2 rounded-md {{ data_get($latestStatusResult, 'processHeartbeatStatus.stale') ? 'bg-amber-50 text-amber-800' : 'bg-emerald-50 text-emerald-800' }} px-3 py-2 text-xs font-semibold">
                        {{ data_get($latestStatusResult, 'processHeartbeatStatus.statusText') }}
                    </div>
                @endif
            </div>

            <div class="max-h-96 overflow-auto rounded-lg border border-slate-200 bg-slate-50 p-4">
                <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Ablauf</div>
                <div class="mt-3 space-y-2">
                    @forelse($timelineEvents->reverse()->values() as $event)
                        <div class="rounded-md bg-white p-3 text-xs shadow-sm">
                            <div class="font-semibold text-slate-900">{{ data_get($event, 'stage', '-') }}</div>
                            <div class="mt-1 text-slate-600">{{ data_get($event, 'message', '-') }}</div>
                            <div class="mt-1 text-slate-400">{{ data_get($event, 'at', '') }}</div>
                        </div>
                    @empty
                        <div class="text-sm text-slate-500">Noch keine Ablaufdaten.</div>
                    @endforelse
                </div>
            </div>
        </div>

        <div class="max-h-[32rem] overflow-auto rounded-lg border border-slate-200 bg-white p-4">
            <div class="flex items-center justify-between gap-3">
                <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Logs & Debug</div>
                <a
                    href="{{ $jsonDownload([
                        'workflowRunId' => $workflowRun?->id,
                        'workflowRunUuid' => $workflowRun?->run_uuid,
                        'status' => $workflowRun?->status,
                        'durationMs' => $workflowDurationMs,
                        'workflowReturn' => $workflowReturn['has'] ? $workflowReturn : null,
                        'steps' => $stepDebugPanels->pluck('debug')->all(),
                    ]) }}"
                    download="workflow-run-{{ $workflowRun?->id ?? 'debug' }}.json"
                    class="shrink-0 rounded border border-slate-200 px-2 py-1 text-[10px] font-semibold text-slate-600 hover:bg-slate-50"
                >
                    Run JSON
                </a>
            </div>

            <div class="mt-3 space-y-3">
                @forelse($stepDebugPanels as $panel)
                    <div class="rounded-md border border-slate-100 bg-slate-50 p-3">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="truncate text-xs font-semibold text-slate-900">{{ $panel['title'] }}</div>
                                    <div class="mt-1 flex flex-wrap items-center gap-1 text-[11px] text-slate-500">
                                        <span>{{ $panel['status'] }}</span>
                                        @if($panel['external'])
                                            <span>·</span>
                                            <span class="truncate">{{ $panel['external'] }}</span>
                                        @endif
                                    </div>
                                </div>
                                <a
                                    href="{{ $jsonDownload($panel['debug']) }}"
                                    download="{{ $downloadName($panel['title']) }}-step-{{ data_get($panel, 'debug.workflowStepRunId') }}.json"
                                    class="shrink-0 rounded border border-slate-200 bg-white px-2 py-1 text-[10px] font-semibold text-slate-600 hover:bg-slate-100"
                                >
                                    Debug
                                </a>
                            </div>

                            @if($panel['message'])
                                <div class="mt-2 rounded bg-white px-2 py-1 text-[11px] text-slate-600">{{ $panel['message'] }}</div>
                            @endif

                            @if($panel['return']['has'])
                                <div class="mt-2 rounded border border-indigo-100 bg-indigo-50 px-2 py-1 text-[11px] text-indigo-800">
                                    <span class="font-semibold">Rueckgabe:</span>
                                    {{ $panel['return']['key'] }} = {{ $panel['return']['valueLabel'] }}
                                    <span class="text-indigo-600">· OK: {{ $panel['return']['okLabel'] }}</span>
                                </div>
                            @endif

                            @if($panel['tasks']->isNotEmpty())
                                <div class="mt-2 space-y-1">
                                    @foreach($panel['tasks'] as $task)
                                        <div class="flex items-center justify-between gap-2 rounded border border-white bg-white px-2 py-1 text-[11px]">
                                            <div class="min-w-0">
                                                <div class="truncate font-semibold text-slate-700">{{ $task['title'] }}</div>
                                                <div class="truncate text-slate-400">
                                                    {{ $task['status'] }}
                                                    @if($task['runner'])
                                                        · {{ $task['runner'] }}
                                                    @endif
                                                    @if($task['node_script'])
                                                        · {{ $task['node_script'] }}
                                                    @elseif($task['php_handler'])
                                                        · {{ $task['php_handler'] }}
                                                    @endif
                                                </div>
                                                @if($task['return']['has'])
                                                    <div class="mt-1 break-words text-indigo-600">
                                                        Rueckgabe: {{ $task['return']['key'] }} = {{ $task['return']['valueLabel'] }}
                                                        · OK: {{ $task['return']['okLabel'] }}
                                                    </div>
                                                @endif
                                            </div>
                                            <a
                                                href="{{ $jsonDownload($task['debug']) }}"
                                                download="{{ $downloadName($panel['title'].' '.$task['title']) }}.json"
                                                class="shrink-0 rounded border border-slate-200 px-2 py-1 text-[10px] font-semibold text-slate-600 hover:bg-slate-50"
                                            >
                                                JSON
                                            </a>
                                        </div>
                                    @endforeach
                                </div>
                            @endif

                            @if($panel['events'] !== [])
                                <div class="mt-2 space-y-1">
                                    @foreach(array_reverse($panel['events']) as $event)
                                        <div class="rounded bg-white px-2 py-1 text-[11px] text-slate-500">
                                            <span class="font-semibold text-slate-700">{{ data_get($event, 'stage', data_get($event, 'type', '-')) }}</span>
                                            <span>{{ data_get($event, 'message', data_get($event, 'text', '')) }}</span>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @empty
                        <div class="text-sm text-slate-500">Noch keine Debugdaten.</div>
                    @endforelse
                </div>
        </div>
    </div>
</div>
