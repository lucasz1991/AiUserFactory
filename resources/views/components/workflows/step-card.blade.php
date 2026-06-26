@props([
    'step',
])

@php
    $enabledClass = $step->is_enabled
        ? 'border-slate-200 bg-slate-100'
        : 'border-slate-200 bg-slate-50 opacity-70';
@endphp

<div {{ $attributes->merge(['class' => 'flex max-h-[760px] min-h-[260px] w-[310px] shrink-0 flex-col rounded-md border shadow-sm '.$enabledClass]) }}>
    <div class="border-b border-slate-200 p-3">
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
            <div x-sort:handle class="flex h-8 w-8 cursor-grab items-center justify-center rounded-md border border-slate-200 bg-white text-xs font-bold text-slate-500 active:cursor-grabbing">
                ::
            </div>
        </div>

        <p class="mt-3 text-sm text-slate-600">{{ $step->config_summary }}</p>
        @if($step->wait_after_seconds > 0)
            <p class="mt-1 text-xs text-slate-400">Pause danach: {{ $step->wait_after_seconds }}s</p>
        @endif
        @if($step->routes !== [])
            <div class="mt-3 flex flex-wrap gap-1 text-[11px]">
                @foreach($step->routes as $outcome => $route)
                    <span class="rounded-full bg-white px-2 py-1 font-semibold text-slate-600 ring-1 ring-slate-200">
                        {{ $outcome }} -> {{ data_get($route, 'label', data_get($route, 'action_key', data_get($route, 'target', data_get($route, 'type', 'route')))) }}
                    </span>
                @endforeach
            </div>
        @endif
    </div>

    <div x-data x-sort="$wire.reorderTaskCard({{ $step->id }}, $item, $position)" class="flex-1 space-y-3 overflow-auto p-3">
        @forelse($step->task_cards as $task)
            <div x-sort:item="{{ $task['key'] ?? '' }}" wire:key="workflow-task-{{ $step->id }}-{{ $task['key'] ?? 'task' }}">
                <x-workflows.task-card :task="$task">
                    <x-slot name="actions">
                        <button type="button" wire:click="removeTaskCard({{ $step->id }}, @js($task['key'] ?? ''))" wire:confirm="Step-Karte wirklich entfernen?" class="rounded-md border border-red-200 bg-white px-2 py-1 text-[11px] font-semibold text-red-700 shadow-sm hover:bg-red-50">
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

    @isset($actions)
        <div class="flex flex-wrap gap-2 border-t border-slate-200 p-3">
            {{ $actions }}
        </div>
    @endisset
</div>
