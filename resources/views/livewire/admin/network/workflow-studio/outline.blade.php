@php
    $taskResultsByStep = collect($run?->stepRuns ?? [])
        ->groupBy('workflow_step_id')
        ->map(function ($stepRuns) {
            return $stepRuns->flatMap(fn ($stepRun) => collect((array) data_get($stepRun->result_json, 'tasks', [])))
                ->filter(fn ($task) => is_array($task) && filled($task['key'] ?? null))
                ->keyBy('key');
        });
@endphp

<aside class="flex min-h-0 flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
    <div class="flex shrink-0 items-start justify-between gap-3 border-b border-slate-200 px-4 py-3">
        <div>
            <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-cyan-700">Ablauf</p>
            <h2 class="mt-1 text-sm font-bold text-slate-950">Workflow-Navigator</h2>
            <p class="mt-1 text-[11px] leading-4 text-slate-500">Klick wählt aus, Doppelklick bearbeitet.</p>
        </div>
        <button type="button" wire:click="openStudioPanel('builder')" class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border border-slate-200 bg-slate-50 text-lg font-medium text-slate-700 transition hover:border-cyan-300 hover:bg-cyan-50 hover:text-cyan-700" title="Workflow bearbeiten">+</button>
    </div>

    <div class="min-h-0 flex-1 space-y-3 overflow-y-auto bg-slate-50/70 p-3">
        @forelse($steps as $step)
            @php
                $stepSelected = (int) $selectedStepId === (int) $step->id;
                $stepCursor = (int) $cursorStepId === (int) $step->id;
                $stepTaskResults = $taskResultsByStep->get($step->id, collect());
            @endphp
            <section class="overflow-hidden rounded-xl border bg-white transition {{ $stepSelected ? 'border-cyan-300 shadow-sm ring-1 ring-cyan-200' : 'border-slate-200' }}">
                <div class="flex items-center justify-between gap-2 border-b border-slate-100 px-3 py-2.5">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2">
                            <span class="h-2 w-2 shrink-0 rounded-full {{ $stepCursor ? 'animate-pulse bg-amber-500' : ($step->is_enabled ? 'bg-emerald-500' : 'bg-slate-300') }}"></span>
                            <h3 class="truncate text-xs font-bold text-slate-900">{{ $step->name }}</h3>
                        </div>
                        <p class="mt-1 pl-4 text-[9px] font-bold uppercase tracking-wide text-slate-400">{{ count($step->task_cards) }} Tasks · {{ $step->is_enabled ? 'aktiv' : 'pausiert' }}</p>
                    </div>
                    <button type="button" wire:click="openBuilderForStep({{ $step->id }})" class="shrink-0 rounded-md px-2 py-1 text-[10px] font-bold text-cyan-700 hover:bg-cyan-50">Bearbeiten</button>
                </div>

                <div class="space-y-1.5 p-2">
                    @forelse($step->task_cards as $task)
                        @php
                            $taskKey = (string) ($task['key'] ?? '');
                            $isSelected = $stepSelected && $selectedTaskKey === $taskKey;
                            $isCursor = $cursorTaskKey === $taskKey && ($cursorStepId === null || $stepCursor);
                            $taskStatus = (string) data_get($stepTaskResults->get($taskKey), 'status', 'configured');
                            $statusDot = match ($taskStatus) {
                                'completed', 'success' => 'bg-emerald-500',
                                'failed', 'timeout' => 'bg-rose-500',
                                'running', 'waiting' => 'bg-amber-500',
                                default => 'bg-slate-300',
                            };
                        @endphp
                        <button
                            type="button"
                            wire:click="selectTask({{ $step->id }}, @js($taskKey))"
                            x-on:dblclick.prevent="$wire.editTask({{ $step->id }}, @js($taskKey))"
                            data-studio-selected-task="{{ $isSelected ? 'true' : 'false' }}"
                            class="group relative flex w-full items-center gap-2 rounded-lg border px-2.5 py-2 text-left transition {{ $isSelected ? 'border-cyan-500 bg-cyan-50 text-cyan-950 shadow-sm ring-2 ring-cyan-200' : 'border-transparent bg-white text-slate-700 hover:border-slate-200 hover:bg-slate-50' }}"
                        >
                            @if($isSelected)
                                <span class="absolute inset-y-2 left-0 w-1 rounded-r-full bg-cyan-500" aria-hidden="true"></span>
                            @endif
                            <span class="relative ml-0.5 h-2.5 w-2.5 shrink-0 rounded-full {{ $isCursor ? 'bg-amber-500 ring-4 ring-amber-100' : $statusDot }}"></span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-[11px] font-bold">{{ $task['title'] ?? $taskKey }}</span>
                                <span class="mt-0.5 block truncate font-mono text-[9px] opacity-55">{{ $task['task_key'] ?? $taskKey }}</span>
                            </span>
                            @if($isCursor)
                                <span class="rounded-full bg-amber-100 px-1.5 py-0.5 text-[8px] font-black uppercase tracking-wide text-amber-700">nächste</span>
                            @elseif($isSelected)
                                <span class="rounded-full bg-cyan-600 px-1.5 py-0.5 text-[8px] font-black uppercase tracking-wide text-white">ausgewählt</span>
                            @endif
                        </button>
                    @empty
                        <button type="button" wire:click="openBuilderForStep({{ $step->id }})" class="block w-full rounded-lg border border-dashed border-slate-300 bg-slate-50 px-3 py-4 text-center text-[11px] font-bold text-slate-500 hover:border-cyan-400 hover:text-cyan-700">Erste Task einsetzen</button>
                    @endforelse
                </div>
            </section>
        @empty
            <button type="button" wire:click="openStudioPanel('builder')" class="block w-full rounded-xl border-2 border-dashed border-slate-300 bg-white px-4 py-10 text-center text-xs font-bold text-slate-500 hover:border-cyan-400 hover:text-cyan-700">Erste Liste anlegen</button>
        @endforelse
    </div>
</aside>
