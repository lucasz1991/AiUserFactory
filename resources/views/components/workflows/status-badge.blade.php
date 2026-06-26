@props([
    'status' => 'queued',
])

@php
    $status = (string) $status;
    $labels = [
        'queued' => 'Wartet',
        'running' => 'Laeuft',
        'waiting' => 'Wartet',
        'completed' => 'Fertig',
        'failed' => 'Fehler',
        'partial' => 'Teilstatus',
        'timeout' => 'Timeout',
        'cancelled' => 'Abgebrochen',
        'skipped' => 'Uebersprungen',
    ];
    $classes = [
        'queued' => 'bg-slate-50 text-slate-700 ring-slate-200',
        'running' => 'bg-blue-50 text-blue-700 ring-blue-200',
        'waiting' => 'bg-amber-50 text-amber-700 ring-amber-200',
        'completed' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
        'failed' => 'bg-red-50 text-red-700 ring-red-200',
        'partial' => 'bg-amber-50 text-amber-700 ring-amber-200',
        'timeout' => 'bg-orange-50 text-orange-700 ring-orange-200',
        'cancelled' => 'bg-gray-50 text-gray-700 ring-gray-200',
        'skipped' => 'bg-gray-50 text-gray-700 ring-gray-200',
    ];
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ring-1 '.($classes[$status] ?? $classes['queued'])]) }}>
    {{ $labels[$status] ?? $status }}
</span>
