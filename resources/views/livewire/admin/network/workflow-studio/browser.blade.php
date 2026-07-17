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

    <aside x-data="{ probeOpen: false }" class="min-h-0 overflow-y-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
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

            <section class="overflow-hidden rounded-xl border border-slate-200">
                <button type="button" x-on:click="probeOpen = ! probeOpen" class="flex w-full items-center justify-between gap-3 bg-white px-3 py-3 text-left">
                    <span><span class="block text-xs font-bold text-slate-900">Selector-Probe</span><span class="mt-0.5 block text-[10px] text-slate-500">Browserzustand untersuchen</span></span>
                    <svg class="h-4 w-4 text-slate-400 transition" x-bind:class="probeOpen ? 'rotate-180' : ''" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m6 9 6 6 6-6"></path></svg>
                </button>
                <div x-cloak x-show="probeOpen" x-collapse class="space-y-3 border-t border-slate-200 bg-slate-50 p-3">
                    <div>
                        <label class="block text-[10px] font-bold uppercase tracking-wide text-slate-500">Probeaktion</label>
                        <select wire:model.live="probeAction" class="mt-1 w-full rounded-lg border-slate-300 bg-white text-xs text-slate-800 focus:border-cyan-500 focus:ring-cyan-500">
                            <option value="selector.search">Selector suchen</option>
                            <option value="selector.highlight">Element hervorheben</option>
                            <option value="selector.read">Text / Attribute lesen</option>
                            <option value="probe.click">Klicken</option>
                            <option value="probe.fill">Eingabe setzen</option>
                            <option value="probe.keypress">Taste senden</option>
                            <option value="probe.submit">Formular absenden</option>
                            <option value="probe.wait">Warten</option>
                            <option value="probe.navigate">Navigieren</option>
                            <option value="probe.screenshot">Screenshot neu erfassen</option>
                            <option value="probe.dom_refresh">DOM neu erfassen</option>
                        </select>
                    </div>
                    <div><label class="block text-[10px] font-bold uppercase tracking-wide text-slate-500">Browserfenster</label><input type="text" wire:model="probeBrowserWindow" class="mt-1 w-full rounded-lg border-slate-300 bg-white text-xs focus:border-cyan-500 focus:ring-cyan-500" placeholder="main"></div>
                    <div><label class="block text-[10px] font-bold uppercase tracking-wide text-slate-500">Selector, Text oder Rolle</label><textarea wire:model="probeSelector" rows="3" class="mt-1 w-full rounded-lg border-slate-300 bg-white text-xs focus:border-cyan-500 focus:ring-cyan-500" placeholder="button[type=submit], text=Weiter"></textarea></div>
                    <div><label class="block text-[10px] font-bold uppercase tracking-wide text-slate-500">Wert, URL oder Wartezeit</label><input type="text" wire:model="probeValue" class="mt-1 w-full rounded-lg border-slate-300 bg-white text-xs focus:border-cyan-500 focus:ring-cyan-500"></div>
                    <div class="grid grid-cols-2 gap-2">
                        <button type="button" wire:click="runProbe" @disabled(! $isPaused) class="rounded-lg bg-slate-900 px-3 py-2 text-[11px] font-bold text-white hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-35">Probe ausführen</button>
                        <button type="button" wire:click="commitProbeAsTask" @disabled(! is_array($probeResult)) class="rounded-lg border border-cyan-300 bg-cyan-50 px-3 py-2 text-[11px] font-bold text-cyan-800 hover:bg-cyan-100 disabled:cursor-not-allowed disabled:opacity-35">Als Task einsetzen</button>
                    </div>
                    @if(! $isPaused)
                        <p class="rounded-lg border border-amber-200 bg-amber-50 p-2.5 text-[10px] leading-4 text-amber-800">Proben sind verfügbar, sobald der Lauf pausiert ist.</p>
                    @endif
                    @if(is_array($probeResult))
                        <div class="rounded-lg border p-2.5 {{ data_get($probeResult, 'successful') ? 'border-emerald-200 bg-emerald-50' : 'border-rose-200 bg-rose-50' }}">
                            <p class="text-[10px] font-bold {{ data_get($probeResult, 'successful') ? 'text-emerald-800' : 'text-rose-800' }}">{{ data_get($probeResult, 'successful') ? 'Probe erfolgreich' : 'Probe fehlgeschlagen' }}</p>
                            <pre class="mt-2 max-h-40 overflow-auto whitespace-pre-wrap rounded bg-white/70 p-2 text-[9px] leading-4 text-slate-600">{{ json_encode(data_get($probeResult, 'result'), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                        </div>
                    @endif
                </div>
            </section>
        </div>
    </aside>
</div>
