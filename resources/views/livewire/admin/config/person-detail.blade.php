{{--
    Personen-Profil.

    Aufbau: Hero-Header mit Identitaet und Bereitschaft, darunter eine KPI-Reihe
    zum Betriebszustand, darunter die Tabs. Die frueheren Kopfzeilen-Knoepfe
    (Zurueck, Timeouts, Session aufbauen, Bilder) stehen bewusst nicht mehr hier,
    sondern an ihrer fachlichen Stelle: Timeouts, Session und Registrierung im
    Accounts-Tab, Bilder im Medien-Tab.

    Die Bewegungsschicht liegt additiv in `resources/js/components/person-profile-motion.js`
    und haengt an `[data-person-profile]`; ohne JavaScript bleibt alles sichtbar.
--}}
<div
    class="ff-person-profile"
    data-person-profile
    wire:loading.class="ff-person-profile--busy"
    x-data="{ tab: 'overview' }"
    x-on:person-open-credentials.window="$wire.openEditProfile()"
    x-on:person-open-runtime-settings.window="$wire.openRuntimeSettingsModal()"
    x-on:person-build-session.window="$wire.buildInstagramSession()"
    x-on:person-register-instagram.window="$wire.registerInstagramAccount()"
>
    @if (session()->has('success'))
        <div class="ff-flash ff-flash--success">{{ session('success') }}</div>
    @endif

    @if($profileDetail === [] || ! $personRecord)
        <x-admin.panel>
            <div class="p-5 text-sm text-slate-500">Keine Person ausgewaehlt.</div>
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
            $processStatus = $profileDetail['process_status'] ?? [];
            $connectedAccounts = (int) ($accountSummary['connected'] ?? 0);
            $totalAccountTypes = (int) ($accountSummary['total'] ?? 0);
            $accountsWithPassword = (int) ($accountSummary['with_password'] ?? 0);
            $mediaCount = count($imageFiles) + ($avatarUrl !== '' ? 1 : 0);
            $sessionReady = (bool) $personRecord->session_cookie_present;
            $riskScore = (int) ($activityMetrics['max_day_risk_score'] ?? 0);

            $metrics = [
                [
                    'key' => 'accounts',
                    'label' => 'Accounts verbunden',
                    'value' => $connectedAccounts,
                    'suffix' => ' / '.$totalAccountTypes,
                    'detail' => $connectedAccounts > 0 ? 'Portale und Mailkonto' : 'Noch kein Zugang hinterlegt',
                    'tone' => $connectedAccounts > 0 ? 'ok' : 'muted',
                ],
                [
                    'key' => 'credentials',
                    'label' => 'Zugangsdaten',
                    'value' => $accountsWithPassword,
                    'suffix' => '',
                    'detail' => $accountsWithPassword > 0 ? 'Passwoerter verschluesselt gespeichert' : 'Keine Passwoerter gespeichert',
                    'tone' => $accountsWithPassword > 0 ? 'ok' : 'warn',
                ],
                [
                    'key' => 'session',
                    'label' => 'Login-Session',
                    'value' => (int) $personRecord->cookie_count,
                    'suffix' => ' Cookies',
                    'detail' => $sessionReady ? 'Session-Cookie vorhanden' : 'Kein Session-Cookie',
                    'tone' => $sessionReady ? 'ok' : 'muted',
                ],
                [
                    'key' => 'processes',
                    'label' => 'Prozesse',
                    'value' => (int) ($processStatus['count'] ?? 0),
                    'suffix' => '',
                    'detail' => $processStatus['detail'] ?? 'Aktuell inaktiv',
                    'tone' => ($processStatus['level'] ?? 'empty') === 'running' ? 'ok' : (($processStatus['level'] ?? '') === 'warning' ? 'warn' : 'muted'),
                ],
                [
                    'key' => 'media',
                    'label' => 'Medien',
                    'value' => $mediaCount,
                    'suffix' => '',
                    'detail' => $avatarUrl !== '' ? 'Profilbild gesetzt' : 'Kein Profilbild',
                    'tone' => $mediaCount > 0 ? 'ok' : 'muted',
                ],
                [
                    'key' => 'risk',
                    'label' => 'Aktivitaetsrisiko',
                    'value' => $riskScore,
                    'suffix' => '',
                    'detail' => $activitySimulation === [] ? 'Kein Plan hinterlegt' : ($activityMetrics['planned_sessions'] ?? 0).' geplante Sessions',
                    'tone' => $riskScore >= 70 ? 'alert' : ($activitySimulation === [] ? 'muted' : 'ok'),
                ],
            ];

            $tabs = [
                'overview' => 'Uebersicht',
                'accounts' => 'Accounts',
                'ai' => 'AI-Profil',
                'activity' => 'Aktivitaeten',
                'processes' => 'Prozesse',
                'media' => 'Dateien & Bilder',
                'raw' => 'Rohdaten',
            ];
        @endphp

        <section class="ff-profile-hero" data-profile-hero>
            <div class="ff-profile-hero__glow" aria-hidden="true"></div>

            <div class="ff-profile-hero__inner">
                <div class="ff-profile-hero__identity">
                    <div class="ff-profile-hero__avatar" data-hero-avatar>
                        @if($avatarUrl !== '')
                            <img src="{{ $avatarUrl }}" alt="{{ $profileDetail['display_name'] }}">
                        @else
                            <span>{{ mb_strtoupper(mb_substr($profileDetail['label'] ?? 'P', 0, 1)) }}</span>
                        @endif
                        <span @class([
                            'ff-profile-hero__pulse',
                            'ff-profile-hero__pulse--on' => $profileDetail['is_active'] ?? false,
                        ])></span>
                    </div>

                    <div class="ff-profile-hero__text">
                        <p class="ff-profile-hero__eyebrow" data-hero-line>Persona</p>
                        <h1 class="ff-profile-hero__name" data-hero-line>{{ $profileDetail['display_name'] }}</h1>
                        <p class="ff-profile-hero__meta" data-hero-line>
                            {{ $profileDetail['person_alias'] ?: $profileDetail['label'] }}
                            <span aria-hidden="true">&middot;</span>
                            {{ $personRecord->person_city ?: 'Ort offen' }}
                            <span aria-hidden="true">&middot;</span>
                            {{ $personRecord->person_timezone ?: 'Zeitzone offen' }}
                        </p>

                        <div class="ff-profile-hero__chips" data-hero-line>
                            @if($profileDetail['is_primary'] ?? false)
                                <span class="ff-chip ff-chip--accent">Standard</span>
                            @endif
                            <span class="ff-chip ff-chip--{{ ($profileDetail['is_active'] ?? false) ? 'ok' : 'muted' }}">
                                {{ ($profileDetail['is_active'] ?? false) ? 'Aktiv' : 'Inaktiv' }}
                            </span>
                            <span class="ff-chip">Bot: {{ $botStatusLabel }}</span>
                            @if($personRecord->is_scrape_blocked)
                                <span class="ff-chip ff-chip--alert">
                                    Gesperrt bis {{ $personRecord->scrape_blocked_until?->format('d.m.Y H:i') }}
                                </span>
                            @endif
                        </div>

                        @if(($accountSummary['items'] ?? []) !== [])
                            <ul class="ff-profile-hero__accounts" data-hero-line>
                                @foreach($accountSummary['items'] as $account)
                                    <li wire:key="hero-account-{{ $account['type'] }}">
                                        <span class="ff-profile-hero__accountmark ff-profile-hero__accountmark--{{ $account['accent'] }}">
                                            {{ mb_strtoupper(mb_substr($account['label'], 0, 1)) }}
                                        </span>
                                        <span class="ff-profile-hero__accounthandle">{{ $account['handle'] ?: $account['label'] }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </div>

                <div class="ff-profile-hero__actions" data-hero-line>
                    <button
                        type="button"
                        wire:click="$dispatch('open-ai-complete-person-profile', { personId: {{ $personRecord->id }} })"
                        class="ff-btn ff-btn--primary ff-action-trigger--primary"
                    >
                        Profil bearbeiten
                    </button>
                    <button type="button" @click="tab = 'accounts'" class="ff-btn ff-btn--ghost">
                        Accounts oeffnen
                    </button>
                </div>
            </div>
        </section>

        <section class="ff-profile-metrics" data-profile-metrics aria-label="Kennzahlen der Person">
            @foreach($metrics as $metric)
                <article class="ff-metric ff-metric--{{ $metric['tone'] }}" wire:key="metric-{{ $metric['key'] }}">
                    <dl>
                        <dt>{{ $metric['label'] }}</dt>
                        <dd data-metric-value>{{ $metric['value'] }}<span class="ff-metric__suffix">{{ $metric['suffix'] }}</span></dd>
                    </dl>
                    <p class="ff-metric__detail">{{ $metric['detail'] }}</p>
                </article>
            @endforeach
        </section>

        <div class="ff-profile-tabs" role="tablist" aria-label="Profilbereiche">
            <div class="ff-profile-tabs__track">
                @foreach($tabs as $key => $label)
                    <button
                        type="button"
                        role="tab"
                        @click="tab = '{{ $key }}'"
                        :aria-selected="tab === '{{ $key }}' ? 'true' : 'false'"
                        :class="tab === '{{ $key }}' ? 'ff-profile-tab ff-profile-tab--active' : 'ff-profile-tab'"
                        class="ff-profile-tab"
                    >{{ $label }}</button>
                @endforeach
            </div>
        </div>

        <div class="ff-profile-panels">
            <div x-show="tab === 'overview'" class="ff-profile-panel space-y-6" data-profile-panel>
                <div class="grid gap-6 xl:grid-cols-2">
                    <x-admin.panel title="Stammdaten" class="ff-surface">
                        <dl class="ff-datagrid">
                            <div><dt>Vorname</dt><dd>{{ $personRecord->person_first_name ?: 'Nicht hinterlegt' }}</dd></div>
                            <div><dt>Nachname</dt><dd>{{ $personRecord->person_last_name ?: 'Nicht hinterlegt' }}</dd></div>
                            <div><dt>Alias</dt><dd>{{ $personRecord->person_alias ?: 'Nicht hinterlegt' }}</dd></div>
                            <div><dt>Geburtsdatum</dt><dd>{{ $personRecord->person_date_of_birth?->format('d.m.Y') ?: 'Nicht hinterlegt' }}</dd></div>
                            <div><dt>Geschlecht / Rolle</dt><dd>{{ $personRecord->person_gender ?: 'Nicht hinterlegt' }}</dd></div>
                            <div><dt>Nationalitaet</dt><dd>{{ data_get($identity, 'nationality') ?: 'Nicht hinterlegt' }}</dd></div>
                        </dl>
                    </x-admin.panel>

                    <x-admin.panel title="Kontakt und Adresse" class="ff-surface">
                        <dl class="ff-datagrid">
                            <div><dt>E-Mail</dt><dd class="break-all">{{ $personRecord->person_email ?: 'Nicht hinterlegt' }}</dd></div>
                            <div><dt>Telefon</dt><dd>{{ $personRecord->person_phone ?: 'Nicht hinterlegt' }}</dd></div>
                            <div><dt>Zeitzone</dt><dd>{{ $personRecord->person_timezone ?: 'Nicht hinterlegt' }}</dd></div>
                            <div><dt>PLZ / Ort</dt><dd>{{ trim(($personRecord->person_postal_code ?: '').' '.($personRecord->person_city ?: '')) ?: 'Nicht hinterlegt' }}</dd></div>
                            <div class="sm:col-span-2"><dt>Adresse</dt><dd>{{ trim(($personRecord->person_address_line1 ?: '').' '.($personRecord->person_address_line2 ?: '')) ?: 'Nicht hinterlegt' }}</dd></div>
                        </dl>
                    </x-admin.panel>
                </div>

                <x-admin.panel title="Technik und Status" class="ff-surface">
                    <dl class="ff-datagrid ff-datagrid--wide">
                        <div><dt>Plattform</dt><dd>{{ $personRecord->platform }}</dd></div>
                        <div><dt>Profile Key</dt><dd class="break-all">{{ $personRecord->profile_key }}</dd></div>
                        <div><dt>Base-Sync</dt><dd>{{ $personRecord->base_sync_status ?: 'pending' }}</dd></div>
                        <div><dt>Cookie Count</dt><dd>{{ $personRecord->cookie_count }}</dd></div>
                        <div><dt>Session Cookie</dt><dd>{{ $personRecord->session_cookie_present ? 'Vorhanden' : 'Nicht vorhanden' }}</dd></div>
                        <div><dt>Instagram-Sperre</dt><dd>{{ $personRecord->scrape_blocked_until?->format('d.m.Y H:i') ?: 'Keine aktive Sperre' }}</dd></div>
                    </dl>

                    @if($personRecord->person_notes)
                        <div class="ff-panel-note">
                            <h4>Notizen</h4>
                            <p>{{ $personRecord->person_notes }}</p>
                        </div>
                    @endif
                </x-admin.panel>
            </div>

            <div x-show="tab === 'accounts'" class="ff-profile-panel space-y-6" data-profile-panel>
                <livewire:admin.config.person-accounts
                    :person-id="$personRecord->id"
                    :key="'person-accounts-'.$personRecord->id" />

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

            <div x-show="tab === 'ai'" class="ff-profile-panel space-y-6" data-profile-panel>
                <x-admin.panel title="AI-Persona" description="Diese Felder steuern Kontext, Stil und Verhalten der Persona." class="ff-surface">
                    <x-slot name="actions">
                        <button type="button" wire:click="saveAiProfile" class="ff-btn ff-btn--primary">
                            Speichern
                        </button>
                    </x-slot>

                    <div class="grid gap-4 p-5 md:grid-cols-2">
                        <div>
                            <label class="ff-label">Nationalitaet</label>
                            <input type="text" wire:model.defer="aiNationality" class="ff-input">
                            @error('aiNationality') <p class="ff-error">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="ff-label">Beruf / Taetigkeit</label>
                            <input type="text" wire:model.defer="aiOccupation" class="ff-input">
                            @error('aiOccupation') <p class="ff-error">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="ff-label">Beziehungsstatus</label>
                            <input type="text" wire:model.defer="aiRelationshipStatus" class="ff-input">
                            @error('aiRelationshipStatus') <p class="ff-error">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="ff-label">Sprachen</label>
                            <textarea rows="3" wire:model.defer="aiLanguages" class="ff-input"></textarea>
                            @error('aiLanguages') <p class="ff-error">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="ff-label">Interessen</label>
                            <textarea rows="4" wire:model.defer="aiInterests" class="ff-input"></textarea>
                            @error('aiInterests') <p class="ff-error">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="ff-label">Persoenlichkeitsmerkmale</label>
                            <textarea rows="4" wire:model.defer="aiPersonalityTraits" class="ff-input"></textarea>
                            @error('aiPersonalityTraits') <p class="ff-error">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="ff-label">Werte und Ueberzeugungen</label>
                            <textarea rows="4" wire:model.defer="aiValues" class="ff-input"></textarea>
                            @error('aiValues') <p class="ff-error">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="ff-label">Kommunikationsstil</label>
                            <textarea rows="4" wire:model.defer="aiCommunicationStyle" class="ff-input"></textarea>
                            @error('aiCommunicationStyle') <p class="ff-error">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="ff-label">Schreibstil</label>
                            <textarea rows="4" wire:model.defer="aiWritingStyle" class="ff-input"></textarea>
                            @error('aiWritingStyle') <p class="ff-error">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="ff-label">Typischer Tagesablauf</label>
                            <textarea rows="4" wire:model.defer="aiDailyRoutine" class="ff-input"></textarea>
                            @error('aiDailyRoutine') <p class="ff-error">{{ $message }}</p> @enderror
                        </div>
                        <div class="md:col-span-2">
                            <label class="ff-label">Hintergrundgeschichte</label>
                            <textarea rows="5" wire:model.defer="aiBackgroundStory" class="ff-input"></textarea>
                            @error('aiBackgroundStory') <p class="ff-error">{{ $message }}</p> @enderror
                        </div>
                        <div class="md:col-span-2">
                            <label class="ff-label">Verhaltensrichtlinien fuer die AI</label>
                            <textarea rows="5" wire:model.defer="aiBehaviorGuidelines" class="ff-input"></textarea>
                            @error('aiBehaviorGuidelines') <p class="ff-error">{{ $message }}</p> @enderror
                        </div>
                    </div>
                </x-admin.panel>
            </div>

            <div x-show="tab === 'activity'" class="ff-profile-panel space-y-6" data-profile-panel>
                <x-admin.panel title="Interne Aktivitaeten" description="Sandbox-Plan fuer realistische Persona-Sessions ohne reale Plattformaktionen." class="ff-surface">
                    <x-slot name="actions">
                        @if($activitySimulation !== [])
                            <button type="button" wire:click="clearActivitySimulation" wire:confirm="Interne Aktivitaets-Simulation wirklich entfernen?" class="ff-btn ff-btn--danger">
                                Entfernen
                            </button>
                        @endif
                    </x-slot>

                    <div class="p-5">
                        <div class="grid gap-4 md:grid-cols-4">
                            <div>
                                <label class="ff-label">Tage</label>
                                <input type="number" min="1" max="14" wire:model.defer="activitySimulationDays" class="ff-input">
                                @error('activitySimulationDays') <p class="ff-error">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="ff-label">Intensitaet</label>
                                <select wire:model.defer="activitySimulationIntensity" class="ff-input">
                                    <option value="quiet">Ruhig</option>
                                    <option value="balanced">Ausgewogen</option>
                                    <option value="active">Aktiv</option>
                                    <option value="creator">Creator</option>
                                </select>
                                @error('activitySimulationIntensity') <p class="ff-error">{{ $message }}</p> @enderror
                            </div>
                            <div class="md:col-span-2">
                                <label class="ff-label">Seed</label>
                                <input type="text" wire:model.defer="activitySimulationSeed" placeholder="leer lassen fuer automatischen Seed" class="ff-input">
                                @error('activitySimulationSeed') <p class="ff-error">{{ $message }}</p> @enderror
                            </div>
                        </div>

                        <div class="mt-4 flex justify-end">
                            <button type="button" wire:click="generateActivitySimulation" class="ff-btn ff-btn--primary">
                                Aktivitaeten planen
                            </button>
                        </div>

                        @if($activitySimulation === [])
                            <div class="ff-emptystate">Noch kein interner Aktivitaetsplan gespeichert.</div>
                        @else
                            <div class="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                                <x-admin.stat label="Sessions" :value="$activityMetrics['planned_sessions'] ?? 0" />
                                <x-admin.stat label="Schritte" :value="$activityMetrics['planned_steps'] ?? 0" />
                                <x-admin.stat label="Content" :value="$activityMetrics['planned_posts'] ?? 0" />
                                <x-admin.stat label="Kommentare" :value="$activityMetrics['planned_comments'] ?? 0" />
                                <x-admin.stat label="Max. Risiko" :value="$activityMetrics['max_day_risk_score'] ?? 0" />
                            </div>

                            <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                                <p class="font-semibold">Interne Sandbox</p>
                                <p class="mt-1">Kein Login, keine Browser-Automation, keine externen Plattformaktionen. Status: {{ $activitySimulation['status'] ?? 'draft' }}.</p>
                            </div>

                            @if(!empty($activityProfile['content_themes']))
                                <div class="mt-4 flex flex-wrap gap-2">
                                    @foreach($activityProfile['content_themes'] as $theme)
                                        <span class="ff-chip">{{ $theme }}</span>
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
                                    <article class="rounded-xl border border-slate-200 bg-slate-50 p-4" wire:key="activity-day-{{ $day['date'] ?? $loop->index }}">
                                        <div class="flex flex-wrap items-start justify-between gap-3">
                                            <div>
                                                <h4 class="text-sm font-semibold text-slate-800">{{ $day['weekday'] ?? '' }}, {{ $day['date'] ?? '' }}</h4>
                                                <p class="mt-1 text-sm text-slate-600">{{ $day['anchor'] ?? '' }}</p>
                                            </div>
                                            <div class="flex flex-wrap gap-2">
                                                <span class="rounded-full bg-white px-2.5 py-1 text-xs font-semibold text-slate-700 ring-1 ring-slate-200">{{ $dayMetrics['sessions'] ?? 0 }} Sessions</span>
                                                <span class="rounded-full px-2.5 py-1 text-xs font-semibold ring-1 {{ $riskClass }}">Risiko {{ $dayMetrics['risk_score'] ?? 0 }}</span>
                                            </div>
                                        </div>

                                        <div class="mt-4 grid gap-3 lg:grid-cols-2">
                                            @foreach(array_slice($day['sessions'] ?? [], 0, 4) as $session)
                                                <div class="rounded-lg border border-slate-200 bg-white p-3">
                                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                                        <p class="text-sm font-semibold text-slate-800">{{ $session['starts_at_local'] ?? '' }} - {{ $session['session_type'] ?? 'session' }}</p>
                                                        <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600">{{ $session['duration_minutes'] ?? 0 }} Min.</span>
                                                    </div>
                                                    <p class="mt-1 text-sm text-slate-600">{{ $session['intent'] ?? '' }}</p>
                                                </div>
                                            @endforeach
                                        </div>
                                    </article>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </x-admin.panel>
            </div>

            <div x-show="tab === 'processes'" class="ff-profile-panel space-y-6" data-profile-panel>
                <livewire:admin.config.person-process-list :person-id="$personRecord->id" :key="'person-process-list-'.$personRecord->id" />
            </div>

            <div x-show="tab === 'media'" class="ff-profile-panel space-y-6" data-profile-panel>
                <x-admin.panel title="Profilbild" description="Avatar direkt auf der Person speichern oder entfernen." class="ff-surface">
                    <form wire:submit="uploadAvatar" class="flex flex-wrap items-end gap-3 p-5">
                        <div class="min-w-[260px] flex-1">
                            <input type="file" wire:model="avatarUpload" accept="image/*" class="block w-full rounded-lg border border-slate-300 bg-white text-sm text-slate-600 shadow-sm file:mr-4 file:border-0 file:bg-slate-100 file:px-4 file:py-2.5 file:text-sm file:font-semibold file:text-slate-700 hover:file:bg-slate-200">
                            @error('avatarUpload') <p class="ff-error">{{ $message }}</p> @enderror
                        </div>
                        <button type="submit" class="ff-btn ff-btn--primary">Speichern</button>
                        @if($avatarUrl !== '')
                            <button type="button" wire:click="deleteAvatar" wire:confirm="Profilbild wirklich loeschen?" class="ff-btn ff-btn--danger">Loeschen</button>
                        @endif
                    </form>
                </x-admin.panel>

                @livewire('tools.file-pools.manage-file-pools', ['modelType' => \App\Models\Person::class, 'modelId' => $personRecord->id, 'readOnly' => false], key('person-file-pool-'.$personRecord->id))

                <x-admin.panel title="Bilder" description="Profilbild und weitere Bilddateien koennen einzeln verwaltet werden." class="ff-surface">
                    <x-slot name="actions">
                        <button type="button" wire:click="$dispatch('open-person-image-modal', { personId: {{ $personRecord->id }} })" class="ff-btn ff-btn--primary">
                            Bilder erstellen
                        </button>
                    </x-slot>

                    <div class="p-5">
                        @if($imageFiles === [])
                            <div class="ff-emptystate">Keine weiteren Bilder vorhanden.</div>
                        @else
                            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                                @foreach($imageFiles as $imageFile)
                                    <article class="ff-mediacard" wire:key="person-image-{{ $imageFile['id'] }}">
                                        @if(($imageFile['url'] ?? '') !== '')
                                            <img src="{{ $imageFile['url'] }}" alt="{{ $imageFile['name'] }}" class="aspect-square w-full object-cover">
                                        @else
                                            <div class="flex aspect-square w-full items-center justify-center bg-slate-100 text-sm text-slate-500">Kein Vorschaubild</div>
                                        @endif

                                        <div class="space-y-3 p-3">
                                            <div>
                                                <p class="truncate text-sm font-semibold text-slate-800">{{ $imageFile['name'] }}</p>
                                                <p class="mt-1 text-xs text-slate-500">{{ $imageFile['type'] }}{{ ($imageFile['size'] ?? '') !== '' ? ' - '.$imageFile['size'] : '' }}</p>
                                            </div>
                                            <div class="flex flex-wrap gap-2">
                                                @if(($imageFile['url'] ?? '') !== '')
                                                    <a href="{{ $imageFile['url'] }}" target="_blank" rel="noopener" class="ff-btn ff-btn--small">Oeffnen</a>
                                                @endif
                                                <button type="button" wire:click="useImageAsAvatar({{ $imageFile['id'] }})" class="ff-btn ff-btn--small ff-btn--accent">Als Profilbild</button>
                                                <button type="button" wire:click="deleteImageFile({{ $imageFile['id'] }})" wire:confirm="Dieses Bild wirklich loeschen?" class="ff-btn ff-btn--small ff-btn--danger">Loeschen</button>
                                            </div>
                                        </div>
                                    </article>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </x-admin.panel>
            </div>

            <div x-show="tab === 'raw'" class="ff-profile-panel" data-profile-panel>
                <x-admin.panel title="Rohdaten" description="Vollstaendige gespeicherte Personendaten fuer technische Pruefung und Prompting." class="ff-surface">
                    <div class="p-5">
                        <pre class="overflow-x-auto rounded-lg bg-slate-900 p-4 text-xs leading-relaxed text-slate-100">{{ json_encode($personRecord->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                    </div>
                </x-admin.panel>
            </div>
        </div>
    @endif

    <x-ui.dialog-modal wire:model="showProfileModal" maxWidth="2xl">
        <x-slot name="title">
            Person bearbeiten
        </x-slot>

        <x-slot name="content">
            <div class="space-y-6">
                <div class="rounded-lg border border-slate-200 bg-slate-50 p-4">
                    <h3 class="text-base font-semibold text-slate-800">Personendaten</h3>
                    <p class="mt-1 text-sm text-slate-500">
                        Diese Daten behandeln die Person als eigene Persona und koennen spaeter fuer Bot-Automation genutzt werden.
                    </p>
                    <div class="mt-4 grid gap-4 md:grid-cols-2">
                        <div>
                            <label for="edit-person-first-name" class="block text-sm font-medium text-slate-700">Vorname</label>
                            <input id="edit-person-first-name" type="text" wire:model.defer="personFirstName" class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 shadow-sm focus:border-primary-base focus:ring-2 focus:ring-primary-base/30">
                            @error('personFirstName') <p class="mt-1.5 text-sm text-red-500">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="edit-person-last-name" class="block text-sm font-medium text-slate-700">Nachname</label>
                            <input id="edit-person-last-name" type="text" wire:model.defer="personLastName" class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 shadow-sm focus:border-primary-base focus:ring-2 focus:ring-primary-base/30">
                            @error('personLastName') <p class="mt-1.5 text-sm text-red-500">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="edit-person-alias" class="block text-sm font-medium text-slate-700">Alias / Persona-Name</label>
                            <input id="edit-person-alias" type="text" wire:model.defer="personAlias" class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 shadow-sm focus:border-primary-base focus:ring-2 focus:ring-primary-base/30">
                            @error('personAlias') <p class="mt-1.5 text-sm text-red-500">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="edit-person-date-of-birth" class="block text-sm font-medium text-slate-700">Geburtsdatum</label>
                            <input id="edit-person-date-of-birth" type="date" wire:model.defer="personDateOfBirth" class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 shadow-sm focus:border-primary-base focus:ring-2 focus:ring-primary-base/30">
                            @error('personDateOfBirth') <p class="mt-1.5 text-sm text-red-500">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="edit-person-gender" class="block text-sm font-medium text-slate-700">Geschlecht / Rolle</label>
                            <input id="edit-person-gender" type="text" wire:model.defer="personGender" class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 shadow-sm focus:border-primary-base focus:ring-2 focus:ring-primary-base/30">
                            @error('personGender') <p class="mt-1.5 text-sm text-red-500">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="edit-bot-status" class="block text-sm font-medium text-slate-700">Bot-Status</label>
                            <select id="edit-bot-status" wire:model.defer="botStatus" class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 shadow-sm focus:border-primary-base focus:ring-2 focus:ring-primary-base/30">
                                <option value="manual">Manuell</option>
                                <option value="ready">Bereit fuer Automation</option>
                                <option value="training">Training</option>
                                <option value="disabled">Deaktiviert</option>
                            </select>
                            @error('botStatus') <p class="mt-1.5 text-sm text-red-500">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="edit-person-city" class="block text-sm font-medium text-slate-700">Stadt</label>
                            <input id="edit-person-city" type="text" wire:model.defer="personCity" class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 shadow-sm focus:border-primary-base focus:ring-2 focus:ring-primary-base/30">
                            @error('personCity') <p class="mt-1.5 text-sm text-red-500">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="edit-person-country" class="block text-sm font-medium text-slate-700">Land</label>
                            <input id="edit-person-country" type="text" wire:model.defer="personCountry" class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 shadow-sm focus:border-primary-base focus:ring-2 focus:ring-primary-base/30">
                            @error('personCountry') <p class="mt-1.5 text-sm text-red-500">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="edit-person-email" class="block text-sm font-medium text-slate-700">Persona-E-Mail</label>
                            <input id="edit-person-email" type="email" wire:model.defer="personEmail" class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 shadow-sm focus:border-primary-base focus:ring-2 focus:ring-primary-base/30">
                            @error('personEmail') <p class="mt-1.5 text-sm text-red-500">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="edit-person-phone" class="block text-sm font-medium text-slate-700">Telefon</label>
                            <input id="edit-person-phone" type="text" wire:model.defer="personPhone" class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 shadow-sm focus:border-primary-base focus:ring-2 focus:ring-primary-base/30">
                            @error('personPhone') <p class="mt-1.5 text-sm text-red-500">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="edit-person-address-line1" class="block text-sm font-medium text-slate-700">Strasse und Hausnummer</label>
                            <input id="edit-person-address-line1" type="text" wire:model.defer="personAddressLine1" class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 shadow-sm focus:border-primary-base focus:ring-2 focus:ring-primary-base/30">
                            @error('personAddressLine1') <p class="mt-1.5 text-sm text-red-500">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="edit-person-address-line2" class="block text-sm font-medium text-slate-700">Adresszusatz</label>
                            <input id="edit-person-address-line2" type="text" wire:model.defer="personAddressLine2" class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 shadow-sm focus:border-primary-base focus:ring-2 focus:ring-primary-base/30">
                            @error('personAddressLine2') <p class="mt-1.5 text-sm text-red-500">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="edit-person-postal-code" class="block text-sm font-medium text-slate-700">Postleitzahl</label>
                            <input id="edit-person-postal-code" type="text" wire:model.defer="personPostalCode" class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 shadow-sm focus:border-primary-base focus:ring-2 focus:ring-primary-base/30">
                            @error('personPostalCode') <p class="mt-1.5 text-sm text-red-500">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="edit-person-state" class="block text-sm font-medium text-slate-700">Bundesland / Region</label>
                            <input id="edit-person-state" type="text" wire:model.defer="personState" class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 shadow-sm focus:border-primary-base focus:ring-2 focus:ring-primary-base/30">
                            @error('personState') <p class="mt-1.5 text-sm text-red-500">{{ $message }}</p> @enderror
                        </div>
                        <div class="md:col-span-2">
                            <label for="edit-person-timezone" class="block text-sm font-medium text-slate-700">Zeitzone</label>
                            <input id="edit-person-timezone" type="text" wire:model.defer="personTimezone" placeholder="Europe/Berlin" class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 shadow-sm focus:border-primary-base focus:ring-2 focus:ring-primary-base/30">
                            @error('personTimezone') <p class="mt-1.5 text-sm text-red-500">{{ $message }}</p> @enderror
                        </div>
                        <div class="md:col-span-2">
                            <label for="edit-person-notes" class="block text-sm font-medium text-slate-700">Notizen / Bot-Kontext</label>
                            <textarea id="edit-person-notes" rows="3" wire:model.defer="personNotes" class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 shadow-sm focus:border-primary-base focus:ring-2 focus:ring-primary-base/30"></textarea>
                            @error('personNotes') <p class="mt-1.5 text-sm text-red-500">{{ $message }}</p> @enderror
                        </div>
                    </div>
                </div>

                <div class="grid gap-6 lg:grid-cols-2">
                <div class="space-y-4">
                    <h3 class="text-base font-semibold text-slate-800">Profil und Session</h3>

                    <div>
                        <label for="edit-profile-label" class="block text-sm font-medium text-slate-700">Profilname</label>
                        <input id="edit-profile-label" type="text" wire:model.defer="profileLabel" class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 shadow-sm focus:border-primary-base focus:ring-2 focus:ring-primary-base/30">
                        @error('profileLabel')
                            <p class="mt-1.5 text-sm text-red-500">{{ $message }}</p>
                        @enderror
                    </div>

                    <label for="edit-persistent-profile-enabled" class="flex items-center gap-3 rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm font-medium text-slate-700">
                        <input id="edit-persistent-profile-enabled" type="checkbox" wire:model.defer="persistentProfileEnabled" class="h-4 w-4 rounded border-slate-300 text-primary-base focus:ring-primary-base/40">
                        Persistentes Browser-Profil verwenden
                    </label>

                    <div>
                        <label for="edit-browser-profile-path" class="block text-sm font-medium text-slate-700">Profilpfad</label>
                        <input id="edit-browser-profile-path" type="text" wire:model.defer="browserProfilePath" class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 shadow-sm focus:border-primary-base focus:ring-2 focus:ring-primary-base/30">
                        <p class="mt-1 text-xs text-slate-500">Relativer Pfad innerhalb von `storage/app` oder ein absoluter Pfad.</p>
                        @error('browserProfilePath')
                            <p class="mt-1.5 text-sm text-red-500">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="edit-cookie-file-path" class="block text-sm font-medium text-slate-700">Cookie-Datei</label>
                        <input id="edit-cookie-file-path" type="text" wire:model.defer="cookieFilePath" class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 shadow-sm focus:border-primary-base focus:ring-2 focus:ring-primary-base/30">
                        <p class="mt-1 text-xs text-slate-500">Wird nach erfolgreichem Login automatisch aktualisiert.</p>
                        @error('cookieFilePath')
                            <p class="mt-1.5 text-sm text-red-500">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                <div class="space-y-4">
                    <h3 class="text-base font-semibold text-slate-800">Auto-Login</h3>

                    <label for="edit-auto-login-enabled" class="flex items-center gap-3 rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm font-medium text-slate-700">
                        <input id="edit-auto-login-enabled" type="checkbox" wire:model.defer="autoLoginEnabled" class="h-4 w-4 rounded border-slate-300 text-primary-base focus:ring-primary-base/40">
                        Automatischen Instagram-Login erlauben
                    </label>

                    <div>
                        <label for="edit-login-username" class="block text-sm font-medium text-slate-700">Instagram-Benutzername</label>
                        <input id="edit-login-username" type="text" wire:model.defer="loginUsername" class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 shadow-sm focus:border-primary-base focus:ring-2 focus:ring-primary-base/30">
                        @error('loginUsername')
                            <p class="mt-1.5 text-sm text-red-500">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="edit-login-password" class="block text-sm font-medium text-slate-700">Instagram-Passwort</label>
                        <input id="edit-login-password" type="password" wire:model.defer="loginPassword" autocomplete="new-password" class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 shadow-sm focus:border-primary-base focus:ring-2 focus:ring-primary-base/30">
                        <div class="mt-2 flex items-center justify-between gap-3 text-xs text-slate-500">
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
                            <p class="mt-1.5 text-sm text-red-500">{{ $message }}</p>
                        @enderror
                    </div>
                </div>
            </div>
            </div>
        </x-slot>

        <x-slot name="footer">
            <div class="flex justify-end gap-3">
                <button type="button" wire:click="closeProfileModal" class="rounded-lg border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50">
                    Abbrechen
                </button>
                <button type="button" wire:click="saveProfile" class="rounded-lg bg-primary-base px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-[#274d86]">
                    Account speichern
                </button>
            </div>
        </x-slot>
    </x-ui.dialog-modal>

    <x-ui.dialog-modal wire:model="showRuntimeSettingsModal" maxWidth="2xl">
        <x-slot name="title">
            Timeouts und Listen
        </x-slot>

        <x-slot name="content">
            <div class="space-y-6">
                <div class="grid gap-4 md:grid-cols-3">
                    <div>
                        <label for="runtime-navigation-timeout" class="block text-sm font-medium text-slate-700">Navigation-Timeout in Sekunden</label>
                        <input id="runtime-navigation-timeout" type="number" min="30" max="300" wire:model.defer="navigationTimeoutSeconds" class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 shadow-sm focus:border-primary-base focus:ring-2 focus:ring-primary-base/30">
                        @error('navigationTimeoutSeconds')
                            <p class="mt-1.5 text-sm text-red-500">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="runtime-post-login-wait" class="block text-sm font-medium text-slate-700">Wartezeit nach Login in Millisekunden</label>
                        <input id="runtime-post-login-wait" type="number" min="500" max="15000" wire:model.defer="postLoginWaitMs" class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 shadow-sm focus:border-primary-base focus:ring-2 focus:ring-primary-base/30">
                        @error('postLoginWaitMs')
                            <p class="mt-1.5 text-sm text-red-500">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="runtime-typing-delay" class="block text-sm font-medium text-slate-700">Tippverzoegerung in Millisekunden</label>
                        <input id="runtime-typing-delay" type="number" min="0" max="500" wire:model.defer="typingDelayMs" class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 shadow-sm focus:border-primary-base focus:ring-2 focus:ring-primary-base/30">
                        @error('typingDelayMs')
                            <p class="mt-1.5 text-sm text-red-500">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                <div>
                    <h3 class="text-base font-semibold text-slate-800">Follower- und Gefolgt-Listen</h3>
                    <p class="mt-1 text-sm text-slate-500">
                        Ein Limit von 0 bedeutet: alle von Instagram ladbaren Eintraege speichern. Die Scroll-Runden sind nur eine technische Sicherung gegen Endlosschleifen.
                    </p>

                    <div class="mt-4 grid gap-4 md:grid-cols-2">
                        <div>
                            <label for="runtime-relationship-list-process-timeout" class="block text-sm font-medium text-slate-700">Listen-Timeout in Sekunden</label>
                            <input id="runtime-relationship-list-process-timeout" type="number" min="14400" max="21600" wire:model.defer="relationshipListProcessTimeoutSeconds" class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 shadow-sm focus:border-primary-base focus:ring-2 focus:ring-primary-base/30">
                            @error('relationshipListProcessTimeoutSeconds')
                                <p class="mt-1.5 text-sm text-red-500">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="runtime-relationship-list-max-scroll-rounds" class="block text-sm font-medium text-slate-700">Maximale Scroll-Runden</label>
                            <input id="runtime-relationship-list-max-scroll-rounds" type="number" min="20" max="1000000" wire:model.defer="relationshipListMaxScrollRounds" class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 shadow-sm focus:border-primary-base focus:ring-2 focus:ring-primary-base/30">
                            @error('relationshipListMaxScrollRounds')
                                <p class="mt-1.5 text-sm text-red-500">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="runtime-follower-list-max-items" class="block text-sm font-medium text-slate-700">Follower-Limit</label>
                            <input id="runtime-follower-list-max-items" type="number" min="0" max="1000000" wire:model.defer="followerListMaxItems" class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 shadow-sm focus:border-primary-base focus:ring-2 focus:ring-primary-base/30">
                            @error('followerListMaxItems')
                                <p class="mt-1.5 text-sm text-red-500">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="runtime-following-list-max-items" class="block text-sm font-medium text-slate-700">Gefolgt-Limit</label>
                            <input id="runtime-following-list-max-items" type="number" min="0" max="1000000" wire:model.defer="followingListMaxItems" class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 shadow-sm focus:border-primary-base focus:ring-2 focus:ring-primary-base/30">
                            @error('followingListMaxItems')
                                <p class="mt-1.5 text-sm text-red-500">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                </div>
            </div>
        </x-slot>

        <x-slot name="footer">
            <div class="flex justify-end gap-3">
                <button type="button" wire:click="closeRuntimeSettingsModal" class="rounded-lg border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50">
                    Abbrechen
                </button>
                <button type="button" wire:click="saveRuntimeSettings" class="rounded-lg bg-primary-base px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-[#274d86]">
                    Einstellungen speichern
                </button>
            </div>
        </x-slot>
    </x-ui.dialog-modal>

    @livewire('admin.persons.ai-complete-person-profile-modal')
    @livewire('admin.persons.generate-person-images-modal')
</div>
