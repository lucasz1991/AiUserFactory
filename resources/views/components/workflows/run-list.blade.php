@props([
    'runs',
])

<div {{ $attributes->merge(['class' => 'divide-y divide-slate-100']) }}>
    @forelse($runs as $run)
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
                    </div>
                    <button type="button" x-on:click="workflowPreviewOpen = true" class="rounded border border-blue-200 bg-white px-3 py-1.5 text-xs font-semibold text-blue-700 hover:bg-blue-50">
                        Vorschau
                    </button>
                </div>
            </div>

            @if($run->stepRuns->isNotEmpty())
                <div class="mt-3 grid gap-2 md:grid-cols-2">
                    @foreach($run->stepRuns as $stepRun)
                        <div class="rounded-md border border-slate-100 bg-slate-50 px-3 py-2">
                            <div class="flex items-center justify-between gap-2">
                                <span class="truncate text-xs font-semibold text-slate-700">{{ $stepRun->workflowStep?->name ?? 'Schritt' }}</span>
                                <x-workflows.status-badge :status="$stepRun->status" />
                            </div>
                            @if($stepRun->external_run_id)
                                <p class="mt-1 truncate text-[11px] text-slate-500">{{ $stepRun->external_run_type }} · {{ $stepRun->external_run_id }}</p>
                            @endif
                            @if(is_array(data_get($stepRun->result_json, 'tasks')) && data_get($stepRun->result_json, 'tasks') !== [])
                                <div class="mt-2 space-y-1">
                                    @foreach(data_get($stepRun->result_json, 'tasks', []) as $task)
                                        <div class="rounded border border-white bg-white px-2 py-1 text-[11px] text-slate-600">
                                            <div class="flex items-center justify-between gap-2">
                                                <span class="truncate font-semibold">{{ data_get($task, 'title', 'Task') }}</span>
                                                <span class="shrink-0 text-slate-400">{{ data_get($task, 'status', '-') }}</span>
                                            </div>
                                            @if(data_get($task, 'runner') || data_get($task, 'node_script') || data_get($task, 'php_handler') || data_get($task, 'timeout_seconds'))
                                                <div class="mt-1 truncate text-slate-400">
                                                    {{ data_get($task, 'runner', '-') }}
                                                    @if(data_get($task, 'node_script'))
                                                        - {{ data_get($task, 'node_script') }}
                                                    @elseif(data_get($task, 'php_handler'))
                                                        - {{ data_get($task, 'php_handler') }}
                                                    @endif
                                                    @if((int) data_get($task, 'timeout_seconds', 0) > 0)
                                                        - {{ (int) data_get($task, 'timeout_seconds') }}s
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
