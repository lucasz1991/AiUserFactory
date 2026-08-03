{{--
    Netzwerk -> Automatisierung.

    Zeitplaene, globale Grenzen und Not-Aus. Der Not-Aus schaltet ausschliesslich
    die Ausfuehrung ab — kein Zeitplan wird dabei geloescht oder veraendert.
--}}
<div class="ff-person-profile" data-automation-index x-data="{ tab: @entangle('activeTab') }">
    <section class="ff-profile-hero">
        <div class="ff-profile-hero__glow" aria-hidden="true"></div>
        <div class="ff-profile-hero__inner">
            <div class="ff-profile-hero__text">
                <p class="ff-profile-hero__eyebrow">Netzwerk</p>
                <h1 class="ff-profile-hero__name">Automatisierung</h1>
                <p class="ff-profile-hero__meta">
                    Zeitgesteuerte Workflows je Person. Zeitfenster und Tagesdeckel gelten in der Ortszeit der Person.
                </p>
                <div class="ff-profile-hero__chips">
                    <span class="ff-chip ff-chip--{{ $enabled ? 'ok' : 'alert' }}">
                        {{ $enabled ? 'Automatisierung laeuft' : 'Not-Aus aktiv' }}
                    </span>
                    <span class="ff-chip">{{ $stats['active'] }} aktive Zeitplaene</span>
                    <span class="ff-chip">{{ $stats['due'] }} faellig</span>
                </div>
            </div>
            <div class="ff-profile-hero__actions">
                <button type="button" wire:click="toggleEmergencyStop" class="ff-btn {{ $enabled ? 'ff-btn--danger' : 'ff-btn--primary' }}">
                    {{ $enabled ? 'Not-Aus ausloesen' : 'Automatisierung einschalten' }}
                </button>
                <button type="button" wire:click="runDispatcherNow" wire:loading.attr="disabled" class="ff-btn ff-btn--ghost">
                    Jetzt pruefen
                </button>
            </div>
        </div>
    </section>

    <section class="ff-profile-metrics" aria-label="Kennzahlen der Automatisierung">
        <article class="ff-metric ff-metric--{{ $enabled ? 'ok' : 'alert' }}">
            <dl><dt>Zustand</dt><dd>{{ $enabled ? 'Aktiv' : 'Gestoppt' }}</dd></dl>
            <p class="ff-metric__detail">{{ $enabled ? 'Der Dispatcher startet faellige Laeufe.' : 'Es wird nichts gestartet.' }}</p>
        </article>
        <article class="ff-metric ff-metric--ok">
            <dl><dt>Zeitplaene</dt><dd>{{ $stats['total'] }}</dd></dl>
            <p class="ff-metric__detail">{{ $stats['active'] }} aktiv, {{ $stats['paused'] }} pausiert</p>
        </article>
        <article class="ff-metric ff-metric--{{ $stats['due'] > 0 ? 'warn' : 'muted' }}">
            <dl><dt>Faellig</dt><dd>{{ $stats['due'] }}</dd></dl>
            <p class="ff-metric__detail">Warten auf den naechsten Durchlauf</p>
        </article>
        <article class="ff-metric ff-metric--muted">
            <dl><dt>Gleichzeitig</dt><dd>{{ $maxConcurrentRuns }}</dd></dl>
            <p class="ff-metric__detail">Globale Obergrenze</p>
        </article>
    </section>

    <div class="ff-profile-tabs" role="tablist" aria-label="Bereiche" x-init="$root.dataset.tabsReady = '1'">
        <div class="ff-profile-tabs__track">
            <button type="button" role="tab" @click="tab = 'schedules'"
                :class="tab === 'schedules' ? 'ff-profile-tab ff-profile-tab--active' : 'ff-profile-tab'"
                class="ff-profile-tab">Zeitplaene</button>
            <button type="button" role="tab" @click="tab = 'limits'"
                :class="tab === 'limits' ? 'ff-profile-tab ff-profile-tab--active' : 'ff-profile-tab'"
                class="ff-profile-tab">Grenzen &amp; Not-Aus</button>
        </div>
    </div>

    <div class="ff-profile-panels">
        <div x-show="tab === 'schedules'" class="ff-profile-panel space-y-6" data-profile-panel>
            <div class="ff-accounts">
                <div class="ff-accounts__summary">
                    <div>
                        <p class="ff-accounts__eyebrow">Zeitplaene</p>
                        <h3 class="ff-accounts__title">Person x Workflow x Zeitregel</h3>
                        <p class="ff-accounts__hint">
                            Jeder Eintrag startet einen vorhandenen Workflow fuer genau eine Person. Browsergebundene
                            Workflows laufen je Person immer exklusiv, damit sich zwei Laeufe nicht die Session ueberschreiben.
                        </p>
                    </div>
                    <div class="ff-account-card__actions" style="margin-top:0">
                        <button type="button" wire:click="openBulkModal" class="ff-btn">Massenzuweisung</button>
                        <button type="button" wire:click="newSchedule" class="ff-btn ff-btn--primary" data-magnetic>Zeitplan anlegen</button>
                    </div>
                </div>

                <div class="p-5">
                    <div class="grid gap-3 sm:grid-cols-3">
                        <div>
                            <label class="ff-label">Person</label>
                            <select wire:model.live="filterPerson" class="ff-input">
                                <option value="">Alle</option>
                                @foreach($persons as $person)
                                    <option value="{{ $person->id }}">{{ $person->display_name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="ff-label">Workflow</label>
                            <select wire:model.live="filterWorkflow" class="ff-input">
                                <option value="">Alle</option>
                                @foreach($workflows as $workflow)
                                    <option value="{{ $workflow->id }}">{{ $workflow->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="ff-label">Zustand</label>
                            <select wire:model.live="filterState" class="ff-input">
                                <option value="all">Alle</option>
                                <option value="active">Nur aktive</option>
                                <option value="paused">Nur pausierte</option>
                            </select>
                        </div>
                    </div>

                    @if($schedules->isEmpty())
                        <div class="ff-emptystate">
                            Noch keine Zeitplaene. Lege einen an oder weise einen Workflow per Massenzuweisung mehreren Personen zu.
                        </div>
                    @else
                        <div class="mt-5 overflow-x-auto">
                            <table class="ff-table">
                                <thead>
                                    <tr>
                                        <th>Person</th>
                                        <th>Workflow</th>
                                        <th>Takt</th>
                                        <th>Naechster Lauf</th>
                                        <th>Letzter Lauf</th>
                                        <th>Hinweis</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($schedules as $schedule)
                                        <tr wire:key="schedule-{{ $schedule->id }}">
                                            <td>
                                                <span class="ff-table__strong">{{ $schedule->person?->display_name ?? 'Unbekannt' }}</span>
                                                <span class="ff-table__sub">{{ $schedule->person?->automation_timezone }}</span>
                                            </td>
                                            <td>
                                                <span class="ff-table__strong">{{ $schedule->workflow?->name ?? 'Unbekannt' }}</span>
                                                @if($browserBound[$schedule->workflow_id] ?? false)
                                                    <span class="ff-badge ff-badge--warn">Browser exklusiv</span>
                                                @else
                                                    <span class="ff-badge ff-badge--muted">Ohne Browser</span>
                                                @endif
                                            </td>
                                            <td>
                                                <span class="ff-table__strong">{{ $schedule->cadence_label }}</span>
                                                <span class="ff-table__sub">
                                                    @if($schedule->cadence_type === 'interval')
                                                        alle {{ $schedule->interval_minutes }} Min.
                                                    @elseif($schedule->cadence_type === 'daily_times')
                                                        {{ implode(', ', $schedule->daily_times ?? []) }}
                                                    @else
                                                        {{ implode(', ', $schedule->activity_plan_session_types ?? []) ?: 'alle Sessions' }}
                                                    @endif
                                                    @if($schedule->window_start && $schedule->window_end)
                                                        &middot; {{ substr((string) $schedule->window_start, 0, 5) }}–{{ substr((string) $schedule->window_end, 0, 5) }}
                                                    @endif
                                                </span>
                                            </td>
                                            <td>{{ $schedule->next_run_at?->format('d.m.Y H:i') ?: '—' }}</td>
                                            <td>
                                                {{ $schedule->last_run_at?->format('d.m.Y H:i') ?: 'Noch nie' }}
                                                @if($schedule->lastWorkflowRun)
                                                    <span class="ff-table__sub">{{ $schedule->lastWorkflowRun->status }}</span>
                                                @endif
                                            </td>
                                            <td>
                                                @if($schedule->last_skip_reason)
                                                    <span class="ff-table__sub">{{ $schedule->last_skip_reason }}</span>
                                                @elseif($schedule->consecutive_failures > 0)
                                                    <span class="ff-badge ff-badge--alert">{{ $schedule->consecutive_failures }} Fehler</span>
                                                @else
                                                    <span class="ff-table__sub">—</span>
                                                @endif
                                            </td>
                                            <td>
                                                <div class="flex flex-wrap justify-end gap-2">
                                                    <button type="button" wire:click="toggleSchedule({{ $schedule->id }})" class="ff-btn ff-btn--small">
                                                        {{ $schedule->is_active ? 'Pausieren' : 'Aktivieren' }}
                                                    </button>
                                                    <button type="button" wire:click="editSchedule({{ $schedule->id }})" class="ff-btn ff-btn--small">Bearbeiten</button>
                                                    <button type="button" wire:click="deleteSchedule({{ $schedule->id }})"
                                                        wire:confirm="Diesen Zeitplan wirklich entfernen?"
                                                        class="ff-btn ff-btn--small ff-btn--danger">Entfernen</button>
                                                </div>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>
        </div>

        <div x-show="tab === 'limits'" class="ff-profile-panel space-y-6" data-profile-panel>
            <x-admin.panel title="Grenzen und Not-Aus" description="Wirken zusaetzlich zu den Einzelschaltern der Zeitplaene." class="ff-surface">
                <div class="p-5">
                    <label class="flex items-center gap-3 rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm font-medium text-slate-700">
                        <input type="checkbox" wire:model.defer="enabled" class="h-4 w-4 rounded border-slate-300">
                        Automatisierung eingeschaltet — ohne diesen Haken startet der Dispatcher nichts.
                    </label>

                    <div class="mt-4 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        <div>
                            <label class="ff-label">Gleichzeitige Laeufe insgesamt</label>
                            <input type="number" min="1" max="200" wire:model.defer="maxConcurrentRuns" class="ff-input">
                            @error('maxConcurrentRuns') <p class="ff-error">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="ff-label">Starts je Durchlauf</label>
                            <input type="number" min="1" max="100" wire:model.defer="maxStartsPerTick" class="ff-input">
                            @error('maxStartsPerTick') <p class="ff-error">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="ff-label">Laeufe je Person und Tag (0 = unbegrenzt)</label>
                            <input type="number" min="0" max="500" wire:model.defer="maxRunsPerPersonPerDay" class="ff-input">
                            @error('maxRunsPerPersonPerDay') <p class="ff-error">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="ff-label">Pausieren nach Fehlern in Folge (0 = nie)</label>
                            <input type="number" min="0" max="50" wire:model.defer="pauseAfterFailures" class="ff-input">
                            @error('pauseAfterFailures') <p class="ff-error">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="ff-label">Grundwert des Backoffs in Minuten</label>
                            <input type="number" min="1" max="1440" wire:model.defer="failureBackoffMinutes" class="ff-input">
                            @error('failureBackoffMinutes') <p class="ff-error">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div class="mt-5 flex justify-end">
                        <button type="button" wire:click="saveLimits" class="ff-btn ff-btn--primary">Grenzen speichern</button>
                    </div>

                    <div class="ff-account-card__notice" style="margin-top:1.25rem">
                        Der Dispatcher haengt am Laravel-Scheduler. Ohne einen Cron, der jede Minute
                        <code>php artisan schedule:run</code> ausfuehrt, und ohne laufenden Queue-Worker startet nichts.
                    </div>
                </div>
            </x-admin.panel>
        </div>
    </div>

    {{-- Zeitplan-Dialog --}}
    <x-ui.dialog-modal wire:model="showScheduleModal" maxWidth="2xl">
        <x-slot name="title">{{ $editingScheduleId ? 'Zeitplan bearbeiten' : 'Zeitplan anlegen' }}</x-slot>
        <x-slot name="content">
            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <label class="ff-label">Person</label>
                    <select wire:model.defer="formPersonId" class="ff-input">
                        <option value="">Bitte waehlen</option>
                        @foreach($persons as $person)
                            <option value="{{ $person->id }}">{{ $person->display_name }}</option>
                        @endforeach
                    </select>
                    @error('formPersonId') <p class="ff-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="ff-label">Workflow</label>
                    <select wire:model.defer="formWorkflowId" class="ff-input">
                        <option value="">Bitte waehlen</option>
                        @foreach($workflows as $workflow)
                            <option value="{{ $workflow->id }}">{{ $workflow->name }}{{ $workflow->is_active ? '' : ' (deaktiviert)' }}</option>
                        @endforeach
                    </select>
                    @error('formWorkflowId') <p class="ff-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="ff-label">Bezeichnung</label>
                    <input type="text" wire:model.defer="formLabel" placeholder="morgens" class="ff-input">
                </div>
                <div>
                    <label class="ff-label">Takt</label>
                    <select wire:model.live="formCadence" class="ff-input">
                        @foreach($cadences as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                @if($formCadence === 'interval')
                    <div>
                        <label class="ff-label">Intervall in Minuten</label>
                        <input type="number" min="5" max="20160" wire:model.defer="formIntervalMinutes" class="ff-input">
                        @error('formIntervalMinutes') <p class="ff-error">{{ $message }}</p> @enderror
                    </div>
                @elseif($formCadence === 'daily_times')
                    <div>
                        <label class="ff-label">Uhrzeiten (durch Komma getrennt)</label>
                        <input type="text" wire:model.defer="formDailyTimes" placeholder="08:00, 13:30, 19:45" class="ff-input">
                    </div>
                @else
                    <div>
                        <label class="ff-label">Session-Typen aus dem Aktivitaetsplan (leer = alle)</label>
                        <input type="text" wire:model.defer="formActivitySessionTypes" placeholder="morning_scroll, evening_post" class="ff-input">
                    </div>
                @endif

                <div>
                    <label class="ff-label">Zeitfenster Beginn (Ortszeit)</label>
                    <input type="time" wire:model.defer="formWindowStart" class="ff-input">
                </div>
                <div>
                    <label class="ff-label">Zeitfenster Ende (Ortszeit)</label>
                    <input type="time" wire:model.defer="formWindowEnd" class="ff-input">
                </div>
                <div>
                    <label class="ff-label">Streuung in Sekunden</label>
                    <input type="number" min="0" max="7200" wire:model.defer="formJitterSeconds" class="ff-input">
                    <p class="mt-1 text-xs text-slate-500">Verhindert, dass alle Personas gleichzeitig starten.</p>
                </div>
                <div>
                    <label class="ff-label">Hoechstens Laeufe pro Tag</label>
                    <input type="number" min="1" max="500" wire:model.defer="formMaxRunsPerDay" placeholder="unbegrenzt" class="ff-input">
                </div>
                <div>
                    <label class="ff-label">Mindestabstand in Minuten</label>
                    <input type="number" min="0" max="20160" wire:model.defer="formMinGapMinutes" class="ff-input">
                </div>
                <div>
                    <label class="ff-label">Prioritaet (kleiner zuerst)</label>
                    <input type="number" min="-100" max="100" wire:model.defer="formPriority" class="ff-input">
                </div>

                <div class="md:col-span-2">
                    <label class="ff-label">Wochentage (keine Auswahl = alle)</label>
                    <div class="mt-2 flex flex-wrap gap-3">
                        @foreach(['1' => 'Mo', '2' => 'Di', '3' => 'Mi', '4' => 'Do', '5' => 'Fr', '6' => 'Sa', '7' => 'So'] as $value => $label)
                            <label class="inline-flex items-center gap-2 text-sm">
                                <input type="checkbox" value="{{ $value }}" wire:model.defer="formWeekdays" class="h-4 w-4 rounded border-slate-300">
                                {{ $label }}
                            </label>
                        @endforeach
                    </div>
                </div>

                <div class="md:col-span-2">
                    <label class="ff-label">Zusaetzlicher Kontext als JSON (optional)</label>
                    <textarea rows="3" wire:model.defer="formContext" placeholder='{"search_term": "kaffee"}' class="ff-input"></textarea>
                    <p class="mt-1 text-xs text-slate-500">
                        Zugangsdaten werden hier nicht gespeichert. Passwortartige Schluessel und ein abweichendes
                        <code>person_id</code> werden beim Speichern verworfen.
                    </p>
                </div>

                <div class="md:col-span-2">
                    <label class="flex items-center gap-3 rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm font-medium text-slate-700">
                        <input type="checkbox" wire:model.defer="formIsActive" class="h-4 w-4 rounded border-slate-300">
                        Zeitplan aktiv
                    </label>
                </div>
            </div>
        </x-slot>
        <x-slot name="footer">
            <div class="flex justify-end gap-3">
                <button type="button" wire:click="$set('showScheduleModal', false)" class="ff-btn">Abbrechen</button>
                <button type="button" wire:click="saveSchedule" class="ff-btn ff-btn--primary">Speichern</button>
            </div>
        </x-slot>
    </x-ui.dialog-modal>

    {{-- Massenzuweisung --}}
    <x-ui.dialog-modal wire:model="showBulkModal" maxWidth="xl">
        <x-slot name="title">Workflow mehreren Personen zuweisen</x-slot>
        <x-slot name="content">
            <div class="grid gap-4 md:grid-cols-2">
                <div class="md:col-span-2">
                    <label class="ff-label">Workflow</label>
                    <select wire:model.defer="bulkWorkflowId" class="ff-input">
                        <option value="">Bitte waehlen</option>
                        @foreach($workflows as $workflow)
                            <option value="{{ $workflow->id }}">{{ $workflow->name }}</option>
                        @endforeach
                    </select>
                    @error('bulkWorkflowId') <p class="ff-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="ff-label">Intervall in Minuten</label>
                    <input type="number" min="5" max="20160" wire:model.defer="bulkIntervalMinutes" class="ff-input">
                </div>
                <div>
                    <label class="ff-label">Streuung in Sekunden</label>
                    <input type="number" min="0" max="7200" wire:model.defer="bulkJitterSeconds" class="ff-input">
                </div>
                <div>
                    <label class="ff-label">Zeitfenster Beginn</label>
                    <input type="time" wire:model.defer="bulkWindowStart" class="ff-input">
                </div>
                <div>
                    <label class="ff-label">Zeitfenster Ende</label>
                    <input type="time" wire:model.defer="bulkWindowEnd" class="ff-input">
                </div>
                <div class="md:col-span-2 space-y-2">
                    <label class="flex items-center gap-3 text-sm">
                        <input type="checkbox" wire:model.defer="bulkOnlyActivePersons" class="h-4 w-4 rounded border-slate-300">
                        Nur aktive Personen
                    </label>
                    <label class="flex items-center gap-3 text-sm">
                        <input type="checkbox" wire:model.defer="bulkSkipExisting" class="h-4 w-4 rounded border-slate-300">
                        Personen mit vorhandenem Zeitplan fuer diesen Workflow ueberspringen
                    </label>
                </div>
            </div>
        </x-slot>
        <x-slot name="footer">
            <div class="flex justify-end gap-3">
                <button type="button" wire:click="$set('showBulkModal', false)" class="ff-btn">Abbrechen</button>
                <button type="button" wire:click="applyBulk" class="ff-btn ff-btn--primary">Zuweisen</button>
            </div>
        </x-slot>
    </x-ui.dialog-modal>
</div>
