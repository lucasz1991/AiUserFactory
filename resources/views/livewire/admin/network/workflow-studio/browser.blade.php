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
            <div class="flex h-full min-h-0 flex-col overflow-hidden rounded-xl border border-slate-200 bg-white/95 shadow-inner">
                <div class="flex shrink-0 flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-3 py-2.5 sm:px-4">
                    <div class="min-w-0">
                        <p class="ff-kicker">Testlauf vorbereiten</p>
                        <p class="mt-1 text-xs leading-5 text-slate-600">
                            {{ $autonomousMode ? 'Definition prüfen und danach die Copilot-Optimierung starten.' : 'Task auswählen; Doppelklick öffnet direkt die gemeinsamen Task-Einstellungen.' }}
                        </p>
                    </div>
                    @if($selectedTask)
                        <div class="ff-selected-task max-w-full rounded-lg border px-3 py-2">
                            <p class="text-[9px] font-black uppercase tracking-[0.15em] text-blue-700">Startpunkt</p>
                            <p class="mt-0.5 max-w-64 truncate text-xs font-bold text-slate-950">{{ $selectedTask['title'] ?? $selectedTaskKey }}</p>
                        </div>
                    @endif
                </div>
                <div class="min-h-0 flex-1 overflow-auto p-3">
                    <x-workflows.minimap
                        :workflow="$workflow"
                        :selected-step-id="$selectedStepId !== '' ? (int) $selectedStepId : null"
                        :selected-task-key="$selectedTaskKey ?: null"
                        :show-header="false"
                        :selectable-tasks="! $autonomousMode"
                        :zoomable="true"
                        initial-zoom="overview"
                        :instance="'studio-definition-'.$session->id"
                    />
                </div>
            </div>
        @endif
    </div>
</section>
