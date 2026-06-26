@props([
    'task',
])

<div {{ $attributes->merge(['class' => 'rounded-md border border-slate-200 bg-white px-3 py-2 shadow-sm transition hover:border-slate-300 hover:shadow-md']) }}>
    <div class="flex items-center justify-between gap-2">
        <p class="min-w-0 truncate text-sm font-semibold text-slate-900">{{ $task['title'] ?? 'Task' }}</p>
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
</div>
