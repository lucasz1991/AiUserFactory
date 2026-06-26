@props([
    'step',
])

@php
    $enabledClass = $step->is_enabled
        ? 'border-transparent bg-transparent'
        : 'border-transparent bg-transparent opacity-70';
@endphp

<div {{ $attributes->merge(['class' => 'flex max-h-[760px] min-h-[260px] w-[310px] shrink-0 flex-col rounded-md border '.$enabledClass]) }}>
    <div class="px-2 pb-2 pt-1">
        <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <p class="font-semibold text-white">{{ $step->name }}</p>
                    @if(! $step->is_enabled)
                        <x-workflows.status-badge status="skipped" />
                    @endif
                </div>
                <p class="mt-1 text-xs font-semibold text-blue-100">{{ $step->type_label }}</p>
            </div>
            <div class="flex items-center gap-1">
                <div x-sort:handle class="flex h-8 w-8 cursor-grab items-center justify-center rounded-md text-xs font-bold text-blue-100 hover:bg-white/15 active:cursor-grabbing">
                    ::
                </div>
                @isset($actions)
                    <div class="relative" x-data="{ open: false }">
                        <button type="button" x-on:click.stop="open = ! open" class="flex h-8 w-8 items-center justify-center rounded-md text-blue-100 hover:bg-white/15 hover:text-white">
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
        x-data
        x-sort="$wire.reorderTaskCard({{ $step->id }}, $item, $position)"
        x-on:dragover.prevent="$event.dataTransfer.dropEffect = 'copy'"
        x-on:drop.prevent="const key = $event.dataTransfer.getData('text/plain'); if (key) { $wire.prepareTaskFromCatalog({{ $step->id }}, key); }"
        class="flex-1 space-y-3 overflow-auto px-2 pb-3"
    >
        @foreach($step->task_cards as $task)
            <div
                x-sort:item="{{ $task['key'] ?? '' }}"
                x-on:click.stop="focusedTask = @js($step->id.'::'.($task['key'] ?? ''))"
                x-on:dblclick.stop="$wire.openEditTaskCard({{ $step->id }}, @js($task['key'] ?? ''))"
                x-bind:class="focusedTask === @js($step->id.'::'.($task['key'] ?? '')) ? 'ring-2 ring-white ring-offset-2 ring-offset-[#0079bf]' : ''"
                class="rounded-md"
                wire:key="workflow-task-{{ $step->id }}-{{ $task['key'] ?? 'task' }}"
            >
                <x-workflows.task-card :task="$task">
                    <x-slot name="actions">
                        <button type="button" wire:click="openEditTaskCard({{ $step->id }}, @js($task['key'] ?? ''))" class="block w-full rounded px-3 py-2 text-left text-xs font-semibold text-slate-700 hover:bg-slate-100">
                            Bearbeiten
                        </button>
                        <button type="button" wire:click="removeTaskCard({{ $step->id }}, @js($task['key'] ?? ''))" wire:confirm="Step-Karte wirklich entfernen?" class="block w-full rounded px-3 py-2 text-left text-xs font-semibold text-red-700 hover:bg-red-50">
                            Entfernen
                        </button>
                    </x-slot>
                </x-workflows.task-card>
            </div>
        @endforeach

        @if($step->task_cards === [])
            <x-workflows.task-card :task="[
                'title' => $step->name,
                'description' => $step->config_summary,
                'kind' => $step->type === 'wait' ? 'wait' : 'data',
            ]" />
        @endif

        <button type="button" wire:click="$set('showTaskPanel', true)" class="block w-full rounded-md border border-dashed border-white/45 bg-transparent px-3 py-3 text-left text-sm font-semibold text-blue-50 transition hover:border-white hover:bg-white/10 hover:text-white">
            + Task am Listenende
        </button>
    </div>
</div>
