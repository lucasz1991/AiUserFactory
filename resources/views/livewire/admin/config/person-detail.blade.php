<div class="space-y-6" wire:loading.class="opacity-60 pointer-events-none">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold text-gray-900">Person-Detail</h1>
            <p class="mt-1 text-sm text-gray-500">Persona, AI-Profil, Aktivitaeten und Medien an einem Ort.</p>
        </div>
        <div class="flex flex-wrap gap-3">
            <a href="{{ route('persons.index') }}" class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50">
                Zurueck
            </a>
            @if($personRecord)
                <button type="button" wire:click="openRuntimeSettingsModal" class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50">
                    Timeouts
                </button>
                <button type="button" wire:click="buildInstagramSession" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-blue-700">
                    Session aufbauen
                </button>
                <button type="button" wire:click="$dispatch('open-person-image-modal', { personId: {{ $personRecord->id }} })" class="rounded-md border border-indigo-200 bg-white px-4 py-2 text-sm font-semibold text-indigo-700 shadow-sm hover:bg-indigo-50">
                    Bilder
                </button>
                <button type="button" wire:click="$dispatch('open-ai-complete-person-profile', { personId: {{ $personRecord->id }} })" class="rounded-md bg-purple-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-purple-700">
                    Bearbeiten
                </button>
            @endif
        </div>
    </div>

    @if (session()->has('success'))
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">
            {{ session('success') }}
        </div>
    @endif

    @if($profileDetail === [] || ! $personRecord)
        <x-admin.panel>
            <div class="text-sm text-gray-500">Keine Person ausgewaehlt.</div>
        </x-admin.panel>
    @else
        @php
            $identity = is_array($personRecord->identity_profile) ? $personRecord->identity_profile : [];
            $bot = is_array($personRecord->bot_profile) ? $personRecord->bot_profile : [];
            $activityMetrics = $activitySimulation['metrics'] ?? [];
            $activityDays = $activitySimulation['days_plan'] ?? [];
            $activityProfile = $activitySimulation['profile'] ?? [];
            $botStatusLabel = match($profileDetail['bot_status'] ?? 'manual') {
                'ready' => 'Bereit',
                'training' => 'Training',
                'disabled' => 'Deaktiviert',
                default => 'Manuell',
            };
            $instagramAccount = collect($profileDetail['social_accounts'] ?? [])->firstWhere('platform', 'instagram') ?? [];
            $instagramUsername = $instagramAccount['username'] ?? ($profileDetail['login_username'] ?? '');
            $instagramStatus = ($instagramAccount['status'] ?? null) ?: (($profileDetail['is_active'] ?? false) ? 'active' : 'inactive');
            $instagramStatusLabel = match($instagramStatus) {
                'active' => 'Aktiv',
                'inactive' => 'Inaktiv',
                'blocked' => 'Gesperrt',
                default => ucfirst((string) $instagramStatus),
            };
            $instagramStatusClass = match($instagramStatus) {
                'active' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
                'blocked' => 'bg-amber-50 text-amber-700 ring-amber-200',
                default => 'bg-slate-100 text-slate-700 ring-slate-200',
            };
        @endphp

        <section class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
            <div class="bg-slate-950 px-6 py-6 text-white">
                <div class="flex flex-wrap items-start gap-5">
                    @if($avatarUrl !== '')
                        <img src="{{ $avatarUrl }}" alt="{{ $profileDetail['display_name'] }}" class="h-28 w-28 rounded-lg object-cover ring-2 ring-white/20">
                    @else
                        <div class="flex h-28 w-28 items-center justify-center rounded-lg bg-white/10 text-4xl font-semibold">
                            {{ strtoupper(substr($profileDetail['label'] ?? 'P', 0, 1)) }}
                        </div>
                    @endif

                    <div class="min-w-0 flex-1">
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-300">Persona</p>
                        <h2 class="mt-1 truncate text-3xl font-semibold">{{ $profileDetail['display_name'] }}</h2>
                        <p class="mt-2 text-sm text-slate-300">
                            {{ $profileDetail['person_alias'] ?: $profileDetail['label'] }}
                            <span class="mx-2 text-slate-500">/</span>
                            {{ $profileDetail['login_username'] !== '' ? '@'.$profileDetail['login_username'] : 'Kein Instagram-Benutzername' }}
                        </p>

                        <div class="mt-4 flex flex-wrap gap-2">
                            @if($profileDetail['is_primary'] ?? false)
                                <span class="rounded-full bg-blue-500 px-2.5 py-1 text-xs font-semibold text-white">Standard</span>
                            @endif
                            <span class="rounded-full {{ ($profileDetail['is_active'] ?? false) ? 'bg-emerald-500/20 text-emerald-100 ring-emerald-400/30' : 'bg-slate-700 text-slate-200 ring-slate-500/30' }} px-2.5 py-1 text-xs font-semibold ring-1">
                                {{ ($profileDetail['is_active'] ?? false) ? 'Aktiv' : 'Inaktiv' }}
                            </span>
                            <span class="rounded-full bg-white/10 px-2.5 py-1 text-xs font-semibold text-slate-100 ring-1 ring-white/10">Bot: {{ $botStatusLabel }}</span>
                            <span class="rounded-full bg-white/10 px-2.5 py-1 text-xs font-semibold text-slate-100 ring-1 ring-white/10">{{ $personRecord->person_city ?: 'Ort offen' }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="grid gap-3 p-5 sm:grid-cols-2 xl:grid-cols-5">
                <x-admin.stat label="Bilder" :value="count($imageFiles) + ($avatarUrl !== '' ? 1 : 0)" tone="slate" />
                <x-admin.stat label="Sessions" :value="$activityMetrics['planned_sessions'] ?? 0" tone="blue" />
                <x-admin.stat label="Aktionen" :value="$activityMetrics['planned_steps'] ?? 0" tone="emerald" />
                <x-admin.stat label="Content" :value="$activityMetrics['planned_posts'] ?? 0" tone="amber" />
                <x-admin.stat label="Max. Risiko" :value="$activityMetrics['max_day_risk_score'] ?? 0" :tone="(($activityMetrics['max_day_risk_score'] ?? 0) >= 70 ? 'red' : 'slate')" />
            </div>
        </section>

        <div x-data="{ tab: 'overview' }" class="space-y-6">
            <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white p-2 shadow-sm">
                <div class="flex min-w-max gap-2">
                    <button type="button" @click="tab = 'overview'" :class="tab === 'overview' ? 'bg-slate-900 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'" class="rounded-md px-4 py-2 text-sm font-semibold">Uebersicht</button>
                    <button type="button" @click="tab = 'ai'" :class="tab === 'ai' ? 'bg-slate-900 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'" class="rounded-md px-4 py-2 text-sm font-semibold">AI-Profil</button>
                    <button type="button" @click="tab = 'activity'" :class="tab === 'activity' ? 'bg-slate-900 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'" class="rounded-md px-4 py-2 text-sm font-semibold">Aktivitaeten</button>
                    <button type="button" @click="tab = 'media'" :class="tab === 'media' ? 'bg-slate-900 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'" class="rounded-md px-4 py-2 text-sm font-semibold">Dateien & Bilder</button>
                    <button type="button" @click="tab = 'social'" :class="tab === 'social' ? 'bg-slate-900 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'" class="rounded-md px-4 py-2 text-sm font-semibold">Social Media</button>
                    <button type="button" @click="tab = 'raw'" :class="tab === 'raw' ? 'bg-slate-900 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'" class="rounded-md px-4 py-2 text-sm font-semibold">Rohdaten</button>
                </div>
            </div>

            <div x-show="tab === 'overview'" class="space-y-6">
                <div class="grid gap-6 xl:grid-cols-2">
                    <x-admin.panel title="Stammdaten">
                        <dl class="grid gap-4 text-sm sm:grid-cols-2">
                            <div><dt class="font-medium text-gray-500">Vorname</dt><dd class="mt-1 text-gray-900">{{ $personRecord->person_first_name ?: 'Nicht hinterlegt' }}</dd></div>
                            <div><dt class="font-medium text-gray-500">Nachname</dt><dd class="mt-1 text-gray-900">{{ $personRecord->person_last_name ?: 'Nicht hinterlegt' }}</dd></div>
                            <div><dt class="font-medium text-gray-500">Alias</dt><dd class="mt-1 text-gray-900">{{ $personRecord->person_alias ?: 'Nicht hinterlegt' }}</dd></div>
                            <div><dt class="font-medium text-gray-500">Geburtsdatum</dt><dd class="mt-1 text-gray-900">{{ $personRecord->person_date_of_birth?->format('d.m.Y') ?: 'Nicht hinterlegt' }}</dd></div>
                            <div><dt class="font-medium text-gray-500">Geschlecht / Rolle</dt><dd class="mt-1 text-gray-900">{{ $personRecord->person_gender ?: 'Nicht hinterlegt' }}</dd></div>
                            <div><dt class="font-medium text-gray-500">Nationalitaet</dt><dd class="mt-1 text-gray-900">{{ data_get($identity, 'nationality') ?: 'Nicht hinterlegt' }}</dd></div>
                        </dl>
                    </x-admin.panel>

                    <x-admin.panel title="Kontakt und Adresse">
                        <dl class="grid gap-4 text-sm sm:grid-cols-2">
                            <div><dt class="font-medium text-gray-500">E-Mail</dt><dd class="mt-1 break-all text-gray-900">{{ $personRecord->person_email ?: 'Nicht hinterlegt' }}</dd></div>
                            <div><dt class="font-medium text-gray-500">Telefon</dt><dd class="mt-1 text-gray-900">{{ $personRecord->person_phone ?: 'Nicht hinterlegt' }}</dd></div>
                            <div><dt class="font-medium text-gray-500">Zeitzone</dt><dd class="mt-1 text-gray-900">{{ $personRecord->person_timezone ?: 'Nicht hinterlegt' }}</dd></div>
                            <div><dt class="font-medium text-gray-500">PLZ / Ort</dt><dd class="mt-1 text-gray-900">{{ trim(($personRecord->person_postal_code ?: '').' '.($personRecord->person_city ?: '')) ?: 'Nicht hinterlegt' }}</dd></div>
                            <div class="sm:col-span-2"><dt class="font-medium text-gray-500">Adresse</dt><dd class="mt-1 text-gray-900">{{ trim(($personRecord->person_address_line1 ?: '').' '.($personRecord->person_address_line2 ?: '')) ?: 'Nicht hinterlegt' }}</dd></div>
                        </dl>
                    </x-admin.panel>
                </div>

                <x-admin.panel title="Technik und Status">
                    <dl class="grid gap-4 text-sm md:grid-cols-3">
                        <div><dt class="font-medium text-gray-500">Plattform</dt><dd class="mt-1 text-gray-900">{{ $personRecord->platform }}</dd></div>
                        <div><dt class="font-medium text-gray-500">Profile Key</dt><dd class="mt-1 break-all text-gray-900">{{ $personRecord->profile_key }}</dd></div>
                        <div><dt class="font-medium text-gray-500">Base-Sync</dt><dd class="mt-1 text-gray-900">{{ $personRecord->base_sync_status ?: 'pending' }}</dd></div>
                        <div><dt class="font-medium text-gray-500">Cookie Count</dt><dd class="mt-1 text-gray-900">{{ $personRecord->cookie_count }}</dd></div>
                        <div><dt class="font-medium text-gray-500">Session Cookie</dt><dd class="mt-1 text-gray-900">{{ $personRecord->session_cookie_present ? 'Vorhanden' : 'Nicht vorhanden' }}</dd></div>
                        <div><dt class="font-medium text-gray-500">Instagram-Sperre</dt><dd class="mt-1 text-gray-900">{{ $personRecord->scrape_blocked_until?->format('d.m.Y H:i') ?: 'Keine aktive Sperre' }}</dd></div>
                    </dl>

                    @if($personRecord->person_notes)
                        <div class="mt-5 border-t border-gray-100 pt-4">
                            <h4 class="text-sm font-semibold text-gray-900">Notizen</h4>
                            <p class="mt-2 whitespace-pre-line text-sm text-gray-700">{{ $personRecord->person_notes }}</p>
                        </div>
                    @endif
                </x-admin.panel>
            </div>

            <div x-show="tab === 'ai'" class="space-y-6">
                <x-admin.panel title="AI-Persona" description="Diese Felder steuern Kontext, Stil und Verhalten der Persona.">
                    <x-slot name="actions">
                        <button type="button" wire:click="saveAiProfile" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-blue-700">
                            Speichern
                        </button>
                    </x-slot>

                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Nationalitaet</label>
                            <input type="text" wire:model.defer="aiNationality" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm">
                            @error('aiNationality') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Beruf / Taetigkeit</label>
                            <input type="text" wire:model.defer="aiOccupation" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm">
                            @error('aiOccupation') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Beziehungsstatus</label>
                            <input type="text" wire:model.defer="aiRelationshipStatus" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm">
                            @error('aiRelationshipStatus') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Sprachen</label>
                            <textarea rows="3" wire:model.defer="aiLanguages" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm"></textarea>
                            @error('aiLanguages') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Interessen</label>
                            <textarea rows="4" wire:model.defer="aiInterests" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm"></textarea>
                            @error('aiInterests') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Persoenlichkeitsmerkmale</label>
                            <textarea rows="4" wire:model.defer="aiPersonalityTraits" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm"></textarea>
                            @error('aiPersonalityTraits') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Werte und Ueberzeugungen</label>
                            <textarea rows="4" wire:model.defer="aiValues" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm"></textarea>
                            @error('aiValues') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Kommunikationsstil</label>
                            <textarea rows="4" wire:model.defer="aiCommunicationStyle" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm"></textarea>
                            @error('aiCommunicationStyle') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Schreibstil</label>
                            <textarea rows="4" wire:model.defer="aiWritingStyle" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm"></textarea>
                            @error('aiWritingStyle') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Typischer Tagesablauf</label>
                            <textarea rows="4" wire:model.defer="aiDailyRoutine" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm"></textarea>
                            @error('aiDailyRoutine') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-gray-700">Hintergrundgeschichte</label>
                            <textarea rows="5" wire:model.defer="aiBackgroundStory" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm"></textarea>
                            @error('aiBackgroundStory') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-gray-700">Verhaltensrichtlinien fuer die AI</label>
                            <textarea rows="5" wire:model.defer="aiBehaviorGuidelines" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm"></textarea>
                            @error('aiBehaviorGuidelines') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                    </div>
                </x-admin.panel>
            </div>

            <div x-show="tab === 'activity'" class="space-y-6">
                <x-admin.panel title="Interne Aktivitaeten" description="Sandbox-Plan fuer realistische Persona-Sessions ohne reale Plattformaktionen.">
                    <x-slot name="actions">
                        @if($activitySimulation !== [])
                            <button type="button" wire:click="clearActivitySimulation" onclick="return confirm('Interne Aktivitaets-Simulation wirklich entfernen?')" class="rounded-md border border-red-200 bg-white px-4 py-2 text-sm font-semibold text-red-700 shadow-sm hover:bg-red-50">
                                Entfernen
                            </button>
                        @endif
                    </x-slot>

                    <div class="grid gap-4 md:grid-cols-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Tage</label>
                            <input type="number" min="1" max="14" wire:model.defer="activitySimulationDays" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm">
                            @error('activitySimulationDays') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Intensitaet</label>
                            <select wire:model.defer="activitySimulationIntensity" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm">
                                <option value="quiet">Ruhig</option>
                                <option value="balanced">Ausgewogen</option>
                                <option value="active">Aktiv</option>
                                <option value="creator">Creator</option>
                            </select>
                            @error('activitySimulationIntensity') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-gray-700">Seed</label>
                            <input type="text" wire:model.defer="activitySimulationSeed" placeholder="leer lassen fuer automatischen Seed" class="mt-1 block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm">
                            @error('activitySimulationSeed') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div class="mt-4 flex justify-end">
                        <button type="button" wire:click="generateActivitySimulation" class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-slate-800">
                            Aktivitaeten planen
                        </button>
                    </div>

                    @if($activitySimulation === [])
                        <div class="mt-5 rounded-md border border-dashed border-gray-300 bg-gray-50 p-6 text-sm text-gray-500">
                            Noch kein interner Aktivitaetsplan gespeichert.
                        </div>
                    @else
                        <div class="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                            <x-admin.stat label="Sessions" :value="$activityMetrics['planned_sessions'] ?? 0" />
                            <x-admin.stat label="Schritte" :value="$activityMetrics['planned_steps'] ?? 0" />
                            <x-admin.stat label="Content" :value="$activityMetrics['planned_posts'] ?? 0" />
                            <x-admin.stat label="Kommentare" :value="$activityMetrics['planned_comments'] ?? 0" />
                            <x-admin.stat label="Max. Risiko" :value="$activityMetrics['max_day_risk_score'] ?? 0" />
                        </div>

                        <div class="mt-4 rounded-md border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                            <p class="font-semibold">Interne Sandbox</p>
                            <p class="mt-1">Kein Login, keine Browser-Automation, keine externen Plattformaktionen. Status: {{ $activitySimulation['status'] ?? 'draft' }}.</p>
                        </div>

                        @if(!empty($activityProfile['content_themes']))
                            <div class="mt-4 flex flex-wrap gap-2">
                                @foreach($activityProfile['content_themes'] as $theme)
                                    <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700 ring-1 ring-slate-200">{{ $theme }}</span>
                                @endforeach
                            </div>
                        @endif

                        <div class="mt-5 space-y-4">
                            @foreach($activityDays as $day)
                                @php
                                    $dayMetrics = $day['metrics'] ?? [];
                                    $riskClass = match($dayMetrics['risk_level'] ?? 'low') {
                                        'review' => 'bg-red-50 text-red-700 ring-red-200',
                                        'moderate' => 'bg-amber-50 text-amber-700 ring-amber-200',
                                        default => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
                                    };
                                @endphp
                                <article class="rounded-md border border-gray-200 bg-gray-50 p-4" wire:key="activity-day-{{ $day['date'] ?? $loop->index }}">
                                    <div class="flex flex-wrap items-start justify-between gap-3">
                                        <div>
                                            <h4 class="text-sm font-semibold text-gray-900">{{ $day['weekday'] ?? '' }}, {{ $day['date'] ?? '' }}</h4>
                                            <p class="mt-1 text-sm text-gray-600">{{ $day['anchor'] ?? '' }}</p>
                                        </div>
                                        <div class="flex flex-wrap gap-2">
                                            <span class="rounded-full bg-white px-2.5 py-1 text-xs font-semibold text-gray-700 ring-1 ring-gray-200">{{ $dayMetrics['sessions'] ?? 0 }} Sessions</span>
                                            <span class="rounded-full px-2.5 py-1 text-xs font-semibold ring-1 {{ $riskClass }}">Risiko {{ $dayMetrics['risk_score'] ?? 0 }}</span>
                                        </div>
                                    </div>

                                    <div class="mt-4 grid gap-3 lg:grid-cols-2">
                                        @foreach(array_slice($day['sessions'] ?? [], 0, 4) as $session)
                                            <div class="rounded-md border border-gray-200 bg-white p-3">
                                                <div class="flex flex-wrap items-center justify-between gap-2">
                                                    <p class="text-sm font-semibold text-gray-900">{{ $session['starts_at_local'] ?? '' }} - {{ $session['session_type'] ?? 'session' }}</p>
                                                    <span class="rounded-full bg-gray-100 px-2.5 py-1 text-xs font-semibold text-gray-600">{{ $session['duration_minutes'] ?? 0 }} Min.</span>
                                                </div>
                                                <p class="mt-1 text-sm text-gray-600">{{ $session['intent'] ?? '' }}</p>
                                            </div>
                                        @endforeach
                                    </div>
                                </article>
                            @endforeach
                        </div>
                    @endif
                </x-admin.panel>
            </div>

            <div x-show="tab === 'media'" class="space-y-6">
                <x-admin.panel title="Profilbild" description="Avatar direkt auf der Person speichern oder entfernen.">
                    <form wire:submit="uploadAvatar" class="flex flex-wrap items-end gap-3">
                        <div class="min-w-[260px] flex-1">
                            <input type="file" wire:model="avatarUpload" accept="image/*" class="block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm">
                            @error('avatarUpload') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <button type="submit" class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-slate-800">Speichern</button>
                        @if($avatarUrl !== '')
                            <button type="button" wire:click="deleteAvatar" onclick="return confirm('Profilbild wirklich loeschen?')" class="rounded-md border border-red-200 bg-white px-4 py-2 text-sm font-semibold text-red-700 shadow-sm hover:bg-red-50">Loeschen</button>
                        @endif
                    </form>
                </x-admin.panel>

                @livewire('tools.file-pools.manage-file-pools', ['modelType' => \App\Models\Person::class, 'modelId' => $personRecord->id, 'readOnly' => false], key('person-file-pool-'.$personRecord->id))

                <x-admin.panel title="Bilder" description="Profilbild und weitere Bilddateien koennen einzeln verwaltet werden.">
                    <x-slot name="actions">
                        <button type="button" wire:click="$dispatch('open-person-image-modal', { personId: {{ $personRecord->id }} })" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700">
                            Bilder erstellen
                        </button>
                    </x-slot>

                    @if($imageFiles === [])
                        <div class="rounded-md border border-dashed border-gray-300 bg-gray-50 p-6 text-sm text-gray-500">Keine weiteren Bilder vorhanden.</div>
                    @else
                        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                            @foreach($imageFiles as $imageFile)
                                <article class="overflow-hidden rounded-md border border-gray-200 bg-gray-50" wire:key="person-image-{{ $imageFile['id'] }}">
                                    @if(($imageFile['url'] ?? '') !== '')
                                        <img src="{{ $imageFile['url'] }}" alt="{{ $imageFile['name'] }}" class="aspect-square w-full object-cover">
                                    @else
                                        <div class="flex aspect-square w-full items-center justify-center bg-gray-100 text-sm text-gray-500">Kein Vorschaubild</div>
                                    @endif

                                    <div class="space-y-3 p-3">
                                        <div>
                                            <p class="truncate text-sm font-semibold text-gray-900">{{ $imageFile['name'] }}</p>
                                            <p class="mt-1 text-xs text-gray-500">{{ $imageFile['type'] }}{{ ($imageFile['size'] ?? '') !== '' ? ' - '.$imageFile['size'] : '' }}</p>
                                        </div>
                                        <div class="flex flex-wrap gap-2">
                                            @if(($imageFile['url'] ?? '') !== '')
                                                <a href="{{ $imageFile['url'] }}" target="_blank" rel="noopener" class="rounded-md border border-gray-300 bg-white px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-50">Oeffnen</a>
                                            @endif
                                            <button type="button" wire:click="useImageAsAvatar({{ $imageFile['id'] }})" class="rounded-md border border-blue-200 bg-white px-3 py-1.5 text-xs font-semibold text-blue-700 hover:bg-blue-50">Als Profilbild</button>
                                            <button type="button" wire:click="deleteImageFile({{ $imageFile['id'] }})" onclick="return confirm('Dieses Bild wirklich loeschen?')" class="rounded-md border border-red-200 bg-white px-3 py-1.5 text-xs font-semibold text-red-700 hover:bg-red-50">Loeschen</button>
                                        </div>
                                    </div>
                                </article>
                            @endforeach
                        </div>
                    @endif
                </x-admin.panel>
            </div>

            <div x-show="tab === 'social'" class="space-y-6">
                <x-admin.panel title="Social Media Accounts" description="Vorerst wird hier nur der Instagram-Account dieser Person verwaltet.">
                    <x-slot name="actions">
                        <div class="flex flex-wrap gap-2">
                            <button type="button" wire:click="openEditProfile" class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50">
                                Instagram bearbeiten
                            </button>
                            <button type="button" wire:click="openRuntimeSettingsModal" class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50">
                                Timeouts
                            </button>
                            <button type="button" wire:click="buildInstagramSession" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-blue-700">
                                Session aufbauen
                            </button>
                        </div>
                    </x-slot>

                    <article class="overflow-hidden rounded-lg border border-pink-100 bg-gradient-to-br from-pink-50 via-white to-orange-50">
                        <div class="flex flex-wrap items-start justify-between gap-4 border-b border-pink-100 bg-white/70 p-5">
                            <div class="min-w-0">
                                <p class="text-xs font-semibold uppercase tracking-wide text-pink-600">Instagram</p>
                                <h3 class="mt-1 truncate text-xl font-semibold text-slate-900">
                                    {{ $instagramUsername !== '' ? '@'.$instagramUsername : 'Kein Instagram-Benutzername' }}
                                </h3>
                                <p class="mt-1 text-sm text-slate-600">Profil: {{ $profileDetail['label'] }}</p>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <span class="rounded-full px-2.5 py-1 text-xs font-semibold ring-1 {{ $instagramStatusClass }}">{{ $instagramStatusLabel }}</span>
                                <span class="rounded-full {{ ($profileDetail['has_stored_password'] ?? false) ? 'bg-emerald-50 text-emerald-700 ring-emerald-200' : 'bg-amber-50 text-amber-700 ring-amber-200' }} px-2.5 py-1 text-xs font-semibold ring-1">
                                    {{ ($profileDetail['has_stored_password'] ?? false) ? 'Passwort gespeichert' : 'Kein Passwort' }}
                                </span>
                                <span class="rounded-full {{ $personRecord->session_cookie_present ? 'bg-emerald-50 text-emerald-700 ring-emerald-200' : 'bg-slate-100 text-slate-700 ring-slate-200' }} px-2.5 py-1 text-xs font-semibold ring-1">
                                    {{ $personRecord->session_cookie_present ? 'Session-Cookie vorhanden' : 'Kein Session-Cookie' }}
                                </span>
                            </div>
                        </div>

                        <div class="grid gap-4 p-5 md:grid-cols-2 xl:grid-cols-3">
                            <div class="rounded-md bg-white/80 p-4 ring-1 ring-slate-200">
                                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Benutzername</p>
                                <p class="mt-2 break-all text-sm font-semibold text-slate-900">{{ $instagramUsername !== '' ? '@'.$instagramUsername : 'Nicht hinterlegt' }}</p>
                            </div>
                            <div class="rounded-md bg-white/80 p-4 ring-1 ring-slate-200">
                                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Browser-Profil</p>
                                <p class="mt-2 break-all text-sm text-slate-900">{{ $profileDetail['browser_profile_path'] }}</p>
                            </div>
                            <div class="rounded-md bg-white/80 p-4 ring-1 ring-slate-200">
                                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Cookie-Datei</p>
                                <p class="mt-2 break-all text-sm text-slate-900">{{ $profileDetail['cookie_file_path'] }}</p>
                            </div>
                            <div class="rounded-md bg-white/80 p-4 ring-1 ring-slate-200">
                                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Cookies</p>
                                <p class="mt-2 text-sm text-slate-900">{{ $personRecord->cookie_count }} gespeichert</p>
                                <p class="mt-1 text-xs text-slate-500">Synchronisiert: {{ $personRecord->cookies_synced_at?->format('d.m.Y H:i') ?: 'Noch nicht' }}</p>
                            </div>
                            <div class="rounded-md bg-white/80 p-4 ring-1 ring-slate-200">
                                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Auto-Login</p>
                                <p class="mt-2 text-sm text-slate-900">{{ ($profileDetail['login_username'] ?? '') !== '' ? 'Benutzername hinterlegt' : 'Nicht konfiguriert' }}</p>
                                <p class="mt-1 text-xs text-slate-500">Passwort: {{ ($profileDetail['has_stored_password'] ?? false) ? 'gespeichert' : 'fehlt' }}</p>
                            </div>
                            <div class="rounded-md bg-white/80 p-4 ring-1 ring-slate-200">
                                <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Instagram-Sperre</p>
                                <p class="mt-2 text-sm text-slate-900">{{ $personRecord->scrape_blocked_until?->format('d.m.Y H:i') ?: 'Keine aktive Sperre' }}</p>
                                @if($personRecord->scrape_blocked_reason)
                                    <p class="mt-1 text-xs text-amber-700">{{ $personRecord->scrape_blocked_reason }}</p>
                                @endif
                            </div>
                        </div>
                    </article>
                </x-admin.panel>

                @if($sessionBuildResult)
                    @php
                        $sessionResultClass = ($sessionBuildResult['ok'] ?? false)
                            ? 'border-emerald-200 bg-emerald-50 text-emerald-900'
                            : 'border-amber-200 bg-amber-50 text-amber-950';
                    @endphp

                    <div class="rounded-lg border p-4 text-sm {{ $sessionResultClass }}">
                        <p class="font-semibold">{{ $sessionBuildResult['statusMessage'] ?? 'Session-Aufbau abgeschlossen.' }}</p>

                        @if(!empty($sessionBuildResult['debugLogPath']))
                            <p class="mt-2 break-all text-xs">
                                <span class="font-semibold">Debug-Log:</span>
                                {{ $sessionBuildResult['debugLogPath'] }}
                            </p>
                        @endif

                        @if(!empty($sessionBuildResult['cookieDiagnostics']) || !empty($sessionBuildResult['loginDiagnostics']))
                            <div class="mt-3 grid gap-2 text-xs sm:grid-cols-2">
                                <div class="rounded-md border border-current/20 bg-white/40 p-3">
                                    <p class="font-semibold">Cookie-Diagnose</p>
                                    <p class="mt-1">sessionid in Datei: {{ data_get($sessionBuildResult, 'cookieDiagnostics.sessionCookieProvided') ? 'Ja' : 'Nein' }}</p>
                                    <p>sessionid akzeptiert: {{ data_get($sessionBuildResult, 'cookieDiagnostics.sessionCookieAccepted') ? 'Ja' : 'Nein' }}</p>
                                    <p>sessionid nach Reload noch da: {{ data_get($sessionBuildResult, 'cookieDiagnostics.sessionCookieRetained') ? 'Ja' : 'Nein' }}</p>
                                </div>
                                <div class="rounded-md border border-current/20 bg-white/40 p-3">
                                    <p class="font-semibold">Login-Diagnose</p>
                                    <p class="mt-1">Auto-Login versucht: {{ data_get($sessionBuildResult, 'loginDiagnostics.attempted') ? 'Ja' : 'Nein' }}</p>
                                    <p>Formular gefunden: {{ data_get($sessionBuildResult, 'loginDiagnostics.formDetected') ? 'Ja' : 'Nein' }}</p>
                                    <p>Login erfolgreich: {{ data_get($sessionBuildResult, 'loginDiagnostics.success') ? 'Ja' : 'Nein' }}</p>
                                    <p>sessionid nach Login: {{ data_get($sessionBuildResult, 'loginDiagnostics.sessionCookiePresent') ? 'Ja' : 'Nein' }}</p>
                                </div>
                            </div>
                        @endif

                        @if(!empty($sessionBuildResult['notes']))
                            <ul class="mt-3 list-disc space-y-1 pl-5">
                                @foreach($sessionBuildResult['notes'] as $note)
                                    <li>{{ $note }}</li>
                                @endforeach
                            </ul>
                        @endif

                        @if(!empty($sessionBuildResult['warnings']))
                            <div class="mt-3 rounded-md border border-current/20 bg-white/50 p-3">
                                <p class="font-semibold">Hinweise</p>
                                <ul class="mt-2 list-disc space-y-1 pl-5">
                                    @foreach($sessionBuildResult['warnings'] as $warning)
                                        <li>{{ $warning }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                    </div>
                @endif
            </div>

            <div x-show="tab === 'raw'">
                <x-admin.panel title="Rohdaten" description="Vollstaendige gespeicherte Personendaten fuer technische Pruefung und Prompting.">
                    <pre class="overflow-x-auto rounded-md bg-slate-950 p-4 text-xs text-slate-100">{{ json_encode($personRecord->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                </x-admin.panel>
            </div>
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

    @livewire('admin.persons.ai-complete-person-profile-modal')
    @livewire('admin.persons.generate-person-images-modal')
</div>
