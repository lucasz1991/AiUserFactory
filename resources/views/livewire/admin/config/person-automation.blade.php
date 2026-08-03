{{--
    Profil-Tab "Automatisierung".

    Zeitplaene dieser Person und ihre echte Lauf-Historie. Die Historie ist erst
    moeglich, seit `workflow_runs.person_id` als indizierter Spiegel existiert —
    vorher lag der Personenbezug nur im JSON und war nicht abfragbar.
--}}
<div class="ff-accounts" data-person-automation>
    @if(! $person)
        <div class="p-5 text-sm text-slate-500">Keine Person geladen.</div>
    @else
        <div class="ff-accounts__summary">
            <div>
                <p class="ff-accounts__eyebrow">Automatisierung</p>
                <h3 class="ff-accounts__title">Zeitgesteuerte Workflows dieser Person</h3>
                <p class="ff-accounts__hint">
                    Zeitfenster und Tagesdeckel gelten in der Ortszeit der Person
                    (<strong>{{ $person->automation_timezone }}</strong>).
                    @unless($globalEnabled)
                        <span class="ff-badge ff-badge--alert">Not-Aus aktiv — es wird nichts gestartet</span>
                    @endunless
                </p>
            </div>
            <div class="ff-accounts__summary-metrics">
                <div class="ff-accounts__summary-metric">
                    <span class="ff-accounts__summary-value">{{ $stats['active'] }}</span>
                    <span class="ff-accounts__summary-label">aktiv</span>
                </div>
                <div class="ff-accounts__summary-metric">
                    <span class="ff-accounts__summary-value">{{ $stats['running'] }}</span>
                    <span class="ff-accounts__summary-label">laufen</span>
                </div>
                <div class="ff-accounts__summary-metric">
                    <span class="ff-accounts__summary-value">{{ $stats['runs_total'] }}</span>
                    <span class="ff-accounts__summary-label">Laeufe</span>
                </div>
            </div>
        </div>

        <div class="ff-accounts__panel">
            {{-- Einstellungen der Person --}}
            <section class="ff-account-card">
                <header class="ff-account-card__head">
                    <div>
                        <p class="ff-account-card__eyebrow">Einstellungen</p>
                        <h4 class="ff-account-card__title">Grenzen dieser Person</h4>
                    </div>
                    <div class="ff-account-card__badges">
                        <span class="ff-badge ff-badge--{{ $person->automation_enabled ? 'ok' : 'muted' }}">
                            {{ $person->automation_enabled ? 'Automatisierung an' : 'Automatisierung aus' }}
                        </span>
                        @if($stats['next'])
                            <span class="ff-badge ff-badge--muted">Naechster Lauf {{ $stats['next']->format('d.m.Y H:i') }}</span>
                        @endif
                    </div>
                </header>

                <div class="ff-account-form__grid" style="margin-top:1rem">
                    <div>
                        <label class="ff-label">Gleichzeitige Laeufe</label>
                        <input type="number" min="1" max="20" wire:model.defer="maxConcurrentRuns" class="ff-input">
                        <p class="mt-1 text-xs text-slate-500">
                            Browsergebundene Workflows laufen unabhaengig davon immer einzeln — Browserprofil,
                            Cookie-Datei und Session gibt es je Person nur einmal.
                        </p>
                        @error('maxConcurrentRuns') <p class="ff-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="ff-label">Zustand</label>
                        <label class="flex items-center gap-3 rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm font-medium text-slate-700"
                            style="margin-top:0.35rem">
                            <input type="checkbox" wire:model.defer="automationEnabled" class="h-4 w-4 rounded border-slate-300">
                            Automatisierung fuer diese Person zulassen
                        </label>
                    </div>
                </div>

                <div class="ff-account-form__footer">
                    <button type="button" wire:click="savePersonSettings" class="ff-btn ff-btn--primary">Speichern</button>
                </div>
            </section>

            {{-- Zeitplaene --}}
            <section class="ff-account-card">
                <header class="ff-account-card__head">
                    <div>
                        <p class="ff-account-card__eyebrow">Zeitplaene</p>
                        <h4 class="ff-account-card__title">{{ $stats['schedules'] }} hinterlegt</h4>
                    </div>
                    <div class="ff-account-card__badges">
                        <a href="{{ route('network.automation') }}" class="ff-btn ff-btn--small">Zeitplan anlegen</a>
                    </div>
                </header>

                @if($schedules->isEmpty())
                    <div class="ff-emptystate">
                        Fuer diese Person ist noch kein Workflow eingeplant. Unter Netzwerk &rarr; Automatisierung
                        kannst du einen anlegen oder mehreren Personen gleichzeitig zuweisen.
                    </div>
                @else
                    <div class="overflow-x-auto" style="margin-top:1rem">
                        <table class="ff-table">
                            <thead>
                                <tr>
                                    <th>Workflow</th>
                                    <th>Takt</th>
                                    <th>Naechster Lauf</th>
                                    <th>Hinweis</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($schedules as $schedule)
                                    <tr wire:key="person-schedule-{{ $schedule->id }}">
                                        <td>
                                            <span class="ff-table__strong">{{ $schedule->workflow?->name ?? 'Unbekannt' }}</span>
                                            <span class="ff-table__sub">
                                                {{ $schedule->label ?: 'ohne Bezeichnung' }}
                                                @if($browserBound[$schedule->workflow_id] ?? false)
                                                    &middot; Browser exklusiv
                                                @endif
                                            </span>
                                        </td>
                                        <td>
                                            <span class="ff-table__strong">{{ $schedule->cadence_label }}</span>
                                            <span class="ff-table__sub">
                                                @if($schedule->cadence_type === 'interval')
                                                    alle {{ $schedule->interval_minutes }} Min.
                                                @elseif($schedule->cadence_type === 'daily_times')
                                                    {{ implode(', ', $schedule->daily_times ?? []) }}
                                                @else
                                                    aus dem Aktivitaetsplan
                                                @endif
                                            </span>
                                        </td>
                                        <td>
                                            {{ $schedule->next_run_at?->format('d.m.Y H:i') ?: '—' }}
                                            <span class="ff-table__sub">{{ $schedule->runs_today }} heute</span>
                                        </td>
                                        <td>
                                            @if($schedule->last_skip_reason)
                                                <span class="ff-table__sub">{{ $schedule->last_skip_reason }}</span>
                                            @else
                                                <span class="ff-table__sub">—</span>
                                            @endif
                                        </td>
                                        <td>
                                            <div class="flex flex-wrap justify-end gap-2">
                                                <button type="button" wire:click="runNow({{ $schedule->id }})" class="ff-btn ff-btn--small">Jetzt faellig</button>
                                                <button type="button" wire:click="toggleSchedule({{ $schedule->id }})" class="ff-btn ff-btn--small">
                                                    {{ $schedule->is_active ? 'Pausieren' : 'Aktivieren' }}
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>

            {{-- Lauf-Historie --}}
            <section class="ff-account-card">
                <header class="ff-account-card__head">
                    <div>
                        <p class="ff-account-card__eyebrow">Historie</p>
                        <h4 class="ff-account-card__title">Letzte Laeufe dieser Person</h4>
                    </div>
                    <div class="ff-account-card__badges">
                        <span class="ff-badge ff-badge--{{ $stats['runs_failed'] > 0 ? 'warn' : 'ok' }}">
                            {{ $stats['runs_failed'] }} fehlgeschlagen
                        </span>
                    </div>
                </header>

                @if($runs->isEmpty())
                    <div class="ff-emptystate">Noch keine Laeufe fuer diese Person gespeichert.</div>
                @else
                    <div class="overflow-x-auto" style="margin-top:1rem">
                        <table class="ff-table">
                            <thead>
                                <tr>
                                    <th>Workflow</th>
                                    <th>Status</th>
                                    <th>Quelle</th>
                                    <th>Start</th>
                                    <th>Dauer</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($runs as $run)
                                    <tr wire:key="person-run-{{ $run->id }}">
                                        <td><span class="ff-table__strong">{{ $run->workflow?->name ?? 'Unbekannt' }}</span></td>
                                        <td>
                                            <span class="ff-badge ff-badge--{{ match($run->status) {
                                                'completed' => 'ok',
                                                'failed', 'timed_out', 'lost' => 'alert',
                                                'cancelled' => 'muted',
                                                default => 'warn',
                                            } }}">{{ $run->status }}</span>
                                        </td>
                                        <td><span class="ff-table__sub">{{ $run->requested_by ?: '—' }}</span></td>
                                        <td>{{ ($run->started_at ?? $run->queued_at)?->format('d.m.Y H:i') ?: '—' }}</td>
                                        <td>{{ $run->duration_ms ? round($run->duration_ms / 1000).' s' : '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
        </div>
    @endif
</div>
