<div class="space-y-8">
    @if (session()->has('success'))
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">
            {{ session('success') }}
        </div>
    @endif

    <div>
        <h2 class="text-lg font-semibold text-gray-900">Automatische Aktivitaetsplanung</h2>
        <p class="mt-1 text-sm text-gray-500">
            Steuert, ob die interne Netzwerk-Aktivitaetsplanung per Scheduler automatisch ausgefuehrt wird.
        </p>
    </div>

    <div class="rounded-lg border border-gray-200 bg-white p-5">
        <h3 class="text-sm font-semibold text-gray-900">Scheduler</h3>

        <div class="mt-5 grid gap-6 md:grid-cols-2 xl:grid-cols-4">
            <div class="md:col-span-2">
                <label for="auto-planning-enabled" class="block text-sm font-medium text-gray-700">Automatische Planung</label>
                <div class="mt-1 flex h-[46px] items-center rounded-md border border-gray-300 bg-white px-3 shadow-sm">
                    <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                        <input id="auto-planning-enabled" type="checkbox" wire:model.defer="autoPlanningEnabled" class="rounded border-gray-300 text-slate-900 shadow-sm focus:ring-slate-900">
                        <span>Scheduler-Laeufe aktivieren</span>
                    </label>
                </div>
                @error('autoPlanningEnabled') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="auto-planning-days" class="block text-sm font-medium text-gray-700">Plan-Tage</label>
                <input id="auto-planning-days" type="number" min="1" max="14" wire:model.defer="autoPlanningDays" class="mt-1 block w-full rounded-md border border-gray-300 p-3 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" />
                @error('autoPlanningDays') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="auto-planning-intensity" class="block text-sm font-medium text-gray-700">Plan-Intensitaet</label>
                <select id="auto-planning-intensity" wire:model.defer="autoPlanningIntensity" class="mt-1 block w-full rounded-md border border-gray-300 p-3 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    <option value="quiet">Ruhig</option>
                    <option value="balanced">Ausgewogen</option>
                    <option value="active">Aktiv</option>
                    <option value="creator">Creator</option>
                </select>
                @error('autoPlanningIntensity') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="mt-6 grid gap-4 md:grid-cols-2">
            <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                <input type="checkbox" wire:model.defer="autoPlanningQueued" class="rounded border-gray-300 text-slate-900 shadow-sm focus:ring-slate-900">
                Planung als Queue-Job dispatchen
            </label>
        </div>
    </div>

    <div class="rounded-lg border border-slate-100 bg-slate-50 p-4 text-sm text-slate-600">
        <p class="font-semibold">Aktueller Zeitplan</p>
        <p class="mt-1">
            Wenn aktiviert, startet die Planung taeglich um {{ implode(', ', $scheduleTimes) }} Uhr in der App-Zeitzone.
            Gespeicherte Setting-Keys: Gruppe <code>network</code>, Key <code>activity_planning</code>.
        </p>
    </div>

    <div class="flex justify-end">
        <button type="button" wire:click="saveSettings" class="inline-flex items-center justify-center rounded-md bg-slate-900 px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-slate-800">
            Speichern
        </button>
    </div>
</div>
