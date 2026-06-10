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

            <div x-show="tab === 'raw'">
                <x-admin.panel title="Rohdaten" description="Vollstaendige gespeicherte Personendaten fuer technische Pruefung und Prompting.">
                    <pre class="overflow-x-auto rounded-md bg-slate-950 p-4 text-xs text-slate-100">{{ json_encode($personRecord->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                </x-admin.panel>
            </div>
        </div>
    @endif

    @livewire('admin.persons.ai-complete-person-profile-modal')
    @livewire('admin.persons.generate-person-images-modal')
</div>
