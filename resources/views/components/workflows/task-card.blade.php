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

<div {{ $attributes->merge(['class' => 'rounded-md border border-slate-200 bg-white p-3 shadow-sm']) }}>
    <div class="flex items-start justify-between gap-2">
        <p class="min-w-0 text-sm font-semibold text-slate-900">{{ $task['title'] ?? 'Task' }}</p>
        <span class="shrink-0 rounded-full px-2 py-0.5 text-[11px] font-semibold ring-1 {{ $kindClasses[$kind] ?? $kindClasses['browser'] }}">
            {{ $kindLabels[$kind] ?? $kind }}
        </span>
    </div>
    @if(($task['description'] ?? '') !== '')
        <p class="mt-2 text-xs leading-5 text-slate-500">{{ $task['description'] }}</p>
    @endif
    @if($elementSelector !== '' || $inputSelector !== '' || $inputValue !== '')
        <div class="mt-3 space-y-1 rounded-md bg-slate-50 p-2 text-[11px] text-slate-500">
            @if($elementSelector !== '')
                <div class="truncate">Element: {{ $elementSelector }}</div>
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
    @if(is_array($task['next'] ?? null) || is_array($task['on_partial'] ?? null) || is_array($task['on_error'] ?? null))
        <div class="mt-3 grid gap-1 text-[11px] text-slate-500">
            @if(is_array($task['next'] ?? null))
                <div class="rounded border border-emerald-100 bg-emerald-50 px-2 py-1 text-emerald-700">
                    Weiter: {{ data_get($task, 'next.label', data_get($task, 'next.card', data_get($task, 'next.step', 'naechstes Ziel'))) }}
                </div>
            @endif
            @if(is_array($task['on_partial'] ?? null))
                <div class="rounded border border-amber-100 bg-amber-50 px-2 py-1 text-amber-700">
                    Teilstatus: {{ data_get($task, 'on_partial.label', data_get($task, 'on_partial.card', data_get($task, 'on_partial.step', 'Teilstatus-Ziel'))) }}
                </div>
            @endif
            @if(is_array($task['on_error'] ?? null))
                <div class="rounded border border-red-100 bg-red-50 px-2 py-1 text-red-700">
                    Fehler: {{ data_get($task, 'on_error.label', data_get($task, 'on_error.card', data_get($task, 'on_error.step', 'Fehlerziel'))) }}
                </div>
            @endif
        </div>
    @endif
    @isset($actions)
        <div class="mt-3 flex justify-end">
            {{ $actions }}
        </div>
    @endisset
</div>
