<div class="grid h-full min-h-0 gap-3 xl:grid-cols-[280px_minmax(0,1fr)_340px]">
    @include('livewire.admin.network.workflow-studio.outline')

    <section class="flex min-h-0 min-w-0 flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div class="flex shrink-0 flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-4 py-3">
            <div>
                <div class="flex items-center gap-2">
                    <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-cyan-700">Testfläche</p>
                    @if($run)
                        <span class="rounded-full border px-2 py-0.5 text-[9px] font-bold {{ $statusTone }}">Lauf #{{ $run->id }}</span>
                    @endif
                </div>
                <h2 class="mt-1 text-sm font-bold text-slate-950">Live-Browser & Ausführung</h2>
                <p class="mt-1 text-[11px] text-slate-500">Browserbild, ausgeführte Route und Task-Ergebnis bleiben in einer Ansicht.</p>
            </div>
            <div class="flex items-center gap-2 text-[10px] font-bold text-slate-500">
                <span class="inline-flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-amber-500"></span>Laufcursor</span>
                <span class="inline-flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-cyan-500"></span>Auswahl</span>
            </div>
        </div>

        <div class="min-h-0 flex-1 overflow-auto bg-slate-50 p-3">
            @if($run)
                <div class="min-h-full overflow-hidden rounded-xl border border-slate-200 bg-white shadow-inner">
                    <x-workflows.run-preview
                        :workflow-run="$run"
                        :selected-step-id="$selectedStepId !== '' ? (int) $selectedStepId : null"
                        :selected-task-key="$selectedTaskKey ?: null"
                        :selectable-tasks="true"
                        :expanded="true"
                    />
                </div>
            @else
                <div class="flex min-h-full items-center justify-center rounded-xl border border-dashed border-slate-300 bg-white p-6">
                    <div class="max-w-md text-center">
                        <span class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-cyan-50 text-cyan-700 ring-1 ring-cyan-100">
                            <svg class="h-7 w-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="2"></rect><path d="M3 9h18M7 6.5h.01M10 6.5h.01M9 14l2 2 4-4"></path></svg>
                        </span>
                        <h3 class="mt-4 text-base font-bold text-slate-900">Bereit für den ersten Test</h3>
                        <p class="mt-2 text-xs leading-5 text-slate-500">Wähle links eine Task aus. Du kannst genau diese eine Task testen oder den Workflow vollständig starten.</p>
                        @if($selectedTask)
                            <div class="mx-auto mt-4 max-w-sm rounded-xl border border-cyan-200 bg-cyan-50 px-4 py-3 text-left">
                                <p class="text-[9px] font-black uppercase tracking-[0.15em] text-cyan-700">Ausgewählte Task</p>
                                <p class="mt-1 truncate text-sm font-bold text-cyan-950">{{ $selectedTask['title'] ?? $selectedTaskKey }}</p>
                                <p class="mt-0.5 truncate font-mono text-[10px] text-cyan-700">{{ $selectedTask['task_key'] ?? $selectedTaskKey }}</p>
                            </div>
                        @endif
                        <div class="mt-5 flex flex-wrap justify-center gap-2">
                            <button type="button" wire:click="runSingleTask" @disabled(! $selectedTask) class="inline-flex h-10 items-center gap-2 rounded-lg bg-cyan-600 px-4 text-xs font-bold text-white shadow-sm transition hover:bg-cyan-500 disabled:cursor-not-allowed disabled:opacity-40">
                                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5v14l11-7z"></path></svg>
                                1 Task testen
                            </button>
                            <button type="button" wire:click="startRun" class="h-10 rounded-lg border border-slate-300 bg-white px-4 text-xs font-bold text-slate-700 shadow-sm hover:bg-slate-50">Komplett durchlaufen</button>
                        </div>
                    </div>
                </div>
            @endif
        </div>
    </section>

    <aside class="min-h-0 overflow-y-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div class="sticky top-0 z-10 border-b border-slate-200 bg-white/95 px-4 py-3 backdrop-blur">
            <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-cyan-700">Inspector</p>
            <h2 class="mt-1 text-sm font-bold text-slate-950">Auswahl & Testwerkzeuge</h2>
        </div>

        <div class="space-y-4 p-4">
            @if($selectedTask)
                <section class="overflow-hidden rounded-xl border-2 border-cyan-400 bg-cyan-50 shadow-sm">
                    <div class="border-b border-cyan-200 bg-white/70 px-3 py-2">
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-[9px] font-black uppercase tracking-[0.16em] text-cyan-700">Ausgewählte Task</span>
                            @if($selectedTaskNumber)
                                <span class="rounded-full bg-cyan-600 px-2 py-0.5 text-[9px] font-black text-white">{{ $selectedTaskNumber }} / {{ $taskCount }}</span>
                            @endif
                        </div>
                    </div>
                    <div class="p-3">
                        <h3 class="text-sm font-bold text-cyan-950">{{ $selectedTask['title'] ?? $selectedTaskKey }}</h3>
                        <p class="mt-1 break-all font-mono text-[10px] text-cyan-700">{{ $selectedTask['task_key'] ?? $selectedTaskKey }}</p>
                        @if(filled($selectedTask['description'] ?? null))
                            <p class="mt-2 text-[11px] leading-4 text-cyan-900/75">{{ $selectedTask['description'] }}</p>
                        @endif
                        <div class="mt-3 grid grid-cols-2 gap-2">
                            <button type="button" wire:click="editSelectedTask" class="rounded-lg bg-cyan-600 px-3 py-2 text-[11px] font-bold text-white shadow-sm hover:bg-cyan-500">Task bearbeiten</button>
                            <button type="button" wire:click="openBuilderForStep({{ (int) $selectedStepId }})" class="rounded-lg border border-cyan-300 bg-white px-3 py-2 text-[11px] font-bold text-cyan-800 hover:bg-cyan-100">Task-Katalog</button>
                        </div>
                    </div>
                </section>
            @else
                <div class="rounded-xl border border-dashed border-slate-300 bg-slate-50 p-4 text-xs leading-5 text-slate-500">Wähle links eine Task aus, um sie zu testen oder zu bearbeiten.</div>
            @endif

            <section class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <h3 class="text-xs font-bold text-slate-900">Task-Navigation</h3>
                        <p class="mt-0.5 text-[10px] text-slate-500">Auswahl verschieben, ohne etwas auszuführen.</p>
                    </div>
                    <span class="font-mono text-[10px] font-bold text-slate-400">{{ $selectedTaskNumber ?: '–' }}/{{ $taskCount }}</span>
                </div>
                <div class="mt-3 grid grid-cols-2 gap-2">
                    <button type="button" wire:click="selectPreviousTask" @disabled(! $hasPreviousTask) class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-[11px] font-bold text-slate-700 hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-35">← Task zurück</button>
                    <button type="button" wire:click="selectNextTask" @disabled(! $hasNextTask) class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-[11px] font-bold text-slate-700 hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-35">Task weiter →</button>
                </div>
            </section>

            <section class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                <h3 class="text-xs font-bold text-slate-900">Browser-Selector prüfen</h3>
                <p class="mt-1 text-[10px] leading-4 text-slate-500">Öffne die Selector-Prüfung direkt am gewünschten Browserfenster in der oberen Leiste.</p>
                <button type="button" wire:click="openSelectorProbe(@js($probeBrowserWindow))" class="mt-3 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-[11px] font-bold text-slate-700 hover:border-cyan-300 hover:text-cyan-700">Selector-Modal öffnen</button>
            </section>
        </div>
    </aside>
</div>
