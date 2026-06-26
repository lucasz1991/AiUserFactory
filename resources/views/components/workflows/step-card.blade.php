@props([
    'step',
])

@php
    $enabledClass = $step->is_enabled
        ? 'border-slate-200 bg-[#ebecf0]'
        : 'border-slate-200 bg-slate-100 opacity-70';
    $visibleRoutes = collect($step->routes)->reject(fn ($route, string $outcome): bool => $outcome === 'partial');
@endphp

<div {{ $attributes->merge(['class' => 'flex max-h-[760px] min-h-[260px] w-[310px] shrink-0 flex-col rounded-md border shadow-sm '.$enabledClass]) }}>
    <div class="p-3">
        <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <p class="font-semibold text-slate-900">{{ $step->name }}</p>
                    @if(! $step->is_enabled)
                        <x-workflows.status-badge status="skipped" />
                    @endif
                </div>
                <p class="mt-1 text-xs font-semibold text-slate-500">{{ $step->type_label }}</p>
            </div>
            <div class="flex items-center gap-1">
                <div x-sort:handle class="flex h-8 w-8 cursor-grab items-center justify-center rounded-md text-xs font-bold text-slate-500 hover:bg-white active:cursor-grabbing">
                    ::
                </div>
                @isset($actions)
                    <div class="relative" x-data="{ open: false }">
                        <button type="button" x-on:click.stop="open = ! open" class="flex h-8 w-8 items-center justify-center rounded-md text-slate-500 hover:bg-white hover:text-slate-900">
                            ...
                        </button>
                        <div x-cloak x-show="open" x-transition x-on:click.stop x-on:click.outside="open = false" class="absolute right-0 z-30 mt-1 w-40 rounded-md border border-slate-200 bg-white p-1 shadow-lg">
                            {{ $actions }}
                        </div>
                    </div>
                @endisset
            </div>
        </div>

        <p class="mt-3 text-sm text-slate-600">{{ $step->config_summary }}</p>
        @if($step->wait_after_seconds > 0)
            <p class="mt-1 text-xs text-slate-400">Pause danach: {{ $step->wait_after_seconds }}s</p>
        @endif
        @if($visibleRoutes->isNotEmpty())
            <div class="mt-3 grid gap-1.5 text-[11px]">
                @foreach($visibleRoutes as $outcome => $route)
                    <div class="flex items-center gap-2 {{ $outcome === 'failed' ? 'text-red-700' : 'text-emerald-700' }}">
                        <span class="h-px flex-1 {{ $outcome === 'failed' ? 'bg-red-300' : 'bg-emerald-300' }}"></span>
                        <span class="h-0 w-0 border-y-4 border-l-8 border-y-transparent {{ $outcome === 'failed' ? 'border-l-red-400' : 'border-l-emerald-400' }}"></span>
                        <span class="max-w-[180px] truncate font-semibold">{{ data_get($route, 'label', data_get($route, 'action_key', data_get($route, 'target', data_get($route, 'type', 'route')))) }}</span>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    <div
        x-data
        x-sort="$wire.reorderTaskCard({{ $step->id }}, $item, $position)"
        x-on:dragover.prevent="$event.dataTransfer.dropEffect = 'copy'"
        x-on:drop.prevent="const key = $event.dataTransfer.getData('text/plain'); if (key) { $wire.prepareTaskFromCatalog({{ $step->id }}, key); }"
        class="flex-1 space-y-3 overflow-auto px-2 pb-3"
    >
        @forelse($step->task_cards as $task)
            <div
                x-sort:item="{{ $task['key'] ?? '' }}"
                x-on:click.stop="focusedTask = @js($step->id.'::'.($task['key'] ?? ''))"
                x-on:dblclick.stop="$wire.openEditTaskCard({{ $step->id }}, @js($task['key'] ?? ''))"
                x-bind:class="focusedTask === @js($step->id.'::'.($task['key'] ?? '')) ? 'ring-2 ring-blue-500 ring-offset-2 ring-offset-[#ebecf0]' : ''"
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
        @empty
            <x-workflows.task-card :task="[
                'title' => $step->name,
                'description' => $step->config_summary,
                'kind' => $step->type === 'wait' ? 'wait' : 'data',
            ]" />
        @endforelse
    </div>
</div>
