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
    $taskTone = static function (string $status, bool $active): string {
        return match (true) {
            $active || in_array($status, ['running', 'waiting'], true) => 'border-amber-300 bg-amber-50 text-amber-900 shadow-amber-100',
            $status === 'completed' || $status === 'success' => 'border-emerald-300 bg-emerald-50 text-emerald-900 shadow-emerald-100',
            in_array($status, ['failed', 'timeout'], true) => 'border-red-300 bg-red-50 text-red-900 shadow-red-100',
            default => 'border-slate-200 bg-white text-slate-600 shadow-slate-100',
        };
    };
    $connectorTone = static function (string $status, bool $active): string {
        return match (true) {
            $active || in_array($status, ['running', 'waiting'], true) => 'bg-amber-300 text-amber-400',
            $status === 'completed' || $status === 'success' => 'bg-emerald-300 text-emerald-400',
            in_array($status, ['failed', 'timeout'], true) => 'bg-red-300 text-red-400',
            default => 'bg-slate-200 text-slate-300',
        };
    };
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

        <div class="overflow-x-auto pb-2">
            <div class="flex min-w-max items-start gap-0">
                @foreach($steps as $step)
                    @php
                        $stepRun = $stepRunByStep->get($step->id);
                        $isActiveStep = (int) $activeStepId === (int) $step->id;
                        $stepStatus = (string) ($stepRun?->status ?? 'configured');
                        $tasks = $step->task_cards;
                        $resultTasks = collect(data_get($stepRun?->result_json, 'tasks', []))->keyBy(fn ($task) => (string) data_get($task, 'key'));
                        $stepTone = $taskTone($stepStatus, $isActiveStep);
                    @endphp

                    <div class="flex items-start">
                        <div class="w-56 shrink-0">
                            <div class="mb-2 flex items-center justify-between gap-2 px-1">
                                <div class="truncate text-xs font-semibold text-slate-800">{{ $step->name }}</div>
                                @if($stepRun?->status)
                                    <span class="shrink-0 rounded-full px-2 py-0.5 text-[10px] font-semibold {{ $stepTone }}">
                                        {{ $stepRun->status }}
                                    </span>
                                @endif
                            </div>

                            <div class="space-y-0">
                                @forelse($tasks as $task)
                                    @php
                                        $taskKey = (string) ($task['key'] ?? '');
                                        $taskResult = $resultTasks->get($taskKey);
                                        $taskStatus = (string) data_get($taskResult, 'status', data_get($task, 'status', 'configured'));
                                        $isTaskActive = $isActiveStep && ($activeTaskKey === '' ? ($loop->first && in_array($stepStatus, ['running', 'waiting'], true)) : $taskKey === $activeTaskKey);
                                        $tone = $taskTone($taskStatus, $isTaskActive);
                                        $lineTone = $connectorTone($taskStatus, $isTaskActive);
                                    @endphp

                                    @if(! $loop->first)
                                        <div class="ml-4 h-4 w-px {{ $lineTone }}"></div>
                                    @endif

                                    <div class="relative rounded-md border px-2 py-1.5 text-[11px] shadow-sm {{ $tone }}">
                                        <div class="truncate font-semibold">{{ $task['title'] ?? 'Task' }}</div>
                                        <div class="mt-0.5 truncate opacity-70">{{ $taskStatus }}</div>
                                    </div>
                                @empty
                                    <div class="rounded-md border px-2 py-1.5 text-[11px] shadow-sm {{ $stepTone }}">
                                        <div class="truncate font-semibold">{{ $step->type_label }}</div>
                                        <div class="mt-0.5 truncate opacity-70">{{ $stepStatus }}</div>
                                    </div>
                                @endforelse
                            </div>
                        </div>

                        @if(! $loop->last)
                            <div class="flex h-20 w-12 shrink-0 items-center px-2">
                                <div class="h-px flex-1 {{ $connectorTone($stepStatus, $isActiveStep) }}"></div>
                                <div class="h-0 w-0 border-y-4 border-l-8 border-y-transparent {{ in_array($stepStatus, ['failed', 'timeout'], true) ? 'border-l-red-400' : ($isActiveStep || in_array($stepStatus, ['running', 'waiting'], true) ? 'border-l-amber-400' : ($stepStatus === 'completed' ? 'border-l-emerald-400' : 'border-l-slate-300')) }}"></div>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>

        @if(data_get($workflowRun->context_json, 'route_history'))
            <div class="flex flex-wrap gap-1 text-[11px]">
                @foreach(array_slice(data_get($workflowRun->context_json, 'route_history', []), -4) as $routeEvent)
                    @php
                        $outcome = (string) data_get($routeEvent, 'outcome', '-');
                        $routeClass = match ($outcome) {
                            'success' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
                            'failed', 'timeout' => 'bg-red-50 text-red-700 ring-red-200',
                            'partial', 'waiting' => 'bg-amber-50 text-amber-700 ring-amber-200',
                            default => 'bg-slate-100 text-slate-600 ring-slate-200',
                        };
                    @endphp
                    <span class="rounded-full px-2 py-1 font-semibold ring-1 {{ $routeClass }}">
                        {{ $outcome }} -> {{ data_get($routeEvent, 'route.label', data_get($routeEvent, 'route.action_key', data_get($routeEvent, 'route.type', '-'))) }}
                    </span>
                @endforeach
            </div>
        @endif
    @endif
</div>
