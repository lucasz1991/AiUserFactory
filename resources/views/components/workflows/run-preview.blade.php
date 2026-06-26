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
            default => [],
        };
    };
    $jsonDownload = static function (array $payload): string {
        return 'data:application/json;base64,'.base64_encode(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    };
    $downloadName = static function (string $name): string {
        return \Illuminate\Support\Str::slug($name) ?: 'workflow-debug';
    };
    $stepRuns = $workflowRun?->stepRuns ?? collect();
    $screenshotPanels = collect($stepRuns)
        ->flatMap(function ($stepRun) use ($publicUrl, $windowStatus, $liveStatusForStepRun) {
            $storedResult = is_array($stepRun->result_json) ? $stepRun->result_json : [];
            $liveStatus = $liveStatusForStepRun($stepRun);
            $result = array_replace_recursive($storedResult, $liveStatus);
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
                    'image' => $image,
                    'window' => $windowStatus($window, $result),
                    'dom' => data_get($window, 'debugDomUrl'),
                    'step' => $stepRun->workflowStep?->name ?? 'Schritt',
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
                    $panels[] = $panel;
                }
            }

            return $panels;
        })
        ->filter(fn ($panel) => $panel['image'] || is_array($panel['window']) || $panel['dom'])
        ->unique(fn ($panel) => ($panel['title'] ?? '').'|'.($panel['image'] ?? '').'|'.($panel['step'] ?? ''))
        ->values();
    $latestStatusResult = collect($stepRuns)
        ->reverse()
        ->map(function ($stepRun) use ($liveStatusForStepRun) {
            $storedResult = is_array($stepRun->result_json) ? $stepRun->result_json : [];

            return array_replace_recursive($storedResult, $liveStatusForStepRun($stepRun));
        })
        ->first(fn (array $result): bool => $result !== []) ?? [];
    $stepDebugPanels = collect($stepRuns)
        ->map(function ($stepRun) use ($liveStatusForStepRun) {
            $step = $stepRun->workflowStep;
            $storedResult = is_array($stepRun->result_json) ? $stepRun->result_json : [];
            $storedLogs = is_array($stepRun->logs_json) ? $stepRun->logs_json : [];
            $liveStatus = $liveStatusForStepRun($stepRun);
            $result = array_replace_recursive($storedResult, $liveStatus);
            $resultTasks = collect(is_array(data_get($result, 'tasks')) ? data_get($result, 'tasks') : [])
                ->filter(fn ($task) => is_array($task))
                ->keyBy(fn ($task) => (string) data_get($task, 'key'));
            $templateTasks = collect($step?->task_cards ?? []);
            $tasks = $templateTasks
                ->map(function (array $task) use ($resultTasks, $stepRun, $result) {
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
                    $debug = [
                        'workflowRunId' => $stepRun->workflow_run_id,
                        'workflowStepRunId' => $stepRun->id,
                        'workflowStepId' => $stepRun->workflow_step_id,
                        'externalRunType' => $stepRun->external_run_type,
                        'externalRunId' => $stepRun->external_run_id,
                        'status' => $status,
                        'task' => $task,
                        'resultTask' => $resultTask,
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
@endphp

<div {{ $attributes->merge(['class' => 'space-y-5']) }}>
    @if($process)
        <div class="rounded-md border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600">
            <span class="font-semibold text-slate-900">Prozess:</span>
            PID {{ $process->pid }} · {{ $process->process_type }} · {{ $process->status }}
        </div>
    @endif

    <x-workflows.minimap
        :workflow-run="$workflowRun"
        :active-step-id="$activeStepId"
        :active-task-key="$activeTaskKey"
    />

    <div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_minmax(360px,460px)]">
        <div class="grid gap-3">
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
                            <a href="{{ $panel['dom'] }}" download="workflow-preview-dom.json" class="rounded border border-slate-700 px-2 py-1 text-[10px] text-slate-200 hover:bg-slate-800">
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
                <div class="rounded-md border border-dashed border-slate-300 bg-slate-50 p-4 text-sm text-slate-500">
                    Fuer diesen Workflow-Lauf wurden noch keine Browser-Screenshots gespeichert.
                </div>
            @endforelse
        </div>

        <div class="space-y-4">
            <div class="rounded-lg border border-slate-200 bg-white p-4">
                <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Status</div>
                <div class="mt-2 text-sm font-semibold text-slate-900">
                    {{ data_get($latestStatusResult, 'statusMessage', data_get($latestStatusResult, 'message', $workflowRun?->status)) }}
                </div>
                <div class="mt-2 break-all text-xs text-slate-500">
                    Run: {{ $workflowRun?->run_uuid ?: '-' }}
                </div>
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

            <div class="max-h-[28rem] overflow-auto rounded-lg border border-slate-200 bg-white p-4">
                <div class="flex items-center justify-between gap-3">
                    <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Logs & Debug</div>
                    <a
                        href="{{ $jsonDownload([
                            'workflowRunId' => $workflowRun?->id,
                            'workflowRunUuid' => $workflowRun?->run_uuid,
                            'status' => $workflowRun?->status,
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
</div>
