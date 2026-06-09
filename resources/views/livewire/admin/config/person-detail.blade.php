<div class="space-y-6" wire:loading.class="opacity-60 pointer-events-none">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold text-gray-900">Person-Detail</h1>
            <p class="mt-1 text-sm text-gray-500">
                Vollstaendige Detailansicht fuer Persona-, Session-, Datei- und AI-Profildaten.
            </p>
        </div>
        <div class="flex flex-wrap gap-3">
            <a href="{{ route('persons.index') }}" class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50">
                Zurueck zu Personen
            </a>

            @if($personRecord)
                <button
                    type="button"
                    wire:click="$dispatch('open-ai-complete-person-profile', { personId: {{ $personRecord->id }} })"
                    class="rounded-md bg-purple-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-purple-700"
                >
                    Person bearbeiten
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
        <div class="rounded-lg border border-gray-200 bg-white p-6 text-sm text-gray-500 shadow-sm">
            Keine Person ausgewaehlt.
        </div>
    @else
        <div class="grid gap-6 xl:grid-cols-3">
            <section class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm xl:col-span-1">
                <div class="border-b border-gray-200 p-5">
                    <div class="flex items-start gap-4">
                        @if($avatarUrl !== '')
                            <img src="{{ $avatarUrl }}" alt="{{ $profileDetail['display_name'] }}" class="h-24 w-24 rounded-lg object-cover ring-1 ring-gray-200">
                        @else
                            <div class="flex h-24 w-24 items-center justify-center rounded-lg bg-slate-900 text-3xl font-semibold text-white">
                                {{ strtoupper(substr($profileDetail['label'] ?? 'P', 0, 1)) }}
                            </div>
                        @endif

                        <div class="min-w-0 flex-1">
                            <h2 class="truncate text-xl font-semibold text-gray-900">{{ $profileDetail['display_name'] }}</h2>
                            <p class="mt-1 text-sm text-gray-500">{{ $profileDetail['person_alias'] ?: $profileDetail['label'] }}</p>
                            <p class="mt-1 text-sm text-gray-500">{{ $profileDetail['login_username'] !== '' ? '@'.$profileDetail['login_username'] : 'Kein Instagram-Benutzername' }}</p>

                            <div class="mt-3 flex flex-wrap gap-2">
                                @if($profileDetail['is_primary'] ?? false)
                                    <span class="rounded-full bg-blue-600 px-2.5 py-1 text-xs font-semibold text-white">Standard</span>
                                @endif
                                @if($profileDetail['is_active'] ?? false)
                                    <span class="rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700 ring-1 ring-emerald-200">Analyse aktiv</span>
                                @else
                                    <span class="rounded-full bg-gray-100 px-2.5 py-1 text-xs font-semibold text-gray-600">Inaktiv</span>
                                @endif
                                <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700 ring-1 ring-slate-200">
                                    Bot: {{ match($profileDetail['bot_status'] ?? 'manual') {
                                        'ready' => 'bereit',
                                        'training' => 'Training',
                                        'disabled' => 'deaktiviert',
                                        default => 'manuell',
                                    } }}
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="space-y-5 p-5">
                    <div>
                        <h3 class="text-sm font-semibold text-gray-900">Profilbild</h3>
                        <form wire:submit="uploadAvatar" class="mt-3 space-y-3">
                            <input type="file" wire:model="avatarUpload" accept="image/*" class="block w-full rounded-md border border-gray-300 p-2 text-sm shadow-sm">
                            @error('avatarUpload') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                            <button type="submit" class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-slate-800">
                                Profilbild speichern
                            </button>
                            @if($avatarUrl !== '')
                                <button
                                    type="button"
                                    wire:click="deleteAvatar"
                                    onclick="return confirm('Profilbild wirklich loeschen?')"
                                    class="ml-2 rounded-md border border-red-200 bg-white px-4 py-2 text-sm font-semibold text-red-700 shadow-sm hover:bg-red-50"
                                >
                                    Profilbild loeschen
                                </button>
                            @endif
                        </form>
                    </div>

                    <div>
                        <h3 class="text-sm font-semibold text-gray-900">Stammdaten</h3>
                        <dl class="mt-3 space-y-2 text-sm">
                            <div><dt class="font-medium text-gray-500">Vorname</dt><dd class="text-gray-900">{{ $personRecord->person_first_name ?: 'Nicht hinterlegt' }}</dd></div>
                            <div><dt class="font-medium text-gray-500">Nachname</dt><dd class="text-gray-900">{{ $personRecord->person_last_name ?: 'Nicht hinterlegt' }}</dd></div>
                            <div><dt class="font-medium text-gray-500">Alias</dt><dd class="text-gray-900">{{ $personRecord->person_alias ?: 'Nicht hinterlegt' }}</dd></div>
                            <div><dt class="font-medium text-gray-500">Geburtsdatum</dt><dd class="text-gray-900">{{ $personRecord->person_date_of_birth?->format('d.m.Y') ?: 'Nicht hinterlegt' }}</dd></div>
                            <div><dt class="font-medium text-gray-500">Geschlecht / Rolle</dt><dd class="text-gray-900">{{ $personRecord->person_gender ?: 'Nicht hinterlegt' }}</dd></div>
                            <div><dt class="font-medium text-gray-500">Nationalitaet</dt><dd class="text-gray-900">{{ data_get($personRecord->identity_profile, 'nationality') ?: 'Nicht hinterlegt' }}</dd></div>
                            <div><dt class="font-medium text-gray-500">Beruf</dt><dd class="text-gray-900">{{ data_get($personRecord->identity_profile, 'occupation') ?: 'Nicht hinterlegt' }}</dd></div>
                            <div><dt class="font-medium text-gray-500">Beziehungsstatus</dt><dd class="text-gray-900">{{ data_get($personRecord->identity_profile, 'relationship_status') ?: 'Nicht hinterlegt' }}</dd></div>
                        </dl>
                    </div>
                </div>
            </section>

            <div class="space-y-6 xl:col-span-2">
                <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
                    <h3 class="text-lg font-semibold text-gray-900">Personendaten</h3>
                    <div class="mt-4 grid gap-4 md:grid-cols-2">
                        <div class="rounded-md border border-gray-200 bg-gray-50 p-4">
                            <h4 class="text-sm font-semibold text-gray-900">Kontakt</h4>
                            <dl class="mt-3 space-y-2 text-sm">
                                <div><dt class="font-medium text-gray-500">E-Mail</dt><dd class="break-all text-gray-900">{{ $personRecord->person_email ?: 'Nicht hinterlegt' }}</dd></div>
                                <div><dt class="font-medium text-gray-500">Telefon</dt><dd class="text-gray-900">{{ $personRecord->person_phone ?: 'Nicht hinterlegt' }}</dd></div>
                                <div><dt class="font-medium text-gray-500">Zeitzone</dt><dd class="text-gray-900">{{ $personRecord->person_timezone ?: 'Nicht hinterlegt' }}</dd></div>
                            </dl>
                        </div>

                        <div class="rounded-md border border-gray-200 bg-gray-50 p-4">
                            <h4 class="text-sm font-semibold text-gray-900">Adresse</h4>
                            <dl class="mt-3 space-y-2 text-sm">
                                <div><dt class="font-medium text-gray-500">Strasse</dt><dd class="text-gray-900">{{ $personRecord->person_address_line1 ?: 'Nicht hinterlegt' }}</dd></div>
                                <div><dt class="font-medium text-gray-500">Adresszusatz</dt><dd class="text-gray-900">{{ $personRecord->person_address_line2 ?: 'Nicht hinterlegt' }}</dd></div>
                                <div><dt class="font-medium text-gray-500">PLZ / Ort</dt><dd class="text-gray-900">{{ trim(($personRecord->person_postal_code ?: '').' '.($personRecord->person_city ?: '')) ?: 'Nicht hinterlegt' }}</dd></div>
                                <div><dt class="font-medium text-gray-500">Region / Land</dt><dd class="text-gray-900">{{ trim(($personRecord->person_state ?: '').' '.($personRecord->person_country ?: '')) ?: 'Nicht hinterlegt' }}</dd></div>
                            </dl>
                        </div>
                    </div>

                    <div class="mt-4 rounded-md border border-gray-200 bg-gray-50 p-4">
                        <h4 class="text-sm font-semibold text-gray-900">Notizen</h4>
                        <p class="mt-2 whitespace-pre-line text-sm text-gray-800">{{ $personRecord->person_notes ?: 'Keine Notizen hinterlegt.' }}</p>
                    </div>
                </section>

                <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
                    <h3 class="text-lg font-semibold text-gray-900">Instagram und Technik</h3>
                    <div class="mt-4 grid gap-4 md:grid-cols-2">
                        <div class="rounded-md border border-gray-200 bg-gray-50 p-4">
                            <h4 class="text-sm font-semibold text-gray-900">Session</h4>
                            <dl class="mt-3 space-y-2 text-sm">
                                <div><dt class="font-medium text-gray-500">Plattform</dt><dd class="text-gray-900">{{ $personRecord->platform }}</dd></div>
                                <div><dt class="font-medium text-gray-500">Profile Key</dt><dd class="break-all text-gray-900">{{ $personRecord->profile_key }}</dd></div>
                                <div><dt class="font-medium text-gray-500">Profilpfad</dt><dd class="break-all text-gray-900">{{ $personRecord->browser_profile_path ?: 'Nicht hinterlegt' }}</dd></div>
                                <div><dt class="font-medium text-gray-500">Cookie-Datei</dt><dd class="break-all text-gray-900">{{ $personRecord->cookie_file_path ?: 'Nicht hinterlegt' }}</dd></div>
                                <div><dt class="font-medium text-gray-500">Passwort</dt><dd class="text-gray-900">{{ ($profileDetail['has_stored_password'] ?? false) ? 'Gespeichert' : 'Nicht gespeichert' }}</dd></div>
                            </dl>
                        </div>

                        <div class="rounded-md border border-gray-200 bg-gray-50 p-4">
                            <h4 class="text-sm font-semibold text-gray-900">Status</h4>
                            <dl class="mt-3 space-y-2 text-sm">
                                <div><dt class="font-medium text-gray-500">Base-Sync</dt><dd class="text-gray-900">{{ $personRecord->base_sync_status ?: 'pending' }}</dd></div>
                                <div><dt class="font-medium text-gray-500">Base synchronisiert</dt><dd class="text-gray-900">{{ $personRecord->base_synced_at?->format('d.m.Y H:i') ?: 'Noch nicht' }}</dd></div>
                                <div><dt class="font-medium text-gray-500">Cookie Count</dt><dd class="text-gray-900">{{ $personRecord->cookie_count }}</dd></div>
                                <div><dt class="font-medium text-gray-500">Session Cookie</dt><dd class="text-gray-900">{{ $personRecord->session_cookie_present ? 'Vorhanden' : 'Nicht vorhanden' }}</dd></div>
                                <div><dt class="font-medium text-gray-500">Instagram-Sperre</dt><dd class="text-gray-900">{{ $personRecord->scrape_blocked_until?->format('d.m.Y H:i') ?: 'Keine aktive Sperre' }}</dd></div>
                            </dl>
                        </div>
                    </div>

                    @if(!empty($personRecord->social_accounts))
                        <div class="mt-4 rounded-md border border-gray-200 bg-gray-50 p-4">
                            <h4 class="text-sm font-semibold text-gray-900">Social Accounts</h4>
                            <div class="mt-3 grid gap-3 md:grid-cols-2">
                                @foreach($personRecord->social_accounts as $account)
                                    <div class="rounded-md border border-gray-200 bg-white p-3 text-sm">
                                        <p class="font-semibold text-gray-900">{{ ucfirst($account['platform'] ?? 'Account') }}</p>
                                        <p class="mt-1 text-gray-600">{{ $account['handle'] ?? ($account['username'] ?? 'Kein Handle') }}</p>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </section>

                <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-900">AI-Persona</h3>
                            <p class="mt-1 text-sm text-gray-500">
                                Diese Daten helfen einer AI, sich konsistent als diese Person auszugeben und mit passendem Stil zu interagieren.
                            </p>
                        </div>
                        <button type="button" wire:click="saveAiProfile" class="rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-blue-700">
                            AI-Daten speichern
                        </button>
                    </div>

                    <div class="mt-4 grid gap-4 md:grid-cols-2">
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
                            <p class="mt-1 text-xs text-gray-500">Eine Sprache pro Zeile oder komma-getrennt.</p>
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
                </section>

                <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-900">Interne Aktivitaeten</h3>
                            <p class="mt-1 text-sm text-gray-500">
                                Sandbox-Plan fuer realistische Persona-Sessions ohne reale Plattformaktionen.
                            </p>
                        </div>
                        @if($activitySimulation !== [])
                            <button
                                type="button"
                                wire:click="clearActivitySimulation"
                                onclick="return confirm('Interne Aktivitaets-Simulation wirklich entfernen?')"
                                class="rounded-md border border-red-200 bg-white px-4 py-2 text-sm font-semibold text-red-700 shadow-sm hover:bg-red-50"
                            >
                                Entfernen
                            </button>
                        @endif
                    </div>

                    <div class="mt-5 grid gap-4 md:grid-cols-4">
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
                        @php
                            $activityMetrics = $activitySimulation['metrics'] ?? [];
                            $activityProfile = $activitySimulation['profile'] ?? [];
                            $activityDays = $activitySimulation['days_plan'] ?? [];
                        @endphp

                        <div class="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                            <div class="rounded-md border border-gray-200 bg-gray-50 p-3">
                                <p class="text-xs font-semibold uppercase text-gray-500">Sessions</p>
                                <p class="mt-1 text-lg font-semibold text-gray-900">{{ $activityMetrics['planned_sessions'] ?? 0 }}</p>
                            </div>
                            <div class="rounded-md border border-gray-200 bg-gray-50 p-3">
                                <p class="text-xs font-semibold uppercase text-gray-500">Schritte</p>
                                <p class="mt-1 text-lg font-semibold text-gray-900">{{ $activityMetrics['planned_steps'] ?? 0 }}</p>
                            </div>
                            <div class="rounded-md border border-gray-200 bg-gray-50 p-3">
                                <p class="text-xs font-semibold uppercase text-gray-500">Content</p>
                                <p class="mt-1 text-lg font-semibold text-gray-900">{{ $activityMetrics['planned_posts'] ?? 0 }}</p>
                            </div>
                            <div class="rounded-md border border-gray-200 bg-gray-50 p-3">
                                <p class="text-xs font-semibold uppercase text-gray-500">Kommentare</p>
                                <p class="mt-1 text-lg font-semibold text-gray-900">{{ $activityMetrics['planned_comments'] ?? 0 }}</p>
                            </div>
                            <div class="rounded-md border border-gray-200 bg-gray-50 p-3">
                                <p class="text-xs font-semibold uppercase text-gray-500">Max. Risiko</p>
                                <p class="mt-1 text-lg font-semibold text-gray-900">{{ $activityMetrics['max_day_risk_score'] ?? 0 }}</p>
                            </div>
                        </div>

                        <div class="mt-4 rounded-md border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                            <p class="font-semibold">Interne Sandbox</p>
                            <p class="mt-1">Kein Login, keine Browser-Automation, keine externen Plattformaktionen. Status: {{ $activitySimulation['status'] ?? 'draft' }}.</p>
                        </div>

                        @if(!empty($activityProfile['content_themes']))
                            <div class="mt-4">
                                <h4 class="text-sm font-semibold text-gray-900">Themen</h4>
                                <div class="mt-2 flex flex-wrap gap-2">
                                    @foreach($activityProfile['content_themes'] as $theme)
                                        <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700 ring-1 ring-slate-200">{{ $theme }}</span>
                                    @endforeach
                                </div>
                            </div>
                        @endif

                        <div class="mt-5 space-y-4">
                            @foreach($activityDays as $day)
                                @php
                                    $dayMetrics = $day['metrics'] ?? [];
                                    $riskLevel = $dayMetrics['risk_level'] ?? 'low';
                                    $riskClass = match($riskLevel) {
                                        'review' => 'bg-red-50 text-red-700 ring-red-200',
                                        'moderate' => 'bg-amber-50 text-amber-700 ring-amber-200',
                                        default => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
                                    };
                                @endphp
                                <div class="rounded-md border border-gray-200 bg-gray-50 p-4" wire:key="activity-day-{{ $day['date'] ?? $loop->index }}">
                                    <div class="flex flex-wrap items-start justify-between gap-3">
                                        <div>
                                            <h4 class="text-sm font-semibold text-gray-900">{{ $day['weekday'] ?? '' }}, {{ $day['date'] ?? '' }}</h4>
                                            <p class="mt-1 text-sm text-gray-600">{{ $day['anchor'] ?? '' }}</p>
                                            <p class="mt-1 text-xs text-gray-500">Mood: {{ $day['mood'] ?? 'neutral' }}</p>
                                        </div>
                                        <div class="flex flex-wrap gap-2">
                                            <span class="rounded-full bg-white px-2.5 py-1 text-xs font-semibold text-gray-700 ring-1 ring-gray-200">{{ $dayMetrics['sessions'] ?? 0 }} Sessions</span>
                                            <span class="rounded-full bg-white px-2.5 py-1 text-xs font-semibold text-gray-700 ring-1 ring-gray-200">{{ $dayMetrics['steps'] ?? 0 }} Schritte</span>
                                            <span class="rounded-full px-2.5 py-1 text-xs font-semibold ring-1 {{ $riskClass }}">Risiko {{ $dayMetrics['risk_score'] ?? 0 }}</span>
                                        </div>
                                    </div>

                                    @if(!empty($day['content_items']))
                                        <div class="mt-4 grid gap-3 md:grid-cols-2">
                                            @foreach($day['content_items'] as $item)
                                                <div class="rounded-md border border-blue-100 bg-white p-3 text-sm">
                                                    <p class="font-semibold text-blue-900">{{ $item['planned_time_local'] ?? '' }} - {{ $item['type'] ?? 'content' }}</p>
                                                    <p class="mt-1 text-gray-700">{{ $item['prompt'] ?? '' }}</p>
                                                </div>
                                            @endforeach
                                        </div>
                                    @endif

                                    <div class="mt-4 space-y-3">
                                        @foreach(($day['sessions'] ?? []) as $session)
                                            <div class="rounded-md border border-gray-200 bg-white p-3">
                                                <div class="flex flex-wrap items-center justify-between gap-2">
                                                    <p class="text-sm font-semibold text-gray-900">
                                                        {{ $session['starts_at_local'] ?? '' }} - {{ $session['session_type'] ?? 'session' }}
                                                    </p>
                                                    <span class="rounded-full bg-gray-100 px-2.5 py-1 text-xs font-semibold text-gray-600">
                                                        {{ $session['duration_minutes'] ?? 0 }} Min. / {{ $session['energy'] ?? 'medium' }}
                                                    </span>
                                                </div>
                                                <p class="mt-1 text-sm text-gray-600">{{ $session['intent'] ?? '' }}</p>

                                                <ol class="mt-3 space-y-2 text-xs text-gray-600">
                                                    @foreach(array_slice($session['steps'] ?? [], 0, 7) as $step)
                                                        <li class="flex gap-2">
                                                            <span class="w-10 shrink-0 font-semibold text-gray-900">+{{ $step['offset_minutes'] ?? 0 }}m</span>
                                                            <span>
                                                                <span class="font-semibold text-gray-800">{{ $step['label'] ?? ($step['action'] ?? 'step') }}</span>
                                                                <span class="text-gray-500">- {{ $step['details'] ?? '' }}</span>
                                                            </span>
                                                        </li>
                                                    @endforeach
                                                    @if(count($session['steps'] ?? []) > 7)
                                                        <li class="text-gray-400">+ {{ count($session['steps']) - 7 }} weitere interne Schritte</li>
                                                    @endif
                                                </ol>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </section>

                @livewire('tools.file-pools.manage-file-pools', ['modelType' => \App\Models\Person::class, 'modelId' => $personRecord->id, 'readOnly' => false], key('person-file-pool-'.$personRecord->id))

                <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-900">Bilder</h3>
                            <p class="mt-1 text-sm text-gray-500">
                                Profilbild und weitere Bilddateien koennen einzeln entfernt werden.
                            </p>
                        </div>
                        <span class="rounded-full bg-gray-100 px-3 py-1 text-xs font-semibold text-gray-600">
                            {{ count($imageFiles) }} weitere Bilder
                        </span>
                    </div>

                    @if($imageFiles === [])
                        <div class="mt-4 rounded-md border border-dashed border-gray-300 bg-gray-50 p-6 text-sm text-gray-500">
                            Keine weiteren Bilder vorhanden.
                        </div>
                    @else
                        <div class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                            @foreach($imageFiles as $imageFile)
                                <div class="overflow-hidden rounded-md border border-gray-200 bg-gray-50" wire:key="person-image-{{ $imageFile['id'] }}">
                                    @if(($imageFile['url'] ?? '') !== '')
                                        <img src="{{ $imageFile['url'] }}" alt="{{ $imageFile['name'] }}" class="aspect-square w-full object-cover">
                                    @else
                                        <div class="flex aspect-square w-full items-center justify-center bg-gray-100 text-sm text-gray-500">
                                            Kein Vorschaubild
                                        </div>
                                    @endif

                                    <div class="space-y-3 p-3">
                                        <div>
                                            <p class="truncate text-sm font-semibold text-gray-900">{{ $imageFile['name'] }}</p>
                                            <p class="mt-1 text-xs text-gray-500">{{ $imageFile['type'] }}{{ ($imageFile['size'] ?? '') !== '' ? ' - '.$imageFile['size'] : '' }}</p>
                                        </div>

                                        <div class="flex flex-wrap gap-2">
                                            @if(($imageFile['url'] ?? '') !== '')
                                                <a href="{{ $imageFile['url'] }}" target="_blank" rel="noopener" class="rounded-md border border-gray-300 bg-white px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-50">
                                                    Oeffnen
                                                </a>
                                            @endif
                                            <button
                                                type="button"
                                                wire:click="useImageAsAvatar({{ $imageFile['id'] }})"
                                                class="rounded-md border border-blue-200 bg-white px-3 py-1.5 text-xs font-semibold text-blue-700 hover:bg-blue-50"
                                            >
                                                Als Profilbild
                                            </button>
                                            <button
                                                type="button"
                                                wire:click="deleteImageFile({{ $imageFile['id'] }})"
                                                onclick="return confirm('Dieses Bild wirklich loeschen?')"
                                                class="rounded-md border border-red-200 bg-white px-3 py-1.5 text-xs font-semibold text-red-700 hover:bg-red-50"
                                            >
                                                Loeschen
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </section>

                <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
                    <h3 class="text-lg font-semibold text-gray-900">Rohdaten</h3>
                    <p class="mt-1 text-sm text-gray-500">
                        Vollstaendige gespeicherte Personendaten fuer technische Pruefung und Prompting.
                    </p>
                    <pre class="mt-4 overflow-x-auto rounded-md bg-slate-950 p-4 text-xs text-slate-100">{{ json_encode($personRecord->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                </section>
            </div>
        </div>
    @endif
    @livewire('admin.persons.ai-complete-person-profile-modal')
</div>
