@props([
    'step',
])

@php
    $enabledClass = $step->is_enabled
        ? 'border-slate-200 bg-white'
        : 'border-slate-200 bg-slate-50 opacity-70';
@endphp

<div {{ $attributes->merge(['class' => 'rounded-md border p-4 shadow-sm '.$enabledClass]) }}>
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="flex min-w-0 flex-1 items-start gap-3">
            <div x-sort:handle class="mt-1 flex h-8 w-8 cursor-grab items-center justify-center rounded-md border border-slate-200 bg-slate-50 text-xs font-bold text-slate-500 active:cursor-grabbing">
                ::
            </div>
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <p class="font-semibold text-slate-900">{{ $step->name }}</p>
                    <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-600 ring-1 ring-slate-200">
                        {{ $step->type_label }}
                    </span>
                    @if(! $step->is_enabled)
                        <x-workflows.status-badge status="skipped" />
                    @endif
                </div>
                <p class="mt-1 text-sm text-slate-500">{{ $step->config_summary }}</p>
                @if($step->wait_after_seconds > 0)
                    <p class="mt-1 text-xs text-slate-400">Pause danach: {{ $step->wait_after_seconds }}s</p>
                @endif
            </div>
        </div>

        @isset($actions)
            <div class="flex flex-wrap justify-end gap-2">
                {{ $actions }}
            </div>
        @endisset
    </div>
</div>
