@php
    $copilotState = is_array($copilotSession?->state_json) ? $copilotSession->state_json : [];
    $copilotActive = $copilotSession && in_array($copilotSession->status, ['running', 'repairing', 'verifying'], true);
@endphp

<div class="sticky top-0 z-10 border-b border-slate-200 bg-white/95 px-4 py-3 backdrop-blur">
    <div class="flex items-center justify-between gap-3">
        <div><p class="text-[10px] font-bold uppercase tracking-[0.16em] text-cyan-700">Copilot</p><h2 class="mt-1 text-sm font-bold text-slate-950">Immer bereit</h2></div>
        <span class="inline-flex items-center gap-1.5 rounded-full border px-2 py-1 text-[9px] font-black {{ $copilotActive ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-slate-200 bg-slate-50 text-slate-500' }}"><span class="h-1.5 w-1.5 rounded-full bg-current"></span>{{ $copilotSession?->status ?? 'bereit' }}</span>
    </div>
</div>

<div class="space-y-4 p-4">
    @if($copilotSession)
        <section class="rounded-xl border border-cyan-200 bg-cyan-50 p-3">
            <p class="text-[9px] font-black uppercase tracking-[0.14em] text-cyan-700">Aktueller Vorgang</p>
            <h3 class="mt-1 text-xs font-bold text-cyan-950">{{ data_get($copilotState, 'current_step_name', 'Wird vorbereitet') }}</h3>
            <p class="mt-1 break-all font-mono text-[10px] text-cyan-700">{{ data_get($copilotState, 'current_task_key', 'Noch keine Task') }}</p>
            <p class="mt-2 text-[11px] leading-4 text-cyan-900/80">{{ data_get($copilotState, 'next_action', 'Analyse fortsetzen') }}</p>
            @if($copilotLatestEvent)<p class="mt-3 border-t border-cyan-200 pt-2 text-[10px] leading-4 text-cyan-800">{{ $copilotLatestEvent->message }}</p>@endif
        </section>
    @endif

    <section class="rounded-xl border border-slate-200 bg-slate-50 p-3">
        <div class="flex items-center justify-between gap-3"><h3 class="text-xs font-bold text-slate-900">Ziel und Erfolg</h3>@if($modeLocked)<span class="text-[9px] font-bold text-slate-400">festgeschrieben</span>@endif</div>
        <label class="mt-3 block text-[10px] font-bold text-slate-600">Fachliches Ziel</label>
        <textarea wire:model="goal" rows="3" @disabled($modeLocked) class="mt-1 w-full rounded-lg border-slate-300 bg-white text-xs leading-5 text-slate-800 shadow-sm focus:border-cyan-500 focus:ring-cyan-500 disabled:bg-slate-100" placeholder="Welches Ergebnis soll erreicht werden?"></textarea>
        <label class="mt-3 block text-[10px] font-bold text-slate-600">Erfolgskriterien, eines pro Zeile</label>
        <textarea wire:model="successCriteria" rows="4" @disabled($modeLocked) class="mt-1 w-full rounded-lg border-slate-300 bg-white text-xs leading-5 text-slate-800 shadow-sm focus:border-cyan-500 focus:ring-cyan-500 disabled:bg-slate-100" placeholder="Finale URL enthält /success"></textarea>
    </section>

    <section class="rounded-xl border border-slate-200 bg-white p-3">
        <h3 class="text-xs font-bold text-slate-900">Testkontext</h3>
        <select wire:model="personId" @disabled($modeLocked) class="mt-3 w-full rounded-lg border-slate-300 bg-white text-xs text-slate-800 shadow-sm focus:border-cyan-500 focus:ring-cyan-500 disabled:bg-slate-100">
            <option value="">Keine Person</option>
            @foreach($persons as $person)<option value="{{ $person->id }}">{{ $person->display_name }}</option>@endforeach
        </select>
        @if(! $autonomousMode)
            <select wire:model.live="executionTarget" @disabled($modeLocked) class="mt-2 w-full rounded-lg border-slate-300 bg-white text-xs text-slate-800 shadow-sm focus:border-cyan-500 focus:ring-cyan-500 disabled:bg-slate-100">
                <option value="system">System</option>
                <option value="client_controller">ClientController</option>
            </select>
            @if($executionTarget === 'client_controller')
                <select wire:model="networkNodeId" @disabled($modeLocked) class="mt-2 w-full rounded-lg border-slate-300 bg-white text-xs text-slate-800 shadow-sm disabled:bg-slate-100">
                    <option value="">Node automatisch wählen</option>
                    @foreach($networkNodes as $node)<option value="{{ $node->id }}">{{ $node->name }}</option>@endforeach
                </select>
            @endif
        @else
            <p class="mt-2 rounded-lg bg-slate-50 px-3 py-2 text-[10px] text-slate-500">Autonome Optimierung läuft ausschließlich im System.</p>
        @endif
        <details class="mt-3">
            <summary class="cursor-pointer text-[10px] font-bold text-slate-600">Workflow-Eingaben (JSON)</summary>
            <textarea wire:model="workflowInputs" rows="6" @disabled($modeLocked) spellcheck="false" class="mt-2 w-full rounded-lg border-slate-700 bg-slate-950 font-mono text-[10px] leading-4 text-slate-100 shadow-sm disabled:opacity-70"></textarea>
        </details>
    </section>

    <section class="rounded-xl border border-sky-200 bg-sky-50 p-3">
        <h3 class="text-xs font-bold text-sky-900">Copilot-Berechtigung</h3>
        <select wire:model="permissionMode" @disabled($modeLocked) class="mt-2 w-full rounded-lg border-sky-200 bg-white text-xs font-semibold text-slate-800 shadow-sm disabled:bg-slate-100">
            @foreach($permissionModes as $permission)
                @if($permission->value !== 'unrestricted' || auth()->user()?->isAdmin())<option value="{{ $permission->value }}">{{ $permission->label() }}</option>@endif
            @endforeach
        </select>
        @if($permissionMode === 'unrestricted' && ! $modeLocked)
            <label class="mt-2 flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 p-2 text-[10px] font-semibold leading-4 text-amber-900"><input type="checkbox" wire:model="unrestrictedWarningAcknowledged" class="mt-0.5 rounded border-amber-400 text-amber-600">Uneingeschränkte Außenaktionen für diese Sitzung erlauben.</label>
        @endif
        @if(! $modeLocked)<button type="button" wire:click="setPermissionMode" class="mt-2 w-full rounded-lg border border-sky-200 bg-white px-3 py-2 text-[10px] font-bold text-sky-700 hover:bg-sky-100">Berechtigung speichern</button>@endif
    </section>

    @if(! $modeLocked)
        <button type="button" wire:click="saveSessionDefinition" class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-xs font-bold text-slate-700 shadow-sm hover:bg-slate-50">Vorgaben speichern</button>
    @endif

    @if($autonomousMode)
        @if(! $copilotSession || in_array($copilotSession->status, ['succeeded', 'failed', 'stopped', 'budget_exhausted'], true))
            <button type="button" wire:click="startCopilot" wire:confirm="Autonome Copilot-Optimierung starten? Danach ist die Benutzersteuerung für diese Sitzung gesperrt." class="w-full rounded-lg bg-cyan-700 px-3 py-3 text-xs font-bold text-white shadow-sm transition hover:bg-cyan-600 active:translate-y-px">Autonome Optimierung starten</button>
        @else
            <div class="rounded-xl border border-cyan-200 bg-cyan-50 p-3 text-[11px] leading-5 text-cyan-900"><strong>Copilot steuert diese Sitzung.</strong><br>Das Modal kann geschlossen werden; der Lauf arbeitet im Hintergrund weiter. Ein technischer Not-Stopp bleibt der Administration vorbehalten.</div>
        @endif
    @else
        <div class="rounded-xl border border-slate-200 bg-slate-50 p-3 text-[11px] leading-5 text-slate-600"><strong class="text-slate-900">Interaktiver Copilot-Kontext aktiv.</strong><br>Auswahl, Browserzustand, Fehler und Revision sind für Vorschläge verfügbar. Änderungen werden über das bekannte Task-Formular übernommen.</div>
    @endif
</div>
