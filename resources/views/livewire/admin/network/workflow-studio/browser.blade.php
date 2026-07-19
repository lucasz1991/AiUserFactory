<section class="flex h-full min-h-0 min-w-0 flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
    <div class="flex shrink-0 flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-4 py-3">
        <div>
            <div class="flex items-center gap-2">
                <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-cyan-700">Einzige Workflow-Ansicht</p>
                @if($run)<span class="rounded-full border px-2 py-0.5 text-[9px] font-bold {{ $statusTone }}">Lauf #{{ $run->id }}</span>@endif
            </div>
            <h2 class="mt-1 text-sm font-bold text-slate-950">Workflow-Vorschau & Live-Ausführung</h2>
            <p class="mt-1 text-[11px] text-slate-500">Taskauswahl, ausgeführte Abzweigung, Browserbild und Laufstatus in derselben bekannten Vorschau.</p>
        </div>
        @if($selectedTask && ! $autonomousMode)
            <button type="button" wire:click="editSelectedTask" class="rounded-lg border border-cyan-200 bg-cyan-50 px-3 py-2 text-[11px] font-bold text-cyan-800 transition hover:bg-cyan-100">Ausgewählte Task bearbeiten</button>
        @endif
    </div>

    <div class="min-h-0 flex-1 overflow-auto bg-slate-50 p-3">
        @if($run)
            <div class="min-h-full overflow-hidden rounded-xl border border-slate-200 bg-white shadow-inner">
                <x-workflows.run-preview
                    :workflow-run="$run"
                    :selected-step-id="$selectedStepId !== '' ? (int) $selectedStepId : null"
                    :selected-task-key="$selectedTaskKey ?: null"
                    :selectable-tasks="! $autonomousMode"
                    :expanded="true"
                />
            </div>
        @else
            <div class="flex min-h-full items-center justify-center rounded-xl border border-dashed border-slate-300 bg-white p-6">
                <div class="max-w-md text-center">
                    <span class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-cyan-50 text-2xl text-cyan-700 ring-1 ring-cyan-100">▶</span>
                    <h3 class="mt-4 text-base font-bold text-slate-900">Bereit für den ersten Test</h3>
                    <p class="mt-2 text-xs leading-5 text-slate-500">{{ $autonomousMode ? 'Prüfe rechts Ziel und Erfolgskriterien. Danach übernimmt der Copilot Planung, Test und Reparatur.' : 'Wähle eine Task in der Workflow-Vorschau. Du kannst genau diese Task testen oder den Ablauf bis zum Ende starten.' }}</p>
                    @if($selectedTask)
                        <div class="mx-auto mt-4 max-w-sm rounded-xl border border-cyan-200 bg-cyan-50 px-4 py-3 text-left">
                            <p class="text-[9px] font-black uppercase tracking-[0.15em] text-cyan-700">Ausgewählte Task</p>
                            <p class="mt-1 truncate text-sm font-bold text-cyan-950">{{ $selectedTask['title'] ?? $selectedTaskKey }}</p>
                            <p class="mt-0.5 truncate font-mono text-[10px] text-cyan-700">{{ $selectedTask['task_key'] ?? $selectedTaskKey }}</p>
                        </div>
                    @endif
                </div>
            </div>
        @endif
    </div>
</section>
