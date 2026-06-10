@props([
    'label',
    'value',
    'tone' => 'slate',
])

@php
    $toneClasses = [
        'blue' => 'bg-blue-50 text-blue-700 ring-blue-200',
        'emerald' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
        'amber' => 'bg-amber-50 text-amber-700 ring-amber-200',
        'red' => 'bg-red-50 text-red-700 ring-red-200',
        'slate' => 'bg-slate-50 text-slate-700 ring-slate-200',
    ][$tone] ?? 'bg-slate-50 text-slate-700 ring-slate-200';
@endphp

<div {{ $attributes->merge(['class' => 'rounded-md px-4 py-3 ring-1 '.$toneClasses]) }}>
    <p class="text-xs font-semibold uppercase text-current opacity-75">{{ $label }}</p>
    <p class="mt-1 text-lg font-semibold text-gray-900">{{ $value }}</p>
</div>
