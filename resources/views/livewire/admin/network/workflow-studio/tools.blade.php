<div class="h-full min-h-0 overflow-y-auto">
    <div class="mx-auto grid max-w-[1500px] gap-4 xl:grid-cols-[minmax(0,1fr)_380px]">
        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div><p class="text-[10px] font-black uppercase tracking-[0.16em] text-cyan-700">Browserwerkzeuge</p><h2 class="mt-1 text-base font-bold text-slate-950">Selector pro Fenster prüfen</h2><p class="mt-1 text-xs text-slate-500">Jede Probe bleibt an den pausierten Zustand des gewählten Browserfensters gebunden.</p></div>
                <span class="rounded-full border px-2.5 py-1 text-[10px] font-bold {{ $statusTone }}">{{ $statusLabel }}</span>
            </div>
            <div class="mt-5 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                @foreach($browserWindows as $window)
                    <article class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                        <div class="flex items-start justify-between gap-3"><div class="min-w-0"><h3 class="truncate text-sm font-bold text-slate-900">{{ $window['name'] }}</h3><p class="mt-1 truncate text-[10px] text-slate-500">{{ $window['url'] ?: 'Noch keine URL erfasst' }}</p></div><span class="mt-1 h-2.5 w-2.5 shrink-0 rounded-full {{ $window['connected'] ? 'bg-emerald-500' : 'bg-slate-300' }}"></span></div>
                        <button type="button" wire:click="openSelectorProbe(@js($window['name']))" class="mt-4 w-full rounded-lg bg-slate-900 px-3 py-2 text-xs font-bold text-white hover:bg-slate-800">Selector-Modal öffnen</button>
                    </article>
                @endforeach
            </div>
            @if(! $isPaused)<p class="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs leading-5 text-amber-800">Selector-Proben werden erst ausgeführt, wenn der Workflow pausiert ist. Das Modal kann vorher bereits vorbereitet werden.</p>@endif
        </section>

        <aside class="space-y-4">
            <section class="rounded-2xl border-2 border-cyan-300 bg-cyan-50 p-4 shadow-sm">
                <p class="text-[9px] font-black uppercase tracking-[0.16em] text-cyan-700">Ausgewählte Task</p>
                @if($selectedTask)
                    <h2 class="mt-2 text-sm font-bold text-cyan-950">{{ $selectedTask['title'] ?? $selectedTaskKey }}</h2><p class="mt-1 break-all font-mono text-[10px] text-cyan-700">{{ $selectedTask['task_key'] ?? $selectedTaskKey }}</p>
                    <div class="mt-4 grid grid-cols-2 gap-2"><button type="button" wire:click="runSingleTask" @disabled($isActive) class="rounded-lg bg-cyan-600 px-3 py-2 text-[11px] font-bold text-white disabled:opacity-40">1 Task testen</button><button type="button" wire:click="editSelectedTask" class="rounded-lg border border-cyan-300 bg-white px-3 py-2 text-[11px] font-bold text-cyan-800">Bearbeiten</button></div>
                @else
                    <p class="mt-2 text-xs leading-5 text-cyan-800">Wähle im Workflow-Tab eine Task aus.</p>
                @endif
            </section>
            <section class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm"><div class="flex items-center justify-between"><div><h3 class="text-xs font-bold text-slate-900">Task-Navigation</h3><p class="mt-1 text-[10px] text-slate-500">Nur Auswahl, keine Ausführung</p></div><span class="font-mono text-[10px] font-bold text-slate-400">{{ $selectedTaskNumber ?: '–' }}/{{ $taskCount }}</span></div><div class="mt-3 grid grid-cols-2 gap-2"><button type="button" wire:click="selectPreviousTask" @disabled(! $hasPreviousTask) class="rounded-lg border border-slate-300 px-3 py-2 text-[11px] font-bold text-slate-700 disabled:opacity-35">← Zurück</button><button type="button" wire:click="selectNextTask" @disabled(! $hasNextTask) class="rounded-lg border border-slate-300 px-3 py-2 text-[11px] font-bold text-slate-700 disabled:opacity-35">Weiter →</button></div></section>
        </aside>
    </div>
</div>
