<div class="space-y-8">
    <div>
        <h2 class="text-lg font-semibold text-gray-900">Prozesse</h2>
        <p class="mt-1 text-sm text-gray-500">
            Laufzeitverhalten fuer Browser-Prozesse, Live-Vorschau und Debug-Ausgaben.
        </p>
    </div>

    <div class="rounded-lg border border-gray-200 bg-white p-5">
        <h3 class="text-sm font-semibold text-gray-900">Vorschau und Aktivitaet</h3>

        <div class="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <label class="inline-flex items-center gap-3 rounded-md border border-gray-200 bg-gray-50 p-3 text-sm font-medium text-gray-700">
                <input type="checkbox" wire:model.defer="previewModalEnabled" class="rounded border-gray-300 text-slate-900 shadow-sm focus:ring-slate-900">
                Vorschau-Modal automatisch anzeigen
            </label>

            <label class="inline-flex items-center gap-3 rounded-md border border-gray-200 bg-gray-50 p-3 text-sm font-medium text-gray-700">
                <input type="checkbox" wire:model.defer="livePreviewEnabled" class="rounded border-gray-300 text-slate-900 shadow-sm focus:ring-slate-900">
                Screenshots speichern
            </label>

            <label class="inline-flex items-center gap-3 rounded-md border border-gray-200 bg-gray-50 p-3 text-sm font-medium text-gray-700">
                <input type="checkbox" wire:model.defer="browserActivityCheckEnabled" class="rounded border-gray-300 text-slate-900 shadow-sm focus:ring-slate-900">
                Browserfenster weiter ueberwachen
            </label>

            <label class="inline-flex items-center gap-3 rounded-md border border-gray-200 bg-gray-50 p-3 text-sm font-medium text-gray-700">
                <input type="checkbox" wire:model.defer="domDebugEnabled" class="rounded border-gray-300 text-slate-900 shadow-sm focus:ring-slate-900">
                DOM-Debug protokollieren
            </label>
        </div>

        <div class="mt-5 grid gap-6 md:grid-cols-2 xl:grid-cols-3">
            <div>
                <label for="process-live-preview-interval" class="block text-sm font-medium text-gray-700">Screenshot- und Vorschauintervall (Sek.)</label>
                <input id="process-live-preview-interval" type="number" min="1" max="60" wire:model.defer="livePreviewIntervalSeconds" class="mt-1 block w-full rounded-md border border-gray-300 p-3 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                @error('livePreviewIntervalSeconds') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
        </div>
    </div>

    <div class="rounded-lg border border-slate-100 bg-slate-50 p-4 text-sm text-slate-600">
        <p class="font-semibold">Wirkung</p>
        <p class="mt-1">
            Das Intervall steuert die Screenshot-Frequenz im Node-Prozess und die Aktualisierung der Bilder in den Livewire-Vorschau-Modalen.
        </p>
    </div>

    <div class="flex justify-end">
        <button type="button" wire:click="saveSettings" wire:loading.attr="disabled" class="inline-flex items-center justify-center rounded-md bg-slate-900 px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-slate-800 disabled:cursor-wait disabled:opacity-60">
            Speichern
        </button>
    </div>
</div>
