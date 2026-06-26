@props([
    'task',
])

@php
    $kind = (string) ($task['kind'] ?? 'browser');
    $kindClasses = [
        'browser' => 'bg-blue-50 text-blue-700 ring-blue-200',
        'input' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
        'wait' => 'bg-amber-50 text-amber-700 ring-amber-200',
        'data' => 'bg-slate-50 text-slate-700 ring-slate-200',
    ];
    $kindLabels = [
        'browser' => 'Browser',
        'input' => 'Input',
        'wait' => 'Warten',
        'data' => 'Daten',
    ];
    $elementSelector = trim((string) ($task['element_selector'] ?? $task['selector'] ?? ''));
    $inputSelector = trim((string) ($task['input_selector'] ?? ''));
    $inputValue = trim((string) ($task['value'] ?? $task['input'] ?? ''));
    $url = trim((string) ($task['url'] ?? ''));
    $compactPayload = static function (mixed $payload): string {
        if ($payload === null || $payload === '') {
            return '';
        }

        if (is_array($payload)) {
            $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            return \Illuminate\Support\Str::limit((string) $encoded, 120);
        }

        return \Illuminate\Support\Str::limit((string) $payload, 120);
    };
    $successPayload = $compactPayload($task['success_payload'] ?? null);
    $failurePayload = $compactPayload($task['failure_payload'] ?? null);
@endphp

<div {{ $attributes->merge(['class' => 'rounded-md border border-slate-200 bg-white p-3 shadow-sm transition hover:border-slate-300 hover:shadow-md']) }}>
    <div class="flex items-start justify-between gap-2">
        <div class="min-w-0">
            <p class="min-w-0 text-sm font-semibold text-slate-900">{{ $task['title'] ?? 'Task' }}</p>
            <span class="mt-1 inline-flex rounded-full px-2 py-0.5 text-[11px] font-semibold ring-1 {{ $kindClasses[$kind] ?? $kindClasses['browser'] }}">
                {{ $kindLabels[$kind] ?? $kind }}
            </span>
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
    @if(($task['description'] ?? '') !== '')
        <p class="mt-2 text-xs leading-5 text-slate-500">{{ $task['description'] }}</p>
    @endif
    @if($url !== '' || $elementSelector !== '' || $inputSelector !== '' || $inputValue !== '')
        <div class="mt-3 space-y-1 rounded-md bg-slate-50 p-2 text-[11px] text-slate-500">
            @if($url !== '')
                <div class="truncate">URL: {{ $url }}</div>
            @endif
            @if($elementSelector !== '')
                <div class="truncate">Selector: {{ $elementSelector }}</div>
            @endif
            @if($inputSelector !== '')
                <div class="truncate">Input-Selector: {{ $inputSelector }}</div>
            @endif
            @if($inputValue !== '')
                <div class="truncate">Input-Wert: {{ $inputValue }}</div>
            @endif
        </div>
    @endif
    @if($successPayload !== '' || $failurePayload !== '')
        <div class="mt-3 space-y-1 rounded-md bg-slate-50 p-2 text-[11px] text-slate-500">
            @if($successPayload !== '')
                <div class="truncate text-emerald-700">Erfolgsdaten: {{ $successPayload }}</div>
            @endif
            @if($failurePayload !== '')
                <div class="truncate text-red-700">Fehlerdaten: {{ $failurePayload }}</div>
            @endif
        </div>
    @endif
    @if(($task['runner'] ?? '') !== '' || ($task['node_script'] ?? '') !== '' || ($task['php_handler'] ?? '') !== '' || (int) ($task['timeout_seconds'] ?? 0) > 0)
        <div class="mt-3 space-y-1 rounded-md bg-slate-50 p-2 text-[11px] text-slate-500">
            @if(($task['runner'] ?? '') !== '')
                <div class="truncate">Runner: {{ $task['runner'] }}</div>
            @endif
            @if(($task['node_script'] ?? '') !== '')
                <div class="truncate">Node: {{ $task['node_script'] }}</div>
            @endif
            @if(($task['php_handler'] ?? '') !== '')
                <div class="truncate">PHP: {{ $task['php_handler'] }}</div>
            @endif
            @if((int) ($task['timeout_seconds'] ?? 0) > 0)
                <div class="truncate">Timeout: {{ (int) $task['timeout_seconds'] }}s</div>
            @endif
        </div>
    @endif
    @if(is_array($task['next'] ?? null) || is_array($task['on_error'] ?? null))
        <div class="mt-3 grid gap-2 text-[11px] text-slate-500">
            @if(is_array($task['next'] ?? null))
                <div class="flex items-center gap-2 text-emerald-700">
                    <span class="h-px flex-1 bg-emerald-300"></span>
                    <span class="h-0 w-0 border-y-4 border-l-8 border-y-transparent border-l-emerald-400"></span>
                    <span class="max-w-[160px] truncate font-semibold">{{ data_get($task, 'next.label', data_get($task, 'next.card', data_get($task, 'next.step', 'naechstes Ziel'))) }}</span>
                </div>
            @endif
            @if(is_array($task['on_error'] ?? null))
                <div class="flex items-center gap-2 text-red-700">
                    <span class="h-px flex-1 bg-red-300"></span>
                    <span class="h-0 w-0 border-y-4 border-l-8 border-y-transparent border-l-red-400"></span>
                    <span class="max-w-[160px] truncate font-semibold">{{ data_get($task, 'on_error.label', data_get($task, 'on_error.card', data_get($task, 'on_error.step', 'Fehlerziel'))) }}</span>
                </div>
            @endif
        </div>
    @endif
</div>
