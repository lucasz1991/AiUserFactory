@props([
    'workflow',
])

@php
    $taskCardCount = $workflow->steps->sum(fn ($step) => count($step->task_cards));
    $actionCount = $workflow->steps
        ->filter(fn ($step) => $step->type !== \App\Models\WorkflowStep::TYPE_WAIT)
        ->count();
    $isSeeded = (bool) data_get($workflow->settings_json, 'seeded', false);
@endphp

<div {{ $attributes->merge(['class' => 'rounded-md border border-slate-200 bg-white p-4 shadow-sm transition hover:border-blue-200 hover:shadow-md']) }}>
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2">
                <h2 class="text-base font-semibold text-slate-900">{{ $workflow->name }}</h2>
                <span class="rounded-full px-2 py-0.5 text-xs font-semibold ring-1 {{ $workflow->is_active ? 'bg-emerald-50 text-emerald-700 ring-emerald-200' : 'bg-slate-50 text-slate-500 ring-slate-200' }}">
                    {{ $workflow->is_active ? 'Aktiv' : 'Inaktiv' }}
                </span>
                @if($isSeeded)
                    <span class="rounded-full bg-blue-50 px-2 py-0.5 text-xs font-semibold text-blue-700 ring-1 ring-blue-200">Seeder</span>
                @endif
            </div>
            <p class="mt-1 text-xs text-slate-500">{{ $workflow->slug }}</p>
            @if($workflow->description)
                <p class="mt-2 line-clamp-2 text-sm leading-5 text-slate-600">{{ $workflow->description }}</p>
            @endif
        </div>

        <a href="{{ route('network.workflows.manage', $workflow) }}" class="rounded-md bg-slate-900 px-3 py-2 text-xs font-semibold text-white shadow-sm hover:bg-slate-800">
            Board oeffnen
        </a>
    </div>

    <div class="mt-4 grid grid-cols-2 gap-2 sm:grid-cols-4">
        <div class="rounded-md bg-slate-50 p-3">
            <div class="text-[11px] font-semibold uppercase text-slate-500">Aktionen</div>
            <div class="mt-1 text-lg font-semibold text-slate-900">{{ $actionCount }}</div>
        </div>
        <div class="rounded-md bg-slate-50 p-3">
            <div class="text-[11px] font-semibold uppercase text-slate-500">Listen</div>
            <div class="mt-1 text-lg font-semibold text-slate-900">{{ $workflow->steps_count }}</div>
        </div>
        <div class="rounded-md bg-slate-50 p-3">
            <div class="text-[11px] font-semibold uppercase text-slate-500">Step-Karten</div>
            <div class="mt-1 text-lg font-semibold text-slate-900">{{ $taskCardCount }}</div>
        </div>
        <div class="rounded-md bg-slate-50 p-3">
            <div class="text-[11px] font-semibold uppercase text-slate-500">Laeufe</div>
            <div class="mt-1 text-lg font-semibold text-slate-900">{{ $workflow->runs_count }}</div>
        </div>
    </div>

    <div class="mt-4 flex flex-wrap justify-between gap-2">
        <div class="text-xs text-slate-500">
            @if($workflow->last_run_at)
                Letzter Lauf: {{ $workflow->last_run_at->format('d.m.Y H:i') }}
            @else
                Noch nicht ausgefuehrt
            @endif
        </div>
        <button type="button" wire:click="deleteWorkflow({{ $workflow->id }})" wire:confirm="Workflow wirklich aus der Datenbank loeschen?" class="rounded-md border border-red-200 bg-white px-3 py-1.5 text-xs font-semibold text-red-700 shadow-sm hover:bg-red-50">
            Loeschen
        </button>
    </div>
</div>
