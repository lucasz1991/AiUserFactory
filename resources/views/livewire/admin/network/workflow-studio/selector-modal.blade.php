<x-ui.dialog-modal wire:model="showSelectorProbeModal" maxWidth="3xl">
    <x-slot name="title"><div><span class="text-base font-semibold text-slate-950">Selector-Prüfung · {{ $probeBrowserWindow }}</span><p class="mt-1 text-xs font-normal text-slate-500">Elemente im ausgewählten Browserfenster suchen, markieren oder testweise bedienen.</p></div></x-slot>
    <x-slot name="content">
        <div class="grid gap-4 md:grid-cols-2">
            <div class="space-y-4">
                <div><label class="block text-xs font-bold uppercase tracking-wide text-slate-500">Probeaktion</label><select wire:model.live="probeAction" class="mt-1 w-full rounded-lg border-slate-300 bg-white text-sm"><option value="selector.search">Selector suchen</option><option value="selector.highlight">Element hervorheben</option><option value="selector.read">Text / Attribute lesen</option><option value="probe.click">Klicken</option><option value="probe.fill">Eingabe setzen</option><option value="probe.keypress">Taste senden</option><option value="probe.submit">Formular absenden</option><option value="probe.wait">Warten</option><option value="probe.navigate">Navigieren</option><option value="probe.screenshot">Screenshot neu erfassen</option><option value="probe.dom_refresh">DOM neu erfassen</option></select></div>
                <div><label class="block text-xs font-bold uppercase tracking-wide text-slate-500">Browserfenster</label><input type="text" wire:model="probeBrowserWindow" class="mt-1 w-full rounded-lg border-slate-300 bg-slate-50 text-sm" readonly></div>
                <div><label class="block text-xs font-bold uppercase tracking-wide text-slate-500">Selector, Text oder Rolle</label><textarea wire:model="probeSelector" rows="4" class="mt-1 w-full rounded-lg border-slate-300 bg-white font-mono text-xs" placeholder="button[type=submit], text=Weiter"></textarea></div>
                <div><label class="block text-xs font-bold uppercase tracking-wide text-slate-500">Wert, URL oder Wartezeit</label><input type="text" wire:model="probeValue" class="mt-1 w-full rounded-lg border-slate-300 bg-white text-sm"></div>
            </div>
            <div>
                @if(is_array($probeResult))
                    <div class="h-full rounded-xl border p-4 {{ data_get($probeResult, 'successful') ? 'border-emerald-200 bg-emerald-50' : 'border-rose-200 bg-rose-50' }}"><p class="text-xs font-bold {{ data_get($probeResult, 'successful') ? 'text-emerald-800' : 'text-rose-800' }}">{{ data_get($probeResult, 'successful') ? 'Probe erfolgreich' : 'Probe fehlgeschlagen' }}</p><pre class="mt-3 max-h-80 overflow-auto whitespace-pre-wrap rounded-lg bg-white/80 p-3 text-[10px] leading-5 text-slate-600">{{ json_encode(data_get($probeResult, 'result'), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre></div>
                @else
                    <div class="flex h-full min-h-64 items-center justify-center rounded-xl border border-dashed border-slate-300 bg-slate-50 p-6 text-center text-xs leading-5 text-slate-500">Die Probe nutzt den pausierten Browserzustand. Das Ergebnis erscheint hier und kann anschließend als echte Workflow-Task übernommen werden.</div>
                @endif
            </div>
        </div>
        @if(! $isPaused)<p class="mt-4 rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs text-amber-800">Pausiere zuerst den Lauf, damit die Probe denselben stabilen Browserzustand untersucht.</p>@endif
    </x-slot>
    <x-slot name="footer"><button type="button" x-on:click="$dispatch('close')" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700">Schließen</button><button type="button" wire:click="runProbe" @disabled(! $isPaused) class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white disabled:opacity-40">Probe ausführen</button><button type="button" wire:click="commitProbeAsTask" @disabled(! is_array($probeResult)) class="rounded-lg bg-cyan-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-40">Als Task einsetzen</button></x-slot>
</x-ui.dialog-modal>
