<section class="ff-canvas-shell flex h-full min-h-0 min-w-0 flex-col" data-workflow-studio-diagram>
    <div class="ff-canvas-grid min-h-0 flex-1 overflow-hidden p-2.5 sm:p-3">
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
            <div class="flex h-full min-h-0 items-center justify-center overflow-y-auto rounded-xl border border-dashed border-slate-300 bg-white/90 p-5 sm:p-8">
                <div class="max-w-lg text-center">
                    <span class="mx-auto flex h-12 w-12 items-center justify-center rounded-xl border border-blue-200 bg-blue-50 text-blue-700 shadow-sm" aria-hidden="true">
                        <svg class="h-5 w-5 translate-x-px" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M8 5.5v13l10-6.5z"></path>
                        </svg>
                    </span>
                    <p class="ff-kicker mt-4">Testlauf</p>
                    <h3 class="mt-1.5 text-lg font-bold tracking-tight text-slate-950">Bereit für den ersten Test</h3>
                    <p class="mx-auto mt-2 max-w-md text-xs leading-5 text-slate-500">{{ $autonomousMode ? 'Öffne die Copilot-Einstellungen, prüfe Ziel und Erfolgskriterien und starte danach die Optimierung.' : 'Wähle eine Task im Diagramm. Du kannst genau diese Task testen oder den Ablauf bis zum Ende starten.' }}</p>
                    @if($selectedTask)
                        <div class="ff-selected-task mx-auto mt-5 max-w-sm rounded-xl border px-4 py-3 text-left">
                            <div class="flex items-center justify-between gap-3">
                                <p class="text-[9px] font-black uppercase tracking-[0.15em] text-blue-700">Ausgewählte Task</p>
                                <span class="rounded-md border border-blue-200 bg-white/80 px-1.5 py-0.5 text-[8px] font-bold uppercase tracking-wide text-blue-700">Startpunkt</span>
                            </div>
                            <p class="mt-1.5 truncate text-sm font-bold text-slate-950">{{ $selectedTask['title'] ?? $selectedTaskKey }}</p>
                            <p class="mt-0.5 truncate font-mono text-[10px] text-slate-600">{{ $selectedTask['task_key'] ?? $selectedTaskKey }}</p>
                        </div>
                    @elseif(! $autonomousMode)
                        <p class="mx-auto mt-4 inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-2 text-[10px] font-semibold text-slate-600 shadow-sm">
                            <span class="h-1.5 w-1.5 rounded-full bg-blue-500" aria-hidden="true"></span>
                            Eine Task im Workflow auswählen, dann oben starten.
                        </p>
                    @endif
                </div>
            </div>
        @endif
    </div>
</section>
