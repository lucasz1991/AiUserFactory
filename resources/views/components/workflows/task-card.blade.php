@props([
    'task',
])

@php
    $browserWindow = trim((string) data_get($task, 'browser_window_name', data_get($task, 'browser_window', '')));
    $isWorkflowTask = (string) data_get($task, 'runner') === 'workflow';
@endphp

<div {{ $attributes->merge(['class' => 'rounded-md border border-slate-200 bg-white px-3 py-2 shadow-sm transition hover:border-slate-300 hover:shadow-md']) }}>
    <div class="flex items-center justify-between gap-2">
        <div class="flex min-w-0 items-center gap-2">
            <div class="flex h-6 w-6 shrink-0 cursor-grab items-center justify-center rounded text-[10px] font-bold text-slate-400 hover:bg-slate-100 hover:text-slate-700 active:cursor-grabbing">
                ::
            </div>
            <p class="min-w-0 truncate text-sm font-semibold text-slate-900">{{ $task['title'] ?? 'Task' }}</p>
        </div>
        @isset($actions)
            <div class="relative shrink-0" x-data="{ open: false }">
                <button type="button" x-on:click.stop="open = ! open" class="flex h-7 w-7 items-center justify-center rounded-md text-slate-500 hover:bg-slate-100 hover:text-slate-900">
                    ...
                </button>
                <div x-cloak x-show="open" x-transition x-on:click.stop x-on:click.outside="open = false" class="absolute right-0 z-30 mt-1 w-36 rounded-md border border-slate-200 bg-white p-1 shadow-lg">
                    {{ $actions }}
                </div>
            </div>
        @endisset
    </div>
    @if($browserWindow !== '')
        <div class="mt-2 inline-flex max-w-full items-center rounded border border-sky-100 bg-sky-50 px-2 py-0.5 text-[11px] font-semibold text-sky-800">
            <span class="truncate">Fenster: {{ $browserWindow }}</span>
        </div>
    @endif
    @if($isWorkflowTask)
        <div class="mt-2 inline-flex max-w-full items-center rounded border border-violet-200 bg-violet-50 px-2 py-0.5 text-[11px] font-semibold text-violet-800">
            <span class="truncate">Eingebetteter Workflow</span>
        </div>
    @endif
</div>
