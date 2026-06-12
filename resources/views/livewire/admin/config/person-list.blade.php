<div class="space-y-6" wire:loading.class="opacity-50 pointer-events-none cursor-wait">
    @php
        $profiles = collect($profileOptions);
        $primaryProfile = $profiles->firstWhere('is_primary', true);
        $totalPersonsCount = $profiles->count();
        $activePersonsCount = $profiles->where('is_active', true)->count();
        $blockedPersonsCount = $profiles->where('is_scrape_blocked', true)->count();
        $botReadyPersonsCount = $profiles->whereIn('bot_status', ['ready', 'training'])->count();
        $baseSyncedPersonsCount = $profiles->where('base_sync_status', 'synced')->count();
    @endphp

    <div class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-200 bg-slate-50 px-5 py-4">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="min-w-0">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Instagram-Scraper</p>
                    <h2 class="mt-1 text-xl font-semibold text-slate-900">Personen verwalten</h2>
                    <p class="mt-1 max-w-3xl text-sm text-slate-600">
                        Sessions, Persona-Daten und Bot-Status an einem Ort. Sichtbare Browserfenster werden nur beim expliziten Session-Aufbau geoeffnet.
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <button type="button" wire:click="openRuntimeSettingsModal" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50">
                        Timeouts
                    </button>
                    <button type="button" wire:click="openCreateProfileModal" class="rounded-md bg-slate-900 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-slate-800">
                        Person hinzufuegen
                    </button>
                </div>
            </div>
        </div>

        <div class="grid gap-px bg-slate-200 sm:grid-cols-2 xl:grid-cols-5">
            <div class="bg-white p-4">
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Personen</p>
                <p class="mt-2 text-2xl font-semibold text-slate-900">{{ $totalPersonsCount }}</p>
            </div>
            <div class="bg-white p-4">
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Analyse aktiv</p>
                <p class="mt-2 text-2xl font-semibold text-emerald-700">{{ $activePersonsCount }}</p>
            </div>
            <div class="bg-white p-4">
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Instagram-Sperren</p>
                <p class="mt-2 text-2xl font-semibold text-amber-700">{{ $blockedPersonsCount }}</p>
            </div>
            <div class="bg-white p-4">
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Bot-bereit</p>
                <p class="mt-2 text-2xl font-semibold text-blue-700">{{ $botReadyPersonsCount }}</p>
            </div>
            <div class="bg-white p-4">
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Base-Sync</p>
                <p class="mt-2 text-2xl font-semibold text-slate-900">{{ $baseSyncedPersonsCount }}<span class="text-sm font-medium text-slate-500">/{{ $totalPersonsCount }}</span></p>
            </div>
        </div>
    </div>

    @if (session()->has('success'))
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">
            {{ session('success') }}
        </div>
    @endif

    <div class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0">
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Standard-Person</p>
                <h3 class="mt-1 text-lg font-semibold text-slate-900">
                    @if($primaryProfile)
                        {{ $primaryProfile['display_name'] }}
                    @else
                        Keine Standard-Person ausgewaehlt
                    @endif
                </h3>
                <p class="mt-1 text-sm text-slate-600">
                    @if($primaryProfile)
                        {{ $primaryProfile['label'] }}{{ $primaryProfile['login_username'] !== '' ? ' - @'.$primaryProfile['login_username'] : '' }} - {{ $activePersonsCount }} {{ $activePersonsCount === 1 ? 'Person ist' : 'Personen sind' }} aktiv.
                    @else
                        Lege eine Person an oder setze eine vorhandene Person als Standard.
                    @endif
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                @if($primaryProfile)
                    <a href="{{ route('persons.show', ['profileId' => $primaryProfile['id']]) }}" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50">
                        Details
                    </a>
                    <button type="button" wire:click="selectAndEditProfile('{{ $primaryProfile['id'] }}')" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50">
                        Bearbeiten
                    </button>
                @endif
                <button type="button" wire:click="syncProfilesToBase" class="rounded-md bg-slate-900 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-slate-800">
                    An Base senden
                </button>
            </div>
        </div>
    </div>

    <div class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-200 px-5 py-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h3 class="text-lg font-semibold text-slate-900">Personenliste</h3>
                    <p class="mt-1 text-sm text-slate-500">Schneller Ueberblick ueber Account, Session, Status und die wichtigsten Aktionen.</p>
                </div>
                <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600 ring-1 ring-slate-200">
                    {{ $totalPersonsCount }} {{ $totalPersonsCount === 1 ? 'Eintrag' : 'Eintraege' }}
                </span>
            </div>
        </div>

        <div class="hidden grid-cols-12 gap-4 border-b border-slate-200 bg-slate-50 px-5 py-3 text-xs font-semibold uppercase tracking-wide text-slate-500 lg:grid">
            <div class="col-span-4">Person</div>
            <div class="col-span-3">Session</div>
            <div class="col-span-3">Status</div>
            <div class="col-span-2 text-right">Aktionen</div>
        </div>

        <div class="divide-y divide-slate-200">
            @forelse($profileOptions as $profile)
                @php
                    $rowClass = $profile['is_scrape_blocked']
                        ? 'border-l-amber-500 bg-amber-50/70'
                        : ($profile['is_primary']
                            ? 'border-l-blue-500 bg-blue-50/60'
                            : ($profile['is_active'] ? 'border-l-emerald-500 bg-white hover:bg-emerald-50/30' : 'border-l-slate-200 bg-white hover:bg-slate-50'));
                    $avatarClass = $profile['is_scrape_blocked']
                        ? 'bg-amber-600 text-white'
                        : ($profile['is_primary']
                            ? 'bg-blue-600 text-white'
                            : ($profile['is_active'] ? 'bg-emerald-600 text-white' : 'bg-slate-100 text-slate-700'));
                    $botLabel = match($profile['bot_status'] ?? 'manual') {
                        'ready' => 'bereit',
                        'training' => 'Training',
                        'disabled' => 'deaktiviert',
                        default => 'manuell',
                    };
                    $baseLabel = match($profile['base_sync_status'] ?? 'pending') {
                        'synced' => 'synchronisiert',
                        'failed' => 'Fehler',
                        default => 'offen',
                    };
                    $baseClass = match($profile['base_sync_status'] ?? 'pending') {
                        'synced' => 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200',
                        'failed' => 'bg-red-50 text-red-700 ring-1 ring-red-200',
                        default => 'bg-slate-100 text-slate-700 ring-1 ring-slate-200',
                    };
                @endphp

                <article wire:key="scraper-profile-{{ $profile['id'] }}" class="grid gap-5 border-l-4 px-5 py-5 text-sm lg:grid-cols-12 {{ $rowClass }}">
                    <div class="flex min-w-0 items-start gap-4 lg:col-span-4">
                        @if(!empty($profile['avatar_url']))
                            <img
                                src="{{ $profile['avatar_url'] }}"
                                alt="Profilbild von {{ $profile['display_name'] }}"
                                class="h-12 w-12 shrink-0 rounded-lg object-cover ring-1 ring-slate-200"
                            >
                        @else
                            <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-lg {{ $avatarClass }} text-base font-semibold">
                                {{ strtoupper(substr($profile['label'], 0, 1)) }}
                            </div>
                        @endif
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <p class="truncate font-semibold text-slate-900">{{ $profile['display_name'] }}</p>
                                @if($profile['is_primary'])
                                    <span class="rounded-full bg-blue-600 px-2 py-0.5 text-xs font-semibold text-white">Standard</span>
                                @endif
                            </div>
                            <p class="mt-1 truncate text-xs text-slate-500">{{ $profile['label'] }}</p>
                            <p class="mt-1 truncate text-xs font-medium {{ $profile['login_username'] !== '' ? 'text-pink-700' : 'text-slate-400' }}">
                                {{ $profile['login_username'] !== '' ? '@'.$profile['login_username'] : 'Kein Instagram-Benutzername' }}
                            </p>
                            <p class="mt-2 truncate text-xs text-slate-500">
                                {{ trim(($profile['person_city'] ?? '').' '.($profile['person_country'] ?? '')) ?: 'Keine Personendaten hinterlegt' }}
                            </p>
                        </div>
                    </div>

                    <div class="min-w-0 space-y-2 text-xs text-slate-500 lg:col-span-3">
                        <div class="rounded-md bg-slate-50 p-3 ring-1 ring-slate-200">
                            <p class="font-semibold text-slate-700">Browser-Profil</p>
                            <p class="mt-1 break-all">{{ $profile['browser_profile_path'] }}</p>
                        </div>
                        <div class="rounded-md bg-slate-50 p-3 ring-1 ring-slate-200">
                            <p class="font-semibold text-slate-700">Cookie-Datei</p>
                            <p class="mt-1 break-all">{{ $profile['cookie_file_path'] }}</p>
                        </div>
                    </div>

                    <div class="flex flex-wrap content-start items-center gap-2 lg:col-span-3">
                        @if($profile['is_active'])
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700 ring-1 ring-emerald-200">
                                <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                                Analyse aktiv
                            </span>
                        @else
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600 ring-1 ring-slate-200">
                                <span class="h-1.5 w-1.5 rounded-full bg-slate-400"></span>
                                Inaktiv
                            </span>
                        @endif

                        <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700 ring-1 ring-slate-200">Bot: {{ $botLabel }}</span>
                        <span class="rounded-full {{ $baseClass }} px-2.5 py-1 text-xs font-semibold">Base: {{ $baseLabel }}</span>
                        <span class="rounded-full {{ $profile['has_stored_password'] ? 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200' : 'bg-amber-50 text-amber-700 ring-1 ring-amber-200' }} px-2.5 py-1 text-xs font-semibold">
                            {{ $profile['has_stored_password'] ? 'Passwort gespeichert' : 'Kein Passwort' }}
                        </span>

                        @if($profile['is_scrape_blocked'])
                            <span class="rounded-full bg-amber-100 px-2.5 py-1 text-xs font-semibold text-amber-800 ring-1 ring-amber-300">
                                Gesperrt bis {{ $profile['scrape_blocked_until_label'] ?? 'unbekannt' }}
                            </span>
                        @endif

                        @if(($profile['base_sync_status'] ?? 'pending') === 'synced' && !empty($profile['base_synced_at_label']))
                            <p class="basis-full text-xs text-slate-500">Zuletzt synchronisiert: {{ $profile['base_synced_at_label'] }}</p>
                        @endif
                        @if(($profile['base_sync_status'] ?? 'pending') === 'failed' && !empty($profile['base_sync_error']))
                            <p class="basis-full break-words text-xs text-red-700">{{ $profile['base_sync_error'] }}</p>
                        @endif
                    </div>

                    <div class="flex flex-wrap items-start justify-start gap-2 lg:col-span-2 lg:justify-end">
                        <a href="{{ route('persons.show', ['profileId' => $profile['id']]) }}" class="rounded-md bg-slate-900 px-3 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-slate-800">
                            Details
                        </a>
                        <button type="button" wire:click="selectAndEditProfile('{{ $profile['id'] }}')" class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 shadow-sm hover:bg-slate-50">
                            Bearbeiten
                        </button>
                        @if(! $profile['is_primary'])
                            <button type="button" wire:click="makePrimaryProfile('{{ $profile['id'] }}')" class="rounded-md border border-blue-200 bg-white px-3 py-1.5 text-xs font-semibold text-blue-700 shadow-sm hover:bg-blue-50">
                                Standard
                            </button>
                        @endif
                        @if($profile['is_scrape_blocked'])
                            <button type="button" wire:click="clearProfileScrapeBlock('{{ $profile['id'] }}')" class="rounded-md border border-amber-300 bg-white px-3 py-1.5 text-xs font-semibold text-amber-800 shadow-sm hover:bg-amber-50">
                                Entsperren
                            </button>
                        @endif
                        @if(! $profile['is_active'])
                            <button type="button" wire:click="toggleProfileActive('{{ $profile['id'] }}')" class="rounded-md border border-emerald-200 bg-white px-3 py-1.5 text-xs font-semibold text-emerald-700 shadow-sm hover:bg-emerald-50">
                                Aktivieren
                            </button>
                        @else
                            <button type="button" wire:click="toggleProfileActive('{{ $profile['id'] }}')" class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 shadow-sm hover:bg-slate-50">
                                Deaktivieren
                            </button>
                        @endif
                        <button
                            type="button"
                            wire:click="deleteProfile('{{ $profile['id'] }}')"
                            onclick="return confirm('Diese Person wirklich loeschen?')"
                            class="rounded-md border border-red-200 bg-white px-3 py-1.5 text-xs font-semibold text-red-700 shadow-sm hover:bg-red-50"
                        >
                            Loeschen
                        </button>
                    </div>
                </article>
            @empty
                <div class="px-5 py-10 text-center">
                    <p class="font-semibold text-slate-900">Noch keine Personen vorhanden</p>
                    <p class="mt-1 text-sm text-slate-500">Lege eine Person an, um Instagram-Session und Persona-Daten zu verwalten.</p>
                    <button type="button" wire:click="openCreateProfileModal" class="mt-4 rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-slate-800">
                        Erste Person hinzufuegen
                    </button>
                </div>
            @endforelse
        </div>
    </div>

    <x-dialog-modal wire:model="showCreateProfileModal">
        <x-slot name="title">
            Neue Person anlegen
        </x-slot>

        <x-slot name="content">
            <div class="space-y-4">
                <div>
                    <label for="new-profile-label" class="block text-sm font-medium text-gray-700">Account-Name</label>
                    <input id="new-profile-label" type="text" wire:model.defer="newProfileLabel" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    @error('newProfileLabel')
                        <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="new-login-username" class="block text-sm font-medium text-gray-700">Instagram-Benutzername</label>
                    <input id="new-login-username" type="text" wire:model.defer="newLoginUsername" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    @error('newLoginUsername')
                        <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="new-login-password" class="block text-sm font-medium text-gray-700">Instagram-Passwort</label>
                    <input id="new-login-password" type="password" wire:model.defer="newLoginPassword" autocomplete="new-password" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    <p class="mt-1 text-xs text-gray-500">Profil- und Cookie-Pfade werden automatisch aus dem Account erzeugt und koennen danach im Formular angepasst werden.</p>
                    @error('newLoginPassword')
                        <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <label for="new-auto-login-enabled" class="flex items-center gap-3 rounded-md border border-gray-200 bg-gray-50 p-3 text-sm font-medium text-gray-700">
                    <input id="new-auto-login-enabled" type="checkbox" wire:model.defer="newAutoLoginEnabled" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                    Automatischen Instagram-Login fuer diesen Account erlauben
                </label>
            </div>
        </x-slot>

        <x-slot name="footer">
            <div class="flex justify-end gap-3">
                <button type="button" wire:click="closeCreateProfileModal" class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50">
                    Abbrechen
                </button>
                <button type="button" wire:click="createProfile" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-blue-700">
                    Person erstellen
                </button>
            </div>
        </x-slot>
    </x-dialog-modal>

    @if($baseSyncResult)
        <div class="rounded-lg border p-4 text-sm {{ ($baseSyncResult['ok'] ?? false) ? 'border-emerald-200 bg-emerald-50 text-emerald-900' : 'border-red-200 bg-red-50 text-red-900' }}">
            <p class="font-semibold">{{ $baseSyncResult['message'] ?? 'Base-Sync abgeschlossen.' }}</p>
        </div>
    @endif

    <x-dialog-modal wire:model="showProfileModal" maxWidth="2xl">
        <x-slot name="title">
            Person bearbeiten
        </x-slot>

        <x-slot name="content">
            <div class="space-y-6">
                <div class="rounded-lg border border-slate-200 bg-slate-50 p-4">
                    <h3 class="text-base font-semibold text-gray-900">Personendaten</h3>
                    <p class="mt-1 text-sm text-gray-500">
                        Diese Daten behandeln die Person als eigene Persona und koennen spaeter fuer Bot-Automation genutzt werden.
                    </p>
                    <div class="mt-4 grid gap-4 md:grid-cols-2">
                        <div>
                            <label for="edit-person-first-name" class="block text-sm font-medium text-gray-700">Vorname</label>
                            <input id="edit-person-first-name" type="text" wire:model.defer="personFirstName" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            @error('personFirstName') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="edit-person-last-name" class="block text-sm font-medium text-gray-700">Nachname</label>
                            <input id="edit-person-last-name" type="text" wire:model.defer="personLastName" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            @error('personLastName') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="edit-person-alias" class="block text-sm font-medium text-gray-700">Alias / Persona-Name</label>
                            <input id="edit-person-alias" type="text" wire:model.defer="personAlias" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            @error('personAlias') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="edit-person-date-of-birth" class="block text-sm font-medium text-gray-700">Geburtsdatum</label>
                            <input id="edit-person-date-of-birth" type="date" wire:model.defer="personDateOfBirth" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            @error('personDateOfBirth') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="edit-person-gender" class="block text-sm font-medium text-gray-700">Geschlecht / Rolle</label>
                            <input id="edit-person-gender" type="text" wire:model.defer="personGender" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            @error('personGender') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="edit-bot-status" class="block text-sm font-medium text-gray-700">Bot-Status</label>
                            <select id="edit-bot-status" wire:model.defer="botStatus" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <option value="manual">Manuell</option>
                                <option value="ready">Bereit fuer Automation</option>
                                <option value="training">Training</option>
                                <option value="disabled">Deaktiviert</option>
                            </select>
                            @error('botStatus') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="edit-person-city" class="block text-sm font-medium text-gray-700">Stadt</label>
                            <input id="edit-person-city" type="text" wire:model.defer="personCity" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            @error('personCity') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="edit-person-country" class="block text-sm font-medium text-gray-700">Land</label>
                            <input id="edit-person-country" type="text" wire:model.defer="personCountry" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            @error('personCountry') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="edit-person-email" class="block text-sm font-medium text-gray-700">Persona-E-Mail</label>
                            <input id="edit-person-email" type="email" wire:model.defer="personEmail" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            @error('personEmail') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="edit-person-phone" class="block text-sm font-medium text-gray-700">Telefon</label>
                            <input id="edit-person-phone" type="text" wire:model.defer="personPhone" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            @error('personPhone') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="edit-person-address-line1" class="block text-sm font-medium text-gray-700">Strasse und Hausnummer</label>
                            <input id="edit-person-address-line1" type="text" wire:model.defer="personAddressLine1" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            @error('personAddressLine1') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="edit-person-address-line2" class="block text-sm font-medium text-gray-700">Adresszusatz</label>
                            <input id="edit-person-address-line2" type="text" wire:model.defer="personAddressLine2" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            @error('personAddressLine2') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="edit-person-postal-code" class="block text-sm font-medium text-gray-700">Postleitzahl</label>
                            <input id="edit-person-postal-code" type="text" wire:model.defer="personPostalCode" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            @error('personPostalCode') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="edit-person-state" class="block text-sm font-medium text-gray-700">Bundesland / Region</label>
                            <input id="edit-person-state" type="text" wire:model.defer="personState" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            @error('personState') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div class="md:col-span-2">
                            <label for="edit-person-timezone" class="block text-sm font-medium text-gray-700">Zeitzone</label>
                            <input id="edit-person-timezone" type="text" wire:model.defer="personTimezone" placeholder="Europe/Berlin" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            @error('personTimezone') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div class="md:col-span-2">
                            <label for="edit-person-notes" class="block text-sm font-medium text-gray-700">Notizen / Bot-Kontext</label>
                            <textarea id="edit-person-notes" rows="3" wire:model.defer="personNotes" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500"></textarea>
                            @error('personNotes') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                    </div>
                </div>

                <div class="grid gap-6 lg:grid-cols-2">
                <div class="space-y-4">
                    <h3 class="text-base font-semibold text-gray-900">Profil und Session</h3>

                    <div>
                        <label for="edit-profile-label" class="block text-sm font-medium text-gray-700">Profilname</label>
                        <input id="edit-profile-label" type="text" wire:model.defer="profileLabel" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        @error('profileLabel')
                            <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <label for="edit-persistent-profile-enabled" class="flex items-center gap-3 rounded-md border border-gray-200 bg-gray-50 p-3 text-sm font-medium text-gray-700">
                        <input id="edit-persistent-profile-enabled" type="checkbox" wire:model.defer="persistentProfileEnabled" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                        Persistentes Browser-Profil verwenden
                    </label>

                    <div>
                        <label for="edit-browser-profile-path" class="block text-sm font-medium text-gray-700">Profilpfad</label>
                        <input id="edit-browser-profile-path" type="text" wire:model.defer="browserProfilePath" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        <p class="mt-1 text-xs text-gray-500">Relativer Pfad innerhalb von `storage/app` oder ein absoluter Pfad.</p>
                        @error('browserProfilePath')
                            <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="edit-cookie-file-path" class="block text-sm font-medium text-gray-700">Cookie-Datei</label>
                        <input id="edit-cookie-file-path" type="text" wire:model.defer="cookieFilePath" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        <p class="mt-1 text-xs text-gray-500">Wird nach erfolgreichem Login automatisch aktualisiert.</p>
                        @error('cookieFilePath')
                            <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                <div class="space-y-4">
                    <h3 class="text-base font-semibold text-gray-900">Auto-Login</h3>

                    <label for="edit-auto-login-enabled" class="flex items-center gap-3 rounded-md border border-gray-200 bg-gray-50 p-3 text-sm font-medium text-gray-700">
                        <input id="edit-auto-login-enabled" type="checkbox" wire:model.defer="autoLoginEnabled" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                        Automatischen Instagram-Login erlauben
                    </label>

                    <div>
                        <label for="edit-login-username" class="block text-sm font-medium text-gray-700">Instagram-Benutzername</label>
                        <input id="edit-login-username" type="text" wire:model.defer="loginUsername" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        @error('loginUsername')
                            <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="edit-login-password" class="block text-sm font-medium text-gray-700">Instagram-Passwort</label>
                        <input id="edit-login-password" type="password" wire:model.defer="loginPassword" autocomplete="new-password" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        <div class="mt-2 flex items-center justify-between gap-3 text-xs text-gray-500">
                            <span>
                                @if($hasStoredPassword)
                                    Es ist bereits ein Passwort gespeichert. Leeres Feld bedeutet: vorhandenes Passwort beibehalten.
                                @else
                                    Aktuell ist noch kein Passwort gespeichert.
                                @endif
                            </span>
                            @if($hasStoredPassword)
                                <button type="button" wire:click="clearStoredPassword" class="font-semibold text-red-600 hover:text-red-700">
                                    Gespeichertes Passwort loeschen
                                </button>
                            @endif
                        </div>
                        @error('loginPassword')
                            <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>
                </div>
            </div>
            </div>
        </x-slot>

        <x-slot name="footer">
            <div class="flex justify-end gap-3">
                <button type="button" wire:click="closeProfileModal" class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50">
                    Abbrechen
                </button>
                <button type="button" wire:click="saveProfile" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-blue-700">
                    Account speichern
                </button>
            </div>
        </x-slot>
    </x-dialog-modal>

    <x-dialog-modal wire:model="showRuntimeSettingsModal" maxWidth="2xl">
        <x-slot name="title">
            Timeouts und Listen
        </x-slot>

        <x-slot name="content">
            <div class="space-y-6">
                <div class="grid gap-4 md:grid-cols-3">
                    <div>
                        <label for="runtime-navigation-timeout" class="block text-sm font-medium text-gray-700">Navigation-Timeout in Sekunden</label>
                        <input id="runtime-navigation-timeout" type="number" min="30" max="300" wire:model.defer="navigationTimeoutSeconds" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        @error('navigationTimeoutSeconds')
                            <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="runtime-post-login-wait" class="block text-sm font-medium text-gray-700">Wartezeit nach Login in Millisekunden</label>
                        <input id="runtime-post-login-wait" type="number" min="500" max="15000" wire:model.defer="postLoginWaitMs" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        @error('postLoginWaitMs')
                            <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="runtime-typing-delay" class="block text-sm font-medium text-gray-700">Tippverzoegerung in Millisekunden</label>
                        <input id="runtime-typing-delay" type="number" min="0" max="500" wire:model.defer="typingDelayMs" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        @error('typingDelayMs')
                            <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                <div>
                    <h3 class="text-base font-semibold text-gray-900">Follower- und Gefolgt-Listen</h3>
                    <p class="mt-1 text-sm text-gray-500">
                        Ein Limit von 0 bedeutet: alle von Instagram ladbaren Eintraege speichern. Die Scroll-Runden sind nur eine technische Sicherung gegen Endlosschleifen.
                    </p>

                    <div class="mt-4 grid gap-4 md:grid-cols-2">
                        <div>
                            <label for="runtime-relationship-list-process-timeout" class="block text-sm font-medium text-gray-700">Listen-Timeout in Sekunden</label>
                            <input id="runtime-relationship-list-process-timeout" type="number" min="14400" max="21600" wire:model.defer="relationshipListProcessTimeoutSeconds" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            @error('relationshipListProcessTimeoutSeconds')
                                <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="runtime-relationship-list-max-scroll-rounds" class="block text-sm font-medium text-gray-700">Maximale Scroll-Runden</label>
                            <input id="runtime-relationship-list-max-scroll-rounds" type="number" min="20" max="1000000" wire:model.defer="relationshipListMaxScrollRounds" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            @error('relationshipListMaxScrollRounds')
                                <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="runtime-follower-list-max-items" class="block text-sm font-medium text-gray-700">Follower-Limit</label>
                            <input id="runtime-follower-list-max-items" type="number" min="0" max="1000000" wire:model.defer="followerListMaxItems" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            @error('followerListMaxItems')
                                <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="runtime-following-list-max-items" class="block text-sm font-medium text-gray-700">Gefolgt-Limit</label>
                            <input id="runtime-following-list-max-items" type="number" min="0" max="1000000" wire:model.defer="followingListMaxItems" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            @error('followingListMaxItems')
                                <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                </div>
            </div>
        </x-slot>

        <x-slot name="footer">
            <div class="flex justify-end gap-3">
                <button type="button" wire:click="closeRuntimeSettingsModal" class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50">
                    Abbrechen
                </button>
                <button type="button" wire:click="saveRuntimeSettings" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-blue-700">
                    Einstellungen speichern
                </button>
            </div>
        </x-slot>
    </x-dialog-modal>

</div>
