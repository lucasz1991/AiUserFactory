@props([
    'step',
    'locked' => false,
])

@php
    $enabledClass = $step->is_enabled
        ? 'border-slate-200 bg-white/70'
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
    $routeNodeForTask = static function (?array $route) use ($step): string {
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
@endphp

<div
    tabindex="-1"
    data-workflow-step-column
    data-workflow-step-id="{{ $step->id }}"
    data-workflow-step-action="{{ $step->action_key }}"
    data-assistant-highlight="workflow_list:{{ $step->action_key }}"
    data-assistant-highlight-key="{{ $step->action_key }}"
    data-step-route-success="{{ $stepSuccessTarget }}"
    data-step-route-failed="{{ $stepFailedTarget }}"
    {{ $attributes->merge(['class' => 'ff-step-column group/step relative flex min-h-[300px] w-[296px] min-w-[296px] max-w-[296px] shrink-0 flex-col rounded-xl border '.$enabledClass]) }}
>
    <div
        data-workflow-route-node="{{ $step->action_key }}::*"
        data-workflow-step-action="{{ $step->action_key }}"
        class="ff-step-header relative z-30 mb-4 rounded-xl border px-4 py-3"
    >
        <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <p class="text-sm font-bold leading-5 text-white">{{ $step->name }}</p>
                    @if(! $step->is_enabled)
                        <x-workflows.status-badge status="skipped" />
                    @endif
                </div>
                <p class="mt-1 text-[10px] font-semibold uppercase tracking-[.12em] text-slate-400">{{ $step->type_label }}</p>
            </div>
            <div class="flex items-center gap-1">
                @if(! $locked)
                    <div x-sort:handle class="ff-step-header-control flex h-8 w-8 cursor-grab items-center justify-center rounded-md text-slate-400 transition hover:bg-white/10 hover:text-white active:cursor-grabbing" title="Liste verschieben" aria-label="Liste verschieben">
                        <svg class="h-4 w-4" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><circle cx="5" cy="3" r="1"></circle><circle cx="11" cy="3" r="1"></circle><circle cx="5" cy="8" r="1"></circle><circle cx="11" cy="8" r="1"></circle><circle cx="5" cy="13" r="1"></circle><circle cx="11" cy="13" r="1"></circle></svg>
                    </div>
                @endif
                @isset($actions)
                    <div class="relative" x-data="{ open: false }">
                        <button type="button" x-on:click.stop="open = ! open" x-bind:aria-expanded="open" class="ff-step-header-control flex h-8 w-8 items-center justify-center rounded-md text-slate-400 transition hover:bg-white/10 hover:text-white" aria-label="Listenaktionen">
                            <svg class="h-4 w-4" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><circle cx="3" cy="8" r="1.25"></circle><circle cx="8" cy="8" r="1.25"></circle><circle cx="13" cy="8" r="1.25"></circle></svg>
                        </button>
                        <div x-cloak x-show="open" x-transition.origin.top.right x-on:click.stop x-on:click.outside="open = false" class="ff-menu absolute right-0 z-30 mt-1 w-40 p-1">
                            {{ $actions }}
                        </div>
                    </div>
                @endisset
            </div>
        </div>
        @if(! $locked)
            <div
                class="ff-touch-step-reorder mt-3"
                role="group"
                aria-label="Liste {{ $step->name }} verschieben"
            >
                <button
                    type="button"
                    class="ff-touch-reorder-button"
                    data-touch-step-move="left"
                    x-on:click.stop="$dispatch('workflow-step-move-requested', { stepId: {{ (int) $step->id }}, direction: 'left' })"
                    aria-label="Liste nach links verschieben"
                    title="Liste nach links"
                >
                    <svg class="h-4 w-4" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M10.5 3.5 6 8l4.5 4.5"></path></svg>
                </button>
                <button
                    type="button"
                    class="ff-touch-reorder-button"
                    data-touch-step-move="right"
                    x-on:click.stop="$dispatch('workflow-step-move-requested', { stepId: {{ (int) $step->id }}, direction: 'right' })"
                    aria-label="Liste nach rechts verschieben"
                    title="Liste nach rechts"
                >
                    <svg class="h-4 w-4" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m5.5 3.5 4.5 4.5-4.5 4.5"></path></svg>
                </button>
            </div>
        @endif
    </div>

    <div
        x-data="{
            dragInside: false,
            draggingTask: false,
            taskActivationBlocked: false,
            dragSequence: 0,
            dragEffect(event) {
                return Array.from(event.dataTransfer.types || []).includes('application/x-workflow-task-catalog') ? 'copy' : 'move';
            },
            beginTaskDrag(event, taskKey, sourceStepId) {
                this.dragSequence += 1;
                this.draggingTask = true;
                this.taskActivationBlocked = true;
                event.dataTransfer.setData('application/x-workflow-task-key', taskKey);
                event.dataTransfer.setData('application/x-workflow-source-step-id', sourceStepId);
                event.dataTransfer.setData('text/plain', taskKey);
                event.dataTransfer.effectAllowed = 'move';
            },
            finishTaskDrag() {
                const completedSequence = this.dragSequence;

                this.dragInside = false;
                this.draggingTask = false;

                window.setTimeout(() => {
                    if (this.dragSequence === completedSequence && !this.draggingTask) {
                        this.taskActivationBlocked = false;
                    }
                }, 220);
            },
            taskActivationAllowed(event) {
                if (!this.draggingTask && !this.taskActivationBlocked) {
                    return true;
                }

                event.preventDefault();
                event.stopPropagation();

                return false;
            },
            taskPositionFromEvent(event) {
                const list = this.$refs.taskList;

                if (!list) {
                    return 0;
                }

                const items = Array.from(list.querySelectorAll('[data-workflow-task-sort-item]'));

                if (items.length === 0) {
                    return 0;
                }

                const pointerY = event.clientY;
                let position = items.length;

                for (let index = 0; index < items.length; index += 1) {
                    const rect = items[index].getBoundingClientRect();

                    if (pointerY < rect.top + (rect.height / 2)) {
                        position = index;
                        break;
                    }
                }

                return position;
            },
            dropTask(event, position = null) {
                if (@js($locked)) return;
                const taskKey = event.dataTransfer.getData('application/x-workflow-task-key');
                const sourceStepId = event.dataTransfer.getData('application/x-workflow-source-step-id');
                const catalogKey = event.dataTransfer.getData('application/x-workflow-task-catalog') || event.dataTransfer.getData('text/plain');
                const targetPosition = position === null ? this.taskPositionFromEvent(event) : position;

                this.dragInside = false;

                if (taskKey) {
                    $dispatch('moveWorkflowTaskCard', {
                        targetStepId: {{ $step->id }},
                        sourceStepId: sourceStepId,
                        taskKey: taskKey,
                        position: targetPosition,
                    });

                    return;
                }

                if (catalogKey) {
                    $wire.prepareTaskFromCatalog({{ $step->id }}, catalogKey, targetPosition);
                }
            },
        }"
        x-on:dragenter.prevent="dragInside = true"
        x-on:dragover.prevent="$event.dataTransfer.dropEffect = dragEffect($event)"
        x-on:dragleave.self="dragInside = false"
        x-on:drop.prevent.stop="dropTask($event)"
        x-on:dragend.window="finishTaskDrag()"
        x-bind:class="dragInside ? 'bg-slate-100/80 ring-2 ring-inset ring-slate-300' : ''"
        class="min-w-0 flex-1 space-y-0 px-3 pb-4 pt-2 transition"
    >
        <div x-ref="taskList" class="min-w-0 space-y-0">
            @foreach($step->task_cards as $task)
                @php
                    $taskKey = trim((string) ($task['key'] ?? ''));
                    $sourceNode = $step->action_key.'::'.$taskKey;
                    $successTarget = $routeNodeForTask(is_array($task['next'] ?? null) ? $task['next'] : null);
                    $failedTarget = $routeNodeForTask(is_array($task['on_error'] ?? null) ? $task['on_error'] : null);
                    $isLoopPairTask = trim((string) data_get($task, 'loop_pair_id', '')) !== '';
                    $previousTask = $loop->first ? null : ($step->task_cards[$loop->index - 1] ?? null);
                    $previousTarget = is_array($previousTask)
                        ? $routeNodeForTask(is_array($previousTask['next'] ?? null) ? $previousTask['next'] : null)
                        : '';
                    $previousNode = is_array($previousTask)
                        ? $step->action_key.'::'.trim((string) ($previousTask['key'] ?? ''))
                        : '';
                    $connectsFromPrevious = ! $loop->first && ($previousTarget === '' || $previousTarget === $sourceNode);
                @endphp
                <div
                    data-workflow-task-sort-item
                    class="min-w-0 max-w-full"
                    wire:key="workflow-task-{{ $step->id }}-{{ $task['key'] ?? 'task' }}"
                >
                    <div
                        x-on:dragover.prevent="$event.dataTransfer.dropEffect = dragEffect($event)"
                        x-on:drop.prevent.stop="dropTask($event, {{ $loop->index }})"
                        class="h-3 rounded border border-dashed border-transparent transition hover:h-8 hover:border-slate-300 hover:bg-slate-50"
                    ></div>
                    @if($connectsFromPrevious)
                        <div
                            class="ml-5 flex h-5 w-3 justify-center transition-opacity"
                            x-bind:class="routeFocusNode() && ![@js($previousNode), @js($sourceNode)].includes(routeFocusNode()) ? 'opacity-50' : 'opacity-100'"
                            aria-hidden="true"
                        >
                            <span
                                class="relative h-4 bg-emerald-400 transition-all"
                                x-bind:class="routeFocusNode() && [@js($previousNode), @js($sourceNode)].includes(routeFocusNode()) ? 'w-0.5 shadow-sm shadow-emerald-400' : 'w-px'"
                            >
                                <span class="absolute -bottom-1 -left-[3px] h-0 w-0 border-x-[4px] border-t-[5px] border-x-transparent border-t-emerald-500"></span>
                            </span>
                        </div>
                    @endif
                    <div
                        data-workflow-task-node="{{ $sourceNode }}"
                        data-workflow-step-action="{{ $step->action_key }}"
                        data-workflow-task-key="{{ $taskKey }}"
                        data-assistant-highlight="workflow_task:{{ $sourceNode }}"
                        data-assistant-highlight-key="{{ $taskKey }}"
                        data-route-success="{{ $successTarget }}"
                        data-route-failed="{{ $failedTarget }}"
                        @if(! $locked) draggable="true" @endif
                        @if(! $locked) x-on:dragstart.stop="beginTaskDrag($event, @js($task['key'] ?? ''), @js((string) $step->id))" @endif
                        x-on:mouseenter="setHoveredRouteNode(@js($sourceNode))"
                        x-on:mouseleave="setHoveredRouteNode('')"
                        x-on:click.stop="if (! taskActivationAllowed($event)) return; focusedTask = @js($step->id.'::'.($task['key'] ?? '')); setActiveRouteNode(@js($sourceNode))"
                        @if(! $locked) x-on:dblclick.stop="if (! taskActivationAllowed($event)) return; $wire.openEditTaskCard({{ $step->id }}, @js($task['key'] ?? ''))" @endif
                        x-bind:class="focusedTask === @js($step->id.'::'.($task['key'] ?? '')) ? 'ring-2 ring-slate-400 ring-offset-2 ring-offset-slate-100' : ''"
                        class="relative z-20 w-full min-w-0 max-w-full rounded-lg"
                    >
                        <x-workflows.task-card :task="$task" :show-ports="true">
                            @if(! $locked)
                                <x-slot name="actions">
                                    <button type="button" wire:click="openEditTaskCard({{ $step->id }}, @js($task['key'] ?? ''))" class="block w-full rounded px-3 py-2 text-left text-xs font-semibold text-slate-700 hover:bg-slate-100">Bearbeiten</button>
                                    <button type="button" wire:click="removeTaskCard({{ $step->id }}, @js($task['key'] ?? ''))" wire:confirm="{{ $isLoopPairTask ? 'Loop-Start und Loop-Ende wirklich entfernen?' : 'Step-Karte wirklich entfernen?' }}" class="block w-full rounded px-3 py-2 text-left text-xs font-semibold text-red-700 hover:bg-red-50">Entfernen</button>
                                </x-slot>
                            @endif
                        </x-workflows.task-card>
                        @if(! $locked)
                            <div
                                class="ff-touch-task-reorder"
                                role="group"
                                aria-label="Task {{ $task['title'] ?? 'Task' }} verschieben"
                                x-on:dblclick.stop
                            >
                                <button
                                    type="button"
                                    class="ff-touch-reorder-button"
                                    data-touch-task-move="up"
                                    x-on:click.stop="$dispatch('workflow-task-move-requested', { stepId: {{ (int) $step->id }}, taskKey: @js($taskKey), direction: 'up' })"
                                    aria-label="Task nach oben verschieben"
                                    title="Task nach oben"
                                    @disabled($loop->first)
                                >
                                    <svg class="h-4 w-4" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M3.5 10.5 8 6l4.5 4.5"></path></svg>
                                </button>
                                <button
                                    type="button"
                                    class="ff-touch-reorder-button"
                                    data-touch-task-move="down"
                                    x-on:click.stop="$dispatch('workflow-task-move-requested', { stepId: {{ (int) $step->id }}, taskKey: @js($taskKey), direction: 'down' })"
                                    aria-label="Task nach unten verschieben"
                                    title="Task nach unten"
                                    @disabled($loop->last)
                                >
                                    <svg class="h-4 w-4" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m3.5 5.5 4.5 4.5 4.5-4.5"></path></svg>
                                </button>
                                <button
                                    type="button"
                                    class="ff-touch-reorder-button"
                                    data-touch-task-move="another-list"
                                    x-on:click.stop="$dispatch('workflow-task-move-requested', { stepId: {{ (int) $step->id }}, taskKey: @js($taskKey), direction: 'another-list' })"
                                    aria-label="Task in eine andere Liste verschieben"
                                    title="In andere Liste"
                                >
                                    <svg class="h-4 w-4" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M2.5 4h7M2.5 8h7M2.5 12h7M11 5.5 13.5 8 11 10.5"></path></svg>
                                </button>
                            </div>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>

        @if($step->task_cards === [])
            <div
                x-on:dragover.prevent="$event.dataTransfer.dropEffect = dragEffect($event)"
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

        <div
            x-on:dragover.prevent="$event.dataTransfer.dropEffect = dragEffect($event)"
            x-on:drop.prevent.stop="dropTask($event, {{ count($step->task_cards) }})"
            class="mb-2 h-3 rounded border border-dashed border-transparent transition hover:h-8 hover:border-slate-300 hover:bg-slate-50"
        ></div>

        @if(! $locked)
            <button
                type="button"
                x-on:click="armTaskInsert({{ $step->id }}, @js($step->name))"
                class="block w-full rounded-lg border border-dashed border-slate-300 bg-slate-50/70 px-3 py-2.5 text-left text-sm font-semibold text-slate-600 opacity-0 transition hover:border-slate-400 hover:bg-slate-100 hover:text-slate-900 focus-visible:opacity-100 group-hover/step:opacity-100 group-focus-within/step:opacity-100 [@media(hover:none)]:opacity-100"
            >+ Task am Listenende</button>
        @endif
    </div>
</div>
