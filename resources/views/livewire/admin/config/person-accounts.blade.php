{{--
    Zusammengefuehrter Accounts-Tab: E-Mail und alle Portalkonten in einer
    Liste. Die Auswahl links, das gewaehlte Konto rechts. Das Mailkonto bindet
    unveraendert `person-email-account-settings` ein, damit IMAP/SMTP,
    Registrierung und Webmail-Session erhalten bleiben.
--}}
<div class="ff-accounts" data-person-accounts wire:loading.class="opacity-70">
    <div class="ff-accounts__summary">
        <div>
            <p class="ff-accounts__eyebrow">Accounts</p>
            <h3 class="ff-accounts__title">Zugaenge dieser Person</h3>
            <p class="ff-accounts__hint">
                E-Mail-Konto und Portalkonten an einem Ort. Jeder Eintrag ist im Workflow ueber
                <code class="ff-accounts__code">person.accounts.&lt;typ&gt;.username</code> erreichbar.
            </p>
        </div>
        <div class="ff-accounts__summary-metrics">
            <div class="ff-accounts__summary-metric">
                <span class="ff-accounts__summary-value">{{ $connectedCount }}</span>
                <span class="ff-accounts__summary-label">verbunden</span>
            </div>
            <div class="ff-accounts__summary-metric">
                <span class="ff-accounts__summary-value">{{ $credentialCount }}</span>
                <span class="ff-accounts__summary-label">mit Passwort</span>
            </div>
            <div class="ff-accounts__summary-metric">
                <span class="ff-accounts__summary-value">{{ count($accounts) }}</span>
                <span class="ff-accounts__summary-label">Typen</span>
            </div>
        </div>
    </div>

    <div class="ff-accounts__body">
        <nav class="ff-accounts__rail" aria-label="Kontotypen">
            @foreach($accounts as $type => $account)
                <button
                    type="button"
                    wire:click="selectType('{{ $type }}')"
                    wire:key="account-rail-{{ $type }}"
                    @class([
                        'ff-account-chip',
                        'ff-account-chip--active' => $selectedType === $type,
                        'ff-account-chip--filled' => $account['isConfigured'],
                    ])
                    data-account-type="{{ $type }}"
                    aria-current="{{ $selectedType === $type ? 'true' : 'false' }}"
                >
                    <span class="ff-account-chip__mark ff-account-chip__mark--{{ $account['accent'] }}">
                        {{ mb_strtoupper(mb_substr($account['label'], 0, 1)) }}
                    </span>
                    <span class="ff-account-chip__body">
                        <span class="ff-account-chip__label">{{ $account['label'] }}</span>
                        <span class="ff-account-chip__detail">
                            @if($account['isConfigured'])
                                {{ $account['handle'] !== '' ? $account['handle'] : $account['address'] }}
                            @else
                                Nicht eingerichtet
                            @endif
                        </span>
                    </span>
                    <span @class([
                        'ff-account-chip__dot',
                        'ff-account-chip__dot--on' => $account['hasPassword'],
                        'ff-account-chip__dot--half' => $account['isConfigured'] && ! $account['hasPassword'],
                    ])></span>
                </button>
            @endforeach
        </nav>

        <div class="ff-accounts__panel">
            @if(! $person || ! $selected)
                <div class="ff-accounts__empty">Keine Person geladen.</div>
            @elseif($selected['kind'] === 'email')
                <livewire:admin.config.person-email-account-settings
                    :person-id="$person->id"
                    :key="'person-email-account-'.$person->id" />
            @else
                <article class="ff-account-card" wire:key="account-card-{{ $selected['type'] }}">
                    <header class="ff-account-card__head">
                        <div class="min-w-0">
                            <p class="ff-account-card__eyebrow">{{ $selected['label'] }}</p>
                            <h4 class="ff-account-card__title">
                                {{ $selected['handle'] !== '' ? $selected['handle'] : 'Kein Benutzername hinterlegt' }}
                            </h4>
                            @if($selected['address'] !== '')
                                <a href="{{ $selected['address'] }}" target="_blank" rel="noopener" class="ff-account-card__link">
                                    {{ $selected['address'] }}
                                </a>
                            @endif
                        </div>
                        <div class="ff-account-card__badges">
                            <span class="ff-badge ff-badge--{{ $selected['status'] === 'active' ? 'ok' : ($selected['status'] === 'blocked' ? 'alert' : 'muted') }}">
                                {{ $selected['statusLabel'] }}
                            </span>
                            <span class="ff-badge ff-badge--{{ $selected['hasPassword'] ? 'ok' : 'warn' }}">
                                {{ $selected['hasPassword'] ? 'Passwort gespeichert' : 'Kein Passwort' }}
                            </span>
                            @if($selected['type'] === 'instagram')
                                <span class="ff-badge ff-badge--{{ $selected['hasSession'] ? 'ok' : 'muted' }}">
                                    {{ $selected['hasSession'] ? 'Session vorhanden' : 'Keine Session' }}
                                </span>
                            @endif
                        </div>
                    </header>

                    @if($selected['type'] === 'instagram')
                        <div class="ff-account-card__notice">
                            Benutzername und Passwort fuer Instagram steuern zusaetzlich den Auto-Login und den
                            Base-Abgleich. Sie werden deshalb weiterhin ueber den Zugangsdaten-Dialog gepflegt.
                        </div>
                    @endif

                    <div class="ff-account-card__actions">
                        <button type="button" wire:click="editAccount('{{ $selected['type'] }}')" class="ff-btn ff-btn--primary" data-magnetic>
                            Account bearbeiten
                        </button>

                        @if($selected['type'] === 'instagram')
                            <button type="button" x-on:click="$dispatch('person-open-credentials')" class="ff-btn">
                                Zugangsdaten bearbeiten
                            </button>
                            <button type="button" x-on:click="$dispatch('person-open-runtime-settings')" class="ff-btn">
                                Timeouts
                            </button>
                            <button type="button" x-on:click="$dispatch('person-register-instagram')" class="ff-btn">
                                Instagram registrieren
                            </button>
                            <button type="button" x-on:click="$dispatch('person-build-session')" class="ff-btn ff-btn--accent">
                                Login-Session speichern
                            </button>
                        @endif

                        @if($selected['isConfigured'])
                            <button
                                type="button"
                                wire:click="deleteAccount('{{ $selected['type'] }}')"
                                wire:confirm="Diesen Account wirklich entfernen?"
                                class="ff-btn ff-btn--danger"
                            >
                                Entfernen
                            </button>
                        @endif
                    </div>

                    @if($showForm)
                        <form wire:submit="saveAccount" class="ff-account-form">
                            <div class="ff-account-form__grid">
                                <div>
                                    <label for="account-username" class="ff-label">Benutzername</label>
                                    <input id="account-username" type="text" wire:model.defer="formUsername" class="ff-input">
                                    @error('formUsername') <p class="ff-error">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label for="account-address" class="ff-label">{{ $selected['addressLabel'] }}</label>
                                    <input id="account-address" type="text" wire:model.defer="formAddress" placeholder="Leer lassen fuer automatische Profiladresse" class="ff-input">
                                    @error('formAddress') <p class="ff-error">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label for="account-email" class="ff-label">Hinterlegte E-Mail</label>
                                    <input id="account-email" type="email" wire:model.defer="formEmail" class="ff-input">
                                    @error('formEmail') <p class="ff-error">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label for="account-status" class="ff-label">Status</label>
                                    <select id="account-status" wire:model.defer="formStatus" class="ff-input">
                                        @foreach($statuses as $value => $label)
                                            <option value="{{ $value }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                    @error('formStatus') <p class="ff-error">{{ $message }}</p> @enderror
                                </div>

                                @if($selected['type'] !== 'instagram')
                                    <div>
                                        <label for="account-password" class="ff-label">Passwort</label>
                                        <input id="account-password" type="password" wire:model.defer="formPassword" autocomplete="new-password" class="ff-input">
                                        <div class="ff-account-form__passwordhint">
                                            <span>
                                                @if($formHasStoredPassword)
                                                    Ein Passwort ist gespeichert. Leeres Feld behaelt es.
                                                @else
                                                    Aktuell ist kein Passwort gespeichert.
                                                @endif
                                            </span>
                                            @if($formHasStoredPassword)
                                                <button type="button" wire:click="clearStoredPassword" class="ff-linkbutton ff-linkbutton--danger">
                                                    Passwort loeschen
                                                </button>
                                            @endif
                                        </div>
                                        @error('formPassword') <p class="ff-error">{{ $message }}</p> @enderror
                                    </div>
                                @endif

                                <div class="ff-account-form__wide">
                                    <label for="account-notes" class="ff-label">Notizen</label>
                                    <textarea id="account-notes" rows="3" wire:model.defer="formNotes" class="ff-input"></textarea>
                                    @error('formNotes') <p class="ff-error">{{ $message }}</p> @enderror
                                </div>
                            </div>

                            <div class="ff-account-form__footer">
                                <button type="button" wire:click="cancelForm" class="ff-btn">Abbrechen</button>
                                <button type="submit" class="ff-btn ff-btn--primary">Account speichern</button>
                            </div>
                        </form>
                    @endif

                    @if($selected['notes'] !== '' && ! $showForm)
                        <div class="ff-account-card__notes">
                            <h5>Notizen</h5>
                            <p>{{ $selected['notes'] }}</p>
                        </div>
                    @endif
                </article>
            @endif

            @if($selected)
                <section class="ff-account-paths">
                    <header>
                        <h4>Workflow-Datenpfade</h4>
                        <p>Diese Pfade stehen im Task-Editor als Wertquelle zur Verfuegung und werden zur Laufzeit aus genau diesem Account gefuellt.</p>
                    </header>
                    <ul>
                        @foreach($selected['dataPaths'] as $path => $label)
                            <li wire:key="path-{{ $selected['type'] }}-{{ $loop->index }}">
                                <code>{{ $path }}</code>
                                <span>{{ $label }}</span>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif
        </div>
    </div>
</div>
