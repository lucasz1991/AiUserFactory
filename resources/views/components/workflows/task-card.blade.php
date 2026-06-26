@props([
    'task',
])

@php
    $browserWindow = trim((string) data_get($task, 'browser_window_name', data_get($task, 'browser_window', '')));
    $successRoute = is_array(data_get($task, 'next')) ? data_get($task, 'next') : null;
    $failedRoute = is_array(data_get($task, 'on_error')) ? data_get($task, 'on_error') : null;
    $routeLabel = static function (?array $route, string $fallback): string {
        if (! $route) {
            return '';
        }

        $label = trim((string) data_get($route, 'reason', data_get($route, 'label', '')));

        if ($label !== '') {
            return $label;
        }

        $step = trim((string) data_get($route, 'step', data_get($route, 'action_key', '')));
        $card = trim((string) data_get($route, 'card', data_get($route, 'card_key', '')));

        if ($card !== '') {
            return 'Karte: '.$card;
        }

        return $step !== '' ? $step : $fallback;
    };
    $routeTarget = static function (?array $route): string {
        if (! $route) {
            return '';
        }

        $step = trim((string) data_get($route, 'step', data_get($route, 'action_key', '')));
        $card = trim((string) data_get($route, 'card', data_get($route, 'card_key', '')));

        if ($card !== '') {
            return ' -> '.$card;
        }

        if ($step !== '') {
            return ' -> '.$step;
        }

        return '';
    };
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
    @if($successRoute || $failedRoute)
        <div class="mt-2 space-y-1">
            @if($successRoute)
                <div class="flex min-w-0 items-center gap-1 text-[11px] font-semibold text-emerald-700" title="{{ $routeLabel($successRoute, 'Erfolg') }}{{ $routeTarget($successRoute) }}">
                    <span class="shrink-0">Erfolg -></span>
                    <span class="truncate rounded bg-emerald-50 px-1.5 py-0.5">{{ $routeLabel($successRoute, 'Erfolg') }}{{ $routeTarget($successRoute) }}</span>
                </div>
            @endif
            @if($failedRoute)
                @php($failedAttempts = max(0, (int) data_get($failedRoute, 'max_attempts', 0)))
                <div class="flex min-w-0 items-center gap-1 text-[11px] font-semibold text-red-700" title="{{ $routeLabel($failedRoute, 'Fehler') }}{{ $routeTarget($failedRoute) }}">
                    <span class="shrink-0">Fehler -></span>
                    <span class="truncate rounded bg-red-50 px-1.5 py-0.5">{{ $routeLabel($failedRoute, 'Fehler') }}{{ $routeTarget($failedRoute) }}@if($failedAttempts > 0) - {{ $failedAttempts }}x @endif</span>
                </div>
            @endif
        </div>
    @endif
</div>
