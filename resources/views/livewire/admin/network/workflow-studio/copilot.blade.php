<div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_340px]">
    <div class="space-y-4">
        <div>
            <label class="block text-sm font-semibold text-slate-800">Fachliches Ziel</label>
            <p class="mt-1 text-xs text-slate-500">Beschreibe das gewünschte Ergebnis, nicht nur einzelne Klicks.</p>
            <textarea wire:model="goal" rows="4" class="mt-2 w-full rounded-lg border-slate-300 bg-white text-sm text-slate-800 shadow-sm focus:border-sky-500 focus:ring-sky-500" placeholder="Was soll der Workflow von Anfang bis Ende erreichen?"></textarea>
        </div>
        <div>
            <label class="block text-sm font-semibold text-slate-800">Erfolgskriterien</label>
            <p class="mt-1 text-xs text-slate-500">Ein prüfbares Kriterium pro Zeile.</p>
            <textarea wire:model="successCriteria" rows="5" class="mt-2 w-full rounded-lg border-slate-300 bg-white text-sm text-slate-800 shadow-sm focus:border-sky-500 focus:ring-sky-500" placeholder="Ergebnisliste enthält mindestens einen Treffer"></textarea>
        </div>
        <div>
            <label class="block text-sm font-semibold text-slate-800">Workflow-Eingaben</label>
            <p class="mt-1 text-xs text-slate-500">JSON-Objekt mit den Startvariablen des Tests.</p>
            <textarea wire:model="workflowInputs" rows="8" spellcheck="false" class="mt-2 w-full rounded-lg border-slate-300 bg-slate-950 font-mono text-xs leading-5 text-slate-100 shadow-sm focus:border-sky-500 focus:ring-sky-500"></textarea>
        </div>
    </div>

    <aside class="space-y-4">
        <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
            <h3 class="text-xs font-bold uppercase tracking-wide text-slate-500">Arbeitsmodus</h3>
            <select wire:model="mode" class="mt-3 w-full rounded-lg border-slate-300 bg-white text-sm text-slate-800 shadow-sm focus:border-sky-500 focus:ring-sky-500">
                <option value="manual">Manuell</option>
                <option value="assisted">Mit Copilot</option>
                <option value="autonomous">Autonom</option>
            </select>
            <div class="mt-3 space-y-2">
                <select wire:model="personId" class="w-full rounded-lg border-slate-300 bg-white text-sm text-slate-800 shadow-sm focus:border-sky-500 focus:ring-sky-500">
                    <option value="">Keine Person</option>
                    @foreach($persons as $person)<option value="{{ $person->id }}">{{ $person->display_name }}</option>@endforeach
                </select>
                <select wire:model.live="executionTarget" class="w-full rounded-lg border-slate-300 bg-white text-sm text-slate-800 shadow-sm focus:border-sky-500 focus:ring-sky-500">
                    <option value="system">System</option>
                    <option value="client_controller">ClientController</option>
                </select>
                @if($executionTarget === 'client_controller')
                    <select wire:model="networkNodeId" class="w-full rounded-lg border-slate-300 bg-white text-sm text-slate-800 shadow-sm focus:border-sky-500 focus:ring-sky-500">
                        <option value="">Node wählen</option>
                        @foreach($networkNodes as $node)<option value="{{ $node->id }}">{{ $node->name }}</option>@endforeach
                    </select>
                @endif
            </div>
        </div>

        <div class="rounded-xl border border-sky-200 bg-sky-50 p-4">
            <h3 class="text-xs font-bold uppercase tracking-wide text-sky-700">Copilot-Berechtigung</h3>
            <select wire:model="permissionMode" class="mt-3 w-full rounded-lg border-sky-200 bg-white text-sm font-semibold text-slate-800 shadow-sm focus:border-sky-500 focus:ring-sky-500">
                @foreach($permissionModes as $permission)
                    @if($permission->value !== 'unrestricted' || auth()->user()?->isAdmin())
                        <option value="{{ $permission->value }}">{{ $permission->label() }}</option>
                    @endif
                @endforeach
            </select>
            <p class="mt-2 text-xs leading-5 text-sky-800">
                @if($permissionMode === 'ask_all') Jede Probe, Ausführung und Änderung wird bestätigt.
                @elseif($permissionMode === 'unrestricted') Alle unterstützten Aktionen sind ohne Einzelrückfrage erlaubt.
                @else Sichere Reparaturen laufen automatisch; kritische Änderungen und Außenaktionen werden bestätigt.
                @endif
            </p>
            @if($permissionMode === 'unrestricted')
                <label class="mt-3 flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs font-semibold leading-5 text-amber-900">
                    <input type="checkbox" wire:model="unrestrictedWarningAcknowledged" class="mt-0.5 rounded border-amber-400 text-amber-600 focus:ring-amber-500">
                    Uneingeschränkte Außenaktionen für diese Sitzung erlauben.
                </label>
            @endif
            <button type="button" wire:click="setPermissionMode" class="mt-3 w-full rounded-lg border border-sky-200 bg-white px-3 py-2 text-xs font-bold text-sky-700 shadow-sm hover:bg-sky-100">Berechtigung speichern</button>
        </div>

        <div class="grid gap-2">
            <button type="button" wire:click="saveSessionDefinition" class="rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-xs font-bold text-slate-700 shadow-sm hover:bg-slate-50">Definition speichern</button>
            <button type="button" wire:click="startCopilot" wire:confirm="Autonome Copilot-Optimierung starten?" class="rounded-lg bg-sky-600 px-3 py-2.5 text-xs font-bold text-white shadow-sm hover:bg-sky-500">Copilot starten</button>
        </div>
    </aside>
</div>
