@props([
    'runs',
])

<div {{ $attributes->merge(['class' => 'divide-y divide-slate-100']) }}>
    @forelse($runs as $run)
        <div class="py-4">
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
                <div class="text-right text-xs text-slate-500">
                    <div>{{ optional($run->started_at ?? $run->queued_at)->format('d.m.Y H:i') }}</div>
                    @if($run->finished_at)
                        <div>{{ $run->finished_at->format('d.m.Y H:i') }}</div>
                    @endif
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
        </div>
    @empty
        <div class="py-8 text-center text-sm text-slate-500">
            Noch keine Workflow-Laeufe.
        </div>
    @endforelse
</div>
