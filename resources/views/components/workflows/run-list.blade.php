@props([
    'runs',
])

@php
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
@endphp

<div {{ $attributes->merge(['class' => 'divide-y divide-slate-100']) }}>
    @forelse($runs as $run)
        @php
            $durationLabel = $formatDuration($runDurationMs($run));
        @endphp
        <div x-data="{ workflowPreviewOpen: false }" class="py-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <p class="font-semibold text-slate-900">#{{ $run->id }}</p>
                        <x-workflows.status-badge :status="$run->status" />
                    </div>
                    <p class="mt-1 break-all text-xs text-slate-500">{{ $run->run_uuid }}</p>
                    @if($run->error_message)
                        <p class="mt-2 text-sm font-semibold text-red-700">{{ $run->error_message }}</p>
                    @endif
                </div>
                <div class="flex flex-col items-end gap-2 text-right text-xs text-slate-500">
                    <div>
                        <div>{{ optional($run->started_at ?? $run->queued_at)->format('d.m.Y H:i') }}</div>
                        @if($run->finished_at)
                            <div>{{ $run->finished_at->format('d.m.Y H:i') }}</div>
                        @endif
                        <div class="font-semibold text-slate-600">Dauer: {{ $durationLabel }}</div>
                    </div>
                    <button type="button" x-on:click="workflowPreviewOpen = true" class="rounded border border-blue-200 bg-white px-3 py-1.5 text-xs font-semibold text-blue-700 hover:bg-blue-50">
                        Vorschau
                    </button>
                </div>
            </div>

            @if($run->stepRuns->isNotEmpty())
                <div class="mt-3 grid gap-2 md:grid-cols-2">
                    @foreach($run->stepRuns as $stepRun)
                        @php
                            $stepTasks = collect($stepRun->workflowStep?->task_cards ?? []);
                            $resultTasks = collect(is_array(data_get($stepRun->result_json, 'tasks')) ? data_get($stepRun->result_json, 'tasks') : [])
                                ->filter(fn ($task) => is_array($task))
                                ->keyBy(fn ($task) => (string) data_get($task, 'key'));
                            $displayTasks = $stepTasks->map(function (array $task) use ($resultTasks, $stepRun) {
                                $taskKey = (string) ($task['key'] ?? '');
                                $resultTask = $resultTasks->get($taskKey);
                                $isPlannedOnly = ($stepRun->workflowStep?->type ?? null) === \App\Models\WorkflowStep::TYPE_PLANNED_ACTION
                                    && trim((string) $stepRun->external_run_id) === '';
                                $status = $isPlannedOnly
                                    ? 'not_executed'
                                    : (is_array($resultTask)
                                    ? (string) data_get($resultTask, 'status', $stepRun->status)
                                    : (($stepRun->status === 'completed' && $resultTasks->isEmpty()) ? 'not_executed' : 'configured'));

                                return [
                                    'title' => (string) ($task['title'] ?? 'Task'),
                                    'status' => $status,
                                    'runner' => (string) ($task['runner'] ?? ''),
                                    'node_script' => (string) ($task['node_script'] ?? ''),
                                    'php_handler' => (string) ($task['php_handler'] ?? ''),
                                    'debug' => [
                                        'workflowRunId' => $run->id,
                                        'workflowStepRunId' => $stepRun->id,
                                        'workflowStepId' => $stepRun->workflow_step_id,
                                        'externalRunType' => $stepRun->external_run_type,
                                        'externalRunId' => $stepRun->external_run_id,
                                        'status' => $status,
                                        'task' => $task,
                                        'resultTask' => $resultTask,
                                        'logs' => is_array($stepRun->logs_json) ? $stepRun->logs_json : [],
                                    ],
                                ];
                            })->values();
                            $stepDebug = [
                                'workflowRunId' => $run->id,
                                'workflowRunUuid' => $run->run_uuid,
                                'workflowStepRunId' => $stepRun->id,
                                'workflowStepId' => $stepRun->workflow_step_id,
                                'stepName' => $stepRun->workflowStep?->name,
                                'stepType' => $stepRun->workflowStep?->type,
                                'status' => $stepRun->status,
                                'externalRunType' => $stepRun->external_run_type,
                                'externalRunId' => $stepRun->external_run_id,
                                'errorMessage' => $stepRun->error_message,
                                'logs' => is_array($stepRun->logs_json) ? $stepRun->logs_json : [],
                                'result' => is_array($stepRun->result_json) ? $stepRun->result_json : [],
                                'config' => $stepRun->workflowStep?->config_json,
                            ];
                        @endphp
                        <div class="rounded-md border border-slate-100 bg-slate-50 px-3 py-2">
                            <div class="flex items-center justify-between gap-2">
                                <span class="truncate text-xs font-semibold text-slate-700">{{ $stepRun->workflowStep?->name ?? 'Schritt' }}</span>
                                <div class="flex shrink-0 items-center gap-2">
                                    <a
                                        href="{{ $jsonDownload($stepDebug) }}"
                                        download="{{ $downloadName($stepRun->workflowStep?->name ?? 'schritt') }}-step-{{ $stepRun->id }}.json"
                                        class="rounded border border-slate-200 bg-white px-2 py-1 text-[10px] font-semibold text-slate-500 hover:bg-slate-100"
                                    >
                                        Debug
                                    </a>
                                    <x-workflows.status-badge :status="$stepRun->status" />
                                </div>
                            </div>
                            @if($stepRun->external_run_id)
                                <p class="mt-1 truncate text-[11px] text-slate-500">{{ $stepRun->external_run_type }} · {{ $stepRun->external_run_id }}</p>
                            @endif
                            @if($displayTasks->isNotEmpty())
                                <div class="mt-2 space-y-1">
                                    @foreach($displayTasks as $task)
                                        <div class="rounded border border-white bg-white px-2 py-1 text-[11px] text-slate-600">
                                            <div class="flex items-center justify-between gap-2">
                                                <span class="truncate font-semibold">{{ $task['title'] }}</span>
                                                <div class="flex shrink-0 items-center gap-2">
                                                    <span class="text-slate-400">{{ $task['status'] }}</span>
                                                    <a
                                                        href="{{ $jsonDownload($task['debug']) }}"
                                                        download="{{ $downloadName(($stepRun->workflowStep?->name ?? 'schritt').' '.$task['title']) }}.json"
                                                        class="rounded border border-slate-100 px-1.5 py-0.5 text-[10px] font-semibold text-slate-500 hover:bg-slate-50"
                                                    >
                                                        JSON
                                                    </a>
                                                </div>
                                            </div>
                                            @if($task['runner'] || $task['node_script'] || $task['php_handler'])
                                                <div class="mt-1 truncate text-slate-400">
                                                    {{ $task['runner'] ?: '-' }}
                                                    @if($task['node_script'])
                                                        - {{ $task['node_script'] }}
                                                    @elseif($task['php_handler'])
                                                        - {{ $task['php_handler'] }}
                                                    @endif
                                                </div>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif

            @if(is_array(data_get($run->context_json, 'route_history')) && data_get($run->context_json, 'route_history') !== [])
                <div class="mt-3 rounded-md border border-slate-100 bg-slate-50 p-3">
                    <div class="text-[11px] font-semibold uppercase text-slate-500">Weiterleitungen</div>
                    <div class="mt-2 space-y-1">
                        @foreach(array_slice(data_get($run->context_json, 'route_history', []), -6) as $routeEvent)
                            <div class="rounded bg-white px-2 py-1 text-[11px] text-slate-600">
                                {{ data_get($routeEvent, 'outcome', '-') }} -> {{ data_get($routeEvent, 'route.label', data_get($routeEvent, 'route.action_key', data_get($routeEvent, 'route.type', '-'))) }}
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            <div
                x-show="workflowPreviewOpen"
                x-cloak
                class="fixed inset-0 z-50 overflow-y-auto px-4 py-4 sm:px-6 sm:py-8"
                style="display: none;"
                x-on:keydown.escape.window="workflowPreviewOpen = false"
            >
                <div class="fixed inset-0 bg-gray-500 opacity-75" x-on:click="workflowPreviewOpen = false"></div>
                <div class="relative z-10 mx-auto flex min-h-full max-w-6xl items-center justify-center">
                    <div class="flex max-h-[calc(100vh-4rem)] w-full flex-col overflow-hidden rounded-lg bg-white shadow-xl" x-trap.inert.noscroll="workflowPreviewOpen">
                        <div class="flex items-start justify-between gap-4 border-b border-gray-200 px-6 py-4">
                            <div>
                                <h3 class="text-lg font-semibold text-gray-900">Workflow-Vorschau</h3>
                                <p class="mt-1 text-sm text-gray-500">Run #{{ $run->id }} · {{ $run->status }}</p>
                            </div>
                            <button type="button" x-on:click="workflowPreviewOpen = false" class="rounded-md p-2 text-gray-400 hover:bg-gray-100 hover:text-gray-600">
                                <span class="sr-only">Schliessen</span>
                                <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>
                        <div class="min-h-0 flex-1 overflow-y-auto p-6">
                            <x-workflows.run-preview :workflow-run="$run" />
                        </div>
                        <div class="flex justify-end border-t border-gray-200 bg-gray-50 px-6 py-4">
                            <button type="button" x-on:click="workflowPreviewOpen = false" class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50">
                                Schliessen
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @empty
        <div class="py-8 text-center text-sm text-slate-500">
            Noch keine Workflow-Laeufe.
        </div>
    @endforelse
</div>
