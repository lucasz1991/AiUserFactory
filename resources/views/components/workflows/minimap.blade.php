@props([
    'workflowRun',
    'activeStepId' => null,
    'activeTaskKey' => null,
    'compact' => false,
])

@php
    $workflow = $workflowRun?->workflow;
    $steps = $workflow?->steps ?? collect();
    $stepRuns = $workflowRun?->stepRuns ?? collect();
    $runningStepRun = $stepRuns->first(fn ($stepRun) => in_array($stepRun->status, ['running', 'waiting'], true));
    $activeStepId = $activeStepId ?: ($workflowRun?->current_workflow_step_id ?: $runningStepRun?->workflow_step_id);
    $activeTaskKey = trim((string) ($activeTaskKey ?: data_get($workflowRun?->context_json, 'next_task_key', '')));
    $stepRunByStep = $stepRuns->keyBy('workflow_step_id');
@endphp

<div {{ $attributes->merge(['class' => 'space-y-3']) }}>
    @if(! $workflowRun || ! $workflow)
        <div class="rounded-md border border-dashed border-slate-300 bg-slate-50 p-4 text-sm text-slate-500">
            Keine Workflow-Daten fuer diesen Prozess gefunden.
        </div>
    @else
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="min-w-0">
                <div class="truncate text-sm font-semibold text-slate-900">{{ $workflow->name }}</div>
                <div class="mt-1 truncate text-xs text-slate-500">Run #{{ $workflowRun->id }} - {{ $workflowRun->status }}</div>
            </div>
            <x-workflows.status-badge :status="$workflowRun->status" />
        </div>

        <div class="flex gap-2 overflow-x-auto pb-2">
            @foreach($steps as $step)
                @php
                    $stepRun = $stepRunByStep->get($step->id);
                    $isActive = (int) $activeStepId === (int) $step->id;
                    $stepStatus = $stepRun?->status;
                    $frameClass = match (true) {
                        $isActive => 'border-blue-300 bg-blue-50',
                        $stepStatus === 'completed' => 'border-emerald-200 bg-emerald-50',
                        $stepStatus === 'failed' => 'border-red-200 bg-red-50',
                        $stepStatus === 'waiting' => 'border-amber-200 bg-amber-50',
                        default => 'border-slate-200 bg-white',
                    };
                    $tasks = $step->task_cards;
                    $resultTasks = collect(data_get($stepRun?->result_json, 'tasks', []))->keyBy(fn ($task) => (string) data_get($task, 'key'));
                @endphp

                <div class="w-48 shrink-0 rounded-md border p-2 {{ $frameClass }}">
                    <div class="flex items-center justify-between gap-2">
                        <div class="truncate text-xs font-semibold text-slate-800">{{ $step->name }}</div>
                        @if($stepStatus)
                            <span class="shrink-0 text-[10px] font-semibold text-slate-500">{{ $stepStatus }}</span>
                        @endif
                    </div>

                    <div class="mt-2 space-y-1">
                        @forelse($tasks as $task)
                            @php
                                $taskKey = (string) ($task['key'] ?? '');
                                $taskStatus = data_get($resultTasks->get($taskKey), 'status', data_get($task, 'status', 'configured'));
                                $isTaskActive = $isActive && $activeTaskKey !== '' && $taskKey === $activeTaskKey;
                                $taskClass = match (true) {
                                    $isTaskActive => 'border-blue-300 bg-blue-100 text-blue-900',
                                    $taskStatus === 'completed' => 'border-emerald-200 bg-white text-emerald-800',
                                    $taskStatus === 'failed' => 'border-red-200 bg-white text-red-800',
                                    $taskStatus === 'timeout' => 'border-orange-200 bg-white text-orange-800',
                                    default => 'border-slate-200 bg-white text-slate-600',
                                };
                            @endphp
                            <div class="truncate rounded border px-2 py-1 text-[11px] {{ $taskClass }}">
                                {{ $task['title'] ?? 'Task' }}
                            </div>
                        @empty
                            <div class="truncate rounded border border-slate-200 bg-white px-2 py-1 text-[11px] text-slate-500">
                                {{ $step->type_label }}
                            </div>
                        @endforelse
                    </div>
                </div>
            @endforeach
        </div>

        @if(data_get($workflowRun->context_json, 'route_history'))
            <div class="flex flex-wrap gap-1 text-[11px]">
                @foreach(array_slice(data_get($workflowRun->context_json, 'route_history', []), -4) as $routeEvent)
                    <span class="rounded-full bg-slate-100 px-2 py-1 font-semibold text-slate-600">
                        {{ data_get($routeEvent, 'outcome', '-') }} -> {{ data_get($routeEvent, 'route.label', data_get($routeEvent, 'route.action_key', data_get($routeEvent, 'route.type', '-'))) }}
                    </span>
                @endforeach
            </div>
        @endif
    @endif
</div>
