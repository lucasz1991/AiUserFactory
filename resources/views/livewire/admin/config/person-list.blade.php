<div class="space-y-6" wire:loading.class="opacity-50 pointer-events-none cursor-wait">
    @php
        $allProfiles = collect($profileOptions);
        $profiles = collect($visibleProfileOptions);
        $totalPersonsCount = $allProfiles->count();
        $visiblePersonsCount = $profiles->count();
        $activePersonsCount = $allProfiles->where('is_active', true)->count();
        $blockedPersonsCount = $allProfiles->where('is_scrape_blocked', true)->count();
        $baseSyncedPersonsCount = $allProfiles->where('base_sync_status', 'synced')->count();
        $instagramReadyPersonsCount = $allProfiles->filter(fn ($profile) => data_get($profile, 'instagram_status.level') === 'success')->count();
        $mailReadyPersonsCount = $allProfiles->filter(fn ($profile) => data_get($profile, 'mail_status.level') === 'success')->count();
        $runningProcessPersonsCount = $allProfiles->filter(fn ($profile) => (int) data_get($profile, 'process_status.count', 0) > 0)->count();
        $selectedCount = count($selectedProfileIds);
        $visibleIds = $profiles->pluck('id')->all();
        $allVisibleSelected = $visibleIds !== [] && array_diff($visibleIds, $selectedProfileIds) === [];
        $statusIconClass = fn ($level) => match($level) {
            'success' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
            'running' => 'bg-blue-50 text-blue-700 ring-blue-200',
            'warning' => 'bg-amber-50 text-amber-700 ring-amber-200',
            'danger' => 'bg-red-50 text-red-700 ring-red-200',
            'partial' => 'bg-sky-50 text-sky-700 ring-sky-200',
            default => 'bg-slate-100 text-slate-500 ring-slate-200',
        };
        $sortIcon = fn ($field) => $sortField === $field
            ? ($sortDirection === 'asc' ? 'fa-chevron-up' : 'fa-chevron-down')
            : 'fa-chevron-right';
    @endphp

    <div class="rounded-lg border border-slate-200 bg-white px-4 py-3 shadow-sm">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="min-w-0">
                <h2 class="text-base font-semibold text-slate-900">Personen</h2>
                <p class="text-xs text-slate-500">{{ $visiblePersonsCount }} sichtbar - {{ $selectedCount }} ausgewaehlt</p>
            </div>
            <div class="grid flex-1 grid-cols-2 gap-2 sm:grid-cols-4 lg:max-w-5xl lg:grid-cols-7">
                <div class="rounded-md bg-slate-50 px-2.5 py-1.5 ring-1 ring-slate-200"><p class="text-[10px] font-semibold uppercase text-slate-400">Gesamt</p><p class="text-sm font-semibold text-slate-900">{{ $totalPersonsCount }}</p></div>
                <div class="rounded-md bg-slate-50 px-2.5 py-1.5 ring-1 ring-slate-200"><p class="text-[10px] font-semibold uppercase text-slate-400">Aktiv</p><p class="text-sm font-semibold text-emerald-700">{{ $activePersonsCount }}</p></div>
                <div class="rounded-md bg-slate-50 px-2.5 py-1.5 ring-1 ring-slate-200"><p class="text-[10px] font-semibold uppercase text-slate-400">Sperren</p><p class="text-sm font-semibold text-amber-700">{{ $blockedPersonsCount }}</p></div>
                <div class="rounded-md bg-slate-50 px-2.5 py-1.5 ring-1 ring-slate-200"><p class="text-[10px] font-semibold uppercase text-slate-400">IG</p><p class="text-sm font-semibold text-pink-700">{{ $instagramReadyPersonsCount }}</p></div>
                <div class="rounded-md bg-slate-50 px-2.5 py-1.5 ring-1 ring-slate-200"><p class="text-[10px] font-semibold uppercase text-slate-400">Mail</p><p class="text-sm font-semibold text-sky-700">{{ $mailReadyPersonsCount }}</p></div>
                <div class="rounded-md bg-slate-50 px-2.5 py-1.5 ring-1 ring-slate-200"><p class="text-[10px] font-semibold uppercase text-slate-400">Runs</p><p class="text-sm font-semibold text-blue-700">{{ $runningProcessPersonsCount }}</p></div>
                <div class="rounded-md bg-slate-50 px-2.5 py-1.5 ring-1 ring-slate-200"><p class="text-[10px] font-semibold uppercase text-slate-400">Base</p><p class="text-sm font-semibold text-slate-900">{{ $baseSyncedPersonsCount }}/{{ $totalPersonsCount }}</p></div>
            </div>
            <div class="flex shrink-0 items-center gap-2">
                <button type="button" title="AI-Vorschlaege" wire:click="openAiSuggestionPicker" class="inline-flex h-9 w-9 items-center justify-center rounded-md border border-purple-200 bg-white text-purple-700 shadow-sm hover:bg-purple-50">
                    <span class="sr-only">AI-Vorschlaege</span>
                    <i class="fas fa-magic text-sm" aria-hidden="true"></i>
                </button>
                <button type="button" title="Timeouts" wire:click="openRuntimeSettingsModal" class="inline-flex h-9 w-9 items-center justify-center rounded-md border border-slate-300 bg-white text-slate-700 shadow-sm hover:bg-slate-50">
                    <span class="sr-only">Timeouts</span>
                    <i class="far fa-clock text-sm" aria-hidden="true"></i>
                </button>
                <button type="button" title="Person hinzufuegen" wire:click="openCreateProfileModal" class="inline-flex h-9 w-9 items-center justify-center rounded-md bg-slate-900 text-white shadow-sm hover:bg-slate-800">
                    <span class="sr-only">Person hinzufuegen</span>
                    <i class="fas fa-plus text-sm" aria-hidden="true"></i>
                </button>
            </div>
        </div>
    </div>

    @if (session()->has('success'))
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">
            {{ session('success') }}
        </div>
    @endif

    <div class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-200 px-4 py-3">
            <div class="grid gap-3 xl:grid-cols-[minmax(260px,1fr)_auto]">
                <div class="grid gap-2 md:grid-cols-[minmax(220px,1fr)_auto]">
                    <input type="search" wire:model.live.debounce.300ms="personSearch" placeholder="Suchen..." class="h-9 rounded-md border-slate-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    <div class="relative" x-data="{ open: false }" x-on:click.outside="open = false">
                        <button type="button" x-on:click="open = !open" class="inline-flex h-9 items-center gap-2 rounded-md border border-slate-300 bg-white px-3 text-xs font-semibold text-slate-700 shadow-sm hover:bg-slate-50">
                            <i class="fas fa-filter text-xs" aria-hidden="true"></i>
                            Filter
                            <i class="fas fa-chevron-down text-[10px]" aria-hidden="true"></i>
                        </button>
                        <div x-cloak x-show="open" x-transition class="absolute right-0 z-30 mt-2 w-72 rounded-md border border-slate-200 bg-white p-3 shadow-lg">
                            <div class="space-y-3">
                                <div>
                                    <label class="block text-xs font-semibold uppercase tracking-wide text-slate-500">Status</label>
                                    <select wire:model.live="statusFilter" class="mt-1 h-9 w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                        <option value="all">Alle Status</option>
                                        <option value="active">Aktiv</option>
                                        <option value="inactive">Inaktiv</option>
                                        <option value="blocked">Gesperrt</option>
                                        <option value="primary">Standard</option>
                                        <option value="process">Prozess laeuft</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold uppercase tracking-wide text-slate-500">Accounts</label>
                                    <select wire:model.live="accountFilter" class="mt-1 h-9 w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                        <option value="all">Alle Accounts</option>
                                        <option value="instagram_ready">Instagram bereit</option>
                                        <option value="instagram_missing">Instagram fehlt</option>
                                        <option value="mail_ready">Mail bereit</option>
                                        <option value="mail_missing">Mail fehlt</option>
                                        <option value="base_synced">Base synchron</option>
                                        <option value="base_pending">Base offen</option>
                                        <option value="base_failed">Base Fehler</option>
                                    </select>
                                </div>
                                <button type="button" wire:click="resetListFilters" class="w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-xs font-semibold text-slate-600 hover:bg-slate-100">
                                    Filter zuruecksetzen
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="flex flex-wrap items-center justify-end gap-2">
                    <button type="button" wire:click="{{ $allVisibleSelected ? 'clearProfileSelection' : 'selectAllVisibleProfiles' }}" class="h-9 rounded-md border border-slate-300 bg-white px-3 text-xs font-semibold text-slate-700 shadow-sm hover:bg-slate-50">
                        {{ $allVisibleSelected ? 'Auswahl leeren' : 'Alle sichtbar' }}
                    </button>
                    <div class="relative" x-data="{ open: false }" x-on:click.outside="open = false">
                        <button type="button" x-on:click="open = !open" @disabled($selectedCount === 0) class="inline-flex h-9 items-center gap-2 rounded-md bg-slate-900 px-3 text-xs font-semibold text-white shadow-sm hover:bg-slate-800 disabled:opacity-40">
                            <i class="fas fa-layer-group text-xs" aria-hidden="true"></i>
                            Aktionen
                            <span class="rounded bg-white/15 px-1.5 py-0.5">{{ $selectedCount }}</span>
                            <i class="fas fa-chevron-down text-[10px]" aria-hidden="true"></i>
                        </button>
                        <div x-cloak x-show="open" x-transition class="absolute right-0 z-30 mt-2 w-56 overflow-hidden rounded-md border border-slate-200 bg-white py-1 text-sm shadow-lg">
                            <button type="button" wire:click="bulkActivateSelected" class="block w-full px-3 py-2 text-left text-emerald-700 hover:bg-emerald-50"><i class="far fa-check-circle mr-2" aria-hidden="true"></i>Aktivieren</button>
                            <button type="button" wire:click="bulkDeactivateSelected" class="block w-full px-3 py-2 text-left text-slate-700 hover:bg-slate-50"><i class="far fa-pause-circle mr-2" aria-hidden="true"></i>Deaktivieren</button>
                            <button type="button" wire:click="bulkSyncSelectedToBase" class="block w-full px-3 py-2 text-left text-blue-700 hover:bg-blue-50"><i class="fas fa-database mr-2" aria-hidden="true"></i>An Base senden</button>
                            <button type="button" wire:click="bulkDeleteSelected" wire:confirm="Ausgewaehlte Personen wirklich loeschen?" class="block w-full px-3 py-2 text-left text-red-700 hover:bg-red-50"><i class="far fa-trash-alt mr-2" aria-hidden="true"></i>Loeschen</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="hidden grid-cols-12 gap-4 border-b border-slate-200 bg-slate-50 px-4 py-2 text-xs font-semibold uppercase tracking-wide text-slate-500 lg:grid">
            <div class="col-span-1">Auswahl</div>
            <button type="button" wire:click="sortBy('name')" class="col-span-4 inline-flex items-center gap-1 text-left font-semibold uppercase tracking-wide hover:text-slate-800">
                Person <i class="fas {{ $sortIcon('name') }} text-[10px]" aria-hidden="true"></i>
            </button>
            <button type="button" wire:click="sortBy('instagram')" class="col-span-3 inline-flex items-center gap-1 text-left font-semibold uppercase tracking-wide hover:text-slate-800">
                Accounts <i class="fas {{ $sortIcon('instagram') }} text-[10px]" aria-hidden="true"></i>
            </button>
            <button type="button" wire:click="sortBy('active')" class="col-span-3 inline-flex items-center gap-1 text-left font-semibold uppercase tracking-wide hover:text-slate-800">
                Status <i class="fas {{ $sortIcon('active') }} text-[10px]" aria-hidden="true"></i>
            </button>
            <div class="col-span-1 text-right">Aktionen</div>
        </div>

        <div class="divide-y divide-slate-200">
            @forelse($visibleProfileOptions as $profile)
                @php
                    $selected = in_array($profile['id'], $selectedProfileIds, true);
                    $rowClass = $profile['is_scrape_blocked']
                        ? 'border-l-amber-500 bg-amber-50/70 hover:bg-amber-50'
                        : ($profile['is_primary']
                            ? 'border-l-blue-500 bg-blue-50/60 hover:bg-blue-50'
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
                    $instagramStatus = $profile['instagram_status'] ?? [];
                    $mailStatus = $profile['mail_status'] ?? [];
                    $processStatus = $profile['process_status'] ?? [];
                    $baseStatus = $profile['base_status'] ?? [];
                @endphp

                <article
                    wire:key="scraper-profile-{{ $profile['id'] }}"
                    x-data="{ clickTimer: null, profileId: @js($profile['id']) }"
                    x-on:click="
                        if ($event.target.closest('[data-row-control]')) return;
                        clearTimeout(clickTimer);
                        clickTimer = setTimeout(() => $wire.toggleProfileSelection(profileId), 180);
                    "
                    x-on:dblclick.prevent="
                        if ($event.target.closest('[data-row-control]')) return;
                        clearTimeout(clickTimer);
                        $wire.openProfileDetail(profileId);
                    "
                    class="grid cursor-pointer gap-4 border-l-4 px-4 py-3 text-sm transition lg:grid-cols-12 {{ $rowClass }} {{ $selected ? 'ring-2 ring-blue-300 ring-inset' : '' }}"
                >
                    <div class="flex items-start lg:col-span-1" data-row-control>
                        <input
                            type="checkbox"
                            class="mt-2 rounded border-slate-300 text-blue-600 shadow-sm focus:ring-blue-500"
                            @checked($selected)
                            x-on:click.stop="$wire.toggleProfileSelection(profileId)"
                            aria-label="Person {{ $profile['display_name'] }} auswaehlen"
                        >
                    </div>

                    <div class="flex min-w-0 items-start gap-3 lg:col-span-4">
                        @if(!empty($profile['avatar_url']))
                            <img
                                src="{{ $profile['avatar_url'] }}"
                                alt="Profilbild von {{ $profile['display_name'] }}"
                                class="h-10 w-10 shrink-0 rounded-md object-cover ring-1 ring-slate-200"
                            >
                        @else
                            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-md {{ $avatarClass }} text-sm font-semibold">
                                {{ strtoupper(substr($profile['label'], 0, 1)) }}
                            </div>
                        @endif
                        <div class="min-w-0">
                            <div class="flex min-w-0 items-center gap-2">
                                <p class="truncate font-semibold text-slate-900">{{ $profile['display_name'] }}</p>
                                @if($profile['is_primary'])
                                    <span title="Standard-Person" class="inline-flex h-4 w-4 shrink-0 items-center justify-center rounded-full bg-blue-600 text-[10px] font-semibold text-white">S</span>
                                @endif
                            </div>
                            <p class="mt-0.5 truncate text-xs text-slate-500">{{ $profile['label'] }}</p>
                            <p class="mt-1 truncate text-xs text-slate-500">
                                {{ trim(($profile['person_city'] ?? '').' '.($profile['person_country'] ?? '')) ?: 'Keine Ortsdaten' }}
                            </p>
                        </div>
                    </div>

                    <div class="flex min-w-0 flex-wrap items-center gap-2 lg:col-span-3">
                        <span title="{{ data_get($instagramStatus, 'label') }} - {{ data_get($instagramStatus, 'detail') }}{{ data_get($instagramStatus, 'synced_at_label') ? ' - Cookies: '.data_get($instagramStatus, 'synced_at_label') : '' }}" class="inline-flex h-9 w-9 items-center justify-center rounded-md ring-1 {{ $statusIconClass(data_get($instagramStatus, 'level')) }}">
                            <span class="sr-only">{{ data_get($instagramStatus, 'label') }}</span>
                            <i class="fab fa-instagram text-sm" aria-hidden="true"></i>
                        </span>

                        <span title="{{ data_get($mailStatus, 'label') }} - {{ data_get($mailStatus, 'detail') }}{{ data_get($mailStatus, 'has_webmail_session') ? ' - Webmail-Session vorhanden' : '' }}" class="inline-flex h-9 w-9 items-center justify-center rounded-md ring-1 {{ $statusIconClass(data_get($mailStatus, 'level')) }}">
                            <span class="sr-only">{{ data_get($mailStatus, 'label') }}</span>
                            <i class="far fa-envelope text-sm" aria-hidden="true"></i>
                        </span>

                        <span title="{{ data_get($processStatus, 'label') }} - {{ data_get($processStatus, 'detail') }}" class="inline-flex h-9 w-9 items-center justify-center rounded-md ring-1 {{ $statusIconClass(data_get($processStatus, 'level')) }}">
                            <span class="sr-only">{{ data_get($processStatus, 'label') }}</span>
                            <i class="fas fa-play text-xs" aria-hidden="true"></i>
                        </span>

                        <span title="{{ data_get($baseStatus, 'label') }} - {{ data_get($baseStatus, 'detail') }}" class="inline-flex h-9 w-9 items-center justify-center rounded-md ring-1 {{ $statusIconClass(data_get($baseStatus, 'level')) }}">
                            <span class="sr-only">{{ data_get($baseStatus, 'label') }}</span>
                            <i class="fas fa-database text-xs" aria-hidden="true"></i>
                        </span>

                        <span title="{{ $profile['is_active'] ? 'Analyse aktiv' : 'Analyse inaktiv' }}" class="inline-flex h-9 w-9 items-center justify-center rounded-md ring-1 {{ $profile['is_active'] ? 'bg-emerald-50 text-emerald-700 ring-emerald-200' : 'bg-slate-100 text-slate-500 ring-slate-200' }}">
                            <span class="sr-only">{{ $profile['is_active'] ? 'Aktiv' : 'Inaktiv' }}</span>
                            <i class="far fa-check-circle text-sm" aria-hidden="true"></i>
                        </span>

                        @if($profile['is_scrape_blocked'])
                            <span title="Gesperrt bis {{ $profile['scrape_blocked_until_label'] ?? 'unbekannt' }}" class="inline-flex h-9 w-9 items-center justify-center rounded-md bg-amber-100 text-amber-800 ring-1 ring-amber-300">
                                <span class="sr-only">Gesperrt</span>
                                <i class="fas fa-lock text-xs" aria-hidden="true"></i>
                            </span>
                        @endif
                    </div>

                    <div class="flex min-w-0 flex-wrap items-center gap-2 text-xs text-slate-500 lg:col-span-3">
                        <span title="Bot: {{ $botLabel }}" class="rounded-full bg-slate-100 px-2 py-1 font-semibold text-slate-600 ring-1 ring-slate-200">Bot {{ $botLabel }}</span>
                        <span title="Base: {{ $baseLabel }}" class="rounded-full {{ $baseClass }} px-2 py-1 font-semibold">Base {{ $baseLabel }}</span>
                    </div>

                    <div class="flex items-start justify-end lg:col-span-1" data-row-control>
                        <div class="relative" x-data="{ open: false }" x-on:click.outside="open = false">
                            <button type="button" title="Aktionen" x-on:click.stop="open = !open" class="inline-flex h-9 w-9 items-center justify-center rounded-md border border-slate-200 bg-white text-slate-600 shadow-sm hover:bg-slate-50">
                                <span class="sr-only">Aktionen</span>
                                <i class="fas fa-ellipsis-v text-sm" aria-hidden="true"></i>
                            </button>
                            <div x-cloak x-show="open" x-transition class="absolute right-0 z-20 mt-2 w-48 overflow-hidden rounded-md border border-slate-200 bg-white py-1 text-sm shadow-lg">
                                <a href="{{ route('persons.show', ['profileId' => $profile['id']]) }}" class="block px-3 py-2 text-slate-700 hover:bg-slate-50">Profil oeffnen</a>
                                <button type="button" wire:click="selectAndEditProfile('{{ $profile['id'] }}')" class="block w-full px-3 py-2 text-left text-slate-700 hover:bg-slate-50">Bearbeiten</button>
                                <button type="button" wire:click="openAiSuggestion('{{ $profile['id'] }}')" class="block w-full px-3 py-2 text-left text-purple-700 hover:bg-purple-50">AI-Vorschlag</button>
                                @if(! $profile['is_primary'])
                                    <button type="button" wire:click="makePrimaryProfile('{{ $profile['id'] }}')" class="block w-full px-3 py-2 text-left text-blue-700 hover:bg-blue-50">Als Standard</button>
                                @endif
                                @if($profile['is_scrape_blocked'])
                                    <button type="button" wire:click="clearProfileScrapeBlock('{{ $profile['id'] }}')" class="block w-full px-3 py-2 text-left text-amber-800 hover:bg-amber-50">Entsperren</button>
                                @endif
                                <button type="button" wire:click="toggleProfileActive('{{ $profile['id'] }}')" class="block w-full px-3 py-2 text-left text-slate-700 hover:bg-slate-50">
                                    {{ $profile['is_active'] ? 'Deaktivieren' : 'Aktivieren' }}
                                </button>
                                <button type="button" wire:click="deleteProfile('{{ $profile['id'] }}')" wire:confirm="Diese Person wirklich loeschen?" class="block w-full px-3 py-2 text-left text-red-700 hover:bg-red-50">Loeschen</button>
                            </div>
                        </div>
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

    <x-dialog-modal wire:model="showAiSuggestionPickerModal" maxWidth="md">
        <x-slot name="title">
            AI-Vorschlag
        </x-slot>

        <x-slot name="content">
            <div class="space-y-4 text-sm text-slate-600">
                <p>
                    Oeffnet den AI-Assistenten fuer die aktuelle Auswahl. Bei mehreren ausgewaehlten Personen wird die erste Person geoeffnet.
                </p>
                <div class="rounded-md bg-slate-50 px-3 py-2 text-xs text-slate-500 ring-1 ring-slate-200">
                    {{ $selectedCount }} {{ $selectedCount === 1 ? 'Person ausgewaehlt' : 'Personen ausgewaehlt' }}
                </div>
            </div>
        </x-slot>

        <x-slot name="footer">
            <div class="flex justify-end gap-3">
                <button type="button" wire:click="closeAiSuggestionPicker" class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                    Abbrechen
                </button>
                <button type="button" wire:click="openAiSuggestionForSelected" class="rounded-md bg-purple-600 px-4 py-2 text-sm font-semibold text-white hover:bg-purple-700">
                    <i class="fas fa-magic mr-2" aria-hidden="true"></i>Oeffnen
                </button>
            </div>
        </x-slot>
    </x-dialog-modal>

    @livewire('admin.persons.ai-complete-person-profile-modal')
    @livewire('admin.persons.generate-person-images-modal')
</div>
