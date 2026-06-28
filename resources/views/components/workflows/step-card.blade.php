@props([
    'step',
    'locked' => false,
])

@php
    $enabledClass = $step->is_enabled
        ? 'border-slate-200 bg-white shadow-sm'
        : 'border-slate-200 bg-slate-50 opacity-70 shadow-sm';
    $routeNodeForStep = static function (?array $route): string {
        if (! is_array($route)) {
            return '';
        }

        $targetStep = trim((string) data_get($route, 'action_key', data_get($route, 'step', '')));
        $targetCard = trim((string) data_get($route, 'card_key', data_get($route, 'card', '')));

        if (in_array($targetStep, ['', 'next', 'end', 'fail'], true)) {
            return '';
        }

        return $targetStep.'::'.($targetCard !== '' ? $targetCard : '*');
    };
    $stepSuccessTarget = $routeNodeForStep(is_array(data_get($step->routes, 'success')) ? data_get($step->routes, 'success') : null);
    $stepFailedTarget = $routeNodeForStep(is_array(data_get($step->routes, 'failed')) ? data_get($step->routes, 'failed') : null);
@endphp

<div
    data-workflow-step-action="{{ $step->action_key }}"
    data-step-route-success="{{ $stepSuccessTarget }}"
    data-step-route-failed="{{ $stepFailedTarget }}"
    {{ $attributes->merge(['class' => 'relative z-10 flex min-h-[300px] w-[296px] shrink-0 flex-col rounded-xl border '.$enabledClass]) }}
>
    <div class="rounded-t-xl border-b border-slate-200 bg-slate-50/80 px-4 py-3">
        <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <p class="text-sm font-semibold leading-5 text-slate-900">{{ $step->name }}</p>
                    @if(! $step->is_enabled)
                        <x-workflows.status-badge status="skipped" />
                    @endif
                </div>
                <p class="mt-1 text-[11px] font-semibold uppercase tracking-wide text-slate-500">{{ $step->type_label }}</p>
            </div>
            <div class="flex items-center gap-1">
                @if(! $locked)
                    <div x-sort:handle class="flex h-8 w-8 cursor-grab items-center justify-center rounded-md text-xs font-bold text-slate-400 hover:bg-slate-200 hover:text-slate-700 active:cursor-grabbing">::</div>
                @endif
                @isset($actions)
                    <div class="relative" x-data="{ open: false }">
                        <button type="button" x-on:click.stop="open = ! open" class="flex h-8 w-8 items-center justify-center rounded-md text-slate-500 hover:bg-slate-200 hover:text-slate-900">
                            ...
                        </button>
                        <div x-cloak x-show="open" x-transition x-on:click.stop x-on:click.outside="open = false" class="absolute right-0 z-30 mt-1 w-40 rounded-md border border-slate-200 bg-white p-1 shadow-lg">
                            {{ $actions }}
                        </div>
                    </div>
                @endisset
            </div>
        </div>
    </div>

    <div
        x-data="{
            dragEffect(event) {
                return Array.from(event.dataTransfer.types || []).includes('application/x-workflow-task-catalog') ? 'copy' : 'move';
            },
            dropTask(event, position) {
                if (@js($locked)) return;
                const taskKey = event.dataTransfer.getData('application/x-workflow-task-key');
                const sourceStepId = event.dataTransfer.getData('application/x-workflow-source-step-id');
                const catalogKey = event.dataTransfer.getData('application/x-workflow-task-catalog') || event.dataTransfer.getData('text/plain');

                if (taskKey) {
                    $dispatch('moveWorkflowTaskCard', {
                        targetStepId: {{ $step->id }},
                        sourceStepId: sourceStepId,
                        taskKey: taskKey,
                        position: position,
                    });

                    return;
                }

                if (catalogKey) {
                    $wire.prepareTaskFromCatalog({{ $step->id }}, catalogKey, position);
                }
            },
        }"
        class="flex-1 space-y-0 px-3 pb-4 pt-2"
    >
        <div @if(! $locked) x-sort="$dispatch('reorderWorkflowTaskCards', { targetStepId: {{ $step->id }}, item: $item, position: $position })" @endif class="space-y-0">
            @foreach($step->task_cards as $task)
                @php
                    $taskKey = trim((string) ($task['key'] ?? ''));
                    $sourceNode = $step->action_key.'::'.$taskKey;
                    $routeNode = static function (?array $route) use ($step): string {
                        if (! is_array($route)) {
                            return '';
                        }

                        $targetStep = trim((string) data_get($route, 'action_key', data_get($route, 'step', '')));
                        $targetCard = trim((string) data_get($route, 'card_key', data_get($route, 'card', '')));

                        if (in_array($targetStep, ['', 'next', 'end', 'fail'], true)) {
                            $targetStep = $targetCard !== '' ? $step->action_key : '';
                        }

                        if ($targetStep === '') {
                            return '';
                        }

                        return $targetStep.'::'.($targetCard !== '' ? $targetCard : '*');
                    };
                    $successTarget = $routeNode(is_array($task['next'] ?? null) ? $task['next'] : null);
                    $failedTarget = $routeNode(is_array($task['on_error'] ?? null) ? $task['on_error'] : null);
                @endphp
                <div
                    @if(! $locked) x-sort:item="@js($step->id.'::'.($task['key'] ?? ''))" @endif
                    data-workflow-task-node="{{ $sourceNode }}"
                    data-workflow-step-action="{{ $step->action_key }}"
                    data-route-success="{{ $successTarget }}"
                    data-route-failed="{{ $failedTarget }}"
                    @if(! $locked) x-on:dragstart.stop="
                        $event.dataTransfer.setData('application/x-workflow-task-key', @js($task['key'] ?? ''));
                        $event.dataTransfer.setData('application/x-workflow-source-step-id', @js((string) $step->id));
                        $event.dataTransfer.effectAllowed = 'move';
                    " @endif
                    x-on:click.stop="focusedTask = @js($step->id.'::'.($task['key'] ?? ''))"
                    @if(! $locked) x-on:dblclick.stop="$wire.openEditTaskCard({{ $step->id }}, @js($task['key'] ?? ''))" @endif
                    x-bind:class="focusedTask === @js($step->id.'::'.($task['key'] ?? '')) ? 'ring-2 ring-slate-400 ring-offset-2 ring-offset-slate-100' : ''"
                    class="rounded-lg"
                    wire:key="workflow-task-{{ $step->id }}-{{ $task['key'] ?? 'task' }}"
                >
                    <div
                        x-on:dragover.prevent="$event.dataTransfer.dropEffect = dragEffect($event)"
                        x-on:drop.prevent.stop="dropTask($event, {{ $loop->index }})"
                        class="h-3 rounded border border-dashed border-transparent transition hover:h-8 hover:border-slate-300 hover:bg-slate-50"
                    ></div>
                    @if(! $loop->first)
                        <div class="ml-5 h-4 w-px bg-emerald-300"></div>
                    @endif
                    <x-workflows.task-card :task="$task">
                        @if(! $locked)
                            <x-slot name="actions">
                                <button type="button" wire:click="openEditTaskCard({{ $step->id }}, @js($task['key'] ?? ''))" class="block w-full rounded px-3 py-2 text-left text-xs font-semibold text-slate-700 hover:bg-slate-100">Bearbeiten</button>
                                <button type="button" wire:click="removeTaskCard({{ $step->id }}, @js($task['key'] ?? ''))" wire:confirm="Step-Karte wirklich entfernen?" class="block w-full rounded px-3 py-2 text-left text-xs font-semibold text-red-700 hover:bg-red-50">Entfernen</button>
                            </x-slot>
                        @endif
                    </x-workflows.task-card>
                </div>
            @endforeach
        </div>

        @if($step->task_cards === [])
            <div
                x-on:dragover.prevent="$event.dataTransfer.dropEffect = 'copy'"
                x-on:drop.prevent.stop="dropTask($event, 0)"
                class="rounded-lg border border-dashed border-slate-300 bg-slate-50 p-2 transition hover:bg-white"
            >
                <x-workflows.task-card :task="[
                    'title' => $step->name,
                    'description' => $step->config_summary,
                    'kind' => $step->type === 'wait' ? 'wait' : 'data',
                ]" />
            </div>
        @endif

        @if($step->task_cards !== [])
            <div class="ml-5 h-4 w-px bg-emerald-300"></div>
        @endif

        <div
            x-on:dragover.prevent="$event.dataTransfer.dropEffect = dragEffect($event)"
            x-on:drop.prevent.stop="dropTask($event, {{ count($step->task_cards) }})"
            class="mb-2 h-3 rounded border border-dashed border-transparent transition hover:h-8 hover:border-slate-300 hover:bg-slate-50"
        ></div>

        @if(! $locked)
            <button type="button" wire:click="$set('showTaskPanel', true)" class="block w-full rounded-lg border border-dashed border-slate-300 bg-slate-50/70 px-3 py-2.5 text-left text-sm font-semibold text-slate-600 transition hover:border-slate-400 hover:bg-slate-100 hover:text-slate-900">+ Task am Listenende</button>
        @endif
    </div>
</div>
