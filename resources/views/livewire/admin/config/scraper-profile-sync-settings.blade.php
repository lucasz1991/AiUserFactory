<div class="space-y-6" wire:loading.class="opacity-50 pointer-events-none cursor-wait">
    <div class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold text-gray-900">Scraper-Profil-Transfer</h1>
                <p class="mt-2 text-sm text-gray-500">
                    Hier wird die Verbindung zur Base-Installation konfiguriert. Der Base-Sync nutzt eine abgesicherte Token-Authentifizierung.
                </p>
            </div>
        </div>
    </div>

    @if (session()->has('success'))
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">
            {{ session('success') }}
        </div>
    @endif

    <div class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
        <div class="border-b border-gray-200 px-6 py-5">
            <h2 class="text-lg font-semibold text-gray-900">Verbindung zur Base-Installation</h2>
            <p class="mt-1 text-sm text-gray-500">Gebe die Base-URL und das Token an, das der Base-API-Endpoint zum Schutz des Scraper-Profil-Syncs erwartet.</p>
        </div>

        <div class="space-y-6 px-6 py-6">
            <div>
                <label for="base-api-url" class="block text-sm font-medium text-gray-700">Base API URL</label>
                <input id="base-api-url" type="url" wire:model.defer="baseApiUrl" placeholder="https://base.example.com/api/scraper-profiles/sync" class="mt-1 block w-full rounded-md border border-gray-300 p-3 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" />
                @error('baseApiUrl') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="api-password" class="block text-sm font-medium text-gray-700">API Passwort</label>
                <input id="api-password" type="password" wire:model.defer="apiPassword" autocomplete="new-password" class="mt-1 block w-full rounded-md border border-gray-300 p-3 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500" />
                @error('apiPassword') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                <p class="mt-2 text-xs text-gray-500">Dieses Passwort muss mit der Base-Installation abgestimmt sein. Ohne korrektes Passwort wird der Scraper-Profil-Sync abgelehnt.</p>
            </div>

            <div class="rounded-lg border border-slate-100 bg-slate-50 p-4 text-sm text-slate-600">
                <p class="font-semibold">Hinweis</p>
                <p class="mt-1">Wenn in der .env-Datei bereits eine Base-URL oder ein Token gesetzt ist, werden diese Werte beim Laden verwendet. Mit dieser Seite kannst du die Werte direkt in der Datenbank speichern.</p>
            </div>

            <div class="flex justify-end">
                <button type="button" wire:click="saveSettings" class="inline-flex items-center justify-center rounded-md bg-slate-900 px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-slate-800">
                    Speichern
                </button>
            </div>
        </div>
    </div>
</div>
