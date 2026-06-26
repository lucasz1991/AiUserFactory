@props([
    'action',
])

@php
    $riskClass = match($action['risk_level'] ?? 'low') {
        'review' => 'bg-red-50 text-red-700 ring-red-200',
        'moderate' => 'bg-amber-50 text-amber-700 ring-amber-200',
        default => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
    };
@endphp

<div {{ $attributes->merge(['class' => 'rounded-md border border-slate-200 bg-white p-4 shadow-sm']) }}>
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2">
                <p class="font-semibold text-slate-900">{{ $action['label'] }}</p>
                <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-600 ring-1 ring-slate-200">
                    {{ $action['type_label'] }}
                </span>
                <span class="rounded-full px-2 py-0.5 text-xs font-semibold ring-1 {{ $riskClass }}">
                    Risiko {{ $action['risk_score'] }}
                </span>
            </div>
            <p class="mt-1 text-sm text-slate-600">{{ $action['person_name'] }} · {{ $action['date'] }} {{ $action['time'] }}</p>
            @if(($action['details'] ?? '') !== '')
                <p class="mt-2 line-clamp-2 text-sm text-slate-500">{{ $action['details'] }}</p>
            @endif
        </div>

        @isset($actions)
            <div class="flex flex-wrap justify-end gap-2">
                {{ $actions }}
            </div>
        @endisset
    </div>
</div>
