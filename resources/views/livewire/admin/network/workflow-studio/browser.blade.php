<section class="flex h-full min-h-0 min-w-0 flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm" data-workflow-studio-diagram>
    <div class="min-h-0 flex-1 overflow-hidden bg-slate-100 p-3">
        @if($run)
            <div class="h-full min-h-0 overflow-hidden rounded-xl border border-slate-200 bg-white/95 shadow-inner">
                <x-workflows.run-preview
                    class="h-full min-h-0"
                    :workflow-run="$run"
                    :selected-step-id="$selectedStepId !== '' ? (int) $selectedStepId : null"
                    :selected-task-key="$selectedTaskKey ?: null"
                    :selectable-tasks="! $autonomousMode"
                    :expanded="true"
                    :diagram-only="true"
                />
            </div>
        @else
            <div class="flex h-full min-h-[24rem] items-center justify-center rounded-xl border border-dashed border-slate-300 bg-white/90 p-6">
                <div class="max-w-md text-center">
                    <span class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-cyan-50 text-2xl text-cyan-700 ring-1 ring-cyan-100" aria-hidden="true">▶</span>
                    <h3 class="mt-4 text-base font-bold text-slate-900">Bereit für den ersten Test</h3>
                    <p class="mt-2 text-xs leading-5 text-slate-500">{{ $autonomousMode ? 'Öffne die Copilot-Einstellungen, prüfe Ziel und Erfolgskriterien und starte danach die Optimierung.' : 'Wähle eine Task im Diagramm. Du kannst genau diese Task testen oder den Ablauf bis zum Ende starten.' }}</p>
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
