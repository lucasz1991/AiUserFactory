<div class="grid h-full min-h-0 gap-4 xl:grid-cols-[minmax(0,1fr)_380px]">
    <div class="flex min-h-0 flex-col overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        <div class="flex shrink-0 flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-4 py-3">
            <div>
                <h2 class="text-sm font-bold text-slate-950">Workflow-Vorschau & Live-Browser</h2>
                <p class="mt-1 text-xs text-slate-500">Tatsächlichen Laufweg, Rücksprünge, Task-Status und Browserzustand gemeinsam beobachten.</p>
            </div>
            <span class="rounded-full border px-2.5 py-1 text-[10px] font-bold {{ $isPaused ? 'border-amber-200 bg-amber-50 text-amber-700' : 'border-slate-200 bg-slate-50 text-slate-500' }}">
                {{ $isPaused ? 'Proben freigegeben' : 'Für Proben pausieren' }}
            </span>
        </div>
        <div class="min-h-0 flex-1 overflow-auto bg-slate-50 p-3">
            <div class="min-h-full overflow-hidden rounded-lg border border-slate-200 bg-white shadow-inner">
                @if($run)
                    <x-workflows.run-preview :workflow-run="$run" :selectable-tasks="true" :expanded="true" />
                @else
                    <div class="flex min-h-[420px] flex-col items-center justify-center px-6 text-center">
                        <span class="flex h-12 w-12 items-center justify-center rounded-full bg-sky-50 text-sky-600">
                            <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="2"></rect><path d="M3 9h18M7 6.5h.01M10 6.5h.01"></path></svg>
                        </span>
                        <p class="mt-4 text-sm font-semibold text-slate-800">Noch keine Browser-Vorschau</p>
                        <p class="mt-1 max-w-sm text-xs leading-5 text-slate-500">Starte den Workflow. Sobald ein Browserfenster verfügbar ist, erscheint es hier und bleibt über Pausen hinweg erhalten.</p>
                    </div>
                @endif
            </div>
        </div>
    </div>

    <aside class="min-h-0 overflow-y-auto rounded-xl border border-slate-200 bg-white shadow-sm">
        <div class="sticky top-0 z-10 border-b border-slate-200 bg-white px-4 py-3">
            <h3 class="text-sm font-bold text-slate-950">Selector-Probe</h3>
            <p class="mt-1 text-xs text-slate-500">Eine Probe ändert den Workflow erst nach „Als Task übernehmen“.</p>
        </div>
        <div class="space-y-4 p-4">
            <div>
                <label class="block text-[11px] font-bold uppercase tracking-wide text-slate-500">Probeaktion</label>
                <select wire:model.live="probeAction" class="mt-1 w-full rounded-lg border-slate-300 bg-white text-sm text-slate-800 shadow-sm focus:border-sky-500 focus:ring-sky-500">
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
            <div>
                <label class="block text-[11px] font-bold uppercase tracking-wide text-slate-500">Browserfenster</label>
                <input type="text" wire:model="probeBrowserWindow" class="mt-1 w-full rounded-lg border-slate-300 bg-white text-sm text-slate-800 shadow-sm focus:border-sky-500 focus:ring-sky-500" placeholder="main">
            </div>
            <div>
                <label class="block text-[11px] font-bold uppercase tracking-wide text-slate-500">Selector, Text oder Rolle</label>
                <textarea wire:model="probeSelector" rows="3" class="mt-1 w-full rounded-lg border-slate-300 bg-white text-sm text-slate-800 shadow-sm focus:border-sky-500 focus:ring-sky-500" placeholder="button[type=submit], text=Weiter, #search"></textarea>
            </div>
            <div>
                <label class="block text-[11px] font-bold uppercase tracking-wide text-slate-500">Wert, URL oder Wartezeit</label>
                <input type="text" wire:model="probeValue" class="mt-1 w-full rounded-lg border-slate-300 bg-white text-sm text-slate-800 shadow-sm focus:border-sky-500 focus:ring-sky-500" placeholder="Suchbegriff, URL oder Sekunden">
            </div>

            <div class="grid gap-2 sm:grid-cols-2 xl:grid-cols-1 2xl:grid-cols-2">
                <button type="button" wire:click="runProbe" @disabled(! $isPaused) class="rounded-lg bg-sky-600 px-3 py-2.5 text-xs font-bold text-white shadow-sm transition hover:bg-sky-500 disabled:cursor-not-allowed disabled:opacity-40">Probe ausführen</button>
                <button type="button" wire:click="commitProbeAsTask" @disabled(! is_array($probeResult)) class="rounded-lg border border-violet-200 bg-violet-50 px-3 py-2.5 text-xs font-bold text-violet-700 transition hover:bg-violet-100 disabled:cursor-not-allowed disabled:opacity-40">Als Task übernehmen</button>
            </div>

            @if(! $isPaused)
                <div class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs leading-5 text-amber-800">
                    Pausiere den Lauf zuerst. So bleibt der aktuelle Browserzustand stabil, während du Selector und Eingaben prüfst.
                </div>
            @endif

            @if(is_array($probeResult))
                <div class="rounded-lg border p-3 {{ data_get($probeResult, 'successful') ? 'border-emerald-200 bg-emerald-50' : 'border-rose-200 bg-rose-50' }}">
                    <div class="text-xs font-bold {{ data_get($probeResult, 'successful') ? 'text-emerald-800' : 'text-rose-800' }}">{{ data_get($probeResult, 'successful') ? 'Probe erfolgreich' : 'Probe fehlgeschlagen' }}</div>
                    <pre class="mt-2 max-h-52 overflow-auto whitespace-pre-wrap rounded-md bg-white/70 p-2 text-[10px] leading-4 text-slate-600">{{ json_encode(data_get($probeResult, 'result'), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                </div>
            @endif

            @if($selectedTask)
                <div class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                    <div class="text-[10px] font-bold uppercase tracking-wide text-slate-400">Aktive Task</div>
                    <div class="mt-1 text-xs font-semibold text-slate-800">{{ $selectedTask['title'] ?? $selectedTaskKey }}</div>
                    <button type="button" wire:click="editSelectedTask" class="mt-2 text-xs font-bold text-sky-700 hover:text-sky-600">Einstellungen im Modal öffnen →</button>
                </div>
            @endif
        </div>
    </aside>
</div>
